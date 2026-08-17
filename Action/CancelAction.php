<?php

/*
 * This file is part of Sol.parts
 *
 * (c) SOLPARTS LLC (EDRPOU 46143031) <mail@sol.parts>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SolParts\PayumLiqPay\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Cancel;
use Payum\Core\Request\Sync;
use SolParts\PayumLiqPay\Api;

/**
 * Скасування платежу LiqPay.
 *
 * Для hold-платежу (`status=hold_wait`) — реальний reversal через
 * `action=refund` API LiqPay: знімає замороження коштів на карті клієнта.
 * LiqPay не різнить void і refund на рівні API — той самий endpoint
 * обробляє і reversal hold'у, і refund captured платежу, повертаючи у
 * відповіді однаковий `status=reversed`. Якщо споживачу пакета потрібно
 * розрізнити cancel-from-hold і refund-from-captured (наприклад, для
 * workflow з окремими transition'ами), це робиться на стороні споживача
 * через extension/listener, який бачить попередній стан платежу.
 *
 * Для new/pending — NOOP: інвойс checkout/pay не має окремого API
 * для інвалідації, він протухне сам.
 *
 * @see https://www.liqpay.ua/uk/doc/api/internet_acquiring/refund
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class CancelAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @var Api */
    protected $api;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /**
     * @param Cancel $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        if (Api::PAYMENT_STATUS_HOLD_WAIT !== ($details['status'] ?? null)) {
            return;
        }

        if (empty($details['order_id'])) {
            return;
        }

        // `amount` для refund — підтримує часткові повернення; LiqPay приймає
        // refund і без amount (повертає повну суму), але передаємо явно — щоб
        // знати, що завжди повертаємо рівно те, що холдували. Беремо з details:
        // воно туди записане ще при cnb-form для hold-платежу. Гучна перевірка
        // замість null у запиті: без amount LiqPay відповідає generic
        // invalid_signature, з якого причину не відновити.
        $details->validateNotEmpty(['amount']);

        $result = $this->api->action('refund', $details['order_id'], [
            'amount' => $details['amount'],
        ]);
        $details->replace($result);

        // Передаємо у Sync первинну модель (а не локальний $details): так
        // extensions/actions споживача можуть через getFirstModel() прочитати
        // початковий контекст запиту (попередній стан, метадані замовлення тощо)
        // і прийняти рішення, як змапити `reversed`-результат на власну
        // доменну модель.
        $this->gateway->execute(new Sync($request->getFirstModel()));
    }

    public function supports($request)
    {
        return
            $request instanceof Cancel
            && $request->getModel() instanceof \ArrayAccess;
    }
}
