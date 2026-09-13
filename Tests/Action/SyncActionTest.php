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
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Sync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SolParts\PayumLiqPay\Action\SyncAction;
use SolParts\PayumLiqPay\Api;

#[CoversClass(SyncAction::class)]
final class SyncActionTest extends TestCase
{
    public function testRejectsInvalidBase64CallbackData(): void
    {
        $api = $this->createMock(Api::class);
        $api->method('signature')->with('%%%')->willReturn('valid-signature');

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(static function (GetHttpRequest $request): void {
            $request->method = 'POST';
            $request->request = ['data' => '%%%', 'signature' => 'valid-signature'];
        });

        $action = new SyncAction();
        $action->setApi($api);
        $action->setGateway($gateway);

        try {
            $action->execute(new Sync(new ArrayObject()));
            self::fail('Invalid callback data must be rejected.');
        } catch (HttpResponse $reply) {
            self::assertSame(403, $reply->getStatusCode());
            self::assertSame('Invalid data.', $reply->getContent());
        }
    }

    /**
     * Повний payload LiqPay (`data` + `signature`) — це справжній callback: стан беремо
     * прямо з тіла запиту, зайвий round-trip у `action=status` при цьому не потрібен.
     */
    public function testAppliesVerifiedCallbackPayloadWithoutStatusCall(): void
    {
        $payload = \base64_encode((string) \json_encode(['order_id' => 'ORDER-1', 'status' => 'success']));

        $api = $this->createMock(Api::class);
        $api->method('signature')->with($payload)->willReturn('valid-signature');
        // Callback самодостатній — polling статусу тут був би зайвим викликом до LiqPay.
        $api->expects(self::never())->method('action');

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(static function (GetHttpRequest $request) use ($payload): void {
            $request->method = 'POST';
            $request->request = ['data' => $payload, 'signature' => 'valid-signature'];
        });

        $action = new SyncAction();
        $action->setApi($api);
        $action->setGateway($gateway);

        $details = new ArrayObject(['order_id' => 'ORDER-1', 'status' => 'hold_wait']);
        $action->execute(new Sync($details));

        self::assertSame('success', $details['status']);
    }

    /**
     * Підроблений підпис callback'а — 403, стан транзакції недоторканий. Пропуск цієї
     * перевірки означав би, що будь-хто, знаючи notify-URL, довільним POST'ом «оплачує»
     * замовлення.
     */
    public function testForgedCallbackSignatureRejectedWithoutTouchingDetails(): void
    {
        $payload = \base64_encode((string) \json_encode(['order_id' => 'ORDER-1', 'status' => 'success']));

        $api = $this->createMock(Api::class);
        $api->method('signature')->with($payload)->willReturn('valid-signature');
        $api->expects(self::never())->method('action');

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(static function (GetHttpRequest $request) use ($payload): void {
            $request->method = 'POST';
            $request->request = ['data' => $payload, 'signature' => 'forged-signature'];
        });

        $action = new SyncAction();
        $action->setApi($api);
        $action->setGateway($gateway);

        $details = new ArrayObject(['order_id' => 'ORDER-1', 'status' => 'hold_wait']);

        try {
            $action->execute(new Sync($details));
            self::fail('Очікували 403 на підробленому підписі.');
        } catch (HttpResponse $reply) {
            self::assertSame(403, $reply->getStatusCode());
        }

        self::assertSame('hold_wait', $details['status']);
    }

    /**
     * Коректно підписаний payload ІНШОГО платежу того ж мерчанта — 403. Підпис
     * мерчант-рівневий, а widget-флоу віддає покупцеві його власну підписану пару прямо
     * в браузер: без прив'язки по order_id нею можна було б перезаписати стан чужої
     * транзакції — «оплатити» дороге замовлення success'ом копійчаного.
     */
    public function testSignedCallbackOfAnotherOrderRejected(): void
    {
        $payload = \base64_encode((string) \json_encode(['order_id' => 'ORDER-CHEAP', 'status' => 'success']));

        $api = $this->createMock(Api::class);
        $api->method('signature')->with($payload)->willReturn('valid-signature');
        $api->expects(self::never())->method('action');

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(static function (GetHttpRequest $request) use ($payload): void {
            $request->method = 'POST';
            $request->request = ['data' => $payload, 'signature' => 'valid-signature'];
        });

        $action = new SyncAction();
        $action->setApi($api);
        $action->setGateway($gateway);

        $details = new ArrayObject(['order_id' => 'ORDER-EXPENSIVE', 'status' => 'hold_wait']);

        try {
            $action->execute(new Sync($details));
            self::fail('Очікували 403 на callback\'у чужого платежу.');
        } catch (HttpResponse $reply) {
            self::assertSame(403, $reply->getStatusCode());
        }

        self::assertSame('hold_wait', $details['status']);
    }

    /**
     * Регресія прод-помилки: клієнт скасовує замовлення з hold-платежем LiqPay →
     * `CancelAction` робить server-to-server `refund` і одразу викликає `Sync` — усе це в
     * межах POST-запиту хоста, який до callback'у LiqPay не має стосунку. Такий POST може
     * нести власне поле `data`: контролер Symfony UX Live Components шле кожну дію як
     * multipart з полем `data` (JSON пропсів), і саме на ньому ознака «POST з полем `data`»
     * хибно спрацьовувала, даючи клієнту 403 «Invalid signature» замість скасування.
     * Контракт: без повного payload'у (`data` + `signature`) це не callback — маємо піти
     * у status-polling.
     *
     * @param array<string, string> $postFields
     */
    #[DataProvider('nonCallbackPostFieldsProvider')]
    public function testPostWithoutFullPayloadFallsBackToStatusPolling(array $postFields): void
    {
        $api = $this->createMock(Api::class);
        // Підпис перевіряти нема на чому — до гілки callback'у ми не мусимо дійти взагалі.
        $api->expects(self::never())->method('signature');
        $api->expects(self::once())
            ->method('action')
            ->with('status', 'ORDER-1')
            ->willReturn(['order_id' => 'ORDER-1', 'status' => 'reversed']);

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(static function (GetHttpRequest $request) use ($postFields): void {
            $request->method = 'POST';
            $request->request = $postFields;
        });

        $action = new SyncAction();
        $action->setApi($api);
        $action->setGateway($gateway);

        $details = new ArrayObject(['order_id' => 'ORDER-1', 'status' => 'hold_wait']);
        $action->execute(new Sync($details));

        self::assertSame('reversed', $details['status']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function nonCallbackPostFieldsProvider(): iterable
    {
        yield 'live component action' => [['data' => '{"props":{"order":1},"updated":[]}']];
        yield 'plain cancel form' => [['_token' => 'csrf-token', 'uniqid' => 'abc123']];
    }
}
