<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Pacing;

use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;

/**
 * Ponto de entrada da ação de campanha para "Batch — lotes programados": sorteia o lote do
 * contato e grava a mensagem na fila do MySQL. O envio é feito pelo PacedWorker.
 */
class PacedScheduler
{
    public function __construct(
        private PacedSettings $settings,
        private PacedLotAllocator $allocator,
        private PacedQueue $queue,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->isEnabled();
    }

    /**
     * @param SendWhatsAppMessage $message mensagem com queueLogId (UUID do log 'queued')
     *
     * @return \DateTimeImmutable horário do lote sorteado (UTC)
     */
    public function schedule(SendWhatsAppMessage $message, int $whatsAppNumberId, int $lotSize, int $interval): \DateTimeImmutable
    {
        if (null === $message->campaignEventId || null === $message->queueLogId) {
            throw new \InvalidArgumentException('Lote programado exige ação de campanha e log da fila.');
        }

        $lot = $this->allocator->allocate($message->campaignId, $message->campaignEventId, $lotSize, $interval);

        $this->queue->enqueue($lot['id'], $lot['due_at'], $whatsAppNumberId, $message->queueLogId, self::toPayload($message));

        return $lot['due_at'];
    }

    /**
     * A mensagem sem a chave de API: ela é lida do número na hora do envio.
     *
     * @return array<string, mixed>
     */
    public static function toPayload(SendWhatsAppMessage $message): array
    {
        return [
            'leadId'             => $message->leadId,
            'phone'              => $message->phone,
            'baseUrl'            => $message->baseUrl,
            'payloadData'        => $message->payloadData,
            'templateName'       => $message->templateName,
            'whatsAppNumberName' => $message->whatsAppNumberName,
            'campaignId'         => $message->campaignId,
            'campaignEventId'    => $message->campaignEventId,
            'queueLogId'         => $message->queueLogId,
            'legacy'             => $message->legacy,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload, string $apiKey): SendWhatsAppMessage
    {
        return new SendWhatsAppMessage(
            leadId:             (int) $payload['leadId'],
            phone:              (string) $payload['phone'],
            apiKey:             $apiKey,
            baseUrl:            (string) $payload['baseUrl'],
            payloadData:        (array) $payload['payloadData'],
            templateName:       (string) $payload['templateName'],
            whatsAppNumberName: (string) ($payload['whatsAppNumberName'] ?? ''),
            campaignId:         isset($payload['campaignId']) ? (int) $payload['campaignId'] : null,
            campaignEventId:    isset($payload['campaignEventId']) ? (int) $payload['campaignEventId'] : null,
            queueLogId:         isset($payload['queueLogId']) ? (string) $payload['queueLogId'] : null,
            legacy:             (bool) ($payload['legacy'] ?? false),
        );
    }
}
