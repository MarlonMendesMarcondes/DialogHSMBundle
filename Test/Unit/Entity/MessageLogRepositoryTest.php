<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MessageLogRepositoryTest extends TestCase
{
    private EntityManagerInterface&MockObject $mockEntityManager;
    private Connection&MockObject $mockConnection;
    private ClassMetadata&MockObject $mockClassMetadata;
    private MessageLogRepository&MockObject $repository;

    protected function setUp(): void
    {
        $this->mockEntityManager = $this->createMock(EntityManagerInterface::class);
        $this->mockConnection    = $this->createMock(Connection::class);
        $this->mockClassMetadata = $this->createMock(ClassMetadata::class);

        $this->mockEntityManager->method('getConnection')->willReturn($this->mockConnection);
        $this->mockEntityManager->method('getClassMetadata')
            ->with(MessageLog::class)
            ->willReturn($this->mockClassMetadata);
        $this->mockClassMetadata->method('getTableName')->willReturn('dialog_hsm_message_log');

        // Bypass CommonRepository constructor (requires ManagerRegistry);
        // only mock getEntityManager() so prune() runs its real logic.
        $this->repository = $this->getMockBuilder(MessageLogRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntityManager'])
            ->getMock();

        $this->repository->method('getEntityManager')->willReturn($this->mockEntityManager);
    }

    public function testPruneDoesNothingWhenCountBelowLimit(): void
    {
        $this->mockConnection
            ->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT COUNT(*) FROM `dialog_hsm_message_log`')
            ->willReturn('9999');

        $this->mockConnection
            ->expects($this->never())
            ->method('executeStatement');

        $this->repository->prune();
    }

    public function testPruneDoesNothingWhenCountEqualsLimit(): void
    {
        $this->mockConnection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn('10000');

        $this->mockConnection
            ->expects($this->never())
            ->method('executeStatement');

        $this->repository->prune();
    }

    public function testPruneDeletesOldestWhenOverLimit(): void
    {
        $this->mockConnection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn('10500');

        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->with('DELETE FROM `dialog_hsm_message_log` ORDER BY date_sent ASC, id ASC LIMIT 500');

        $this->repository->prune();
    }

    public function testPruneRespectsCustomMaxRecords(): void
    {
        $this->mockConnection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn('600');

        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->with('DELETE FROM `dialog_hsm_message_log` ORDER BY date_sent ASC, id ASC LIMIT 100');

        $this->repository->prune(maxRecords: 500);
    }

    public function testPruneDoesNothingWhenTableIsEmpty(): void
    {
        $this->mockConnection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn('0');

        $this->mockConnection
            ->expects($this->never())
            ->method('executeStatement');

        $this->repository->prune();
    }

    public function testPruneDeletesExactlyOneWhenExcessIsOne(): void
    {
        // 10001 registros com limite padrão de 10000 → apaga exatamente 1
        $this->mockConnection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn('10001');

        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->with('DELETE FROM `dialog_hsm_message_log` ORDER BY date_sent ASC, id ASC LIMIT 1');

        $this->repository->prune();
    }

    public function testPruneWithCustomMaxRecordsDoesNothingWhenCountIsExactlyAtLimit(): void
    {
        // Exatamente no limite customizado → não deleta
        $this->mockConnection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn('500');

        $this->mockConnection
            ->expects($this->never())
            ->method('executeStatement');

        $this->repository->prune(maxRecords: 500);
    }
}
