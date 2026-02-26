<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Message;

class SendWhatsAppMessage
{
    public function __construct(
        public readonly int $leadId,
        public readonly string $phone,
        public readonly string $apiKey,
        public readonly string $baseUrl,
        public readonly array $payloadData,
        public readonly string $templateName,
    ) {
    }
}
