<?php

declare(strict_types=1);

defined('MAUTIC_TABLE_PREFIX') or define('MAUTIC_TABLE_PREFIX', '');

use Doctrine\DBAL\Query\QueryBuilder;
use Mautic\ReportBundle\Event\ReportBuilderEvent;
use Mautic\ReportBundle\Event\ReportGeneratorEvent;
use Mautic\ReportBundle\ReportEvents;
use MauticPlugin\DialogHSMBundle\EventListener\ReportSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReportSubscriberTest extends TestCase
{
    private ReportSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new ReportSubscriber();
    }

    // =========================================================================
    // getSubscribedEvents
    // =========================================================================

    public function testGetSubscribedEventsListensToBuildAndGenerate(): void
    {
        $events = ReportSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(ReportEvents::REPORT_ON_BUILD, $events);
        $this->assertArrayHasKey(ReportEvents::REPORT_ON_GENERATE, $events);
        $this->assertSame('onReportBuilder', $events[ReportEvents::REPORT_ON_BUILD][0]);
        $this->assertSame('onReportGenerate', $events[ReportEvents::REPORT_ON_GENERATE][0]);
    }

    // =========================================================================
    // onReportBuilder
    // =========================================================================

    public function testOnReportBuilderDoesNothingForOtherContexts(): void
    {
        $event = $this->makeBuilderEvent(contextMatches: false);
        $event->expects($this->never())->method('addTable');

        $this->subscriber->onReportBuilder($event);
    }

    public function testOnReportBuilderAddsTableForCorrectContext(): void
    {
        $event = $this->makeBuilderEvent(contextMatches: true);
        $event->expects($this->once())
            ->method('addTable')
            ->with(
                ReportSubscriber::CONTEXT,
                $this->isType('array'),
                'channels'
            );

        $this->subscriber->onReportBuilder($event);
    }

    public function testOnReportBuilderTableDataHasDisplayName(): void
    {
        $captured = null;
        $event    = $this->makeBuilderEvent(contextMatches: true);
        $event->method('addTable')
            ->willReturnCallback(function (string $ctx, array $data) use (&$captured) {
                $captured = $data;
                return null;
            });

        $this->subscriber->onReportBuilder($event);

        $this->assertArrayHasKey('display_name', $captured);
        $this->assertSame('dialoghsm.report.context', $captured['display_name']);
    }

    public function testOnReportBuilderColumnsContainsCoreFields(): void
    {
        $captured = null;
        $event    = $this->makeBuilderEvent(contextMatches: true);
        $event->method('addTable')
            ->willReturnCallback(function (string $ctx, array $data) use (&$captured) {
                $captured = $data;
                return null;
            });

        $this->subscriber->onReportBuilder($event);

        $columns = $captured['columns'];
        $this->assertArrayHasKey('ml.template_name', $columns);
        $this->assertArrayHasKey('ml.phone_number', $columns);
        $this->assertArrayHasKey('ml.status', $columns);
        $this->assertArrayHasKey('ml.date_sent', $columns);
        $this->assertArrayHasKey('ml.date_delivered', $columns);
        $this->assertArrayHasKey('ml.date_read', $columns);
        $this->assertArrayHasKey('ml.lead_id', $columns);
        $this->assertArrayHasKey('ml.error_message', $columns);
        $this->assertArrayNotHasKey('ml.wamid', $columns, 'wamid é dado sensível e não deve aparecer nos relatórios');
    }

    public function testOnReportBuilderColumnsContainsCampaignAndMessageFields(): void
    {
        $captured = null;
        $event    = $this->makeBuilderEvent(contextMatches: true);
        $event->method('addTable')
            ->willReturnCallback(function (string $ctx, array $data) use (&$captured) {
                $captured = $data;
                return null;
            });

        $this->subscriber->onReportBuilder($event);

        $columns = $captured['columns'];
        $this->assertArrayHasKey('c.name', $columns);
        $this->assertArrayHasKey('wm.name', $columns);
        $this->assertSame('campaign_name', $columns['c.name']['alias']);
        $this->assertSame('whatsapp_message_name', $columns['wm.name']['alias']);
    }

    public function testOnReportBuilderColumnsContainAggregateMetrics(): void
    {
        $captured = null;
        $event    = $this->makeBuilderEvent(contextMatches: true);
        $event->method('addTable')
            ->willReturnCallback(function (string $ctx, array $data) use (&$captured) {
                $captured = $data;
                return null;
            });

        $this->subscriber->onReportBuilder($event);

        $columns = $captured['columns'];
        $this->assertArrayHasKey('sent_count', $columns);
        $this->assertArrayHasKey('failed_count', $columns);
        $this->assertArrayHasKey('delivery_rate', $columns);
        $this->assertArrayHasKey('read_rate', $columns);

        $this->assertStringContainsString('SUM', $columns['sent_count']['formula']);
        $this->assertStringContainsString('NULLIF', $columns['delivery_rate']['formula']);
        $this->assertStringContainsString('NULLIF', $columns['read_rate']['formula']);
    }

    public function testOnReportBuilderDeliveryRateFormulaCoversDeliveredAndRead(): void
    {
        $captured = null;
        $event    = $this->makeBuilderEvent(contextMatches: true);
        $event->method('addTable')
            ->willReturnCallback(function (string $ctx, array $data) use (&$captured) {
                $captured = $data;
                return null;
            });

        $this->subscriber->onReportBuilder($event);

        $formula = $captured['columns']['delivery_rate']['formula'];
        $this->assertStringContainsString("'delivered'", $formula);
        $this->assertStringContainsString("'read'", $formula);
    }

    public function testOnReportBuilderFiltersContainStatusWithSelectType(): void
    {
        $captured = null;
        $event    = $this->makeBuilderEvent(contextMatches: true);
        $event->method('addTable')
            ->willReturnCallback(function (string $ctx, array $data) use (&$captured) {
                $captured = $data;
                return null;
            });

        $this->subscriber->onReportBuilder($event);

        $filters = $captured['filters'];
        $this->assertArrayHasKey('ml.status', $filters);
        $this->assertSame('select', $filters['ml.status']['type']);
        $this->assertArrayHasKey('list', $filters['ml.status']);

        $list = $filters['ml.status']['list'];
        foreach (['queued', 'sent', 'delivered', 'read', 'failed', 'dlq'] as $status) {
            $this->assertArrayHasKey($status, $list);
        }
    }

    public function testOnReportBuilderDateSentColumnHasGroupByFormula(): void
    {
        $captured = null;
        $event    = $this->makeBuilderEvent(contextMatches: true);
        $event->method('addTable')
            ->willReturnCallback(function (string $ctx, array $data) use (&$captured) {
                $captured = $data;
                return null;
            });

        $this->subscriber->onReportBuilder($event);

        $this->assertArrayHasKey('groupByFormula', $captured['columns']['ml.date_sent']);
        $this->assertStringContainsString('DATE(', $captured['columns']['ml.date_sent']['groupByFormula']);
    }

    // =========================================================================
    // onReportGenerate
    // =========================================================================

    public function testOnReportGenerateDoesNothingForOtherContexts(): void
    {
        $event = $this->makeGeneratorEvent(contextMatches: false);
        $event->expects($this->never())->method('getQueryBuilder');

        $this->subscriber->onReportGenerate($event);
    }

    public function testOnReportGenerateSetFromOnMessageLogTable(): void
    {
        $qb    = $this->makeQueryBuilder();
        $event = $this->makeGeneratorEvent(contextMatches: true, qb: $qb);

        $qb->expects($this->once())
            ->method('from')
            ->with(
                $this->stringContains('dialog_hsm_message_log'),
                'ml'
            )
            ->willReturnSelf();

        $this->subscriber->onReportGenerate($event);
    }

    public function testOnReportGenerateDoesNotJoinCampaignWhenNotUsed(): void
    {
        $qb    = $this->makeQueryBuilder();
        $event = $this->makeGeneratorEvent(
            contextMatches: true,
            qb: $qb,
            usedPrefixes: []
        );

        $qb->expects($this->never())->method('leftJoin');

        $this->subscriber->onReportGenerate($event);
    }

    public function testOnReportGenerateJoinsCampaignWhenCampaignColumnUsed(): void
    {
        $qb    = $this->makeQueryBuilder();
        $event = $this->makeGeneratorEvent(
            contextMatches: true,
            qb: $qb,
            usedPrefixes: ['c']
        );

        $qb->expects($this->atLeastOnce())
            ->method('leftJoin')
            ->with(
                'ml',
                $this->stringContains('campaigns'),
                'c',
                $this->stringContains('c.id = ml.campaign_id')
            )
            ->willReturnSelf();

        $this->subscriber->onReportGenerate($event);
    }

    public function testOnReportGenerateJoinsWhatsAppMessageWhenWmColumnUsed(): void
    {
        $qb    = $this->makeQueryBuilder();
        $event = $this->makeGeneratorEvent(
            contextMatches: true,
            qb: $qb,
            usedPrefixes: ['wm']
        );

        $qb->expects($this->atLeastOnce())
            ->method('leftJoin')
            ->with(
                'ml',
                $this->stringContains('dialog_hsm_whatsapp_messages'),
                'wm',
                $this->stringContains('wm.id = ml.whatsapp_message_id')
            )
            ->willReturnSelf();

        $this->subscriber->onReportGenerate($event);
    }

    public function testOnReportGenerateJoinsBothWhenBothPrefixesUsed(): void
    {
        $qb    = $this->makeQueryBuilder();
        $event = $this->makeGeneratorEvent(
            contextMatches: true,
            qb: $qb,
            usedPrefixes: ['c', 'wm']
        );

        $qb->expects($this->exactly(2))->method('leftJoin')->willReturnSelf();

        $this->subscriber->onReportGenerate($event);
    }

    public function testOnReportGenerateAppliesDateFilters(): void
    {
        $qb    = $this->makeQueryBuilder();
        $event = $this->makeGeneratorEvent(contextMatches: true, qb: $qb);

        $event->expects($this->once())
            ->method('applyDateFilters')
            ->with($qb, 'date_sent', 'ml');

        $this->subscriber->onReportGenerate($event);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeBuilderEvent(bool $contextMatches): ReportBuilderEvent&MockObject
    {
        $event = $this->getMockBuilder(ReportBuilderEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['checkContext', 'addTable'])
            ->getMock();

        $event->method('checkContext')->willReturn($contextMatches);

        return $event;
    }

    /**
     * @param string[] $usedPrefixes Prefixes reported as used by usesColumnWithPrefix()
     */
    private function makeGeneratorEvent(
        bool $contextMatches,
        ?QueryBuilder $qb = null,
        array $usedPrefixes = []
    ): ReportGeneratorEvent&MockObject {
        $event = $this->getMockBuilder(ReportGeneratorEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['checkContext', 'getQueryBuilder', 'usesColumnWithPrefix', 'applyDateFilters'])
            ->getMock();

        $event->method('checkContext')->willReturn($contextMatches);

        if ($contextMatches) {
            $event->method('getQueryBuilder')->willReturn($qb ?? $this->makeQueryBuilder());
            $event->method('usesColumnWithPrefix')
                ->willReturnCallback(fn (string $prefix) => in_array($prefix, $usedPrefixes, true));
            $event->method('applyDateFilters')->willReturnSelf();
        }

        return $event;
    }

    private function makeQueryBuilder(): QueryBuilder&MockObject
    {
        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['from', 'leftJoin'])
            ->getMock();

        $qb->method('from')->willReturnSelf();
        $qb->method('leftJoin')->willReturnSelf();

        return $qb;
    }

    // =========================================================================
    // onReportGraphGenerate helpers
    // =========================================================================

    private function makeGraphQb(array $fetchRows = [], array $fetchOneRow = []): QueryBuilder&MockObject
    {
        $connection = $this->getMockBuilder(\Doctrine\DBAL\Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchAllAssociative', 'fetchAssociative'])
            ->getMock();

        $connection->method('fetchAllAssociative')->willReturn($fetchRows);
        $connection->method('fetchAssociative')->willReturn($fetchOneRow ?: ['cnt' => 0]);

        $expr = $this->getMockBuilder(\Doctrine\DBAL\Query\Expression\ExpressionBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['in'])
            ->getMock();
        $expr->method('in')->willReturn('1=1');

        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['from', 'leftJoin', 'andWhere', 'setParameter', 'select',
                           'groupBy', 'orderBy', 'having', 'setMaxResults',
                           'getSQL', 'getParameters', 'getConnection', 'expr'])
            ->getMock();

        $qb->method('from')->willReturnSelf();
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('having')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getSQL')->willReturn('SELECT 1');
        $qb->method('getParameters')->willReturn([]);
        $qb->method('getConnection')->willReturn($connection);
        $qb->method('expr')->willReturn($expr);

        return $qb;
    }

    private function makeChartQuery(): \Mautic\CoreBundle\Helper\Chart\ChartQuery&MockObject
    {
        $cq = $this->getMockBuilder(\Mautic\CoreBundle\Helper\Chart\ChartQuery::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['modifyTimeDataQuery', 'loadAndBuildTimeData', 'applyDateFilters'])
            ->getMock();

        $cq->method('modifyTimeDataQuery')->willReturnSelf();
        $cq->method('loadAndBuildTimeData')->willReturn(array_fill(0, 30, 0));
        $cq->method('applyDateFilters')->willReturnSelf();

        return $cq;
    }

    private function makeGraphEvent(bool $contextMatches, string $graph, array $fetchRows = [], array $fetchOneRow = []): \Mautic\ReportBundle\Event\ReportGraphEvent&MockObject
    {
        $translator = $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $event = $this->getMockBuilder(\Mautic\ReportBundle\Event\ReportGraphEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['checkContext', 'getRequestedGraphs', 'getQueryBuilder', 'getOptions', 'setGraph'])
            ->getMock();

        $event->method('checkContext')->willReturn($contextMatches);

        if ($contextMatches) {
            $event->method('getRequestedGraphs')->willReturn([$graph]);
            $event->method('getQueryBuilder')->willReturn($this->makeGraphQb($fetchRows, $fetchOneRow));
            $event->method('getOptions')->willReturn([
                'chartQuery' => $this->makeChartQuery(),
                'dateFrom'   => new \DateTime('-30 days'),
                'dateTo'     => new \DateTime(),
                'translator' => $translator,
            ]);
        }

        return $event;
    }

    // =========================================================================
    // onReportGraphGenerate — context guard
    // =========================================================================

    public function testOnReportGraphGenerateDoesNothingForOtherContexts(): void
    {
        $event = $this->makeGraphEvent(false, ReportSubscriber::GRAPH_PIE_STATUS);
        $event->expects($this->never())->method('setGraph');

        $this->subscriber->onReportGraphGenerate($event);
    }

    // =========================================================================
    // onReportGraphGenerate — PIE status distribution
    // =========================================================================

    public function testPieStatusCallsSetGraphWithDataAndName(): void
    {
        $event = $this->makeGraphEvent(true, ReportSubscriber::GRAPH_PIE_STATUS);
        $event->expects($this->once())->method('setGraph')
            ->with(
                ReportSubscriber::GRAPH_PIE_STATUS,
                $this->callback(fn ($d) => isset($d['data']) && isset($d['name']))
            );

        $this->subscriber->onReportGraphGenerate($event);
    }

    public function testPieStatusBucketsDlqIntoFailed(): void
    {
        // dlq já é somado em 'failed' pelo SUM(status IN ('failed','dlq')) no SQL
        $fetchOneRow = ['sent' => 100, 'delivered' => 80, 'read_cnt' => 50, 'replied' => 0, 'failed' => 15];

        $captured = null;
        $event    = $this->makeGraphEvent(true, ReportSubscriber::GRAPH_PIE_STATUS, [], $fetchOneRow);
        $event->method('setGraph')
            ->willReturnCallback(function (string $g, array $data) use (&$captured): void {
                $captured = $data;
            });

        $this->subscriber->onReportGraphGenerate($event);

        // dlq (5) deve somar em failed (10) → total 15
        $datasets = $captured['data']['datasets'][0]['data'];
        $this->assertSame(100, $datasets[0], 'sent bucket');
        $this->assertSame(80, $datasets[1], 'delivered bucket');
        $this->assertSame(50, $datasets[2], 'read bucket');
        $this->assertSame(0, $datasets[3], 'replied bucket');
        $this->assertSame(15, $datasets[4], 'failed bucket deve incluir dlq');
    }

    public function testPieStatusAppliesFourColors(): void
    {
        $event    = $this->makeGraphEvent(true, ReportSubscriber::GRAPH_PIE_STATUS);
        $captured = null;
        $event->method('setGraph')
            ->willReturnCallback(function (string $g, array $data) use (&$captured): void {
                $captured = $data;
            });

        $this->subscriber->onReportGraphGenerate($event);

        $colors = $captured['data']['datasets'][0]['backgroundColor'];
        $this->assertCount(5, $colors);
        $this->assertStringContainsString('92,184,92',   $colors[0]); // sent      #5cb85c
        $this->assertStringContainsString('23,162,184',  $colors[1]); // delivered #17a2b8
        $this->assertStringContainsString('2,117,216',   $colors[2]); // read      #0275d8
        $this->assertStringContainsString('111,66,193',  $colors[3]); // replied   #6f42c1
        $this->assertStringContainsString('217,83,79',   $colors[4]); // failed    #d9534f
    }

    // =========================================================================
    // onReportGraphGenerate — TABLE top templates
    // =========================================================================

    public function testTableTopTemplatesCallsSetGraphWithDataKey(): void
    {
        $rows = [
            ['template' => 'tpl_a', 'sent' => 100, 'delivered' => 80, 'read' => 40, 'failed' => 5, 'total' => 105],
            ['template' => 'tpl_b', 'sent' => 50,  'delivered' => 30, 'read' => 10, 'failed' => 2, 'total' => 52],
        ];

        $captured = null;
        $event    = $this->makeGraphEvent(true, ReportSubscriber::GRAPH_TABLE_TOP_TEMPLATES, $rows);
        $event->method('setGraph')
            ->willReturnCallback(function (string $g, array $data) use (&$captured): void {
                $captured = $data;
            });

        $this->subscriber->onReportGraphGenerate($event);

        $this->assertArrayHasKey('data', $captured);
        $this->assertArrayHasKey('name', $captured);
        $this->assertCount(2, $captured['data']);
        $this->assertArrayHasKey('id', $captured['data'][0]);
        $this->assertSame(1, $captured['data'][0]['id']);
        $this->assertSame(2, $captured['data'][1]['id']);
    }

    // =========================================================================
    // onReportGraphGenerate — TABLE top read rate
    // =========================================================================

    public function testTableTopReadRateCalculatesPercentages(): void
    {
        $rows = [
            ['template' => 'tpl_x', 'sent_plus' => 200, 'delivered_plus' => 160, 'read_count' => 80],
            ['template' => 'tpl_y', 'sent_plus' => 100, 'delivered_plus' => 50,  'read_count' => 10],
        ];

        $captured = null;
        $event    = $this->makeGraphEvent(true, ReportSubscriber::GRAPH_TABLE_TOP_READ_RATE, $rows);
        $event->method('setGraph')
            ->willReturnCallback(function (string $g, array $data) use (&$captured): void {
                $captured = $data;
            });

        $this->subscriber->onReportGraphGenerate($event);

        $this->assertCount(2, $captured['data']);
        // tpl_x: delivery=160/200=80%, read=80/200=40%
        $row0Values = array_values($captured['data'][0]);
        $this->assertContains('80%', $row0Values);
        $this->assertContains('40%', $row0Values);
        // tpl_y: delivery=50/100=50%, read=10/100=10%
        $row1Values = array_values($captured['data'][1]);
        $this->assertContains('50%', $row1Values);
        $this->assertContains('10%', $row1Values);
    }

    // =========================================================================
    // onReportGraphGenerate — LINE sends per day
    // =========================================================================

    public function testLineSendsPerDayCallsSetGraph(): void
    {
        $event = $this->makeGraphEvent(true, ReportSubscriber::GRAPH_LINE_SENDS_PER_DAY);
        $event->expects($this->once())->method('setGraph')
            ->with(
                ReportSubscriber::GRAPH_LINE_SENDS_PER_DAY,
                $this->callback(fn ($d) => isset($d['datasets']) && count($d['datasets']) === 5)
            );

        $this->subscriber->onReportGraphGenerate($event);
    }

    public function testLineSendsPerDayAppliesDashboardPalette(): void
    {
        $captured = null;
        $event    = $this->makeGraphEvent(true, ReportSubscriber::GRAPH_LINE_SENDS_PER_DAY);
        $event->method('setGraph')
            ->willReturnCallback(function (string $g, array $data) use (&$captured): void {
                $captured = $data;
            });

        $this->subscriber->onReportGraphGenerate($event);

        $this->assertStringContainsString('92,184,92',  $captured['datasets'][0]['borderColor']); // sent
        $this->assertStringContainsString('23,162,184', $captured['datasets'][1]['borderColor']); // delivered
        $this->assertStringContainsString('2,117,216',  $captured['datasets'][2]['borderColor']); // read
        $this->assertStringContainsString('111,66,193', $captured['datasets'][3]['borderColor']); // replied
        $this->assertStringContainsString('217,83,79',  $captured['datasets'][4]['borderColor']); // failed
    }

    // =========================================================================
    // onReportGraphGenerate — LINE delivery rate
    // =========================================================================

    public function testLineDeliveryRateCallsSetGraph(): void
    {
        $event = $this->makeGraphEvent(true, ReportSubscriber::GRAPH_LINE_DELIVERY_RATE);
        $event->expects($this->once())->method('setGraph')
            ->with(
                ReportSubscriber::GRAPH_LINE_DELIVERY_RATE,
                $this->callback(fn ($d) => isset($d['datasets']) && count($d['datasets']) === 2)
            );

        $this->subscriber->onReportGraphGenerate($event);
    }

    public function testLineDeliveryRateAppliesTwoColors(): void
    {
        $captured = null;
        $event    = $this->makeGraphEvent(true, ReportSubscriber::GRAPH_LINE_DELIVERY_RATE);
        $event->method('setGraph')
            ->willReturnCallback(function (string $g, array $data) use (&$captured): void {
                $captured = $data;
            });

        $this->subscriber->onReportGraphGenerate($event);

        $this->assertStringContainsString('23,162,184', $captured['datasets'][0]['borderColor']); // delivery #17a2b8
        $this->assertStringContainsString('2,117,216',  $captured['datasets'][1]['borderColor']); // read     #0275d8
    }
}
