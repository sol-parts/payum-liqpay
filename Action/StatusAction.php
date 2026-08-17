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
use Payum\Core\Request\GetStatusInterface;
use SolParts\PayumLiqPay\Api;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class StatusAction implements ActionInterface
{
    /**
     * @param GetStatusInterface $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        $status = $details['status'] ?? null;
        $err_code = $details['err_code'] ?? null;

        if (Api::PAYMENT_STATUS_HOLD_WAIT === $status) {
            $request->markAuthorized();

            return;
        }

        if (Api::PAYMENT_STATUS_SUCCESS === $status || Api::PAYMENT_STATUS_SUBSCRIBED === $status) {
            $request->markCaptured();

            return;
        }

        if (Api::PAYMENT_STATUS_FAILURE === $status && Api::PAYMENT_ERR_CODE_CANCEL === $err_code) {
            $request->markCanceled();

            return;
        }

        if (Api::PAYMENT_STATUS_FAILURE === $status || Api::PAYMENT_STATUS_ERROR === $status) {
            $request->markFailed();

            return;
        }
        if (Api::PAYMENT_STATUS_REVERSED === $status) {
            $request->markRefunded();

            return;
        }
        if (Api::PAYMENT_STATUS_UNSUBSCRIBED === $status) {
            $request->markCanceled();

            return;
        }

        if (!empty($details['order_id'])
            || Api::PAYMENT_STATUS_PROCESSING === $status
            || Api::PAYMENT_STATUS_PREPARED === $status
            || Api::PAYMENT_STATUS_WAIT_QR === $status
            || Api::PAYMENT_STATUS_WAIT_SENDER === $status
            || Api::PAYMENT_STATUS_WAIT_SECURE === $status
            || Api::PAYMENT_STATUS_CASH_WAIT === $status
            || Api::PAYMENT_STATUS_INVOICE_WAIT === $status
            || \str_contains($status ?? '', '_verify') // Статуси, що потребують підтвердження платежу
        ) {
            $request->markPending();

            return;
        }

        if (null === $status) {
            $request->markNew();

            return;
        }

        $request->markUnknown();
    }

    public function supports($request)
    {
        return
            $request instanceof GetStatusInterface
            && $request->getModel() instanceof \ArrayAccess;
    }
}
