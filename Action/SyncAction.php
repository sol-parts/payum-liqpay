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
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Sync;
use SolParts\PayumLiqPay\Api;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class SyncAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @var Api */
    protected $api;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function execute($request): void
    {
        /** @var Sync $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        $getHttpRequest = new GetHttpRequest();
        $this->gateway->execute($getHttpRequest);

        // Callback LiqPay розпізнаємо за повним НЕПОРОЖНІМ payload'ом (`data` + `signature`),
        // а не за самим фактом POST: Sync викликається не лише з NotifyAction, а й після
        // server-to-server `refund` (CancelAction/RefundAction) — уже в межах звичайного
        // POST зі сторінки замовлення чи адмінки. Обидва поля обов'язкові, бо POST хоста
        // може нести власне поле `data` (наприклад, контролер Symfony UX Live Components
        // шле кожну дію як multipart-POST з `data`; порожні значення теж можливі) — м'якша
        // умова приймала б такий запит за callback і відповідала 403 замість синхронізації.
        // Неповний payload безпечно йде у status-polling нижче — там джерело правди API.
        $data = (string) ($getHttpRequest->request['data'] ?? '');
        $signature = (string) ($getHttpRequest->request['signature'] ?? '');

        $isCallback = 'POST' === $getHttpRequest->method && '' !== $data && '' !== $signature;

        if ($isCallback) {
            if (!\hash_equals($this->api->signature($data), $signature)) {
                throw new HttpResponse('Invalid signature.', 403, ['Content-Type' => 'text/plain']);
            }

            $decodedData = \base64_decode($data, true);
            if (false === $decodedData) {
                throw new HttpResponse('Invalid data.', 403, ['Content-Type' => 'text/plain']);
            }

            try {
                $result = \json_decode($decodedData, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpResponse('Invalid data.', 403, ['Content-Type' => 'text/plain']);
            }
            if (!\is_array($result)) {
                throw new HttpResponse('Invalid data.', 403, ['Content-Type' => 'text/plain']);
            }

            // Прив'язка callback'а до транзакції. Підпис — мерчант-рівневий (один
            // private_key на всі платежі), тож коректно підписаний payload ІНШОГО платежу
            // теж пройшов би перевірку вище: widget-флоу віддає покупцеві його власну
            // підписану пару прямо в браузер, і нею можна було б перезаписати стан чужої
            // транзакції (наприклад, «оплатити» дороге замовлення success'ом копійчаного).
            if (!empty($details['order_id'])
                && (string) ($result['order_id'] ?? '') !== (string) $details['order_id']
            ) {
                throw new HttpResponse('Invalid order.', 403, ['Content-Type' => 'text/plain']);
            }

            $details->replace($result);

            return;
        }

        if (!empty($details['order_id'])) {
            $result = $this->api->action('status', $details['order_id']);
            $details->replace($result);
        }
    }

    public function supports($request)
    {
        return
            $request instanceof Sync
            && $request->getModel() instanceof \ArrayAccess;
    }
}
