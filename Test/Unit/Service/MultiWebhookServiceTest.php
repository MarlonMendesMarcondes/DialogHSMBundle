<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\DialogHSMBundle\Service\MultiWebhookService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Subclass that replaces makeClient() with a mock client to avoid real HTTP calls.
 */
class TestableMultiWebhookService extends MultiWebhookService
{
    public function __construct(
        LoggerInterface $logger,
        private readonly Client $mockClient
    ) {
        parent::__construct($logger);
    }

    protected function makeClient(string $apiKey): Client
    {
        return $this->mockClient;
    }
}

class MultiWebhookServiceTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private Client&MockObject $client;
    private TestableMultiWebhookService $service;

    protected function setUp(): void
    {
        $this->logger  = $this->createMock(LoggerInterface::class);
        $this->client  = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'post', 'put', 'patch'])
            ->getMock();
        $this->service = new TestableMultiWebhookService($this->logger, $this->client);
    }

    private function jsonResponse(array $data): Response
    {
        return new Response(200, [], json_encode($data));
    }

    // =========================================================================
    // check()
    // =========================================================================

    public function testCheckReturnsDecodedJsonOnSuccess(): void
    {
        $payload = ['enabled' => true, 'destinations' => []];

        $this->client->method('get')->willReturn($this->jsonResponse($payload));

        $result = $this->service->check('key123');

        $this->assertTrue($result['enabled']);
        $this->assertSame([], $result['destinations']);
    }

    public function testCheckReturnsErrorKeyOnGuzzleException(): void
    {
        $this->client->method('get')->willThrowException(
            new RequestException('connection refused', new Request('GET', '/multi_webhook'))
        );

        $this->logger->expects($this->once())->method('error');

        $result = $this->service->check('bad_key');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('connection refused', $result['error']);
    }

    public function testCheckReturnsEmptyArrayWhenBodyIsNotJson(): void
    {
        $this->client->method('get')->willReturn(new Response(200, [], 'not-json'));

        $result = $this->service->check('key');

        $this->assertSame([], $result);
    }

    // =========================================================================
    // register() — destination does not exist yet (PUT)
    // =========================================================================

    public function testRegisterCreatesDestinationWhenNoneExists(): void
    {
        $state = ['enabled' => true, 'destinations' => []];

        $this->client->method('get')->willReturn($this->jsonResponse($state));
        $this->client->expects($this->once())->method('put')->willReturn(new Response(200));
        $this->client->expects($this->never())->method('patch');
        $this->client->expects($this->never())->method('post');

        $result = $this->service->register('key', 'https://example.com/webhook/5511');

        $this->assertTrue($result['success']);
        $this->assertSame('created', $result['action']);
    }

    // =========================================================================
    // register() — destination exists with different URL (PATCH)
    // =========================================================================

    public function testRegisterUpdatesDestinationWhenUrlChanged(): void
    {
        $state = [
            'enabled'      => true,
            'destinations' => [
                ['name' => 'mautic', 'url' => 'https://old.example.com/webhook/5511'],
            ],
        ];

        $this->client->method('get')->willReturn($this->jsonResponse($state));
        $this->client->expects($this->never())->method('put');
        $this->client->expects($this->once())->method('patch')->willReturn(new Response(200));

        $result = $this->service->register('key', 'https://new.example.com/webhook/5511');

        $this->assertTrue($result['success']);
        $this->assertSame('updated', $result['action']);
    }

    // =========================================================================
    // register() — destination exists with same URL (unchanged)
    // =========================================================================

    public function testRegisterSkipsWhenUrlIsUnchanged(): void
    {
        $url   = 'https://example.com/webhook/5511';
        $state = [
            'enabled'      => true,
            'destinations' => [
                ['name' => 'mautic', 'url' => $url],
            ],
        ];

        $this->client->method('get')->willReturn($this->jsonResponse($state));
        $this->client->expects($this->never())->method('put');
        $this->client->expects($this->never())->method('patch');

        $result = $this->service->register('key', $url);

        $this->assertTrue($result['success']);
        $this->assertSame('unchanged', $result['action']);
    }

    // =========================================================================
    // register() — multi_webhook not yet enabled (POST then PUT)
    // =========================================================================

    public function testRegisterEnablesMultiWebhookWhenDisabled(): void
    {
        $state = ['enabled' => false, 'destinations' => []];

        $this->client->method('get')->willReturn($this->jsonResponse($state));
        $this->client->expects($this->once())->method('post')->willReturn(new Response(200));
        $this->client->expects($this->once())->method('put')->willReturn(new Response(200));

        $result = $this->service->register('key', 'https://example.com/webhook/5511');

        $this->assertTrue($result['success']);
        $this->assertSame('created', $result['action']);
    }

    // =========================================================================
    // register() — error paths
    // =========================================================================

    public function testRegisterContinuesWhenEnableThrowsAlreadyEnabled(): void
    {
        $state = ['enabled' => false, 'destinations' => []];

        $this->client->method('get')->willReturn($this->jsonResponse($state));

        // "already enabled" error is swallowed — should NOT return failure
        $this->client->method('post')->willThrowException(
            new RequestException('already enabled', new Request('POST', '/multi_webhook'))
        );
        $this->client->expects($this->once())->method('put')->willReturn(new Response(200));

        $result = $this->service->register('key', 'https://example.com/webhook/5511');

        $this->assertTrue($result['success']);
        $this->assertSame('created', $result['action']);
    }

    public function testRegisterReturnsFailureWhenInitialGetFails(): void
    {
        $this->client->method('get')->willThrowException(
            new RequestException('timeout', new Request('GET', '/multi_webhook'))
        );

        $result = $this->service->register('key', 'https://example.com/webhook/5511');

        $this->assertFalse($result['success']);
        $this->assertSame('check', $result['action']);
    }

    public function testRegisterReturnsFailureWhenCreateFails(): void
    {
        $state = ['enabled' => true, 'destinations' => []];

        $this->client->method('get')->willReturn($this->jsonResponse($state));
        $this->client->method('put')->willThrowException(
            new RequestException('server error', new Request('PUT', '/multi_webhook'))
        );

        $result = $this->service->register('key', 'https://example.com/webhook/5511');

        $this->assertFalse($result['success']);
        $this->assertSame('create', $result['action']);
    }

    public function testRegisterReturnsFailureWhenUpdateFails(): void
    {
        $state = [
            'enabled'      => true,
            'destinations' => [
                ['name' => 'mautic', 'url' => 'https://old.example.com/webhook'],
            ],
        ];

        $this->client->method('get')->willReturn($this->jsonResponse($state));
        $this->client->method('patch')->willThrowException(
            new RequestException('server error', new Request('PATCH', '/multi_webhook'))
        );

        $result = $this->service->register('key', 'https://new.example.com/webhook');

        $this->assertFalse($result['success']);
        $this->assertSame('update', $result['action']);
    }
}
