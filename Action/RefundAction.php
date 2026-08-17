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
use Payum\Core\Request\Refund;
use Payum\Core\Request\Sync;
use SolParts\PayumLiqPay\Api;

/**
 * Повернення коштів (action: refund).
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class RefundAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
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
     * @param Refund $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        if (empty($details['order_id'])) {
            return;
        }

        // `amount` для refund — підтримує часткові повернення; LiqPay приймає
        // refund і без amount (повертає повну суму), але передаємо явно — щоб
        // знати, що завжди повертаємо рівно те, що було captured. Гучна перевірка
        // замість null у запиті: без amount LiqPay відповідає generic
        // invalid_signature, з якого причину не відновити.
        $details->validateNotEmpty(['amount']);

        $result = $this->api->action('refund', $details['order_id'], [
            'amount' => $details['amount'],
        ]);

        $details->replace($result);

        $this->gateway->execute(new Sync($details));
    }

    public function supports($request)
    {
        return
            $request instanceof Refund
            && $request->getModel() instanceof \ArrayAccess;
    }
}
