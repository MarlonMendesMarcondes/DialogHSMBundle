<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Service\BalanceAlertService;
use MauticPlugin\DialogHSMBundle\Service\PartnerConfigProvider;
use MauticPlugin\DialogHSMBundle\Service\PaymentBlockPauseService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class PaymentBlockPauseServiceTest extends TestCase
{
    private const BLOCKED_RESULT = [
        'success'     => false,
        'response'    => ['meta' => ['developer_message' => 'This number is blocked due to lack of payment on client side.']],
        'error'       => 'HTTP 403: This number is blocked due to lack of payment on client side.',
        'http_status' => 403,
        'wamid'       => null,
        'retryable'   => false,
    ];

    private ArrayAdapter $cache;
    private PartnerConfigProvider&MockObject $config;
    private BalanceAlertService&MockObject $alerts;
    private PaymentBlockPauseService $service;
    private WhatsAppNumber $number;

    protected function setUp(): void
    {
        $this->cache  = new ArrayAdapter();
        $this->config = $this->createMock(PartnerConfigProvider::class);
        $this->config->method('isPaymentPauseEnabled')->willReturn(true);
        $this->config->method('getPaymentPauseMinutes')->willReturn(30);
        $this->alerts = $this->createMock(BalanceAlertService::class);

        $this->service = new PaymentBlockPauseService($this->cache, $this->config, $this->alerts, new NullLogger());
        $this->number  = (new WhatsAppNumber())->setName('Vestibular');
    }

    public function testRecognizesThePaymentBlockResponse(): void
    {
        $this->assertTrue(PaymentBlockPauseService::isPaymentBlock(self::BLOCKED_RESULT));
    }

    public function testOtherErrorsAreNotPaymentBlock(): void
    {
        $this->assertFalse(PaymentBlockPauseService::isPaymentBlock(['http_status' => 403, 'error' => 'HTTP 403: Forbidden', 'response' => null]));
        $this->assertFalse(PaymentBlockPauseService::isPaymentBlock(['http_status' => 400, 'error' => 'lack of payment', 'response' => null]));
    }

    public function testPauseBlocksTheNumberAndAlertsOnlyOnce(): void
    {
        $this->alerts->expects($this->once())->method('notifyPaymentBlock')->with($this->number, true, 30);

        $this->assertFalse($this->service->isPaused('KEY'));

        $this->service->pause('KEY', $this->number, self::BLOCKED_RESULT['error']);
        $this->service->pause('KEY', $this->number, self::BLOCKED_RESULT['error']);

        $this->assertTrue($this->service->isPaused('KEY'));
        $this->assertFalse($this->service->isPaused('OUTRA_KEY'), 'A pausa é por número (apiKey).');
        $this->assertSame(1800, $this->service->getRecheckSeconds());
    }

    public function testSuccessfulSendAfterTheBlockReleasesAndAlerts(): void
    {
        $alerts = [];
        $this->alerts->method('notifyPaymentBlock')->willReturnCallback(function ($n, bool $blocked) use (&$alerts): void {
            $alerts[] = $blocked;
        });

        $this->service->pause('KEY', $this->number, 'blocked');
        $this->service->noteSuccess('KEY', $this->number);

        $this->assertFalse($this->service->isPaused('KEY'));
        $this->assertSame([true, false], $alerts);
    }

    public function testSuccessWithoutBlockDoesNotAlert(): void
    {
        $this->alerts->expects($this->never())->method('notifyPaymentBlock');

        $this->service->noteSuccess('KEY', $this->number);
    }

    public function testBalanceSyncEndsThePauseButKeepsTheBlockUntilASendConfirms(): void
    {
        $alerts = [];
        $this->alerts->method('notifyPaymentBlock')->willReturnCallback(function ($n, bool $blocked) use (&$alerts): void {
            $alerts[] = $blocked;
        });

        $this->service->pause('KEY', $this->number, 'blocked');

        $this->assertTrue($this->service->allowRecheck('KEY', 'balance_sync'));
        $this->assertFalse($this->service->isPaused('KEY'), 'O próximo envio sai como teste.');
        $this->assertSame([true], $alerts, 'Saldo positivo não prova o desbloqueio: sem aviso de liberação ainda.');

        $this->service->noteSuccess('KEY', $this->number);
        $this->assertSame([true, false], $alerts);
    }

    public function testAllowRecheckWithoutPauseDoesNothing(): void
    {
        $this->assertFalse($this->service->allowRecheck('KEY', 'balance_sync'));
    }

    public function testDisabledPauseNeverReportsPaused(): void
    {
        $config = $this->createMock(PartnerConfigProvider::class);
        $config->method('isPaymentPauseEnabled')->willReturn(false);
        $config->method('getPaymentPauseMinutes')->willReturn(30);
        $service = new PaymentBlockPauseService($this->cache, $config, $this->alerts, new NullLogger());

        $this->service->pause('KEY', $this->number, 'blocked');

        $this->assertFalse($service->isPaused('KEY'));
    }

    public function testAlertFailureDoesNotPreventThePause(): void
    {
        $this->alerts->method('notifyPaymentBlock')->willThrowException(new \RuntimeException('SMTP fora do ar'));

        $this->service->pause('KEY', $this->number, 'blocked');

        $this->assertTrue($this->service->isPaused('KEY'));
    }
}
