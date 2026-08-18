<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMPartnerApi;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DialogHSMPartnerApiTest extends TestCase
{
    private Client&MockObject $mockClient;
    private LoggerInterface&MockObject $mockLogger;
    private DialogHSMPartnerApi $api;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(Client::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->api        = new DialogHSMPartnerApi($this->mockClient, $this->mockLogger);
    }

    // =========================================================================
    // Validação de parâmetros
    // =========================================================================

    /**
     * @dataProvider missingParamProvider
     */
    public function testReturnsErrorWhenAnyParamIsEmpty(string $partnerId, string $apiKey, string $clientId, string $channelId): void
    {
        $this->mockClient->expects($this->never())->method('request');

        $result = $this->api->getChannelBalance($partnerId, $apiKey, $clientId, $channelId);

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertFalse($result['retryable']);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function missingParamProvider(): array
    {
        return [
            'missing partnerId' => ['', 'key', 'client', 'channel'],
            'missing apiKey'    => ['partner', '', 'client', 'channel'],
            'missing clientId'  => ['partner', 'key', '', 'channel'],
            'missing channelId' => ['partner', 'key', 'client', ''],
        ];
    }

    // =========================================================================
    // Sucesso
    // =========================================================================

    public function testGetChannelBalanceSuccessParsesBalanceAndCurrency(): void
    {
        $body = json_encode(['balance' => 48.15, 'currency' => 'usd', 'last_renewal' => ['amount' => 52.0]]);
        $this->mockClient->method('request')->willReturn(new Response(200, [], $body));

        $result = $this->api->getChannelBalance('partner1', 'apikey', 'client1', 'channel1');

        $this->assertTrue($result['success']);
        $this->assertSame(48.15, $result['balance']);
        $this->assertSame('usd', $result['currency']);
        $this->assertSame(200, $result['http_status']);
        $this->assertNull($result['error']);
        $this->assertIsArray($result['response']);
    }

    public function testGetChannelBalanceParsesLastRenewal(): void
    {
        $body = json_encode([
            'balance'      => 48.15,
            'currency'     => 'usd',
            'last_renewal' => ['amount' => 52.0, 'date' => '2026-05-13T13:51:11.821Z'],
        ]);
        $this->mockClient->method('request')->willReturn(new Response(200, [], $body));

        $result = $this->api->getChannelBalance('partner1', 'apikey', 'client1', 'channel1');

        $this->assertSame(52.0, $result['last_renewal_amount']);
        $this->assertSame('2026-05-13T13:51:11.821Z', $result['last_renewal_date']);
    }

    public function testGetChannelBalanceNormalizesUsage(): void
    {
        $body = json_encode([
            'balance'  => 48.15,
            'currency' => 'usd',
            'usage'    => [
                ['period_date' => '2026-04-01T00:00:00Z', 'total_price' => 52.5625, 'marketing_quantity' => 841],
                ['period_date' => '2026-05-01T00:00:00Z', 'total_price' => 57.9375, 'marketing_quantity' => 0],
            ],
        ]);
        $this->mockClient->method('request')->willReturn(new Response(200, [], $body));

        $result = $this->api->getChannelBalance('partner1', 'apikey', 'client1', 'channel1');

        $this->assertCount(2, $result['usage']);
        $this->assertSame('2026-04-01T00:00:00Z', $result['usage'][0]['period_date']);
        $this->assertSame(52.5625, $result['usage'][0]['total_price']);
        $this->assertArrayNotHasKey('marketing_quantity', $result['usage'][0]);
    }

    public function testGetChannelBalanceSkipsUsageRowsWithoutPeriodDate(): void
    {
        $body = json_encode([
            'balance'  => 48.15,
            'currency' => 'usd',
            'usage'    => [['total_price' => 10.0]],
        ]);
        $this->mockClient->method('request')->willReturn(new Response(200, [], $body));

        $result = $this->api->getChannelBalance('partner1', 'apikey', 'client1', 'channel1');

        $this->assertSame([], $result['usage']);
    }

    public function testGetChannelBalanceHandlesMissingUsage(): void
    {
        $body = json_encode(['balance' => 48.15, 'currency' => 'usd']);
        $this->mockClient->method('request')->willReturn(new Response(200, [], $body));

        $result = $this->api->getChannelBalance('partner1', 'apikey', 'client1', 'channel1');

        $this->assertSame([], $result['usage']);
    }

    public function testGetChannelBalanceHandlesMissingLastRenewal(): void
    {
        $body = json_encode(['balance' => 48.15, 'currency' => 'usd']);
        $this->mockClient->method('request')->willReturn(new Response(200, [], $body));

        $result = $this->api->getChannelBalance('partner1', 'apikey', 'client1', 'channel1');

        $this->assertNull($result['last_renewal_amount']);
        $this->assertNull($result['last_renewal_date']);
    }

    public function testMissingParamsResultHasLastRenewalKeysAsNull(): void
    {
        $result = $this->api->getChannelBalance('', 'k', 'c', 'ch');

        $this->assertArrayHasKey('last_renewal_amount', $result);
        $this->assertArrayHasKey('last_renewal_date', $result);
        $this->assertNull($result['last_renewal_amount']);
    }

    public function testHttpErrorResultHasLastRenewalKeysAsNull(): void
    {
        $this->mockClient->method('request')->willReturn(new Response(500, [], json_encode(['message' => 'x'])));

        $result = $this->api->getChannelBalance('p', 'k', 'c', 'ch');

        $this->assertArrayHasKey('last_renewal_amount', $result);
        $this->assertNull($result['last_renewal_date']);
    }

    public function testGetChannelBalanceSendsCorrectUrlAndHeaders(): void
    {
        $capturedUrl     = null;
        $capturedOptions = null;

        $this->mockClient->method('request')->willReturnCallback(
            function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedOptions) {
                $capturedUrl     = $url;
                $capturedOptions = $options;

                return new Response(200, [], json_encode(['balance' => 10, 'currency' => 'usd']));
            }
        );

        $this->api->getChannelBalance('P1', 'SECRET_KEY', 'C1', 'CH1');

        $this->assertSame(
            'https://hub.360dialog.io/api/v2/partners/P1/clients/C1/channels/CH1/info/balance',
            $capturedUrl
        );
        $this->assertSame('SECRET_KEY', $capturedOptions['headers']['X-API-Key']);
    }

    public function testGetChannelBalanceUrlEncodesPathSegments(): void
    {
        $capturedUrl = null;

        $this->mockClient->method('request')->willReturnCallback(
            function (string $method, string $url) use (&$capturedUrl) {
                $capturedUrl = $url;

                return new Response(200, [], json_encode(['balance' => 10, 'currency' => 'usd']));
            }
        );

        $this->api->getChannelBalance('P/1', 'key', 'C 1', 'CH?1');

        $this->assertStringNotContainsString('C 1', $capturedUrl);
        $this->assertStringContainsString(rawurlencode('C 1'), $capturedUrl);
        $this->assertStringContainsString(rawurlencode('CH?1'), $capturedUrl);
    }

    // =========================================================================
    // Erros HTTP
    // =========================================================================

    public function testReturns401AsNonRetryable(): void
    {
        $body = json_encode(['error' => ['message' => 'Invalid API key']]);
        $this->mockClient->method('request')->willReturn(new Response(401, [], $body));

        $result = $this->api->getChannelBalance('p', 'k', 'c', 'ch');

        $this->assertFalse($result['success']);
        $this->assertFalse($result['retryable']);
        $this->assertStringContainsString('Invalid API key', $result['error']);
    }

    public function testReturns429AsRetryable(): void
    {
        $this->mockClient->method('request')->willReturn(new Response(429, [], json_encode(['message' => 'rate limited'])));

        $result = $this->api->getChannelBalance('p', 'k', 'c', 'ch');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
    }

    public function testReturns500AsRetryable(): void
    {
        $this->mockClient->method('request')->willReturn(new Response(500, [], json_encode(['message' => 'server error'])));

        $result = $this->api->getChannelBalance('p', 'k', 'c', 'ch');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
    }

    public function testReturns404AsNonRetryable(): void
    {
        $this->mockClient->method('request')->willReturn(new Response(404, [], json_encode(['message' => 'not found'])));

        $result = $this->api->getChannelBalance('p', 'k', 'c', 'ch');

        $this->assertFalse($result['success']);
        $this->assertFalse($result['retryable']);
    }

    // =========================================================================
    // Erros de rede (RequestException / Throwable)
    // =========================================================================

    public function testRequestExceptionWithoutResponseIsRetryable(): void
    {
        $request   = new GuzzleRequest('GET', 'https://hub.360dialog.io/api/v2/partners/p/clients/c/channels/ch/info/balance');
        $exception = new RequestException('Connection timed out', $request);

        $this->mockClient->method('request')->willThrowException($exception);

        $result = $this->api->getChannelBalance('p', 'k', 'c', 'ch');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
        $this->assertNull($result['http_status']);
    }

    public function testRequestExceptionWithResponseUsesResponseStatus(): void
    {
        $request   = new GuzzleRequest('GET', 'https://hub.360dialog.io/api/v2/partners/p/clients/c/channels/ch/info/balance');
        $response  = new Response(403, [], json_encode(['message' => 'forbidden']));
        $exception = new RequestException('Forbidden', $request, $response);

        $this->mockClient->method('request')->willThrowException($exception);

        $result = $this->api->getChannelBalance('p', 'k', 'c', 'ch');

        $this->assertFalse($result['success']);
        $this->assertFalse($result['retryable']);
        $this->assertSame(403, $result['http_status']);
    }

    public function testUnexpectedThrowableIsRetryable(): void
    {
        $this->mockClient->method('request')->willThrowException(new \RuntimeException('boom'));

        $result = $this->api->getChannelBalance('p', 'k', 'c', 'ch');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
        $this->assertSame('boom', $result['error']);
    }

    public function testLoggerErrorCalledOnFailure(): void
    {
        $this->mockLogger->expects($this->atLeastOnce())->method('error');
        $this->mockClient->method('request')->willReturn(new Response(500, [], json_encode(['message' => 'x'])));

        $this->api->getChannelBalance('p', 'k', 'c', 'ch');
    }

    public function testLoggerInfoCalledOnSuccess(): void
    {
        $this->mockLogger->expects($this->once())->method('info');
        $this->mockClient->method('request')->willReturn(new Response(200, [], json_encode(['balance' => 5, 'currency' => 'usd'])));

        $this->api->getChannelBalance('p', 'k', 'c', 'ch');
    }
}
