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
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Model\PaymentInterface;
use Payum\Core\Request\Convert;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class ConvertPaymentAction implements ActionInterface
{
    use GatewayAwareTrait;

    /**
     * @param Convert $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getSource();
        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        $details['amount'] = $payment->getTotalAmount() / 100;
        $details['currency'] = $payment->getCurrencyCode();
        $details['description'] = $payment->getDescription();
        $details['order_id'] = $payment->getNumber();
        $details['version'] = 3;
        $details['language'] ??= 'uk';

        $request->setResult((array) $details);
    }

    public function supports($request)
    {
        return
            $request instanceof Convert
            && $request->getSource() instanceof PaymentInterface
            && 'array' === $request->getTo();
    }
}
