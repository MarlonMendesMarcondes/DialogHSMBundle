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
        $this->assertArrayHasKey('ml.wamid', $columns);
        $this->assertArrayHasKey('ml.error_message', $columns);
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
}
