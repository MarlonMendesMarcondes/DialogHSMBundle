<?php

declare(strict_types=1);

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WhatsAppNumberRepositoryTest extends TestCase
{
    private WhatsAppNumberRepository&MockObject $repository;
    private QueryBuilder&MockObject $mockQueryBuilder;
    private AbstractQuery&MockObject $mockQuery;

    protected function setUp(): void
    {
        $this->mockQuery = $this->createMock(AbstractQuery::class);

        $this->mockQueryBuilder = $this->createMock(QueryBuilder::class);
        $this->mockQueryBuilder->method('select')->willReturnSelf();
        $this->mockQueryBuilder->method('where')->willReturnSelf();
        $this->mockQueryBuilder->method('andWhere')->willReturnSelf();
        $this->mockQueryBuilder->method('setParameter')->willReturnSelf();
        $this->mockQueryBuilder->method('getQuery')->willReturn($this->mockQuery);

        $this->repository = $this->getMockBuilder(WhatsAppNumberRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();

        $this->repository
            ->method('createQueryBuilder')
            ->willReturn($this->mockQueryBuilder);
    }

    // -------------------------------------------------------------------------
    // getDefaultOrder (protected — testado via reflexão)
    // -------------------------------------------------------------------------

    public function testGetDefaultOrderReturnsSortByNameAsc(): void
    {
        $ref = new \ReflectionMethod(WhatsAppNumberRepository::class, 'getDefaultOrder');
        $ref->setAccessible(true);

        $result = $ref->invoke($this->repository);

        $this->assertCount(1, $result, 'Deve haver exatamente uma regra de ordenação');
        $this->assertSame(['wn.name', 'ASC'], $result[0]);
    }

    // -------------------------------------------------------------------------
    // getDistinctBulkQueueNames
    // -------------------------------------------------------------------------

    public function testGetDistinctBulkQueueNamesReturnsQueueNames(): void
    {
        $this->mockQuery
            ->method('getArrayResult')
            ->willReturn([['q' => 'educa'], ['q' => 'vendas']]);

        $result = $this->repository->getDistinctBulkQueueNames();

        $this->assertEquals(['educa', 'vendas'], $result);
    }

    public function testGetDistinctBulkQueueNamesReturnsEmptyArrayWhenNoNumbers(): void
    {
        $this->mockQuery
            ->method('getArrayResult')
            ->willReturn([]);

        $result = $this->repository->getDistinctBulkQueueNames();

        $this->assertEmpty($result);
    }

    public function testGetDistinctBulkQueueNamesFilterNullValues(): void
    {
        $this->mockQuery
            ->method('getArrayResult')
            ->willReturn([['q' => 'educa'], ['q' => null], ['q' => 'vendas']]);

        $result = $this->repository->getDistinctBulkQueueNames();

        $this->assertEquals(['educa', 'vendas'], $result);
        $this->assertNotContains(null, $result);
    }

    public function testGetDistinctBulkQueueNamesFilterEmptyStrings(): void
    {
        $this->mockQuery
            ->method('getArrayResult')
            ->willReturn([['q' => 'educa'], ['q' => '']]);

        $result = $this->repository->getDistinctBulkQueueNames();

        $this->assertEquals(['educa'], $result);
    }

    public function testGetDistinctBulkQueueNamesReturnsReindexedArray(): void
    {
        // array_values garante que o resultado é sempre um array indexado sequencialmente
        $this->mockQuery
            ->method('getArrayResult')
            ->willReturn([['q' => 'educa'], ['q' => null], ['q' => 'vendas']]);

        $result = $this->repository->getDistinctBulkQueueNames();

        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(2, $result);
    }

    // -------------------------------------------------------------------------
    // getDistinctBatchQueueNames
    // -------------------------------------------------------------------------

    public function testGetDistinctBatchQueueNamesReturnsQueueNames(): void
    {
        $this->mockQuery
            ->method('getArrayResult')
            ->willReturn([['q' => 'educa_lote'], ['q' => 'vendas_lote']]);

        $result = $this->repository->getDistinctBatchQueueNames();

        $this->assertEquals(['educa_lote', 'vendas_lote'], $result);
    }

    public function testGetDistinctBatchQueueNamesReturnsEmptyArrayWhenNoNumbers(): void
    {
        $this->mockQuery
            ->method('getArrayResult')
            ->willReturn([]);

        $result = $this->repository->getDistinctBatchQueueNames();

        $this->assertEmpty($result);
    }

    public function testGetDistinctBatchQueueNamesFilterNullValues(): void
    {
        $this->mockQuery
            ->method('getArrayResult')
            ->willReturn([['q' => null], ['q' => 'educa_lote']]);

        $result = $this->repository->getDistinctBatchQueueNames();

        $this->assertEquals(['educa_lote'], $result);
        $this->assertNotContains(null, $result);
    }

    public function testGetDistinctBatchQueueNamesFilterEmptyStrings(): void
    {
        $this->mockQuery
            ->method('getArrayResult')
            ->willReturn([['q' => 'educa_lote'], ['q' => '']]);

        $result = $this->repository->getDistinctBatchQueueNames();

        $this->assertEquals(['educa_lote'], $result);
        $this->assertNotContains('', $result);
    }

    public function testGetDistinctBatchQueueNamesReturnsReindexedArray(): void
    {
        // array_values garante que o resultado é sempre um array indexado sequencialmente
        $this->mockQuery
            ->method('getArrayResult')
            ->willReturn([['q' => null], ['q' => 'educa_lote'], ['q' => 'vendas_lote']]);

        $result = $this->repository->getDistinctBatchQueueNames();

        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(2, $result);
    }

    // -------------------------------------------------------------------------
    // Testes cruzados: bulk e batch são independentes
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // getNumberList
    // -------------------------------------------------------------------------

    public function testGetNumberListReturnsResultsWithNoSearch(): void
    {
        $this->mockQueryBuilder->method('orderBy')->willReturnSelf();
        $this->mockQueryBuilder->method('setFirstResult')->willReturnSelf();
        $this->mockQueryBuilder->method('setMaxResults')->willReturnSelf();

        $this->mockQuery
            ->method('getArrayResult')
            ->willReturn([
                ['id' => 1, 'name' => 'Vendas', 'phoneNumber' => '+5511'],
                ['id' => 2, 'name' => 'Suporte', 'phoneNumber' => '+5521'],
            ]);

        $result = $this->repository->getNumberList();

        $this->assertCount(2, $result);
        $this->assertSame('Vendas', $result[0]['name']);
    }

    public function testGetNumberListWithStringSearchAddsLikeClause(): void
    {
        $this->mockQueryBuilder->method('orderBy')->willReturnSelf();
        $this->mockQueryBuilder->method('setFirstResult')->willReturnSelf();
        $this->mockQueryBuilder->method('setMaxResults')->willReturnSelf();
        $this->mockQueryBuilder->method('expr')->willReturn(
            $this->createMock(\Doctrine\ORM\Query\Expr::class)
        );

        $this->mockQueryBuilder
            ->expects($this->atLeastOnce())
            ->method('setParameter');

        $this->mockQuery->method('getArrayResult')->willReturn([]);

        $result = $this->repository->getNumberList('Vendas');
        $this->assertIsArray($result);
    }

    public function testGetNumberListWithArraySearchAddsInClause(): void
    {
        $this->mockQueryBuilder->method('orderBy')->willReturnSelf();
        $this->mockQueryBuilder->method('setFirstResult')->willReturnSelf();
        $this->mockQueryBuilder->method('setMaxResults')->willReturnSelf();
        $this->mockQueryBuilder->method('expr')->willReturn(
            $this->createMock(\Doctrine\ORM\Query\Expr::class)
        );

        $this->mockQueryBuilder
            ->expects($this->atLeastOnce())
            ->method('setParameter');

        $this->mockQuery->method('getArrayResult')->willReturn([['id' => 1]]);

        $result = $this->repository->getNumberList([1, 2, 3]);
        $this->assertCount(1, $result);
    }

    public function testGetNumberListWithZeroLimitSkipsPagination(): void
    {
        $this->mockQueryBuilder->method('orderBy')->willReturnSelf();

        // setFirstResult/setMaxResults NÃO devem ser chamados quando limit=0
        $this->mockQueryBuilder->expects($this->never())->method('setFirstResult');
        $this->mockQueryBuilder->expects($this->never())->method('setMaxResults');

        $this->mockQuery->method('getArrayResult')->willReturn([]);

        $this->repository->getNumberList('', 0, 0);
    }

    public function testGetNumberListDefaultLimitIs10(): void
    {
        $this->mockQueryBuilder->method('orderBy')->willReturnSelf();
        $this->mockQueryBuilder->method('setFirstResult')->willReturnSelf();

        $this->mockQueryBuilder
            ->expects($this->once())
            ->method('setMaxResults')
            ->with(10)
            ->willReturnSelf();

        $this->mockQuery->method('getArrayResult')->willReturn([]);

        $this->repository->getNumberList();
    }

    public function testGetNumberListWithEmptyArraySearchSkipsSearchClause(): void
    {
        // search=[] é empty() → não aplica where
        $this->mockQueryBuilder->method('orderBy')->willReturnSelf();
        $this->mockQueryBuilder->method('setFirstResult')->willReturnSelf();
        $this->mockQueryBuilder->method('setMaxResults')->willReturnSelf();

        // andWhere só deve ser chamado para isPublished, não para search
        $this->mockQueryBuilder->expects($this->once())->method('andWhere');

        $this->mockQuery->method('getArrayResult')->willReturn([]);

        $this->repository->getNumberList([]);
    }

    public function testBulkAndBatchUseIndependentQueryBuilders(): void
    {
        // Cada chamada ao createQueryBuilder devolve um resultado diferente
        $mockQueryBulk  = $this->createMock(AbstractQuery::class);
        $mockQueryBatch = $this->createMock(AbstractQuery::class);

        $mockQueryBulk->method('getArrayResult')->willReturn([['q' => 'bulk_queue']]);
        $mockQueryBatch->method('getArrayResult')->willReturn([['q' => 'batch_queue']]);

        $mockQbBulk = $this->createMock(QueryBuilder::class);
        $mockQbBulk->method('select')->willReturnSelf();
        $mockQbBulk->method('where')->willReturnSelf();
        $mockQbBulk->method('andWhere')->willReturnSelf();
        $mockQbBulk->method('setParameter')->willReturnSelf();
        $mockQbBulk->method('getQuery')->willReturn($mockQueryBulk);

        $mockQbBatch = $this->createMock(QueryBuilder::class);
        $mockQbBatch->method('select')->willReturnSelf();
        $mockQbBatch->method('where')->willReturnSelf();
        $mockQbBatch->method('andWhere')->willReturnSelf();
        $mockQbBatch->method('setParameter')->willReturnSelf();
        $mockQbBatch->method('getQuery')->willReturn($mockQueryBatch);

        $repository = $this->getMockBuilder(WhatsAppNumberRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();

        $repository
            ->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($mockQbBulk, $mockQbBatch);

        $this->assertEquals(['bulk_queue'], $repository->getDistinctBulkQueueNames());
        $this->assertEquals(['batch_queue'], $repository->getDistinctBatchQueueNames());
    }
}
