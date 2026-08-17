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

use Payum\Core\Bridge\Spl\ArrayObject;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 * @author Oleksandr Nechyporuk <oleksandr@nechyporuk.name>
 */
class LiqPayWidgetGatewayFactory extends LiqPayCheckoutGatewayFactory
{
    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => 'liqpay_widget',
            'payum.factory_title' => 'LiqPay Widget',

            'payum.template.obtain_token' => '@PayumLiqPay/Action/obtain_widget_token.html.twig',
        ]);

        parent::populateConfig($config);
    }
}
