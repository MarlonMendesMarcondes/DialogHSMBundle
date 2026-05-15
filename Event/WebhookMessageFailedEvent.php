<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Event;

use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use Symfony\Contracts\EventDispatcher\Event;

class WebhookMessageFailedEvent extends Event
{
    public function __construct(private readonly MessageLog $log) {}

    public function getLog(): MessageLog
    {
        return $this->log;
    }
}
