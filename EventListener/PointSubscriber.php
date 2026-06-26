<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Mautic\PointBundle\Event\PointBuilderEvent;
use Mautic\PointBundle\PointEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PointSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PointEvents::POINT_ON_BUILD => ['onPointBuild', 0],
        ];
    }

    public function onPointBuild(PointBuilderEvent $event): void
    {
        $event->addAction('dialoghsm.message_read', [
            'group'    => 'dialoghsm.point.group',
            'label'    => 'dialoghsm.point.action.message_read',
            'callback' => [self::class, 'validateRead'],
        ]);

        $event->addAction('dialoghsm.message_replied', [
            'group'    => 'dialoghsm.point.group',
            'label'    => 'dialoghsm.point.action.message_replied',
            'callback' => [self::class, 'validateReplied'],
        ]);
    }

    public static function validateRead(mixed $eventDetails, array $action): bool
    {
        return true;
    }

    public static function validateReplied(mixed $eventDetails, array $action): bool
    {
        return true;
    }
}
