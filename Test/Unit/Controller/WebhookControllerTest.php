<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CacheBundle\Cache\CacheProviderInterface;
use MauticPlugin\DialogHSMBundle\Controller\WebhookController;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

class WebhookControllerTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Cache mock que sempre reporta "não atingido" — nenhuma requisição bloqueada.
     */
    private function makeUnlimitedCache(): CacheProviderInterface&MockObject
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('get')->willReturn(0);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();

        $cache = $this->createMock(CacheProviderInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->method('save')->willReturn(true);

        return $cache;
    }

    /**
     * Cache mock que simula contador já no limite — toda requisição será bloqueada.
     */
    private function makeExhaustedCache(): CacheProviderInterface&MockObject
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn(WebhookController::RATE_LIMIT);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();

        $cache = $this->createMock(CacheProviderInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->method('save')->willReturn(true);

        return $cache;
    }

    private function makeController(
        ?WhatsAppNumber $number,
        ?MessageLog $log,
        ?CacheProviderInterface $cache = null,
    ): WebhookController {
        $numberRepo = $this->createMock(WhatsAppNumberRepository::class);
        $numberRepo->method('findByWebhookToken')->willReturn($number);

        $logRepo = $this->createMock(MessageLogRepository::class);
        $logRepo->method('findByWamid')->willReturn($log);

        $em = $this->createMock(EntityManagerInterface::class);

        return new WebhookController(
            $numberRepo,
            $logRepo,
            $em,
            new NullLogger(),
            $cache ?? $this->makeUnlimitedCache(),
        );
    }

    private function makeRequest(mixed $body, string $ip = '1.2.3.4'): Request
    {
        $request = Request::create(
            '/dialoghsm/webhook/token',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => $ip],
            json_encode($body) ?: ''
        );

        return $request;
    }

    // =========================================================================
    // Rate Limiting
    // =========================================================================

    public function testRateLimitedRequestReturns429(): void
    {
        $controller = $this->makeController(null, null, $this->makeExhaustedCache());
        $response   = $controller->handleAction($this->makeRequest([]), 'any-token');

        $this->assertSame(429, $response->getStatusCode());
    }

    public function testRateLimitedRequestDoesNotReachTokenValidation(): void
    {
        $numberRepo = $this->createMock(WhatsAppNumberRepository::class);
        $numberRepo->expects($this->never())->method('findByWebhookToken');

        $controller = new WebhookController(
            $numberRepo,
            $this->createMock(MessageLogRepository::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            $this->makeExhaustedCache(),
        );

        $controller->handleAction($this->makeRequest([]), 'any-token');
    }

    public function testNonRateLimitedRequestProceedsNormally(): void
    {
        $number     = new WhatsAppNumber();
        $controller = $this->makeController($number, null, $this->makeUnlimitedCache());
        $response   = $controller->handleAction($this->makeRequest(['statuses' => []]), 'valid-token');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCacheItemIsIncrementedOnEachRequest(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn(5);
        $item->method('expiresAfter')->willReturnSelf();

        $item->expects($this->once())
            ->method('set')
            ->with(6) // 5 + 1
            ->willReturnSelf();

        $cache = $this->createMock(CacheProviderInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->expects($this->once())->method('save');

        $number     = new WhatsAppNumber();
        $controller = $this->makeController($number, null, $cache);
        $controller->handleAction($this->makeRequest(['statuses' => []]), 'token');
    }

    public function testFirstRequestStartsCounterAtOne(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false); // sem hit = contador zerado
        $item->method('get')->willReturn(0);
        $item->method('expiresAfter')->willReturnSelf();

        $item->expects($this->once())
            ->method('set')
            ->with(1)
            ->willReturnSelf();

        $cache = $this->createMock(CacheProviderInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->method('save')->willReturn(true);

        $number     = new WhatsAppNumber();
        $controller = $this->makeController($number, null, $cache);
        $controller->handleAction($this->makeRequest(['statuses' => []]), 'token');
    }

    public function testDifferentIpsHaveSeparateLimits(): void
    {
        // IP A tem contador esgotado; IP B passa
        $exhaustedItem = $this->createMock(CacheItemInterface::class);
        $exhaustedItem->method('isHit')->willReturn(true);
        $exhaustedItem->method('get')->willReturn(WebhookController::RATE_LIMIT);
        $exhaustedItem->method('set')->willReturnSelf();
        $exhaustedItem->method('expiresAfter')->willReturnSelf();

        $freeItem = $this->createMock(CacheItemInterface::class);
        $freeItem->method('isHit')->willReturn(false);
        $freeItem->method('get')->willReturn(0);
        $freeItem->method('set')->willReturnSelf();
        $freeItem->method('expiresAfter')->willReturnSelf();

        $cache = $this->createMock(CacheProviderInterface::class);
        $cache->method('getItem')
            ->willReturnCallback(fn (string $key) => str_contains($key, sha1('10.0.0.1'))
                ? $exhaustedItem
                : $freeItem
            );
        $cache->method('save')->willReturn(true);

        $number      = new WhatsAppNumber();
        $controller  = $this->makeController($number, null, $cache);

        $responseA = $controller->handleAction($this->makeRequest(['statuses' => []], '10.0.0.1'), 'tok');
        $responseB = $controller->handleAction($this->makeRequest(['statuses' => []], '10.0.0.2'), 'tok');

        $this->assertSame(429, $responseA->getStatusCode());
        $this->assertSame(200, $responseB->getStatusCode());
    }

    public function testRateLimitConstantsAreDefined(): void
    {
        $this->assertSame(60, WebhookController::RATE_LIMIT);
        $this->assertSame(60, WebhookController::RATE_WINDOW);
    }

    // =========================================================================
    // Testes existentes (mantidos; makeController agora inclui cache)
    // =========================================================================

    public function testInvalidTokenReturns401(): void
    {
        $controller = $this->makeController(null, null);
        $response   = $controller->handleAction($this->makeRequest([]), 'bad-token');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testBadJsonReturns400(): void
    {
        $number     = new WhatsAppNumber();
        $controller = $this->makeController($number, null);

        $request  = Request::create('/dialoghsm/webhook/token', 'POST', [], [], [], ['REMOTE_ADDR' => '1.2.3.4'], 'not-json');
        $response = $controller->handleAction($request, 'valid-token');
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testEmptyStatusesReturns200(): void
    {
        $number     = new WhatsAppNumber();
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

        $controller = new WebhookController(
            $numberRepo,
            $logRepo,
            $em,
            new NullLogger(),
            $this->makeUnlimitedCache(),
        );
        $response = $controller->handleAction(
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

        $this->assertSame(MessageLog::STATUS_SENT, $log->getStatus());
    }

    public function testFailedStatusAppliedWhenCurrentIsNull(): void
    {
        $number = new WhatsAppNumber();
        $log    = new MessageLog();

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

        $controller = new WebhookController(
            $numberRepo,
            $logRepo,
            $em,
            new NullLogger(),
            $this->makeUnlimitedCache(),
        );
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
