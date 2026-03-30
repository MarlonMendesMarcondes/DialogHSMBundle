<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
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
    private QueryBuilder&MockObject $mockQueryBuilder;
    private AbstractQuery&MockObject $mockQuery;

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

        $this->mockQuery = $this->createMock(AbstractQuery::class);

        $this->mockQueryBuilder = $this->createMock(QueryBuilder::class);
        $this->mockQueryBuilder->method('select')->willReturnSelf();
        $this->mockQueryBuilder->method('orderBy')->willReturnSelf();
        $this->mockQueryBuilder->method('addOrderBy')->willReturnSelf();
        $this->mockQueryBuilder->method('setFirstResult')->willReturnSelf();
        $this->mockQueryBuilder->method('setMaxResults')->willReturnSelf();
        $this->mockQueryBuilder->method('andWhere')->willReturnSelf();
        $this->mockQueryBuilder->method('setParameter')->willReturnSelf();
        $this->mockQueryBuilder->method('getQuery')->willReturn($this->mockQuery);

        // Bypass CommonRepository constructor (requires ManagerRegistry);
        // mock getEntityManager() + createQueryBuilder() for getLogs/countAll.
        $this->repository = $this->getMockBuilder(MessageLogRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntityManager', 'createQueryBuilder'])
            ->getMock();

        $this->repository->method('getEntityManager')->willReturn($this->mockEntityManager);
        $this->repository->method('createQueryBuilder')->willReturn($this->mockQueryBuilder);
    }

    // =========================================================================
    // getLogs
    // =========================================================================

    public function testGetLogsReturnsResultArray(): void
    {
        $log1 = new MessageLog();
        $log2 = new MessageLog();

        $this->mockQuery->method('getResult')->willReturn([$log1, $log2]);

        $result = $this->repository->getLogs();

        $this->assertCount(2, $result);
        $this->assertSame($log1, $result[0]);
    }

    public function testGetLogsReturnsEmptyArrayWhenNoLogs(): void
    {
        $this->mockQuery->method('getResult')->willReturn([]);

        $result = $this->repository->getLogs();

        $this->assertEmpty($result);
    }

    public function testGetLogsDefaultStartIsZero(): void
    {
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('setFirstResult')
            ->with(0)
            ->willReturnSelf();

        $this->mockQuery->method('getResult')->willReturn([]);

        $this->repository->getLogs();
    }

    public function testGetLogsDefaultLimitIs50(): void
    {
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('setMaxResults')
            ->with(50)
            ->willReturnSelf();

        $this->mockQuery->method('getResult')->willReturn([]);

        $this->repository->getLogs();
    }

    public function testGetLogsRespectsCustomStartAndLimit(): void
    {
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('setFirstResult')
            ->with(100)
            ->willReturnSelf();

        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('setMaxResults')
            ->with(25)
            ->willReturnSelf();

        $this->mockQuery->method('getResult')->willReturn([]);

        $this->repository->getLogs(100, 25);
    }

    // =========================================================================
    // countAll
    // =========================================================================

    public function testCountAllReturnsInteger(): void
    {
        $this->mockQuery->method('getSingleScalarResult')->willReturn('42');

        $result = $this->repository->countAll();

        $this->assertSame(42, $result);
    }

    public function testCountAllCastsResultToInt(): void
    {
        $this->mockQuery->method('getSingleScalarResult')->willReturn('0');

        $result = $this->repository->countAll();

        $this->assertIsInt($result);
        $this->assertSame(0, $result);
    }

    public function testCountAllWithLargeNumber(): void
    {
        $this->mockQuery->method('getSingleScalarResult')->willReturn('999999');

        $result = $this->repository->countAll();

        $this->assertSame(999999, $result);
    }

    // =========================================================================
    // prune
    // =========================================================================

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

    // =========================================================================
    // Filtros: getLogs com filtros
    // =========================================================================

    public function testGetLogsWithStatusFilterAppliesAndWhere(): void
    {
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('andWhere')
            ->with('dhml.status = :status')
            ->willReturnSelf();

        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('setParameter')
            ->with('status', 'sent')
            ->willReturnSelf();

        $this->mockQuery->method('getResult')->willReturn([]);

        $this->repository->getLogs(0, 50, ['status' => 'sent']);
    }

    public function testGetLogsWithSenderNameFilterUsesLike(): void
    {
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('andWhere')
            ->with('dhml.senderName LIKE :senderName')
            ->willReturnSelf();

        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('setParameter')
            ->with('senderName', '%Numero%')
            ->willReturnSelf();

        $this->mockQuery->method('getResult')->willReturn([]);

        $this->repository->getLogs(0, 50, ['senderName' => 'Numero']);
    }

    public function testGetLogsWithNumericContactFilterUsesLeadId(): void
    {
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('andWhere')
            ->with('dhml.leadId = :leadId')
            ->willReturnSelf();

        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('setParameter')
            ->with('leadId', 42)
            ->willReturnSelf();

        $this->mockQuery->method('getResult')->willReturn([]);

        $this->repository->getLogs(0, 50, ['contact' => '42']);
    }

    public function testGetLogsWithPhoneContactFilterUsesLike(): void
    {
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('andWhere')
            ->with('dhml.phoneNumber LIKE :phone')
            ->willReturnSelf();

        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('setParameter')
            ->with('phone', '%+5511%')
            ->willReturnSelf();

        $this->mockQuery->method('getResult')->willReturn([]);

        $this->repository->getLogs(0, 50, ['contact' => '+5511']);
    }

    public function testGetLogsWithDateFromFilter(): void
    {
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('andWhere')
            ->with('dhml.dateSent >= :dateFrom')
            ->willReturnSelf();

        $this->mockQuery->method('getResult')->willReturn([]);

        $this->repository->getLogs(0, 50, ['dateFrom' => '2025-01-01']);
    }

    public function testGetLogsWithDateToFilter(): void
    {
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('andWhere')
            ->with('dhml.dateSent <= :dateTo')
            ->willReturnSelf();

        $this->mockQuery->method('getResult')->willReturn([]);

        $this->repository->getLogs(0, 50, ['dateTo' => '2025-12-31']);
    }

    public function testGetLogsWithEmptyFiltersDoesNotCallAndWhere(): void
    {
        $this->mockQueryBuilder
            ->expects($this->never())
            ->method('andWhere');

        $this->mockQuery->method('getResult')->willReturn([]);

        $this->repository->getLogs(0, 50, []);
    }

    public function testCountAllWithStatusFilterAppliesCondition(): void
    {
        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('andWhere')
            ->with('dhml.status = :status')
            ->willReturnSelf();

        $this->mockQuery->method('getSingleScalarResult')->willReturn('7');

        $result = $this->repository->countAll(['status' => 'failed']);

        $this->assertSame(7, $result);
    }

    public function testCountAllWithEmptyFiltersReturnsInteger(): void
    {
        $this->mockQueryBuilder
            ->expects($this->never())
            ->method('andWhere');

        $this->mockQuery->method('getSingleScalarResult')->willReturn('100');

        $result = $this->repository->countAll([]);

        $this->assertSame(100, $result);
    }
}
