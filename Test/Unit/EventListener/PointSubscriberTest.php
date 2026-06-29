<?php

declare(strict_types=1);

use Mautic\PointBundle\Event\PointBuilderEvent;
use Mautic\PointBundle\PointEvents;
use MauticPlugin\DialogHSMBundle\EventListener\PointSubscriber;
use PHPUnit\Framework\TestCase;

class PointSubscriberTest extends TestCase
{
    private PointSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new PointSubscriber();
    }

    // =========================================================================
    // getSubscribedEvents
    // =========================================================================

    public function testSubscribesToPointOnBuild(): void
    {
        $events = PointSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(PointEvents::POINT_ON_BUILD, $events);
    }

    public function testPointOnBuildCallsOnPointBuild(): void
    {
        $events = PointSubscriber::getSubscribedEvents();

        $this->assertSame(['onPointBuild', 0], $events[PointEvents::POINT_ON_BUILD]);
    }

    public function testSubscribesOnlyToPointOnBuild(): void
    {
        $events = PointSubscriber::getSubscribedEvents();

        $this->assertCount(1, $events);
    }

    // =========================================================================
    // onPointBuild — registro da ação
    // =========================================================================

    public function testOnPointBuildRegistersTwoActions(): void
    {
        $event = $this->createMock(PointBuilderEvent::class);

        $event->expects($this->exactly(2))->method('addAction');

        $this->subscriber->onPointBuild($event);
    }

    public function testOnPointBuildRegistersMessageReadAction(): void
    {
        $registered = [];

        $event = $this->createMock(PointBuilderEvent::class);
        $event->method('addAction')
            ->willReturnCallback(function (string $key, array $action) use (&$registered): void {
                $registered[$key] = $action;
            });

        $this->subscriber->onPointBuild($event);

        $this->assertArrayHasKey('dialoghsm.message_read', $registered);
    }

    public function testOnPointBuildRegistersMessageRepliedAction(): void
    {
        $registered = [];

        $event = $this->createMock(PointBuilderEvent::class);
        $event->method('addAction')
            ->willReturnCallback(function (string $key, array $action) use (&$registered): void {
                $registered[$key] = $action;
            });

        $this->subscriber->onPointBuild($event);

        $this->assertArrayHasKey('dialoghsm.message_replied', $registered);
    }

    public function testRegisteredActionHasLabelGroupAndCallback(): void
    {
        $captured = null;

        $event = $this->createMock(PointBuilderEvent::class);
        $event->method('addAction')
            ->willReturnCallback(function (string $key, array $action) use (&$captured): void {
                $captured = $action;
            });

        $this->subscriber->onPointBuild($event);

        $this->assertArrayHasKey('group', $captured);
        $this->assertArrayHasKey('label', $captured);
        $this->assertArrayHasKey('callback', $captured);
        $this->assertTrue(is_callable($captured['callback']));
    }

    // =========================================================================
    // validateRead — callback de validação
    // =========================================================================

    public function testValidateReadAlwaysReturnsTrue(): void
    {
        $this->assertTrue(PointSubscriber::validateRead(null, []));
    }

    public function testValidateReadReturnsTrueWithEventDetails(): void
    {
        $this->assertTrue(PointSubscriber::validateRead(new \stdClass(), ['properties' => []]));
    }

    public function testValidateReadIsStatic(): void
    {
        $reflection = new \ReflectionMethod(PointSubscriber::class, 'validateRead');

        $this->assertTrue($reflection->isStatic());
    }

    // =========================================================================
    // validateReplied — callback de validação
    // =========================================================================

    public function testValidateRepliedAlwaysReturnsTrue(): void
    {
        $this->assertTrue(PointSubscriber::validateReplied(null, []));
    }

    public function testValidateRepliedIsStatic(): void
    {
        $reflection = new \ReflectionMethod(PointSubscriber::class, 'validateReplied');

        $this->assertTrue($reflection->isStatic());
    }
}
