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

namespace SolParts\PayumLiqPay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayInterface;
use Payum\Core\Request\Capture;
use Payum\Core\Security\GenericTokenFactoryInterface;
use Payum\Core\Security\TokenInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SolParts\PayumLiqPay\Action\CaptureAction;
use SolParts\PayumLiqPay\Action\PurchaseAction;
use SolParts\PayumLiqPay\Api;

#[CoversClass(PurchaseAction::class)]
final class PurchaseActionTest extends TestCase
{
    /**
     * Регресія контракту return-URL.
     *
     * result_url зашивається у `data` LiqPay-чекаута і мусить вести на повторно-запитуваний
     * `done`-токен (afterUrl capture-токена), а не на сам одноразовий capture-токен з `back=1`:
     * той інвалідується на першому ж поверненні, і повторне повернення з форми LiqPay
     * (browser-back, retry після відмови) падало 404 «A token with hash ... could not be found».
     */
    public function testResultUrlPointsToReusableDoneTokenNotToOneOffCaptureToken(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getTargetUrl')->willReturn('https://skeleton.test/payment/capture/one-off-hash');
        $token->method('getAfterUrl')->willReturn('https://skeleton.test/payment/done?payum_token=reusable-done-hash');
        $token->method('getGatewayName')->willReturn('liqpay_checkout');
        $token->method('getDetails')->willReturn(new \Payum\Core\Model\Identity(1, \stdClass::class));

        $notifyToken = $this->createMock(TokenInterface::class);
        $notifyToken->method('getTargetUrl')->willReturn('https://skeleton.test/payment/notify/notify-hash');

        $tokenFactory = $this->createMock(GenericTokenFactoryInterface::class);
        $tokenFactory->method('createNotifyToken')->willReturn($notifyToken);

        // Криптографію не перевіряємо: у тесті цікавить лише те, ЩО потрапило в result_url
        // до підписування, тож payload-builder віддає фіктивну підписану пару.
        $api = $this->createMock(Api::class);
        $api->method('buildCheckoutPayload')->willReturn([
            'body' => [],
            'data' => 'signed-data',
            'signature' => 'signed-signature',
        ]);

        // ObtainToken мовчки поглинається — рендер сторінки віджета тут не перевіряємо.
        $gateway = $this->createMock(GatewayInterface::class);

        $action = new CaptureAction();
        $action->setApi($api);
        $action->setGateway($gateway);
        $action->setGenericTokenFactory($tokenFactory);

        $details = new ArrayObject([]);
        $request = new Capture($token);
        $request->setModel($details);

        $action->execute($request);

        self::assertSame(
            'https://skeleton.test/payment/done?payum_token=reusable-done-hash',
            $details['result_url'],
        );
    }
}
