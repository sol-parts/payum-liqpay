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

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Authorize;
use SolParts\PayumLiqPay\Api;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 * @author Oleksandr Nechyporuk <oleksandr@nechyporuk.name>
 */
class AuthorizeAction extends PurchaseAction
{
    public function execute($request): void
    {
        /** @var Authorize $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        $details['action'] = Api::PAYMENT_ACTION_HOLD;

        parent::execute($request);
    }

    public function supports($request)
    {
        return
            $request instanceof Authorize
            && $request->getModel() instanceof \ArrayAccess;
    }
}
