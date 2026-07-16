<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Controller\WebhookController;
use MauticPlugin\DialogHSMBundle\Service\WebhookProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class WebhookControllerTest extends TestCase
{
    private WebhookProcessor&MockObject $processor;
    private LoggerInterface&MockObject $logger;
    private WebhookController $controller;

    protected function setUp(): void
    {
        $this->processor = $this->createMock(WebhookProcessor::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->controller = new WebhookController($this->processor, $this->logger);
    }

    public function testSuccessfulProcessingReturns200(): void
    {
        $this->processor->method('process')->willReturn(200);

        $request  = new Request([], [], [], [], [], [], json_encode(['entry' => []]));
        $response = $this->controller->processAction($request, '+5511999999999');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnknownPhoneNumberPassesThrough404(): void
    {
        $this->processor->method('process')->willReturn(404);

        $request  = new Request([], [], [], [], [], [], json_encode(['entry' => []]));
        $response = $this->controller->processAction($request, '+5511000000000');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTransientFailureReturns503AndLogsError(): void
    {
        $this->processor->method('process')->willThrowException(new \RuntimeException('DB indisponível'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('DB indisponível'));

        $request  = new Request([], [], [], [], [], [], json_encode(['entry' => []]));
        $response = $this->controller->processAction($request, '+5511999999999');

        $this->assertSame(503, $response->getStatusCode());
    }

    public function testMalformedJsonPayloadIsTreatedAsEmptyArray(): void
    {
        $this->processor->expects($this->once())
            ->method('process')
            ->with('+5511999999999', [])
            ->willReturn(200);

        $request  = new Request([], [], [], [], [], [], '{not-valid-json');
        $response = $this->controller->processAction($request, '+5511999999999');

        $this->assertSame(200, $response->getStatusCode());
    }
}
