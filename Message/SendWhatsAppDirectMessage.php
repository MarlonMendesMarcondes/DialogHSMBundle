<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Message;

/**
 * Mensagem roteada para o transporte whatsapp_direct (Redis).
 * Usada no envio direto quando Redis está configurado.
 *
 * $rateDelaySeconds: pausa em segundos que o worker deve aguardar após processar esta mensagem.
 * Calculado pelo subscriber a partir de send_delay e batch_limit do formulário da campanha.
 * Exemplo: send_delay=2s, batch_limit=10 → rateDelaySeconds=0.2
 */
class SendWhatsAppDirectMessage extends SendWhatsAppMessage
{
    public function __construct(
        int $leadId,
        string $phone,
        string $apiKey,
        string $baseUrl,
        array $payloadData,
        string $templateName,
        string $whatsAppNumberName = '',
        ?int $campaignId = null,
        ?int $campaignEventId = null,
        public readonly float $rateDelaySeconds = 0.0,
    ) {
        parent::__construct($leadId, $phone, $apiKey, $baseUrl, $payloadData, $templateName, $whatsAppNumberName, $campaignId, $campaignEventId);
    }
}
