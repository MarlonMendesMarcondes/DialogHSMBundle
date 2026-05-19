<?php

declare(strict_types=1);

use Mautic\ChannelBundle\ChannelEvents;
use Mautic\ChannelBundle\Event\ChannelBroadcastEvent;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessageRepository;
use MauticPlugin\DialogHSMBundle\EventListener\BroadcastSubscriber;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppMessageModel;
use PHPUnit\Framework\TestCase;

class BroadcastSubscriberTest extends TestCase
{
    private function makeSubscriber(WhatsAppMessageModel $model): BroadcastSubscriber
    {
        return new BroadcastSubscriber($model);
    }

    /**
     * Build a ChannelBroadcastEvent mock with checkContext and getId stubs.
     *
     * @param bool     $contextMatches Return value for checkContext('whatsapp')
     * @param int|null $id             Return value for getId()
     */
    private function makeEvent(bool $contextMatches, ?int $id = null): ChannelBroadcastEvent
    {
        $event = $this->createMock(ChannelBroadcastEvent::class);
        $event
            ->method('checkContext')
            ->with('whatsapp')
            ->willReturn($contextMatches);
        $event
            ->method('getId')
            ->willReturn($id);

        return $event;
    }

    /**
     * Build a WhatsAppMessageModel mock with a repository that returns the given iterable.
     *
     * @param iterable<WhatsAppMessage> $messages
     */
    private function makeModel(iterable $messages, int $expectedSendCalls = 0): WhatsAppMessageModel
    {
        $repo = $this->createMock(WhatsAppMessageRepository::class);
        $repo
            ->method('getPublishedBroadcastsIterable')
            ->willReturn($messages);

        $model = $this->createMock(WhatsAppMessageModel::class);
        $model
            ->method('getRepository')
            ->willReturn($repo);

        if ($expectedSendCalls === 0) {
            $model
                ->expects($this->never())
                ->method('sendToLists');
        } else {
            $model
                ->expects($this->exactly($expectedSendCalls))
                ->method('sendToLists')
                ->willReturn([1, 0]);
        }

        return $model;
    }

    // -------------------------------------------------------------------------
    // getSubscribedEvents
    // -------------------------------------------------------------------------

    public function testGetSubscribedEventsContainsBroadcastKey(): void
    {
        $events = BroadcastSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(ChannelEvents::CHANNEL_BROADCAST, $events);
        $this->assertEquals(['onBroadcast', 0], $events[ChannelEvents::CHANNEL_BROADCAST]);
    }

    // -------------------------------------------------------------------------
    // onBroadcast — context guard
    // -------------------------------------------------------------------------

    public function testOnBroadcastDoesNotSendWhenContextIsNotWhatsApp(): void
    {
        $model = $this->makeModel([], expectedSendCalls: 0);
        // Repo must not even be called when context fails
        $model
            ->expects($this->never())
            ->method('getRepository');

        $event = $this->makeEvent(contextMatches: false);

        $this->makeSubscriber($model)->onBroadcast($event);
    }

    // -------------------------------------------------------------------------
    // onBroadcast — empty iterable
    // -------------------------------------------------------------------------

    public function testOnBroadcastDoesNotSendWhenIterableIsEmpty(): void
    {
        $model = $this->makeModel(messages: [], expectedSendCalls: 0);

        $event = $this->makeEvent(contextMatches: true, id: 1);

        $this->makeSubscriber($model)->onBroadcast($event);
    }

    // -------------------------------------------------------------------------
    // onBroadcast — single message
    // -------------------------------------------------------------------------

    public function testOnBroadcastSendsOnceAndSetsResultsForOneMessage(): void
    {
        $message = $this->createMock(WhatsAppMessage::class);
        $message
            ->method('getName')
            ->willReturn('Template A');

        $model = $this->makeModel(messages: [$message], expectedSendCalls: 1);

        $event = $this->makeEvent(contextMatches: true, id: 1);
        $event
            ->expects($this->once())
            ->method('setResults')
            ->with(
                $this->stringStartsWith('WhatsApp: '),
                $this->identicalTo(1),
                $this->identicalTo(0)
            );

        $this->makeSubscriber($model)->onBroadcast($event);
    }

    // -------------------------------------------------------------------------
    // onBroadcast — two messages
    // -------------------------------------------------------------------------

    public function testOnBroadcastSendsTwiceAndSetsResultsTwiceForTwoMessages(): void
    {
        $messageA = $this->createMock(WhatsAppMessage::class);
        $messageA->method('getName')->willReturn('Template A');

        $messageB = $this->createMock(WhatsAppMessage::class);
        $messageB->method('getName')->willReturn('Template B');

        $model = $this->makeModel(messages: [$messageA, $messageB], expectedSendCalls: 2);

        $event = $this->makeEvent(contextMatches: true, id: 2);
        $event
            ->expects($this->exactly(2))
            ->method('setResults')
            ->with(
                $this->stringStartsWith('WhatsApp: '),
                $this->identicalTo(1),
                $this->identicalTo(0)
            );

        $this->makeSubscriber($model)->onBroadcast($event);
    }
}
