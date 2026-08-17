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

namespace SolParts\PayumLiqPay;

use Http\Discovery\Psr17FactoryDiscovery;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;
use SolParts\PayumLiqPay\Action\Api\DoCaptureAction;
use SolParts\PayumLiqPay\Action\AuthorizeAction;
use SolParts\PayumLiqPay\Action\CancelAction;
use SolParts\PayumLiqPay\Action\CaptureAction;
use SolParts\PayumLiqPay\Action\ConvertPaymentAction;
use SolParts\PayumLiqPay\Action\NotifyAction;
use SolParts\PayumLiqPay\Action\ObtainTokenAction;
use SolParts\PayumLiqPay\Action\RefundAction;
use SolParts\PayumLiqPay\Action\StatusAction;
use SolParts\PayumLiqPay\Action\SyncAction;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 * @author Oleksandr Nechyporuk <oleksandr@nechyporuk.name>
 */
class LiqPayCheckoutGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => 'liqpay_checkout',
            'payum.factory_title' => 'LiqPay',

            'payum.template.obtain_token' => '@PayumLiqPay/Action/obtain_checkout_token.html.twig',

            'payum.action.capture' => new CaptureAction(),
            'payum.action.obtain_token' => static fn (ArrayObject $config) => new ObtainTokenAction($config['payum.template.obtain_token']),
            'payum.action.authorize' => new AuthorizeAction(),
            'payum.action.refund' => new RefundAction(),
            'payum.action.cancel' => new CancelAction(),
            'payum.action.notify' => new NotifyAction(),
            'payum.action.status' => new StatusAction(),
            'payum.action.sync' => new SyncAction(),
            'payum.action.convert_payment' => new ConvertPaymentAction(),
            'payum.action.api.do_capture' => new DoCaptureAction(),
        ]);

        if (!$config['payum.api']) {
            $config['payum.required_options'] = ['public_key', 'private_key'];

            $config['payum.api'] = static function (ArrayObject $config) {
                $config->validateNotEmpty($config['payum.required_options']);

                return new Api(
                    (array) $config,
                    $config['payum.http_client'],
                    // Хост може передати власні PSR-17 фабрики цими опціями; без них —
                    // discovery, щоб конфіг із коробки працював з будь-якою PSR-17
                    // реалізацією (у payum/core таких опцій немає).
                    $config['payum.http_message_factory'] ?? Psr17FactoryDiscovery::findRequestFactory(),
                    $config['payum.http_stream_factory'] ?? Psr17FactoryDiscovery::findStreamFactory(),
                );
            };
        }

        $config['payum.paths'] = \array_replace([
            'PayumLiqPay' => __DIR__ . '/Resources/views',
        ], $config['payum.paths'] ?: []);
    }
}
