<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Message;

/**
 * legacy=true: veio das ações de campanha "(legado)" — envio como antes, sem a pré-validação do
 * template e sem a pausa por bloqueio de pagamento. As ações novas, Marketing Messages e
 * broadcast criam a mensagem com legacy=false.
 */
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
        public readonly bool $legacy = false,
    ) {
    }

    /**
     * Garante compatibilidade com mensagens serializadas antes da adição de novas propriedades.
     * Sem este método, propriedades ausentes nos dados serializados ficam não-inicializadas
     * e lançam TypeError ao serem acessadas (ex.: $isBatch adicionado após envios na fila).
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->leadId             = $data['leadId'];
        $this->phone              = $data['phone'];
        $this->apiKey             = $data['apiKey'];
        $this->baseUrl            = $data['baseUrl'];
        $this->payloadData        = $data['payloadData'];
        $this->templateName       = $data['templateName'];
        $this->whatsAppNumberName = $data['whatsAppNumberName'] ?? '';
        $this->campaignId         = $data['campaignId']         ?? null;
        $this->campaignEventId    = $data['campaignEventId']    ?? null;
        $this->queueLogId         = $data['queueLogId']         ?? null;
        $this->isBatch            = $data['isBatch']            ?? false;
        // Mensagem serializada antes de existir a versão nova das ações veio do comportamento
        // anterior: continua sem pré-validação e sem pausa até sair da fila.
        $this->legacy             = $data['legacy']             ?? true;
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
            legacy:             $this->legacy,
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
            legacy:             $this->legacy,
        );
    }
}
