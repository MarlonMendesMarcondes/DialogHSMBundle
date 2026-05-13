<?php

declare(strict_types=1);

use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\EventListener\LeadTimelineSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class LeadTimelineSubscriberTest extends TestCase
{
    private MessageLogRepository&MockObject $repository;
    private TranslatorInterface&MockObject $translator;
    private LeadTimelineSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->repository = $this->getMockBuilder(MessageLogRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnArgument(0);

        $this->subscriber = new LeadTimelineSubscriber($this->repository, $this->translator);
    }

    private function makeEvent(
        bool $applicable = true,
        ?int $leadId = 42,
        bool $engagementCount = false,
        array $stats = ['total' => 0, 'results' => []]
    ): LeadTimelineEvent&MockObject {
        $event = $this->getMockBuilder(LeadTimelineEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addEventType', 'isApplicable', 'getLeadId', 'getQueryOptions', 'addToCounter', 'isEngagementCount', 'addEvent'])
            ->getMock();

        $event->method('isApplicable')->willReturn($applicable);
        $event->method('getLeadId')->willReturn($leadId);
        $event->method('isEngagementCount')->willReturn($engagementCount);
        $event->method('getQueryOptions')->willReturn(['paginated' => true, 'limit' => 25, 'start' => 0]);

        $this->repository->method('getLogsForTimeline')->willReturn($stats);

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
    // onTimelineGenerate — event type registration
    // =========================================================================

    public function testOnTimelineGenerateRegistersAllFiveEventTypes(): void
    {
        $event = $this->makeEvent(applicable: false);

        $expectedTypes = [
            'dialoghsm.sent',
            'dialoghsm.delivered',
            'dialoghsm.read',
            'dialoghsm.failed',
            'dialoghsm.dlq',
        ];

        $event->expects($this->exactly(5))
            ->method('addEventType')
            ->with(
                $this->callback(fn ($key) => in_array($key, $expectedTypes, true)),
                $this->isType('string')
            );

        $this->subscriber->onTimelineGenerate($event);
    }

    // =========================================================================
    // onTimelineGenerate — early-exit paths
    // =========================================================================

    public function testOnTimelineGenerateSkipsQueryWhenNotApplicable(): void
    {
        $event = $this->makeEvent(applicable: false);

        $this->repository->expects($this->never())->method('getLogsForTimeline');

        $this->subscriber->onTimelineGenerate($event);
    }

    public function testOnTimelineGenerateSkipsQueryWhenLeadIdIsNull(): void
    {
        $event = $this->makeEvent(applicable: true, leadId: null);

        $this->repository->expects($this->never())->method('getLogsForTimeline');

        $this->subscriber->onTimelineGenerate($event);
    }

    // =========================================================================
    // onTimelineGenerate — counter
    // =========================================================================

    public function testOnTimelineGenerateAddsToCounterForEachApplicableStatus(): void
    {
        $stats = ['total' => 3, 'results' => []];
        $event = $this->makeEvent(applicable: true, leadId: 42, stats: $stats);

        $event->expects($this->exactly(5))->method('addToCounter');

        $this->subscriber->onTimelineGenerate($event);
    }

    // =========================================================================
    // onTimelineGenerate — engagement count (no event objects added)
    // =========================================================================

    public function testOnTimelineGenerateSkipsAddEventWhenEngagementCount(): void
    {
        $stats = ['total' => 2, 'results' => [
            ['id' => 1, 'template_name' => 'tpl', 'phone_number' => '+55119', 'status' => 'sent',
             'error_message' => null, 'campaign_id' => null, 'sender_name' => 'num', 'date_sent' => new \DateTime()],
        ]];
        $event = $this->makeEvent(applicable: true, leadId: 42, engagementCount: true, stats: $stats);

        $event->expects($this->never())->method('addEvent');

        $this->subscriber->onTimelineGenerate($event);
    }

    // =========================================================================
    // onTimelineGenerate — addEvent structure
    // =========================================================================

    public function testOnTimelineGenerateAddsEventWithCorrectStructure(): void
    {
        $dateSent = new \DateTime('2026-01-15 10:00:00');
        $stats    = ['total' => 1, 'results' => [
            ['id' => 7, 'template_name' => 'first_welcome', 'phone_number' => '+5511999999999',
             'status' => MessageLog::STATUS_SENT, 'error_message' => null,
             'campaign_id' => 3, 'sender_name' => 'Sandbox', 'date_sent' => $dateSent,
             'date_delivered' => null, 'date_read' => null],
        ]];

        $event = $this->getMockBuilder(LeadTimelineEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addEventType', 'isApplicable', 'getLeadId', 'getQueryOptions', 'addToCounter', 'isEngagementCount', 'addEvent'])
            ->getMock();
        $event->method('isApplicable')->willReturnCallback(fn ($type) => 'dialoghsm.sent' === $type);
        $event->method('getLeadId')->willReturn(42);
        $event->method('isEngagementCount')->willReturn(false);
        $event->method('getQueryOptions')->willReturn(['paginated' => true, 'limit' => 25, 'start' => 0]);
        $this->repository->method('getLogsForTimeline')->willReturn($stats);

        $event->expects($this->once())
            ->method('addEvent')
            ->with($this->callback(function (array $e) use ($dateSent): bool {
                return 'dialoghsm.sent' === $e['event']
                    && 'dialoghsm.sent7' === $e['eventId']
                    && 'dialoghsm.log.status.sent — first_welcome' === $e['eventLabel']
                    && $dateSent === $e['timestamp']
                    && 'ri-checkbox-circle-line' === $e['icon']
                    && 42 === $e['contactId']
                    && '@DialogHSM/Timeline/whatsapp_message.html.twig' === $e['contentTemplate'];
            }));

        $this->subscriber->onTimelineGenerate($event);
    }

    public function testOnTimelineGenerateUsesDateDeliveredTimestampForDeliveredStatus(): void
    {
        $dateSent      = new \DateTime('2026-01-15 10:00:00');
        $dateDelivered = new \DateTime('2026-01-15 10:05:00');
        $stats         = ['total' => 1, 'results' => [
            ['id' => 8, 'template_name' => 'welcome', 'phone_number' => '+55119',
             'status' => MessageLog::STATUS_DELIVERED, 'error_message' => null,
             'campaign_id' => null, 'sender_name' => 'num',
             'date_sent' => $dateSent, 'date_delivered' => $dateDelivered, 'date_read' => null],
        ]];

        $event = $this->getMockBuilder(LeadTimelineEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addEventType', 'isApplicable', 'getLeadId', 'getQueryOptions', 'addToCounter', 'isEngagementCount', 'addEvent'])
            ->getMock();
        $event->method('isApplicable')->willReturnCallback(fn ($t) => 'dialoghsm.delivered' === $t);
        $event->method('getLeadId')->willReturn(10);
        $event->method('isEngagementCount')->willReturn(false);
        $event->method('getQueryOptions')->willReturn(['paginated' => true, 'limit' => 25, 'start' => 0]);
        $this->repository->method('getLogsForTimeline')->willReturn($stats);

        $event->expects($this->once())
            ->method('addEvent')
            ->with($this->callback(fn (array $e) => $dateDelivered === $e['timestamp']));

        $this->subscriber->onTimelineGenerate($event);
    }

    public function testOnTimelineGenerateUsesDateReadTimestampForReadStatus(): void
    {
        $dateSent  = new \DateTime('2026-01-15 10:00:00');
        $dateRead  = new \DateTime('2026-01-15 10:10:00');
        $stats     = ['total' => 1, 'results' => [
            ['id' => 9, 'template_name' => 'welcome', 'phone_number' => '+55119',
             'status' => MessageLog::STATUS_READ, 'error_message' => null,
             'campaign_id' => null, 'sender_name' => 'num',
             'date_sent' => $dateSent, 'date_delivered' => null, 'date_read' => $dateRead],
        ]];

        $event = $this->getMockBuilder(LeadTimelineEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addEventType', 'isApplicable', 'getLeadId', 'getQueryOptions', 'addToCounter', 'isEngagementCount', 'addEvent'])
            ->getMock();
        $event->method('isApplicable')->willReturnCallback(fn ($t) => 'dialoghsm.read' === $t);
        $event->method('getLeadId')->willReturn(10);
        $event->method('isEngagementCount')->willReturn(false);
        $event->method('getQueryOptions')->willReturn(['paginated' => true, 'limit' => 25, 'start' => 0]);
        $this->repository->method('getLogsForTimeline')->willReturn($stats);

        $event->expects($this->once())
            ->method('addEvent')
            ->with($this->callback(fn (array $e) => $dateRead === $e['timestamp']));

        $this->subscriber->onTimelineGenerate($event);
    }

    public function testOnTimelineGenerateUsesEventTypeNameWhenTemplateNameEmpty(): void
    {
        $stats = ['total' => 1, 'results' => [
            ['id' => 9, 'template_name' => '', 'phone_number' => '+55119',
             'status' => MessageLog::STATUS_FAILED, 'error_message' => 'timeout',
             'campaign_id' => null, 'sender_name' => 'num', 'date_sent' => new \DateTime()],
        ]];

        $event = $this->getMockBuilder(LeadTimelineEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addEventType', 'isApplicable', 'getLeadId', 'getQueryOptions', 'addToCounter', 'isEngagementCount', 'addEvent'])
            ->getMock();
        $event->method('isApplicable')->willReturnCallback(fn ($t) => 'dialoghsm.failed' === $t);
        $event->method('getLeadId')->willReturn(5);
        $event->method('isEngagementCount')->willReturn(false);
        $event->method('getQueryOptions')->willReturn(['paginated' => true, 'limit' => 25, 'start' => 0]);
        $this->repository->method('getLogsForTimeline')->willReturn($stats);

        $event->expects($this->once())
            ->method('addEvent')
            ->with($this->callback(function (array $e): bool {
                // eventLabel falls back to eventTypeName (translation key returned as-is by mock)
                return 'dialoghsm.log.status.failed' === $e['eventLabel'];
            }));

        $this->subscriber->onTimelineGenerate($event);
    }
}
