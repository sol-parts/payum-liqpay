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

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Payum\Core\Exception\LogicException;
use Payum\Core\HttpClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SolParts\PayumLiqPay\Api;

/**
 * Що саме потрапляє в текст винятку, коли LiqPay відповів помилкою.
 *
 * Ціна питання — не краса повідомлення, а можливість діагностувати прод. Виняток звідси
 * доходить до менеджера в історії дій замовлення і до логу помилок хост-застосунку; якщо
 * в ньому немає ні коду відповіді, ні тіла, інцидент нерозв'язний без доступу до шлюзу.
 *
 * Та сама помилка порядку (`json_decode` перед перевіркою статусу) в сусідньому шлюзі
 * родини на реальному інциденті вироджувала будь-яку не-JSON відповідь шлюзу в
 * «Syntax error for <url>» — без коду, без тіла, без різниці між throttling, 5xx і
 * зламаним контрактом. Порядок перевірок і закріплює цей тест.
 */
#[CoversClass(Api::class)]
final class ApiErrorReportingTest extends TestCase
{
    /**
     * Не-JSON тіло на помилковому статусі більше не ховає код: рішення «це throttling,
     * а не поломка інтеграції» ухвалюється саме за ним.
     */
    public function testNonJsonErrorResponseReportsStatusCode(): void
    {
        $exception = $this->captureActionFailure(
            new Response(429, [], '<html><head><title>429 Too Many Requests</title></head></html>'),
        );

        self::assertStringContainsString('with code "429"', $exception->getMessage());
        self::assertStringContainsString('429 Too Many Requests', $exception->getMessage());
    }

    /**
     * Помилковий JSON LiqPay має доходити ЦІЛИМ: `err_code`/`err_description` — єдине, з чого
     * менеджер зрозуміє причину відмови, і саме за цим текстом хост-застосунок формує
     * людський опис помилки в історії дій замовлення.
     */
    public function testErrorJsonSurvivesIntact(): void
    {
        $body = '{"result":"error","err_code":"payment_err_paytype","err_description":"Спосіб оплати недоступний"}';

        $exception = $this->captureActionFailure(new Response(400, [], $body));

        self::assertStringContainsString($body, $exception->getMessage());
    }

    /**
     * 200 з не-JSON тілом — окремий випадок від помилкового статусу: контракт зламано на
     * успішній відповіді. Код усе одно називаємо, інакше не відрізнити його від 5xx.
     */
    public function testBrokenJsonOnSuccessStatusStillReportsBody(): void
    {
        $exception = $this->captureActionFailure(new Response(200, [], 'not a json at all'));

        self::assertStringContainsString('Syntax error', $exception->getMessage());
        self::assertStringContainsString('with code "200"', $exception->getMessage());
        self::assertStringContainsString('not a json at all', $exception->getMessage());
    }

    /**
     * HTML-сторінка 5xx буває на десятки кілобайт — у лог помилок і в `details` транзакції
     * такий текст їхати не повинен.
     */
    public function testHugeBodyIsTruncated(): void
    {
        $exception = $this->captureActionFailure(
            new Response(502, [], \str_repeat('x', 50_000)),
        );

        self::assertLessThan(1500, \mb_strlen($exception->getMessage()));
        self::assertStringContainsString('…', $exception->getMessage());
    }

    /** Порожнє тіло теж має нести код: інакше «empty» не відрізнити від обриву з'єднання. */
    public function testEmptyBodyReportsStatusCode(): void
    {
        $exception = $this->captureActionFailure(new Response(503, [], ''));

        self::assertStringContainsString('with code "503"', $exception->getMessage());
    }

    private function captureActionFailure(ResponseInterface $response): LogicException
    {
        $psr17 = new Psr17Factory();

        $client = new class($response) implements HttpClientInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function send(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $api = new Api(['public_key' => 'public', 'private_key' => 'private'], $client, $psr17, $psr17);

        try {
            $api->action('status', 42);
        } catch (LogicException $e) {
            return $e;
        }

        self::fail('Очікували LogicException від Api::action()');
    }
}
