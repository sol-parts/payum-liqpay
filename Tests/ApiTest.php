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

namespace SolParts\PayumLiqPay\Tests;

use Payum\Core\HttpClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SolParts\PayumLiqPay\Api;

#[CoversClass(Api::class)]
final class ApiTest extends TestCase
{
    public function testBuildPayloadProducesSignedJsonData(): void
    {
        $api = $this->api();

        $payload = $api->buildPayload('refund', 42, ['amount' => 1234]);

        self::assertSame([
            'action' => 'refund',
            'version' => 3,
            'public_key' => 'public',
            'order_id' => 42,
            'amount' => 1234,
        ], $payload['body']);
        self::assertSame($payload['body'], \json_decode(\base64_decode($payload['data'], true), true, 512, \JSON_THROW_ON_ERROR));
        self::assertSame($api->signature($payload['data']), $payload['signature']);
    }

    /**
     * Транзакції, створені старими версіями пакета, несуть у details персистовані
     * колись `data`/`signature`. Білий список тримає їх поза новим payload'ом —
     * інакше старий `data` вкладався б у новий, і кожен повторний рендер роздував би
     * підписані дані, поки сховище не обріже значення посередині base64.
     */
    public function testBuildCheckoutPayloadIgnoresLegacyPersistedDataAndSignature(): void
    {
        $api = $this->api();

        $payload = $api->buildCheckoutPayload([
            'order_id' => 42,
            'data' => 'stale-data',
            'signature' => 'stale-signature',
        ]);

        $decoded = \json_decode(\base64_decode($payload['data'], true), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['order_id' => 42, 'public_key' => 'public'], $decoded);
        self::assertSame($api->signature($payload['data']), $payload['signature']);
    }

    /**
     * `details` транзакції — сховище хост-застосунку: після невдалої спроби там лежить
     * весь callback LiqPay (`status`, `err_code`, `payment_id`, …) і службові ключі
     * хоста. Тіло checkout-запиту збирається білим списком полів протоколу — інакше
     * повторна спроба оплати відправляла б банку його ж відповідь і чужі ключі у
     * підписаних даних (вони видимі в кабінеті мерчанта і залежать від тлумачення
     * LiqPay невідомих полів).
     */
    public function testBuildCheckoutPayloadUsesProtocolFieldsOnly(): void
    {
        $api = $this->api();

        $payload = $api->buildCheckoutPayload([
            'version' => 3,
            'action' => 'pay',
            'amount' => 100.5,
            'currency' => 'UAH',
            'description' => 'Order 42',
            'order_id' => 42,
            'language' => 'uk',
            'result_url' => 'https://shop.example/done',
            'server_url' => 'https://shop.example/notify',
            // залишки callback'а невдалої спроби і службовий ключ хоста
            'status' => 'failure',
            'err_code' => 'limit',
            'payment_id' => 165,
            '_error' => 'internal diagnostics',
        ]);

        $expectedBody = [
            'version' => 3,
            'action' => 'pay',
            'amount' => 100.5,
            'currency' => 'UAH',
            'description' => 'Order 42',
            'order_id' => 42,
            'language' => 'uk',
            'result_url' => 'https://shop.example/done',
            'server_url' => 'https://shop.example/notify',
            'public_key' => 'public',
        ];

        self::assertSame($expectedBody, $payload['body']);
        // `data` — це те саме тіло, підписане як є: розшифрування збігається з body.
        self::assertSame(
            $expectedBody,
            \json_decode(\base64_decode($payload['data'], true), true, 512, \JSON_THROW_ON_ERROR),
        );
        self::assertSame($api->signature($payload['data']), $payload['signature']);
    }

    private function api(): Api
    {
        return new Api(
            ['public_key' => 'public', 'private_key' => 'private'],
            $this->createMock(HttpClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class),
        );
    }
}
