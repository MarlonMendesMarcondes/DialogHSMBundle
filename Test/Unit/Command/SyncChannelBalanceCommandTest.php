<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMPartnerApi;
use MauticPlugin\DialogHSMBundle\Command\SyncChannelBalanceCommand;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Service\BalanceAlertService;
use MauticPlugin\DialogHSMBundle\Service\BalanceHistoryRecorder;
use MauticPlugin\DialogHSMBundle\Service\PartnerConfigProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

class SyncChannelBalanceCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private DialogHSMPartnerApi&MockObject $partnerApi;
    private PartnerConfigProvider&MockObject $partnerConfigProvider;
    private BalanceAlertService&MockObject $balanceAlertService;
    private BalanceHistoryRecorder&MockObject $balanceHistoryRecorder;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->em                     = $this->createMock(EntityManagerInterface::class);
        $this->partnerApi             = $this->createMock(DialogHSMPartnerApi::class);
        $this->partnerConfigProvider  = $this->createMock(PartnerConfigProvider::class);
        $this->balanceAlertService    = $this->createMock(BalanceAlertService::class);
        $this->balanceHistoryRecorder = $this->createMock(BalanceHistoryRecorder::class);
        $this->logger                 = $this->createMock(LoggerInterface::class);

        $this->partnerConfigProvider->method('getPartnerId')->willReturn('partner1');
        $this->partnerConfigProvider->method('getPartnerApiKey')->willReturn('apikey');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeTester(): CommandTester
    {
        return new CommandTester(new SyncChannelBalanceCommand(
            $this->em,
            $this->partnerApi,
            $this->partnerConfigProvider,
            $this->balanceAlertService,
            $this->balanceHistoryRecorder,
            $this->logger
        ));
    }

    private function mockNumbers(array $numbers): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn($numbers);
        $this->em->method('getRepository')->with(WhatsAppNumber::class)->willReturn($repo);
    }

    private function makeEligibleNumber(string $name = 'Vendas'): WhatsAppNumber
    {
        $number = new WhatsAppNumber();
        $number->setName($name);
        $number->setClientId('client1');
        $number->setChannelId('channel1');

        return $number;
    }

    // =========================================================================
    // Config do parceiro ausente
    // =========================================================================

    public function testFailsWhenPartnerIdMissing(): void
    {
        $this->partnerConfigProvider = $this->createMock(PartnerConfigProvider::class);
        $this->partnerConfigProvider->method('getPartnerId')->willReturn(null);
        $this->partnerConfigProvider->method('getPartnerApiKey')->willReturn('apikey');

        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Partner ID', $tester->getDisplay());
    }

    public function testFailsWhenPartnerApiKeyMissing(): void
    {
        $this->partnerConfigProvider = $this->createMock(PartnerConfigProvider::class);
        $this->partnerConfigProvider->method('getPartnerId')->willReturn('partner1');
        $this->partnerConfigProvider->method('getPartnerApiKey')->willReturn(null);

        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    // =========================================================================
    // Sem números elegíveis
    // =========================================================================

    public function testSucceedsWhenNoNumbersExist(): void
    {
        $this->mockNumbers([]);
        $this->partnerApi->expects($this->never())->method('getChannelBalance');

        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testWarnsWhenNumbersLackClientOrChannelId(): void
    {
        $number = new WhatsAppNumber();
        $number->setName('Sem IDs');
        $this->mockNumbers([$number]);
        $this->partnerApi->expects($this->never())->method('getChannelBalance');

        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('1 número(s) ignorado(s)', $tester->getDisplay());
    }

    // =========================================================================
    // Sucesso — atualiza entidade e dispara alerta
    // =========================================================================

    public function testSuccessUpdatesEntityAndCallsAlertService(): void
    {
        $number = $this->makeEligibleNumber();
        $this->mockNumbers([$number]);

        $this->partnerApi->method('getChannelBalance')
            ->with('partner1', 'apikey', 'client1', 'channel1')
            ->willReturn([
                'success'             => true,
                'balance'             => 48.15,
                'currency'            => 'usd',
                'error'               => null,
                'last_renewal_amount' => 52.0,
                'last_renewal_date'   => '2026-05-13T13:51:11.821Z',
                'usage'               => [],
            ]);

        $this->em->expects($this->once())->method('persist')->with($number);
        $this->em->expects($this->once())->method('flush');

        $this->balanceAlertService->expects($this->once())
            ->method('checkAndNotify')
            ->with($number, 48.15, 'usd');

        $this->balanceHistoryRecorder->expects($this->once())
            ->method('recordIfNewRecharge')
            ->with($number, '2026-05-13T13:51:11.821Z', 52.0, 48.15, 'usd');

        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('OK', $tester->getDisplay());
        $this->assertSame(48.15, $number->getBalance());
        $this->assertSame('usd', $number->getBalanceCurrency());
    }

    // =========================================================================
    // Falha na API — não atualiza nem alerta, mas não quebra o loop
    // =========================================================================

    public function testApiFailureLogsAndSkipsAlert(): void
    {
        $number = $this->makeEligibleNumber();
        $this->mockNumbers([$number]);

        $this->partnerApi->method('getChannelBalance')
            ->willReturn(['success' => false, 'balance' => null, 'currency' => null, 'error' => 'HTTP 500: server error']);

        $this->em->expects($this->never())->method('persist');
        $this->balanceAlertService->expects($this->never())->method('checkAndNotify');
        $this->logger->expects($this->once())->method('error');

        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('FAIL', $tester->getDisplay());
    }

    public function testReturnsSuccessWhenAtLeastOneNumberUpdates(): void
    {
        $number = $this->makeEligibleNumber();
        $this->mockNumbers([$number]);

        $this->partnerApi->method('getChannelBalance')
            ->willReturn([
                'success'             => true,
                'balance'             => 10.0,
                'currency'            => 'usd',
                'error'               => null,
                'last_renewal_amount' => null,
                'last_renewal_date'   => null,
                'usage'               => [],
            ]);

        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
    }
}
