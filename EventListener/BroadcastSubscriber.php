<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Mautic\ChannelBundle\ChannelEvents;
use Mautic\ChannelBundle\Event\ChannelBroadcastEvent;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppMessageModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class BroadcastSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WhatsAppMessageModel $broadcastModel,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelEvents::CHANNEL_BROADCAST => ['onBroadcast', 0],
        ];
    }

    public function onBroadcast(ChannelBroadcastEvent $event): void
    {
        if (!$event->checkContext('whatsapp')) {
            return;
        }

        $messages = $this->broadcastModel->getRepository()->getPublishedBroadcastsIterable($event->getId());

        foreach ($messages as $message) {
            [$sent, $failed] = $this->broadcastModel->sendToLists($message, $event);
            $event->setResults(
                sprintf('WhatsApp: %s', $message->getName()),
                $sent,
                $failed
            );
        }
    }
}
