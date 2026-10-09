<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMApi;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\Exception\TransientApiException;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Service\BulkRateLimiter;
use MauticPlugin\DialogHSMBundle\Service\CampaignActivityChecker;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use MauticPlugin\DialogHSMBundle\Service\RedisContactCache;
use MauticPlugin\DialogHSMBundle\Service\WorkerQueueContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
class SendWhatsAppMessageHandler
{
    private const CACHE_TTL_SECONDS   = 30;
    private const DEFAULT_MAX_RECORDS = 0;      // 0 = sem limite por contagem (padrão)
    private const DEFAULT_MAX_DAYS    = 30;

    /**
     * Quanto tempo a mensagem de campanha desativada espera na fila de atraso do RabbitMQ
     * antes de voltar para a fila e a campanha ser verificada de novo. É também o atraso
     * máximo para o envio recomeçar depois que a campanha é reativada.
     */
    private const INACTIVE_CAMPAIGN_RECHECK_MS = 300000;

    /**
     * Intervalo mínimo entre limpezas da tabela de logs no mesmo processo. O worker da fila
     * processa centenas de mensagens por execução: limpar a cada uma pesaria no banco.
     */
    private const PRUNE_INTERVAL_SECONDS = 300;

    private float $lastPruneAt = 0.0;

    private int   $cachedMaxRecords = -1;
    private int   $cachedMaxDays    = -1;
    private float $cacheExp         = 0.0;

    public function __construct(
        private DialogHSMApi $api,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private LeadModel $leadModel,
        private MessageLogRepository $messageLogRepository,
        private BulkRateLimiter $rateLimiter,
        private IntegrationsHelper $integrationsHelper,
        private RedisContactCache $contactCache,
        private WhatsAppNumberRepository $whatsAppNumberRepository,
        private LeadEventLogWriter $leadEventLogWriter,
        private CampaignActivityChecker $campaignActivityChecker,
        private WorkerQueueContext $queueContext,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @param bool $skipHousekeeping Quando true, omite o prune() pós-persistência.
     *                               Use em processamento de lote — o caller é responsável
     *                               por chamar prune() uma única vez ao final do lote.
     * @param bool $skipRateLimit    Quando true, omite o throttle() do BulkRateLimiter.
     *                               Use para envios batch — o throttle do lote é feito via
     *                               sendDelay em SendWhatsAppDirectBatchMessageHandler.
     * @param bool $skipRetry        Quando true, nunca lança TransientApiException mesmo em
     *                               erros transitórios — registra como 'failed' e retorna.
     *                               Use em contexto de lote onde o retry individual não é suportado.
     *
     * @return array{success: bool, response: array|null, error: string|null, http_status: int|null, retryable: bool, paused?: bool}
     *
     * @throws TransientApiException quando o erro é transitório (rede, rate limit, 5xx) e $skipRetry=false,
     *                               para que o Symfony Messenger aplique a retry strategy configurada.
     */
    public function __invoke(SendWhatsAppMessage $message, bool $skipHousekeeping = false, bool $skipRateLimit = false, bool $skipRetry = false): array
    {
        // Idempotency guard: se já existe log de envio bem-sucedido recente para este evento de
        // campanha, não reenvia. Protege contra reentrega de batch (worker crash → batch reenviado
        // do zero), que ocorre dentro de segundos/minutos.
        // A janela de 1 hora permite re-enrollment legítimo (gap típico: horas ou dias) enquanto
        // bloqueia retries imediatos causados por crash de worker.
        // Só aplica quando campaignEventId está presente; envios sem contexto de campanha passam.
        if ($message->campaignEventId !== null) {
            $existing = $this->messageLogRepository->findByCampaignEventAndLead($message->campaignEventId, $message->leadId);
            if ($existing !== null && in_array($existing->getStatus(), [
                MessageLog::STATUS_PENDING_WEBHOOK,
                MessageLog::STATUS_SENT,
                MessageLog::STATUS_DELIVERED,
                MessageLog::STATUS_READ,
            ], true)) {
                $sentAt    = $existing->getDateSent();
                $ageInSecs = $sentAt !== null ? (time() - $sentAt->getTimestamp()) : 0;

                if ($ageInSecs <= 3600) {
                    $this->logger->info('DialogHSM: envio ignorado — mensagem já processada para este evento de campanha', [
                        'lead_id'           => $message->leadId,
                        'campaign_event_id' => $message->campaignEventId,
                        'existing_status'   => $existing->getStatus(),
                        'existing_wamid'    => $existing->getWamid(),
                        'age_seconds'       => $ageInSecs,
                    ]);

                    return ['success' => true, 'response' => null, 'error' => null, 'http_status' => null, 'retryable' => false];
                }
            }
        }

        // Log 'queued' apagado com a mensagem ainda na fila ("Limpar mensagens pendentes"): o
        // envio foi cancelado. Todo caminho que usa queueLogId grava o log antes de publicar a
        // mensagem, então log ausente nunca é corrida com o dispatch.
        if (null !== $message->queueLogId && null === $this->findQueueLog($message->queueLogId)) {
            $this->logger->info('DialogHSM: log da fila removido — envio cancelado', [
                'lead_id'      => $message->leadId,
                'queue_log_id' => $message->queueLogId,
            ]);

            return ['success' => false, 'cancelled' => true, 'response' => null, 'error' => 'Envio cancelado', 'http_status' => null, 'retryable' => false];
        }

        // Campanha desativada (ou fora do período) com a mensagem já na fila: não envia e devolve
        // a mensagem ao RabbitMQ, para sair quando a campanha for reativada. Só no consumo da
        // fila (skipRetry=false) — o envio direto roda dentro do próprio trigger da campanha.
        if (!$skipRetry && null !== $message->campaignId) {
            $campaignStatus = $this->campaignActivityChecker->getStatus($message->campaignId);

            if (CampaignActivityChecker::INACTIVE === $campaignStatus) {
                $this->requeueForInactiveCampaign($message);

                return ['success' => false, 'paused' => true, 'response' => null, 'error' => null, 'http_status' => null, 'retryable' => false];
            }

            // Campanha removida nunca volta: devolver à fila deixaria a mensagem circulando
            // para sempre. Descarta e registra o motivo.
            if (CampaignActivityChecker::DELETED === $campaignStatus) {
                $result = ['success' => false, 'response' => null, 'error' => 'Campanha removida', 'http_status' => null, 'wamid' => null, 'retryable' => false];

                try {
                    $this->logMessage($message->leadId, $message->templateName, $message->phone, $message->whatsAppNumberName, $result, $message->campaignId, $message->campaignEventId, $message->queueLogId, $skipHousekeeping);
                } catch (\Throwable $e) {
                    $this->logger->warning('DialogHSM: Falha ao registrar log da mensagem', [
                        'lead_id' => $message->leadId,
                        'error'   => $e->getMessage(),
                    ]);
                }

                return $result;
            }
        }

        // Número desativado (isPublished=false) após a mensagem já estar na fila: ignora
        // silenciosamente em vez de chamar a API 360dialog e registrar erro. Cobre o caso de
        // workers de fila já em execução (dialog:consume) que não sabem que o número foi
        // desativado depois de iniciados.
        if ('' !== $message->whatsAppNumberName) {
            $number = $this->whatsAppNumberRepository->findOneBy(['name' => $message->whatsAppNumberName]);

            if (null === $number || !$number->getIsPublished()) {
                $this->logger->info('DialogHSM: número desativado — envio ignorado', [
                    'lead_id'            => $message->leadId,
                    'whats_app_number'   => $message->whatsAppNumberName,
                ]);

                $result = ['success' => false, 'response' => null, 'error' => 'Número WhatsApp desativado', 'http_status' => null, 'wamid' => null, 'retryable' => false];

                try {
                    $this->logMessage($message->leadId, $message->templateName, $message->phone, $message->whatsAppNumberName, $result, $message->campaignId, $message->campaignEventId, $message->queueLogId, $skipHousekeeping);
                } catch (\Throwable $e) {
                    $this->logger->warning('DialogHSM: Falha ao registrar log da mensagem', [
                        'lead_id' => $message->leadId,
                        'error'   => $e->getMessage(),
                    ]);
                }

                return $result;
            }
        }

        if (!$skipRateLimit) {
            if ($message->isBatch) {
                // Rate controlado por batch_rate_per_minute nas configurações do plugin
                $this->rateLimiter->throttleBatch($message->whatsAppNumberName);
            } else {
                // Rate controlado por bulk_rate_per_minute nas configurações do plugin
                $this->rateLimiter->throttle($message->whatsAppNumberName);
            }
        }

        $payloadData = $message->payloadData;
        if (empty($payloadData['content']) && !empty($message->templateName)) {
            $payloadData['content'] = $message->templateName;
        }

        $result = $this->api->sendMessage(
            $message->apiKey,
            $message->baseUrl,
            $message->phone,
            $payloadData
        );

        // Erro transitório (rede, 429, 5xx): lança exceção para acionar retry do Messenger.
        // O log será gravado pelo MessengerFailedEventSubscriber após esgotar os retries (DLQ),
        // ou naturalmente quando a tentativa de retry obtiver sucesso.
        // Em contexto de lote (skipRetry=true) não é possível fazer retry individual, então
        // registra como 'failed' normalmente.
        if (!$result['success'] && !$skipRetry && ($result['retryable'] ?? false)) {
            $this->logger->warning('DialogHSM: Erro transitório, agendando retry', [
                'lead_id'     => $message->leadId,
                'http_status' => $result['http_status'],
                'error'       => $result['error'],
            ]);

            throw new TransientApiException($result['error'] ?? 'Erro transitório na API 360dialog');
        }

        try {
            $this->logMessage($message->leadId, $message->templateName, $message->phone, $message->whatsAppNumberName, $result, $message->campaignId, $message->campaignEventId, $message->queueLogId, $skipHousekeeping);
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: Falha ao registrar log da mensagem', [
                'lead_id' => $message->leadId,
                'error'   => $e->getMessage(),
            ]);
        }

        // Grava phone → wamid no Redis para correlação de resposta no webhook (Scenario B)
        if ($result['success'] && !empty($result['wamid'])) {
            $this->contactCache->setLastSent($message->phone, $result['wamid']);
        }

        $this->updateContactFields($message->leadId, $result);

        return $result;
    }

    /**
     * Publica a mensagem de novo na mesma fila, com atraso, e deixa o Messenger confirmar
     * (ack) a original. Sem a fila de origem, recorre ao retry do Messenger, que preserva o
     * routing key — a mensagem continua no RabbitMQ de qualquer forma.
     */
    private function requeueForInactiveCampaign(SendWhatsAppMessage $message): void
    {
        $queueName = $this->queueContext->getCurrentQueueName();

        if (null === $queueName || '' === $queueName) {
            throw new RecoverableMessageHandlingException(sprintf('Campanha #%d desativada — mensagem mantida na fila', $message->campaignId));
        }

        $this->bus->dispatch($message, [
            new AmqpStamp($queueName),
            new DelayStamp(self::INACTIVE_CAMPAIGN_RECHECK_MS),
        ]);

        $this->logger->info('DialogHSM: campanha desativada — mensagem devolvida à fila', [
            'lead_id'     => $message->leadId,
            'campaign_id' => $message->campaignId,
            'queue'       => $queueName,
        ]);
    }

    /**
     * Log 'queued' gravado no dispatch: CampaignSubscriber usa UUID (no wamid); Marketing
     * Messages e broadcast usam o id numérico do log.
     */
    private function findQueueLog(string $queueLogId): ?MessageLog
    {
        return ctype_digit($queueLogId)
            ? $this->messageLogRepository->find((int) $queueLogId)
            : $this->messageLogRepository->findByWamid($queueLogId);
    }

    private function logMessage(int $leadId, string $templateName, string $phone, string $senderName, array $result, ?int $campaignId = null, ?int $campaignEventId = null, ?string $queueLogId = null, bool $skipHousekeeping = false): void
    {
        // Se existe log queued criado no dispatch, atualiza-o em vez de criar novo.
        $log = null !== $queueLogId ? $this->findQueueLog($queueLogId) : null;

        if ($log === null) {
            $log = new MessageLog();
            $log->setLeadId($leadId);
            $log->setCampaignId($campaignId);
            $log->setCampaignEventId($campaignEventId);
            $log->setSenderName($senderName ?: null);
            $log->setTemplateName($templateName);
            $log->setPhoneNumber($phone);
        }

        $log->setWamid($result['wamid'] ?? null);
        // pending_webhook = 360dialog aceitou (HTTP 200); a Meta ainda não confirmou.
        // failed aqui = erro de API (HTTP 4xx/5xx); rejeição da Meta vem via webhook.
        $log->setStatus($result['success'] ? MessageLog::STATUS_PENDING_WEBHOOK : MessageLog::STATUS_FAILED);
        $log->setHttpStatusCode($result['http_status'] ?? null);
        $log->setApiResponse(!empty($result['response']) ? json_encode($result['response']) : null);
        $log->setErrorMessage(!empty($result['error']) ? '[API 360dialog] '.$result['error'] : null);
        $log->setDateSent(new \DateTime());

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        try {
            $this->leadEventLogWriter->write(
                $log,
                $result['success'] ? MessageLog::STATUS_SENT : MessageLog::STATUS_FAILED,
                $log->getDateSent() ?? new \DateTime()
            );
        } catch (\Throwable) {
            // falha silenciosa — não interrompe o registro do envio
        }

        // Vale também para o consumo da fila (queueLogId preenchido): antes só o envio direto e
        // o broadcast limpavam, e numa instalação só com fila log_max_days era ignorado.
        if (!$skipHousekeeping && microtime(true) - $this->lastPruneAt >= self::PRUNE_INTERVAL_SECONDS) {
            $this->lastPruneAt = microtime(true);
            $this->messageLogRepository->prune($this->getLogMaxRecords(), $this->getLogMaxDays());
        }
    }

    public function getLogMaxRecords(): int
    {
        $this->refreshSettingsCache();

        return $this->cachedMaxRecords;
    }

    public function getLogMaxDays(): int
    {
        $this->refreshSettingsCache();

        return $this->cachedMaxDays;
    }

    private function refreshSettingsCache(): void
    {
        $now = microtime(true);
        if ($this->cachedMaxRecords >= 0 && $now < $this->cacheExp) {
            return;
        }

        try {
            $integration            = $this->integrationsHelper->getIntegration(DialogHSMIntegration::NAME);
            $apiKeys                = $integration->getIntegrationConfiguration()->getApiKeys() ?? [];
            $this->cachedMaxRecords = max(0, (int) ($apiKeys['log_max_records'] ?? self::DEFAULT_MAX_RECORDS));
            $this->cachedMaxDays    = max(0, (int) ($apiKeys['log_max_days']    ?? self::DEFAULT_MAX_DAYS));
        } catch (\Throwable) {
            $this->cachedMaxRecords = self::DEFAULT_MAX_RECORDS;
            $this->cachedMaxDays    = self::DEFAULT_MAX_DAYS;
        }

        $this->cacheExp = $now + self::CACHE_TTL_SECONDS;
    }

    private function updateContactFields(int $leadId, array $result): void
    {
        try {
            $lead = $this->leadModel->getEntity($leadId);
            if (null === $lead) {
                return;
            }

            $httpStatus   = $result['http_status'] ?? 'N/A';
            $statusText   = $result['success'] ? 'pending_webhook' : 'failed_api';
            $lastResponse = $result['success']
                ? "HTTP {$httpStatus}: OK"
                : mb_substr($result['error'] ?? '', 0, 255);

            $this->leadModel->setFieldValues($lead, [
                'dialoghsm_status'        => $statusText,
                'dialoghsm_last_response' => $lastResponse,
                'dialoghsm_last_sent'     => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
            $this->leadModel->saveEntity($lead);
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: Failed to update contact custom fields', [
                'lead_id' => $leadId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
