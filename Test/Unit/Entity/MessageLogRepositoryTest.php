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
    // findByWamid
    // =========================================================================

    public function testFindByWamidReturnsNullWhenNotFound(): void
    {
        $this->mockQuery->method('getResult')->willReturn([]);

        $result = $this->repository->findByWamid('nonexistent-uuid');

        $this->assertNull($result);
    }

    public function testFindByWamidReturnsLogWhenFound(): void
    {
        $log = new MessageLog();

        $this->mockQuery->method('getResult')->willReturn([$log]);

        $result = $this->repository->findByWamid('some-uuid-123');

        $this->assertSame($log, $result);
    }

    // =========================================================================
    // prune
    // =========================================================================

    public function testPruneDeletesOldRecordsByAgeFirst(): void
    {
        // Com maxDays=30, deve executar DELETE por idade antes do COUNT
        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('INTERVAL 30 DAY'));

        $this->mockConnection
            ->method('fetchOne')
            ->willReturn('0'); // após limpeza por idade, tabela está vazia

        $this->repository->prune(maxRecords: 100_000, maxDays: 30);
    }

    public function testPruneSkipsAgeDeletionWhenMaxDaysIsZero(): void
    {
        // maxDays=0 → não executa DELETE por idade
        // maxRecords=100_000 → executa COUNT, mas 50 < 100_000 → nenhum DELETE
        $this->mockConnection
            ->expects($this->never())
            ->method('executeStatement');

        $this->mockConnection
            ->method('fetchOne')
            ->willReturn('50');

        $this->repository->prune(maxRecords: 100_000, maxDays: 0);
    }

    public function testPruneDefaultMaxRecordsZeroSkipsCountCheck(): void
    {
        // maxRecords=0 (padrão) → limite por contagem desabilitado
        // Apenas o DELETE por idade roda; COUNT não é consultado
        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('INTERVAL 30 DAY'))
            ->willReturn(0);

        $this->mockConnection
            ->expects($this->never())
            ->method('fetchOne');

        $this->repository->prune(); // sem argumentos → maxRecords=0, maxDays=30
    }

    public function testPruneMaxRecordsZeroExplicitSkipsCountCheck(): void
    {
        // maxRecords=0 explícito → mesmo comportamento que o padrão
        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('INTERVAL 7 DAY'))
            ->willReturn(0);

        $this->mockConnection
            ->expects($this->never())
            ->method('fetchOne');

        $this->repository->prune(maxRecords: 0, maxDays: 7);
    }

    public function testPruneBothDisabledDoesNothing(): void
    {
        // maxDays=0, maxRecords=0 → absolutamente nada acontece
        $this->mockConnection->expects($this->never())->method('executeStatement');
        $this->mockConnection->expects($this->never())->method('fetchOne');

        $this->repository->prune(maxRecords: 0, maxDays: 0);
    }

    public function testPruneDoesNothingWhenCountBelowLimitAfterAgePrune(): void
    {
        // Após limpeza por idade, contagem fica abaixo do limite → nenhum DELETE por contagem
        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('INTERVAL'));

        $this->mockConnection
            ->method('fetchOne')
            ->willReturn('9999');

        $this->repository->prune(maxRecords: 100_000, maxDays: 30);
    }

    public function testPruneDeletesOldestByCountWhenStillOverLimit(): void
    {
        // Mesmo após limpeza por idade, ainda há excesso → DELETE por contagem
        $this->mockConnection
            ->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql): int {
                // Primeira chamada: DELETE por idade; segunda: DELETE por contagem
                return 0;
            });

        $this->mockConnection
            ->method('fetchOne')
            ->willReturn('100500'); // ainda 100.500 após limpeza por idade

        $this->repository->prune(maxRecords: 100_000, maxDays: 30);
    }

    public function testPruneDoesNothingWhenTableIsEmpty(): void
    {
        $this->mockConnection
            ->method('fetchOne')
            ->willReturn('0');

        // Apenas o DELETE por idade deve ser chamado (maxDays=30), nunca o de contagem
        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('INTERVAL'));

        $this->repository->prune(maxRecords: 100_000, maxDays: 30);
    }

    public function testPruneWithMaxDaysZeroAndCountOverLimitDeletesByCount(): void
    {
        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->with('DELETE FROM `dialog_hsm_message_log` ORDER BY date_sent ASC, id ASC LIMIT 500');

        $this->mockConnection
            ->method('fetchOne')
            ->willReturn('500500');

        $this->repository->prune(maxRecords: 500_000, maxDays: 0);
    }

    public function testPruneDeletesExactlyOneWhenExcessIsOne(): void
    {
        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->with('DELETE FROM `dialog_hsm_message_log` ORDER BY date_sent ASC, id ASC LIMIT 1');

        $this->mockConnection
            ->method('fetchOne')
            ->willReturn('100001');

        $this->repository->prune(maxRecords: 100_000, maxDays: 0);
    }

    public function testPruneRespectsCustomMaxRecords(): void
    {
        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->with('DELETE FROM `dialog_hsm_message_log` ORDER BY date_sent ASC, id ASC LIMIT 100');

        $this->mockConnection
            ->method('fetchOne')
            ->willReturn('600');

        $this->repository->prune(maxRecords: 500, maxDays: 0);
    }

    // =========================================================================
    // prune — comportamento de batching (evita table lock)
    // =========================================================================

    public function testPruneAgeDeletionSqlContainsLimit(): void
    {
        // O DELETE por idade deve sempre incluir LIMIT para não travar a tabela inteira
        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->logicalAnd(
                $this->stringContains('INTERVAL 30 DAY'),
                $this->stringContains('LIMIT 1000')
            ))
            ->willReturn(0);

        $this->mockConnection->method('fetchOne')->willReturn('0');

        $this->repository->prune(maxRecords: 100_000, maxDays: 30);
    }

    public function testPruneAgeDeletionLoopsWhileBatchIsFull(): void
    {
        // 1ª batch retorna 1000 (cheio) → continua; 2ª retorna 300 (parcial) → para
        // Resultado: executeStatement chamado exatamente 2 vezes
        $call = 0;
        $this->mockConnection
            ->expects($this->exactly(2))
            ->method('executeStatement')
            ->with($this->stringContains('INTERVAL 7 DAY'))
            ->willReturnCallback(static function () use (&$call): int {
                return ++$call === 1 ? 1000 : 300;
            });

        $this->mockConnection->method('fetchOne')->willReturn('0');

        $this->repository->prune(maxRecords: 100_000, maxDays: 7);
    }

    public function testPruneCountDeletionUsesMultipleBatchesForLargeExcess(): void
    {
        // Excesso de 2500 → 3 batches: LIMIT 1000, LIMIT 1000, LIMIT 500
        $call = 0;
        $this->mockConnection
            ->expects($this->exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$call): int {
                ++$call;
                if ($call <= 2) {
                    $this->assertStringContainsString('LIMIT 1000', $sql);
                    return 1000;
                }
                $this->assertStringContainsString('LIMIT 500', $sql);
                return 500;
            });

        // 102500 registros, limite 100000 → excesso = 2500
        $this->mockConnection->method('fetchOne')->willReturn('102500');

        $this->repository->prune(maxRecords: 100_000, maxDays: 0);
    }

    public function testPruneCountDeletionStopsEarlyWhenTableDrains(): void
    {
        // executeStatement retorna 0 imediatamente → break sem loop infinito
        $this->mockConnection
            ->expects($this->once())
            ->method('executeStatement')
            ->willReturn(0);

        // Excesso = 100000, mas o banco retorna 0 linhas deletadas
        $this->mockConnection->method('fetchOne')->willReturn('200000');

        $this->repository->prune(maxRecords: 100_000, maxDays: 0);
    }

    public function testPruneAgeDeletionThenCountDeletionBothBatch(): void
    {
        // Simula: idade remove em 2 batches (1000 + 200), contagem ainda excede → 1 batch de contagem
        $ageCalls   = 0;
        $countCalls = 0;

        $this->mockConnection
            ->expects($this->exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$ageCalls, &$countCalls): int {
                if (str_contains($sql, 'INTERVAL')) {
                    return ++$ageCalls === 1 ? 1000 : 200;
                }
                ++$countCalls;
                return 0; // break do while de contagem
            });

        $this->mockConnection->method('fetchOne')->willReturn('100500'); // excesso = 500

        $this->repository->prune(maxRecords: 100_000, maxDays: 14);
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

    // =========================================================================
    // getStatsByPeriod
    // =========================================================================

    public function testGetStatsByPeriodReturnsZeroesWhenNoRows(): void
    {
        $this->mockConnection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $result = $this->repository->getStatsByPeriod(new \DateTime('-24 hours'));

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['delivered']);
        $this->assertSame(0, $result['read']);
        $this->assertSame(0, $result['dlq']);
    }

    public function testGetStatsByPeriodSumsTotal(): void
    {
        $this->mockConnection
            ->method('fetchAllAssociative')
            ->willReturn([
                ['status' => 'sent',   'cnt' => '10'],
                ['status' => 'failed', 'cnt' => '3'],
                ['status' => 'dlq',    'cnt' => '1'],
            ]);

        $result = $this->repository->getStatsByPeriod(new \DateTime('-24 hours'));

        $this->assertSame(14, $result['total']);
        $this->assertSame(10, $result['sent']);
        $this->assertSame(3, $result['failed']);
        $this->assertSame(1, $result['dlq']);
        $this->assertSame(0, $result['delivered']);
        $this->assertSame(0, $result['read']);
    }

    public function testGetStatsByPeriodIgnoresUnknownStatuses(): void
    {
        $this->mockConnection
            ->method('fetchAllAssociative')
            ->willReturn([
                ['status' => 'sent',    'cnt' => '5'],
                ['status' => 'unknown', 'cnt' => '99'],
            ]);

        $result = $this->repository->getStatsByPeriod(new \DateTime('-24 hours'));

        $this->assertSame(104, $result['total']); // unknown soma no total
        $this->assertSame(5, $result['sent']);
        $this->assertArrayNotHasKey('unknown', $result);
    }

    public function testGetStatsByPeriodPassesCorrectDateToQuery(): void
    {
        $from = new \DateTime('2025-01-15 10:00:00');

        $this->mockConnection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->stringContains('date_sent >= ?'),
                ['2025-01-15 10:00:00']
            )
            ->willReturn([]);

        $this->repository->getStatsByPeriod($from);
    }

    // =========================================================================
    // getChartData
    // =========================================================================

    public function testGetChartDataReturnsAllDaysWithZeroes(): void
    {
        $this->mockConnection
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $result = $this->repository->getChartData(7);

        $this->assertCount(7, $result);
        foreach ($result as $day) {
            $this->assertSame(0, $day['sent']);
            $this->assertSame(0, $day['failed']);
        }
    }

    public function testGetChartDataFillsCorrectStatus(): void
    {
        $today = (new \DateTime())->format('Y-m-d');

        $this->mockConnection
            ->method('fetchAllAssociative')
            ->willReturn([
                ['day' => $today, 'status' => 'sent',   'cnt' => '8'],
                ['day' => $today, 'status' => 'failed', 'cnt' => '2'],
            ]);

        $result = $this->repository->getChartData(7);

        $this->assertArrayHasKey($today, $result);
        $this->assertSame(8, $result[$today]['sent']);
        $this->assertSame(2, $result[$today]['failed']);
        $this->assertSame(0, $result[$today]['delivered']);
    }

    public function testGetChartDataIgnoresUnknownStatuses(): void
    {
        $today = (new \DateTime())->format('Y-m-d');

        $this->mockConnection
            ->method('fetchAllAssociative')
            ->willReturn([
                ['day' => $today, 'status' => 'unknown', 'cnt' => '5'],
            ]);

        $result = $this->repository->getChartData(7);

        $this->assertArrayNotHasKey('unknown', $result[$today]);
        $this->assertSame(0, $result[$today]['sent']);
    }

    public function testGetChartDataDayCountMatchesParameter(): void
    {
        $this->mockConnection->method('fetchAllAssociative')->willReturn([]);

        $this->assertCount(3,  $this->repository->getChartData(3));
        $this->assertCount(14, $this->repository->getChartData(14));
    }
}
