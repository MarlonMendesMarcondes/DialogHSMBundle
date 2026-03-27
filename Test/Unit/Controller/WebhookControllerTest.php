<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Controller\WebhookController;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

class WebhookControllerTest extends TestCase
{
    private function makeController(
        ?WhatsAppNumber $number,
        ?MessageLog $log,
    ): WebhookController {
        $numberRepo = $this->createMock(WhatsAppNumberRepository::class);
        $numberRepo->method('findByWebhookToken')->willReturn($number);

        $logRepo = $this->createMock(MessageLogRepository::class);
        $logRepo->method('findByWamid')->willReturn($log);

        $em = $this->createMock(EntityManagerInterface::class);

        return new WebhookController($numberRepo, $logRepo, $em, new NullLogger());
    }

    private function makeRequest(mixed $body): Request
    {
        return Request::create('/dialoghsm/webhook/token', 'POST', [], [], [], [], json_encode($body) ?: '');
    }

    public function testInvalidTokenReturns401(): void
    {
        $controller = $this->makeController(null, null);
        $response   = $controller->handleAction($this->makeRequest([]), 'bad-token');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testBadJsonReturns400(): void
    {
        $number = new WhatsAppNumber();
        $controller = $this->makeController($number, null);

        $request = Request::create('/dialoghsm/webhook/token', 'POST', [], [], [], [], 'not-json');
        $response = $controller->handleAction($request, 'valid-token');
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testEmptyStatusesReturns200(): void
    {
        $number = new WhatsAppNumber();
        $controller = $this->makeController($number, null);
        $response   = $controller->handleAction($this->makeRequest(['statuses' => []]), 'token');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnknownStatusIsIgnored(): void
    {
        $number = new WhatsAppNumber();
        $log    = new MessageLog();
        $log->setStatus(MessageLog::STATUS_SENT);

        $controller = $this->makeController($number, $log);
        $response   = $controller->handleAction(
            $this->makeRequest(['statuses' => [['id' => 'wamid.123', 'status' => 'pending']]]),
            'token'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(MessageLog::STATUS_SENT, $log->getStatus());
    }

    public function testUnknownWamidIsIgnored(): void
    {
        $number = new WhatsAppNumber();

        $logRepo = $this->createMock(MessageLogRepository::class);
        $logRepo->method('findByWamid')->willReturn(null);

        $numberRepo = $this->createMock(WhatsAppNumberRepository::class);
        $numberRepo->method('findByWebhookToken')->willReturn($number);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $controller = new WebhookController($numberRepo, $logRepo, $em, new NullLogger());
        $response   = $controller->handleAction(
            $this->makeRequest(['statuses' => [['id' => 'wamid.unknown', 'status' => 'delivered']]]),
            'token'
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSentToDeliveredAdvances(): void
    {
        $number = new WhatsAppNumber();
        $log    = new MessageLog();
        $log->setStatus(MessageLog::STATUS_SENT);

        $controller = $this->makeController($number, $log);
        $controller->handleAction(
            $this->makeRequest(['statuses' => [['id' => 'wamid.abc', 'status' => 'delivered']]]),
            'token'
        );

        $this->assertSame(MessageLog::STATUS_DELIVERED, $log->getStatus());
    }

    public function testDeliveredToReadAdvances(): void
    {
        $number = new WhatsAppNumber();
        $log    = new MessageLog();
        $log->setStatus(MessageLog::STATUS_DELIVERED);

        $controller = $this->makeController($number, $log);
        $controller->handleAction(
            $this->makeRequest(['statuses' => [['id' => 'wamid.abc', 'status' => 'read']]]),
            'token'
        );

        $this->assertSame(MessageLog::STATUS_READ, $log->getStatus());
    }

    public function testReadToDeliveredDoesNotRegress(): void
    {
        $number = new WhatsAppNumber();
        $log    = new MessageLog();
        $log->setStatus(MessageLog::STATUS_READ);

        $controller = $this->makeController($number, $log);
        $controller->handleAction(
            $this->makeRequest(['statuses' => [['id' => 'wamid.abc', 'status' => 'delivered']]]),
            'token'
        );

        $this->assertSame(MessageLog::STATUS_READ, $log->getStatus());
    }

    public function testFailedStatusIsAppliedFromSent(): void
    {
        $number = new WhatsAppNumber();
        $log    = new MessageLog();
        $log->setStatus(MessageLog::STATUS_SENT);

        $controller = $this->makeController($number, $log);
        $controller->handleAction(
            $this->makeRequest(['statuses' => [['id' => 'wamid.abc', 'status' => 'failed']]]),
            'token'
        );

        // failed priority is 0, sent is 1 — no regression allowed
        $this->assertSame(MessageLog::STATUS_SENT, $log->getStatus());
    }

    public function testFailedStatusAppliedWhenCurrentIsNull(): void
    {
        $number = new WhatsAppNumber();
        $log    = new MessageLog();
        // status is null by default

        $controller = $this->makeController($number, $log);
        $controller->handleAction(
            $this->makeRequest(['statuses' => [['id' => 'wamid.abc', 'status' => 'failed']]]),
            'token'
        );

        $this->assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
    }

    public function testMultipleStatusEntriesProcessed(): void
    {
        $number = new WhatsAppNumber();
        $log1   = new MessageLog();
        $log1->setStatus(MessageLog::STATUS_SENT);
        $log2 = new MessageLog();
        $log2->setStatus(MessageLog::STATUS_DELIVERED);

        $numberRepo = $this->createMock(WhatsAppNumberRepository::class);
        $numberRepo->method('findByWebhookToken')->willReturn($number);

        $logRepo = $this->createMock(MessageLogRepository::class);
        $logRepo->method('findByWamid')
            ->willReturnCallback(fn (string $w) => match ($w) {
                'wamid.1' => $log1,
                'wamid.2' => $log2,
                default   => null,
            });

        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new WebhookController($numberRepo, $logRepo, $em, new NullLogger());
        $controller->handleAction(
            $this->makeRequest([
                'statuses' => [
                    ['id' => 'wamid.1', 'status' => 'delivered'],
                    ['id' => 'wamid.2', 'status' => 'read'],
                ],
            ]),
            'token'
        );

        $this->assertSame(MessageLog::STATUS_DELIVERED, $log1->getStatus());
        $this->assertSame(MessageLog::STATUS_READ, $log2->getStatus());
    }
}
