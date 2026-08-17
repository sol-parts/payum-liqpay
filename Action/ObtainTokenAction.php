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
use Payum\Core\Request\RenderTemplate;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;
use SolParts\PayumLiqPay\Api;
use SolParts\PayumLiqPay\Request\Api\ObtainToken;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 * @author Oleksandr Nechyporuk <oleksandr@nechyporuk.name>
 */
class ObtainTokenAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;
    use GenericTokenFactoryAwareTrait;

    /** @var Api */
    protected $api;

    public function __construct(private string $templateName)
    {
        $this->apiClass = Api::class;
    }

    /**
     * @param ObtainToken $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        $this->gateway->execute($renderTemplate = new RenderTemplate($this->templateName, [
            'data' => $request->getData(),
            'signature' => $request->getSignature(),
            'result_url' => $details['result_url'],
            'language' => (string) ($details['language'] ?? 'uk'),
            'checkout_url' => $this->api->getCheckoutUrl(),
        ]));

        throw new HttpResponse($renderTemplate->getResult());
    }

    public function supports($request)
    {
        return
            $request instanceof ObtainToken
            && $request->getModel() instanceof \ArrayAccess;
    }
}
