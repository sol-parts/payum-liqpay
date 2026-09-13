<?php

/*
 * This file is part of SOLPARTS
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
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;
use SolParts\PayumLiqPay\Api;
use SolParts\PayumLiqPay\Request\Api\ObtainToken;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 */
abstract class PurchaseAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;
    use GenericTokenFactoryAwareTrait;

    /** @var Api */
    protected $api;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        $details['action'] ??= Api::PAYMENT_ACTION_PAY;

        if (empty($details['result_url']) && $request->getToken()) {
            // Спільний контракт наших шлюзів: return-URL вішаємо на `done`-токен (afterUrl,
            // який capture-токен і так носить), а не на сам одноразовий capture-токен —
            // той інвалідується на першому ж поверненні, і повторне повернення з форми
            // LiqPay падало б 404. `done`-токен Payum лишає повторно-запитуваним, а
            // done-ендпоінт хоста на кожному відкритті сам робить Sync.
            // Так само зроблено в решті шлюзів цієї родини.
            $details['result_url'] = $request->getToken()->getAfterUrl();
        }

        if (empty($details['server_url']) && $request->getToken()) {
            $notifyToken = $this->tokenFactory->createNotifyToken(
                $request->getToken()->getGatewayName(),
                $request->getToken()->getDetails(),
            );

            $details['server_url'] = $notifyToken->getTargetUrl();
        }

        ['data' => $data, 'signature' => $signature] = $this->api->buildCheckoutPayload((array) $details);

        $obtainToken = new ObtainToken($request->getToken(), $data, $signature);
        $obtainToken->setModel($details);
        $this->gateway->execute($obtainToken);
    }
}
