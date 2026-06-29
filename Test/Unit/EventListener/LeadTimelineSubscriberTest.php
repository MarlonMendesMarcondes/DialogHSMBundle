<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\EventListener\LeadTimelineSubscriber;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class LeadTimelineSubscriberTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private Connection&MockObject             $connection;
    private TranslatorInterface&MockObject    $translator;
    private LeadTimelineSubscriber            $subscriber;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnArgument(0);

        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('getTableName')->willReturn('lead_event_log');

        $this->em->method('getConnection')->willReturn($this->connection);
        $this->em->method('getClassMetadata')->willReturn($meta);

        $this->subscriber = new LeadTimelineSubscriber($this->em, $this->translator);
    }

    /**
     * Configura a connection para retornar as rows fornecidas quando o subscriber consultar lead_event_log.
     */
    private function mockRows(array $rows): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'from', 'where', 'andWhere', 'setParameter', 'orderBy', 'executeQuery'])
            ->getMock();

        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);

        $this->connection->method('createQueryBuilder')->willReturn($qb);
    }

    private function makeRow(string $action, string $dateAdded = '2026-01-15 10:00:00', array $props = []): array
    {
        return [
            'id'         => random_int(1, 9999),
            'action'     => $action,
            'date_added' => $dateAdded,
            'properties' => json_encode(array_merge([
                'template_name' => 'tpl_test',
                'phone_number'  => '+5511999999999',
                'wamid'         => 'wamid.abc',
            ], $props)),
        ];
    }

    private function makeTimelineEvent(
        bool $applicable = true,
        ?int $leadId = 42,
        bool $engagementCount = false,
    ): LeadTimelineEvent&MockObject {
        $event = $this->getMockBuilder(LeadTimelineEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addEventType', 'isApplicable', 'getLeadId', 'getQueryOptions', 'addToCounter', 'isEngagementCount', 'addEvent'])
            ->getMock();

        $event->method('isApplicable')->willReturn($applicable);
        $event->method('getLeadId')->willReturn($leadId);
        $event->method('isEngagementCount')->willReturn($engagementCount);
        $event->method('getQueryOptions')->willReturn(['paginated' => true, 'limit' => 25, 'start' => 0]);

        return $event;
    }

    // =========================================================================
    // getSubscribedEvents
    // =========================================================================

    public function testGetSubscribedEventsListensToTimelineGenerate(): void
    {
        $events = LeadTimelineSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(LeadEvents::TIMELINE_ON_GENERATE, $events);
        $this->assertSame('onTimelineGenerate', $events[LeadEvents::TIMELINE_ON_GENERATE][0]);
    }

    // =========================================================================
    // Registro de tipos de evento
    // =========================================================================

    public function testOnTimelineGenerateRegistersAllSevenEventTypes(): void
    {
        $event = $this->makeTimelineEvent(applicable: false);

        $expectedTypes = [
            'dialoghsm.dispatched',
            'dialoghsm.sent', 'dialoghsm.delivered', 'dialoghsm.read',
            'dialoghsm.replied', 'dialoghsm.failed', 'dialoghsm.dlq',
        ];

        $event->expects($this->exactly(7))
            ->method('addEventType')
            ->with(
                $this->callback(fn ($key) => in_array($key, $expectedTypes, true)),
                $this->isType('string')
            );

        $this->subscriber->onTimelineGenerate($event);
    }

    // =========================================================================
    // Early-exit paths
    // =========================================================================

    public function testSkipsQueryWhenNotApplicable(): void
    {
        $event = $this->makeTimelineEvent(applicable: false);

        $this->connection->expects($this->never())->method('createQueryBuilder');

        $this->subscriber->onTimelineGenerate($event);
    }

    public function testSkipsQueryWhenLeadIdIsNull(): void
    {
        $event = $this->makeTimelineEvent(applicable: true, leadId: null);

        $this->connection->expects($this->never())->method('createQueryBuilder');

        $this->subscriber->onTimelineGenerate($event);
    }

    // =========================================================================
    // Contadores
    // =========================================================================

    public function testAddsToCounterForEachStatus(): void
    {
        $this->mockRows([]);

        $event = $this->makeTimelineEvent();

        $event->expects($this->exactly(7))->method('addToCounter');

        $this->subscriber->onTimelineGenerate($event);
    }

    public function testCounterReflectsActualRowCount(): void
    {
        $this->mockRows([
            $this->makeRow(MessageLog::STATUS_SENT),
            $this->makeRow(MessageLog::STATUS_SENT),
            $this->makeRow(MessageLog::STATUS_DELIVERED),
        ]);

        $event = $this->makeTimelineEvent();

        $counters = [];
        $event->method('addToCounter')
            ->willReturnCallback(function (string $key, array $stats) use (&$counters): void {
                $counters[$key] = $stats['total'];
            });

        $this->subscriber->onTimelineGenerate($event);

        $this->assertSame(2, $counters['dialoghsm.sent']);
        $this->assertSame(1, $counters['dialoghsm.delivered']);
        $this->assertSame(0, $counters['dialoghsm.read'] ?? 0);
    }

    // =========================================================================
    // Engagement count
    // =========================================================================

    public function testSkipsAddEventWhenEngagementCount(): void
    {
        $this->mockRows([$this->makeRow(MessageLog::STATUS_SENT)]);

        $event = $this->makeTimelineEvent(engagementCount: true);

        $event->expects($this->never())->method('addEvent');

        $this->subscriber->onTimelineGenerate($event);
    }

    // =========================================================================
    // Estrutura do addEvent
    // =========================================================================

    public function testAddEventContainsCorrectFields(): void
    {
        $this->mockRows([$this->makeRow(MessageLog::STATUS_SENT, '2026-01-15 10:00:00', ['template_name' => 'welcome'])]);

        $event = $this->getMockBuilder(LeadTimelineEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addEventType', 'isApplicable', 'getLeadId', 'getQueryOptions', 'addToCounter', 'isEngagementCount', 'addEvent'])
            ->getMock();
        $event->method('isApplicable')->willReturnCallback(fn ($t) => 'dialoghsm.sent' === $t);
        $event->method('getLeadId')->willReturn(42);
        $event->method('isEngagementCount')->willReturn(false);
        $event->method('getQueryOptions')->willReturn(['paginated' => true, 'limit' => 25, 'start' => 0]);

        $event->expects($this->once())
            ->method('addEvent')
            ->with($this->callback(function (array $e): bool {
                return 'dialoghsm.sent' === $e['event']
                    && str_starts_with($e['eventId'], 'dialoghsm.sent')
                    && str_contains($e['eventLabel'], 'welcome')
                    && $e['timestamp'] instanceof \DateTimeInterface
                    && 'ri-checkbox-circle-line' === $e['icon']
                    && 42 === $e['contactId']
                    && '@DialogHSM/Timeline/whatsapp_message.html.twig' === $e['contentTemplate'];
            }));

        $this->subscriber->onTimelineGenerate($event);
    }

    public function testAddEventUsesDateAddedAsTimestamp(): void
    {
        $this->mockRows([$this->makeRow(MessageLog::STATUS_DELIVERED, '2026-03-10 14:30:00')]);

        $event = $this->getMockBuilder(LeadTimelineEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addEventType', 'isApplicable', 'getLeadId', 'getQueryOptions', 'addToCounter', 'isEngagementCount', 'addEvent'])
            ->getMock();
        $event->method('isApplicable')->willReturnCallback(fn ($t) => 'dialoghsm.delivered' === $t);
        $event->method('getLeadId')->willReturn(10);
        $event->method('isEngagementCount')->willReturn(false);
        $event->method('getQueryOptions')->willReturn(['paginated' => true, 'limit' => 25, 'start' => 0]);

        $captured = null;
        $event->method('addEvent')->willReturnCallback(function (array $e) use (&$captured): void {
            $captured = $e;
        });

        $this->subscriber->onTimelineGenerate($event);

        $this->assertNotNull($captured);
        $this->assertInstanceOf(\DateTimeInterface::class, $captured['timestamp']);
        $this->assertSame('2026-03-10 14:30:00', $captured['timestamp']->format('Y-m-d H:i:s'));
    }

    public function testAddEventExtraContainsDecodedProperties(): void
    {
        $props = ['template_name' => 'meu_template', 'phone_number' => '+5511912345678', 'date_delivered' => '2026-01-15 10:05:00'];
        $this->mockRows([$this->makeRow(MessageLog::STATUS_DELIVERED, '2026-01-15 10:05:00', $props)]);

        $event = $this->getMockBuilder(LeadTimelineEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addEventType', 'isApplicable', 'getLeadId', 'getQueryOptions', 'addToCounter', 'isEngagementCount', 'addEvent'])
            ->getMock();
        $event->method('isApplicable')->willReturnCallback(fn ($t) => 'dialoghsm.delivered' === $t);
        $event->method('getLeadId')->willReturn(10);
        $event->method('isEngagementCount')->willReturn(false);
        $event->method('getQueryOptions')->willReturn(['paginated' => true, 'limit' => 25, 'start' => 0]);

        $captured = null;
        $event->method('addEvent')->willReturnCallback(function (array $e) use (&$captured): void {
            $captured = $e;
        });

        $this->subscriber->onTimelineGenerate($event);

        $this->assertIsArray($captured['extra']);
        $this->assertSame('meu_template', $captured['extra']['template_name']);
        $this->assertSame('+5511912345678', $captured['extra']['phone_number']);
        $this->assertSame('2026-01-15 10:05:00', $captured['extra']['date_delivered']);
    }

    public function testDispatchedEventIsRenderedWithCorrectIcon(): void
    {
        $this->mockRows([$this->makeRow(LeadEventLogWriter::ACTION_DISPATCHED, '2026-01-15 10:00:00', ['template_name' => 'tpl_mm'])]);

        $event = $this->getMockBuilder(LeadTimelineEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addEventType', 'isApplicable', 'getLeadId', 'getQueryOptions', 'addToCounter', 'isEngagementCount', 'addEvent'])
            ->getMock();
        $event->method('isApplicable')->willReturnCallback(fn ($t) => 'dialoghsm.dispatched' === $t);
        $event->method('getLeadId')->willReturn(42);
        $event->method('isEngagementCount')->willReturn(false);
        $event->method('getQueryOptions')->willReturn(['paginated' => true, 'limit' => 25, 'start' => 0]);

        $captured = null;
        $event->method('addEvent')->willReturnCallback(function (array $e) use (&$captured): void {
            $captured = $e;
        });

        $this->subscriber->onTimelineGenerate($event);

        $this->assertNotNull($captured);
        $this->assertSame('dialoghsm.dispatched', $captured['event']);
        $this->assertSame('ri-send-plane-line', $captured['icon']);
        $this->assertStringContainsString('tpl_mm', $captured['eventLabel']);
    }

    public function testEventLabelFallsBackToTypeNameWhenTemplateNameEmpty(): void
    {
        $this->mockRows([$this->makeRow(MessageLog::STATUS_FAILED, '2026-01-15 10:00:00', ['template_name' => ''])]);

        $event = $this->getMockBuilder(LeadTimelineEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addEventType', 'isApplicable', 'getLeadId', 'getQueryOptions', 'addToCounter', 'isEngagementCount', 'addEvent'])
            ->getMock();
        $event->method('isApplicable')->willReturnCallback(fn ($t) => 'dialoghsm.failed' === $t);
        $event->method('getLeadId')->willReturn(5);
        $event->method('isEngagementCount')->willReturn(false);
        $event->method('getQueryOptions')->willReturn(['paginated' => true, 'limit' => 25, 'start' => 0]);

        $captured = null;
        $event->method('addEvent')->willReturnCallback(function (array $e) use (&$captured): void {
            $captured = $e;
        });

        $this->subscriber->onTimelineGenerate($event);

        $this->assertSame('dialoghsm.log.status.failed', $captured['eventLabel']);
    }
}
