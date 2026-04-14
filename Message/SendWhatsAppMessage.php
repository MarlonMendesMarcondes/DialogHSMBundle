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
        public readonly string $whatsAppNumberName = '',
        public readonly ?int $campaignId = null,
        public readonly ?int $campaignEventId = null,
        public readonly ?string $queueLogId = null,
        public readonly bool $isBatch = false,
    ) {
    }

    /**
     * Retorna uma cópia do DTO com o queueLogId definido.
     */
    public function withQueueLogId(string $queueLogId): self
    {
        return new self(
            leadId:             $this->leadId,
            phone:              $this->phone,
            apiKey:             $this->apiKey,
            baseUrl:            $this->baseUrl,
            payloadData:        $this->payloadData,
            templateName:       $this->templateName,
            whatsAppNumberName: $this->whatsAppNumberName,
            campaignId:         $this->campaignId,
            campaignEventId:    $this->campaignEventId,
            queueLogId:         $queueLogId,
            isBatch:            $this->isBatch,
        );
    }

    /**
     * Retorna uma cópia do DTO marcada como batch (pula o BulkRateLimiter no handler).
     */
    public function withBatchMode(bool $isBatch = true): self
    {
        return new self(
            leadId:             $this->leadId,
            phone:              $this->phone,
            apiKey:             $this->apiKey,
            baseUrl:            $this->baseUrl,
            payloadData:        $this->payloadData,
            templateName:       $this->templateName,
            whatsAppNumberName: $this->whatsAppNumberName,
            campaignId:         $this->campaignId,
            campaignEventId:    $this->campaignEventId,
            queueLogId:         $this->queueLogId,
            isBatch:            $isBatch,
        );
    }
}
