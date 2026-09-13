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

namespace SolParts\PayumLiqPay\Action\Api;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Sync;
use SolParts\PayumContracts\Request\Api\DoCapture;
use SolParts\PayumLiqPay\Api;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class DoCaptureAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
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
        /** @var DoCapture $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        $details->validateNotEmpty(['order_id', 'amount']);

        // `amount` для hold_completion обов'язковий — вказує, скільки списати
        // з холду (часткове capture). Без нього LiqPay віддає generic
        // invalid_signature (див. PHPDoc Api::action()).
        $this->api->action('hold_completion', (string) $details['order_id'], [
            'amount' => $details['amount'],
        ]);

        $this->gateway->execute(new Sync($details));
    }

    public function supports($request)
    {
        return
            $request instanceof DoCapture
            && $request->getModel() instanceof \ArrayAccess;
    }
}
