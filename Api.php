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

namespace SolParts\PayumLiqPay;

use Payum\Core\Exception\LogicException;
use Payum\Core\HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 * @author Oleksandr Nechyporuk <oleksandr@nechyporuk.name>
 *
 * @see https://www.liqpay.ua/doc/api
 * @see https://www.liqpay.ua/doc/api/testing - Платіжні дані для тестування платежів
 */
class Api
{
    /**
     * Тип операції - платіж.
     */
    public const PAYMENT_ACTION_PAY = 'pay';
    public const PAYMENT_ACTION_HOLD = 'hold';

    public const PAYMENT_STATUS_PREPARED = 'prepared';
    public const PAYMENT_STATUS_PROCESSING = 'processing';
    /**
     * Очікується сканування QR-коду клієнтом
     */
    public const PAYMENT_STATUS_WAIT_QR = 'wait_qr';
    /**
     * Очікується підтвердження оплати клієнтом в додатку Privat24/SENDER.
     */
    public const PAYMENT_STATUS_WAIT_SENDER = 'wait_sender';
    /**
     * Платіж на перевірці.
     */
    public const PAYMENT_STATUS_WAIT_SECURE = 'wait_secure';
    /**
     * Очікується оплата готівкою в ТСО.
     */
    public const PAYMENT_STATUS_CASH_WAIT = 'cash_wait';
    /**
     * Інвойс створений успішно, очікується оплата.
     */
    public const PAYMENT_STATUS_INVOICE_WAIT = 'invoice_wait';

    public const PAYMENT_STATUS_HOLD_WAIT = 'hold_wait';
    public const PAYMENT_STATUS_SUCCESS = 'success';

    public const PAYMENT_STATUS_SUBSCRIBED = 'subscribed';
    public const PAYMENT_STATUS_ERROR = 'error';
    public const PAYMENT_STATUS_FAILURE = 'failure';
    public const PAYMENT_STATUS_REVERSED = 'reversed';
    public const PAYMENT_STATUS_UNSUBSCRIBED = 'unsubscribed';
    public const PAYMENT_ERR_CODE_CANCEL = 'cancel';

    /**
     * Скільки символів тіла відповіді лишати в тексті винятку.
     * З запасом покриває помилкову відповідь LiqPay — див. {@see contentSnippet()}.
     */
    private const CONTENT_SNIPPET_LENGTH = 500;

    /**
     * @var HttpClientInterface
     */
    protected $client;

    /**
     * @var array<mixed>
     */
    protected $options = [];

    /**
     * @param array<mixed> $options
     *
     * @throws \Payum\Core\Exception\InvalidArgumentException if an option is invalid
     */
    public function __construct(
        array $options,
        HttpClientInterface $client,
        protected RequestFactoryInterface $requestFactory,
        protected StreamFactoryInterface $streamFactory,
    ) {
        $this->options = $options;
        $this->client = $client;
    }

    public function getFactoryName(): string
    {
        return (string) ($this->options['payum.factory_name'] ?? '');
    }

    /**
     * Поля тіла cnb-checkout запиту за протоколом LiqPay.
     *
     * `details` транзакції — сховище хост-застосунку, а не payload банку: туди цілком
     * мержаться callback'и LiqPay (`status`, `err_code`, `payment_id`, …) і службові
     * ключі хоста; транзакції старих версій пакета несуть там ще й персистовані колись
     * `data`/`signature`. Payload збираємо білим списком полів протоколу — інакше
     * повторна спроба оплати несла б у підписані дані відповідь банку і чужі для
     * checkout-запиту поля.
     *
     * Перелік — з офіційної документації checkout; рідкісний параметр, якого тут
     * бракує, підклас Api може додати, перевизначивши константу (читається через
     * `static::`).
     *
     * @see https://www.liqpay.ua/uk/doc/api/internet_acquiring/checkout?tab=1
     */
    protected const CHECKOUT_PAYLOAD_FIELDS = [
        'version',
        'public_key',
        'action',
        'amount',
        'currency',
        'description',
        'order_id',
        'language',
        'result_url',
        'server_url',
        'expired_date',
        'paytypes',
        'sandbox',
        'info',
        'dae',
        'verifycode',
        'customer',
        'customer_user_id',
        'phone',
        'recurringbytoken',
        'product_category',
        'product_description',
        'product_name',
        'product_url',
        'sender_first_name',
        'sender_last_name',
        'sender_country_code',
        'sender_city',
        'sender_address',
        'sender_postal_code',
        'sender_shipping_state',
        'letter_of_credit',
        'letter_of_credit_date',
        'subscribe',
        'subscribe_date_start',
        'subscribe_periodicity',
        'split_rules',
        'rro_info',
    ];

    /**
     * Збирає підписаний cnb-checkout payload з details транзакції.
     *
     * Похідні `data`/`signature` не пишуться назад у details: це per-render артефакт
     * для форми/віджета, а details — сховище стану. Кожен рендер платіжної сторінки
     * збирає і підписує payload заново з актуальних полів — покупець ніколи не
     * отримує форму, підписану під застарілі дані.
     *
     * @param array<mixed> $details
     *
     * @return array{body: array<string, mixed>, data: string, signature: string}
     */
    public function buildCheckoutPayload(array $details): array
    {
        $body = \array_intersect_key($details, \array_flip(static::CHECKOUT_PAYLOAD_FIELDS));

        $body['public_key'] = $this->options['public_key'];

        $data = \base64_encode(\json_encode($body, \JSON_THROW_ON_ERROR));

        return [
            'body' => $body,
            'data' => $data,
            'signature' => $this->signature($data),
        ];
    }

    /**
     * Server-to-server виклик до `POST /api/request`.
     *
     * LiqPay для частини дій вимагає додаткові обов'язкові поля поза
     * стандартним мінімумом (`action`/`version`/`public_key`/`order_id`):
     *
     *   - `refund`          → передаємо `amount` (підтримуються часткові повернення)
     *   - `hold_completion` → `amount` обов'язковий (скільки списати з холду)
     *   - `status`          → без додаткових полів
     *
     * Required-поля передавай через `$params`. Якщо їх не передати, LiqPay
     * може повернути generic `err_code=invalid_signature` (підпис нібито
     * «не сходиться»), що збиває з пантелику.
     *
     * @see https://www.liqpay.ua/uk/doc/api/internet_acquiring/refund?tab=1
     * @see https://www.liqpay.ua/uk/doc/api/information/status_payment?tab=1
     * @see https://www.liqpay.ua/uk/doc/api/internet_acquiring/two_step?tab=2
     *
     * @param array<string, mixed> $params додаткові поля body понад мінімальний набір
     *
     * @return array<mixed>
     */
    public function action(string $actionName, string|int $order_id, array $params = []): array
    {
        ['data' => $data, 'signature' => $signature] = $this->buildPayload($actionName, $order_id, $params);

        $request = $this->requestFactory
            ->createRequest('POST', $this->getApiEndpoint() . '/api/request')
            ->withBody($this->streamFactory->createStream(\http_build_query([
                'data' => $data,
                'signature' => $signature,
            ])));

        return $this->doRequest($request);
    }

    /**
     * Збирає payload для `POST /api/request` без виконання HTTP-запиту.
     *
     * Окремий публічний метод — щоб діагностичні інструменти і тести могли
     * побачити, що саме йде у LiqPay (body/data/signature), не запускаючи
     * фактичний дзвінок і не дублюючи логіку формування підпису.
     *
     * @param array<string, mixed> $params див. {@see action()}
     *
     * @return array{body: array<string, mixed>, data: string, signature: string}
     */
    public function buildPayload(string $actionName, string|int $order_id, array $params = []): array
    {
        $body = \array_merge([
            'action' => $actionName,
            // Version=3, попри те що публічна doc LiqPay пише «Поточне значення - 7».
            // Реально для server-to-server `/api/request` (status/refund/hold_completion)
            // LiqPay приймає лише v=3 — те саме використовує офіційний LiqPay PHP SDK
            // ({@link https://github.com/liqpay/sdk-php}). v=7 — формат callback від
            // LiqPay у server_url-notify, а не запиту мерчанта. При v=7 у запиті
            // LiqPay віддає `err_code=invalid_signature` (маскує помилку як «підпис
            // не сходиться»).
            'version' => 3,
            'public_key' => $this->options['public_key'],
            'order_id' => $order_id,
        ], $params);

        $data = \base64_encode(\json_encode($body, \JSON_THROW_ON_ERROR));
        $signature = $this->signature($data);

        return [
            'body' => $body,
            'data' => $data,
            'signature' => $signature,
        ];
    }

    /** @return array<mixed> */
    protected function doRequest(RequestInterface $request): array
    {
        $response = $this->client->send($request);
        $content = $response->getBody()->getContents();

        if ('' === $content) {
            throw new LogicException(\sprintf(
                'Response body is empty for "%s" with code "%s".',
                $request->getUri(),
                $response->getStatusCode(),
            ));
        }

        // Статус — ДО парсингу. Помилку шлюз віддає не тільки як JSON: на 429/5xx
        // приходить HTML балансувальника, і json_decode падав першим — діагностика
        // вироджувалась у «Syntax error» без коду відповіді й без тіла, хоча реальна
        // причина (throttling, недоступність) стоїть саме в статусі.
        if (200 !== $response->getStatusCode()) {
            throw new LogicException(\sprintf(
                '%s for "%s" with code "%s". Content: %s.',
                $response->getReasonPhrase(),
                $request->getUri(),
                $response->getStatusCode(),
                self::contentSnippet($content),
            ));
        }

        try {
            $result = \json_decode($content, true, 512, \JSON_BIGINT_AS_STRING | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // 200 з не-JSON тілом: віддаємо його фрагмент, інакше причину не відновити.
            throw new LogicException(\sprintf(
                '%s for "%s" with code "%s". Content: %s.',
                $e->getMessage(),
                $request->getUri(),
                $response->getStatusCode(),
                self::contentSnippet($content),
            ));
        }

        if (!\is_array($result)) {
            throw new LogicException(\sprintf('JSON content was expected to decode to an array, "%s" returned for "%s".', \get_debug_type($content), $request->getUri()));
        }

        return $result;
    }

    /**
     * Тіло відповіді для тексту винятку. Обрізаємо, бо на 5xx це буває HTML-сторінка на
     * десятки кілобайт, яка розпирає і лог помилок, і `details` транзакції.
     */
    private static function contentSnippet(string $content): string
    {
        return \mb_strlen($content) > self::CONTENT_SNIPPET_LENGTH
            ? \mb_substr($content, 0, self::CONTENT_SNIPPET_LENGTH) . '…'
            : $content;
    }

    public function signature(string $data): string
    {
        return \base64_encode(\sha1($this->options['private_key'] . $data . $this->options['private_key'], true));
    }

    /**
     * URL сторінки checkout, куди сабмітиться підписана форма.
     * Окремий публічний метод, щоб шаблони отримували адресу з Api (єдине місце,
     * де живе endpoint) — підклас із перевизначеним getApiEndpoint() автоматично
     * переносить і форму.
     */
    public function getCheckoutUrl(): string
    {
        return $this->getApiEndpoint() . '/api/3/checkout';
    }

    protected function getApiEndpoint(): string
    {
        return 'https://www.liqpay.ua';
    }
}
