<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMApi;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Exception\TransientApiException;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Service\BulkRateLimiter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class SendWhatsAppMessageHandler implements MessageHandlerInterface
{
    private const CACHE_TTL_SECONDS   = 30;
    private const DEFAULT_MAX_RECORDS = 0;      // 0 = sem limite por contagem (padrão)
    private const DEFAULT_MAX_DAYS    = 30;

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
     * @return array{success: bool, response: array|null, error: string|null, http_status: int|null, retryable: bool}
     *
     * @throws TransientApiException quando o erro é transitório (rede, rate limit, 5xx) e $skipRetry=false,
     *                               para que o Symfony Messenger aplique a retry strategy configurada.
     */
    public function __invoke(SendWhatsAppMessage $message, bool $skipHousekeeping = false, bool $skipRateLimit = false, bool $skipRetry = false): array
    {
        if (!$skipRateLimit) {
            $this->rateLimiter->throttle($message->whatsAppNumberName);
        }

        $result = $this->api->sendMessage(
            $message->apiKey,
            $message->baseUrl,
            $message->phone,
            $message->payloadData
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

        $this->updateContactFields($message->leadId, $result);

        return $result;
    }

    private function logMessage(int $leadId, string $templateName, string $phone, string $senderName, array $result, ?int $campaignId = null, ?int $campaignEventId = null, ?string $queueLogId = null, bool $skipHousekeeping = false): void
    {
        // Se existe log queued criado no dispatch, atualiza-o em vez de criar novo
        $log = $queueLogId !== null ? $this->messageLogRepository->findByWamid($queueLogId) : null;

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
        $log->setStatus($result['success'] ? MessageLog::STATUS_SENT : MessageLog::STATUS_FAILED);
        $log->setHttpStatusCode($result['http_status'] ?? null);
        $log->setApiResponse(!empty($result['response']) ? json_encode($result['response']) : null);
        $log->setErrorMessage($result['error'] ?? null);
        $log->setDateSent(new \DateTime());

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        if (!$skipHousekeeping && $queueLogId === null) {
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
            $statusText   = $result['success'] ? "sent (HTTP {$httpStatus})" : "failed (HTTP {$httpStatus})";
            $lastResponse = $result['success'] ? 'OK' : mb_substr($result['error'] ?? '', 0, 255);

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
