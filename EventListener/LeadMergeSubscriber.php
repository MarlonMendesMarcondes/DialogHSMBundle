<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Mautic\LeadBundle\Event\LeadMergeEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class LeadMergeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MessageLogRepository $messageLogRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LEAD_POST_MERGE => ['onLeadMerge', 0],
        ];
    }

    public function onLeadMerge(LeadMergeEvent $event): void
    {
        $this->messageLogRepository->updateLead(
            $event->getLoser()->getId(),
            $event->getVictor()->getId()
        );
    }
}
