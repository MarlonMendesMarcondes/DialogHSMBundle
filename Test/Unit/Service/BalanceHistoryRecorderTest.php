<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberBalanceHistory;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberBalanceHistoryRepository;
use MauticPlugin\DialogHSMBundle\Service\BalanceHistoryRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BalanceHistoryRecorderTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private WhatsAppNumberBalanceHistoryRepository&MockObject $repository;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(WhatsAppNumberBalanceHistoryRepository::class);

        $this->em->method('getRepository')->with(WhatsAppNumberBalanceHistory::class)->willReturn($this->repository);
    }

    private function makeNumber(int $id = 1): WhatsAppNumber
    {
        $number = new WhatsAppNumber();
        $ref    = new \ReflectionProperty(WhatsAppNumber::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($number, $id);

        return $number;
    }

    private function makeService(): BalanceHistoryRecorder
    {
        return new BalanceHistoryRecorder($this->em);
    }

    // =========================================================================
    // Casos em que nada deve ser gravado
    // =========================================================================

    public function testDoesNothingWhenLastRenewalDateIsNull(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->makeService()->recordIfNewRecharge($this->makeNumber(), null, 52.0, 48.15, 'usd');
    }

    public function testDoesNothingWhenNumberHasNoId(): void
    {
        $this->em->expects($this->never())->method('persist');

        $service = $this->makeService();
        $number  = new WhatsAppNumber();
        $service->recordIfNewRecharge($number, '2026-05-13T13:51:11.821Z', 52.0, 48.15, 'usd');
    }

    public function testDoesNothingWhenDateIsInvalid(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->makeService()->recordIfNewRecharge($this->makeNumber(), 'not-a-date', 52.0, 48.15, 'usd');
    }

    public function testDoesNothingWhenSameDateAsLatest(): void
    {
        $latest = new WhatsAppNumberBalanceHistory();
        $latest->setRechargeDate(new \DateTime('2026-05-13 13:51:11'));
        $this->repository->method('findLatestForNumber')->willReturn($latest);

        $this->em->expects($this->never())->method('persist');

        // Data com milissegundos, igual ao formato real da 360dialog — não deve
        // ser tratado como "mais recente" só por conta da precisão de subsegundo.
        $this->makeService()->recordIfNewRecharge($this->makeNumber(), '2026-05-13T13:51:11.821Z', 52.0, 48.15, 'usd');
    }

    public function testDoesNothingWhenDateIsOlderThanLatest(): void
    {
        $latest = new WhatsAppNumberBalanceHistory();
        $latest->setRechargeDate(new \DateTime('2026-06-01 00:00:00'));
        $this->repository->method('findLatestForNumber')->willReturn($latest);

        $this->em->expects($this->never())->method('persist');

        $this->makeService()->recordIfNewRecharge($this->makeNumber(), '2026-05-13T13:51:11.821Z', 52.0, 48.15, 'usd');
    }

    // =========================================================================
    // Casos em que uma nova linha deve ser gravada
    // =========================================================================

    public function testRecordsFirstRechargeWhenNoneExists(): void
    {
        $this->repository->method('findLatestForNumber')->willReturn(null);

        $captured = null;
        $this->em->expects($this->once())->method('persist')
            ->with($this->callback(function ($entity) use (&$captured) {
                $captured = $entity;

                return $entity instanceof WhatsAppNumberBalanceHistory;
            }));
        $this->em->expects($this->once())->method('flush');

        $this->makeService()->recordIfNewRecharge($this->makeNumber(7), '2026-05-13T13:51:11.821Z', 52.0, 48.15, 'usd');

        $this->assertSame(7, $captured->getWhatsAppNumberId());
        $this->assertSame(52.0, $captured->getRechargeAmount());
        $this->assertSame(48.15, $captured->getBalanceAtSync());
        $this->assertSame('usd', $captured->getCurrency());
        $this->assertSame('2026-05-13 13:51:11', $captured->getRechargeDate()->format('Y-m-d H:i:s'));
    }

    public function testRecordsNewRechargeWhenDateIsNewerThanLatest(): void
    {
        $latest = new WhatsAppNumberBalanceHistory();
        $latest->setRechargeDate(new \DateTime('2026-05-13 13:51:11'));
        $this->repository->method('findLatestForNumber')->willReturn($latest);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->makeService()->recordIfNewRecharge($this->makeNumber(), '2026-06-01T00:00:00.000Z', 100.0, 148.15, 'usd');
    }

    public function testDateComparisonIgnoresMillisecondPrecision(): void
    {
        // Mesma data que a última salva, mas com milissegundos diferentes —
        // não deve ser tratada como nova recarga (essa é a regressão coberta).
        $latest = new WhatsAppNumberBalanceHistory();
        $latest->setRechargeDate(new \DateTime('2026-05-13 13:51:11'));
        $this->repository->method('findLatestForNumber')->willReturn($latest);

        $this->em->expects($this->never())->method('persist');

        $this->makeService()->recordIfNewRecharge($this->makeNumber(), '2026-05-13T13:51:11.999Z', 52.0, 48.15, 'usd');
    }
}
