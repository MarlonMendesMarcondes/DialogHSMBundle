<?php

declare(strict_types=1);

use Mautic\ChannelBundle\ChannelEvents;
use Mautic\ChannelBundle\Event\ChannelEvent;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\DialogHSMBundle\EventListener\ChannelSubscriber;
use PHPUnit\Framework\TestCase;

class ChannelSubscriberTest extends TestCase
{
    private function makeSubscriber(IntegrationHelper $helper): ChannelSubscriber
    {
        return new ChannelSubscriber($helper);
    }

    public function testGetSubscribedEventsContainsAddChannelKey(): void
    {
        $events = ChannelSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(ChannelEvents::ADD_CHANNEL, $events);
        $this->assertEquals(['onAddChannel', 90], $events[ChannelEvents::ADD_CHANNEL]);
    }

    public function testOnAddChannelDoesNotCallAddChannelWhenIntegrationIsNull(): void
    {
        $helper = $this->createMock(IntegrationHelper::class);
        $helper
            ->expects($this->once())
            ->method('getIntegrationObject')
            ->with('DialogHSM')
            ->willReturn(null);

        $channelEvent = $this->createMock(ChannelEvent::class);
        $channelEvent
            ->expects($this->never())
            ->method('addChannel');

        $this->makeSubscriber($helper)->onAddChannel($channelEvent);
    }

    public function testOnAddChannelDoesNotCallAddChannelWhenIntegrationIsNotPublished(): void
    {
        $integrationSettings = $this->createMock(Integration::class);
        $integrationSettings
            ->method('getIsPublished')
            ->willReturn(false);

        $integration = $this->createMock(AbstractIntegration::class);
        $integration
            ->method('getIntegrationSettings')
            ->willReturn($integrationSettings);

        $helper = $this->createMock(IntegrationHelper::class);
        $helper
            ->expects($this->once())
            ->method('getIntegrationObject')
            ->with('DialogHSM')
            ->willReturn($integration);

        $channelEvent = $this->createMock(ChannelEvent::class);
        $channelEvent
            ->expects($this->never())
            ->method('addChannel');

        $this->makeSubscriber($helper)->onAddChannel($channelEvent);
    }

    public function testOnAddChannelCallsAddChannelWithWhatsAppWhenIntegrationIsPublished(): void
    {
        $integrationSettings = $this->createMock(Integration::class);
        $integrationSettings
            ->method('getIsPublished')
            ->willReturn(true);

        $integration = $this->createMock(AbstractIntegration::class);
        $integration
            ->method('getIntegrationSettings')
            ->willReturn($integrationSettings);

        $helper = $this->createMock(IntegrationHelper::class);
        $helper
            ->expects($this->once())
            ->method('getIntegrationObject')
            ->with('DialogHSM')
            ->willReturn($integration);

        $channelEvent = $this->createMock(ChannelEvent::class);
        $channelEvent
            ->expects($this->once())
            ->method('addChannel')
            ->with(
                $this->identicalTo('whatsapp'),
                $this->isType('array')
            );

        $this->makeSubscriber($helper)->onAddChannel($channelEvent);
    }
}
