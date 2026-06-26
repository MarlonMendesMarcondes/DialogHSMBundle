<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PointBundle\Model\PointModel;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\Event\WebhookMessageFailedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class WebhookProcessor
{
    private const REPLIED_CACHE_TTL    = 86_400;
    private const REPLIED_CACHE_PREFIX = 'dialoghsm:replied:';

    private ?\Redis $redis = null;

    public function __construct(
        private readonly WhatsAppNumberRepository $numberRepository,
        private readonly MessageLogRepository $logRepository,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LeadModel $leadModel,
        private readonly LeadEventLogWriter $eventLogWriter,
        private readonly PointModel $pointModel,
        private readonly RedisContactCache $contactCache,
        private readonly string $redisDsn = '',
        private readonly ?\Redis $redisOverride = null,
    ) {}

    /**
     * @param array<mixed> $payload
     *
     * @return int 200 em sucesso, 404 se o número não existir
     */
    public function process(string $phoneNumber, array $payload): int
    {
        $number = $this->numberRepository->findByPhoneNumber($phoneNumber);
        if (!$number) {
            return 404;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $statusData) {
                    $this->processStatus($statusData);
                }
                foreach ($change['value']['messages'] ?? [] as $messageData) {
                    $this->processInbound($messageData);
                }
            }
        }

        return 200;
    }

    /**
     * @param array<string, mixed> $statusData
     */
    private function processStatus(array $statusData): void
    {
        $wamid  = $statusData['id'] ?? '';
        $status = $statusData['status'] ?? '';

        $allowed = [
            MessageLog::STATUS_SENT,
            MessageLog::STATUS_DELIVERED,
            MessageLog::STATUS_READ,
            MessageLog::STATUS_FAILED,
        ];
        if (!$wamid || !in_array($status, $allowed, true)) {
            return;
        }

        $log = $this->logRepository->findByWamid($wamid);
        if (!$log || !$this->isValidTransition($log->getStatus(), $status)) {
            return;
        }

        $log->setStatus($status);

        $now = new \DateTime();
        if (MessageLog::STATUS_DELIVERED === $status) {
            $log->setDateDelivered($now);
        } elseif (MessageLog::STATUS_READ === $status) {
            $log->setDateRead($now);
        } elseif (MessageLog::STATUS_FAILED === $status) {
            // failed via webhook = rejeição da Meta (ex: janela 24h, número inválido).
            // Distinto de failed por erro de API (que não tem webhookErrorCode).
            $errors = $statusData['errors'] ?? [];
            if (!empty($errors)) {
                $first = $errors[0];
                $log->setWebhookErrorCode((int) ($first['code'] ?? 0) ?: null);
                $log->setErrorMessage($first['title'] ?? null);
            }
        }

        $this->em->persist($log);
        $this->em->flush();

        $eventDate = match ($status) {
            MessageLog::STATUS_DELIVERED => $log->getDateDelivered() ?? $now,
            MessageLog::STATUS_READ      => $log->getDateRead()      ?? $now,
            // Para "sent": usa date_sent gravado pelo worker no momento exato do API call.
            // Garante que "Enviada" sempre precede "Entregue" no timeline, independente de
            // quando o campaign_lead_event_log.date_triggered foi commitado.
            MessageLog::STATUS_SENT      => $log->getDateSent() ?? $now,
            default                      => $log->getDateSent() ?? $now,
        };

        try {
            $this->eventLogWriter->write($log, $status, $eventDate);
        } catch (\Throwable) {
            // falha silenciosa — não interrompe o fluxo do webhook
        }

        $this->updateCampaignActionMetadata($log);
        $this->updateLeadStatus($log, $status);
        $this->triggerPointAction($log, $status);

        if (MessageLog::STATUS_FAILED === $status) {
            $this->dispatcher->dispatch(new WebhookMessageFailedEvent($log));
        }
    }

    private function updateLeadStatus(MessageLog $log, string $status): void
    {
        $leadId = $log->getLeadId();
        if (!$leadId) {
            return;
        }

        try {
            $lead = $this->leadModel->getEntity($leadId);
            if (null === $lead) {
                return;
            }

            if (MessageLog::STATUS_FAILED === $status) {
                $code         = $log->getWebhookErrorCode();
                $message      = $log->getErrorMessage() ?? '';
                $lastResponse = $code ? "[Meta {$code}] {$message}" : $message;

                $this->leadModel->setFieldValues($lead, [
                    'dialoghsm_status'          => 'failed_meta',
                    'dialoghsm_last_response'   => mb_substr($lastResponse, 0, 255),
                    'dialoghsm_meta_error_code' => $code,
                ]);
            } else {
                // sent, delivered, read — valor do select coincide com o status do webhook
                $this->leadModel->setFieldValues($lead, [
                    'dialoghsm_status' => $status,
                ]);
            }

            $this->leadModel->saveEntity($lead);
        } catch (\Throwable) {
            // falha silenciosa — não interrompe o fluxo do webhook
        }
    }

    private function updateCampaignActionMetadata(MessageLog $log): void
    {
        if ($log->getCampaignEventId() === null || $log->getLeadId() === null) {
            return;
        }

        try {
            $conn = $this->em->getConnection();
            $row  = $conn->fetchAssociative(
                'SELECT id, metadata FROM campaign_lead_event_log WHERE event_id = :eventId AND lead_id = :leadId AND is_scheduled = 1 LIMIT 1',
                ['eventId' => $log->getCampaignEventId(), 'leadId' => $log->getLeadId()]
            );

            if (!$row) {
                return;
            }

            $fmtUtc   = static fn (?\DateTimeInterface $dt): ?string => $dt === null ? null :
                \DateTime::createFromInterface($dt)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $existing = json_decode($row['metadata'] ?? '[]', true) ?? [];
            $existing['whatsapp'] = array_filter([
                'template_name' => $log->getTemplateName(),
                'sender_name'   => $log->getSenderName(),
                'phone_number'  => $log->getPhoneNumber(),
                'status'        => $log->getStatus(),
                'date_sent'     => $fmtUtc($log->getDateSent()),
                'error_message' => $log->getErrorMessage(),
            ], static fn ($v) => $v !== null && $v !== '');

            $conn->update('campaign_lead_event_log', ['metadata' => json_encode($existing)], ['id' => $row['id']]);
        } catch (\Throwable) {
            // falha silenciosa — não interrompe o fluxo do webhook
        }
    }

    private function triggerPointAction(MessageLog $log, string $status): void
    {
        $actionMap = [
            MessageLog::STATUS_READ => 'dialoghsm.message_read',
        ];

        $pointAction = $actionMap[$status] ?? null;
        if (null === $pointAction) {
            return;
        }

        $leadId = $log->getLeadId();
        if (!$leadId) {
            return;
        }

        try {
            $lead = $this->leadModel->getEntity($leadId);
            if (null === $lead) {
                return;
            }

            $this->pointModel->triggerAction($pointAction, null, null, $lead, true);
        } catch (\Throwable) {
            // falha silenciosa — não interrompe o fluxo do webhook
        }
    }

    /**
     * @param array<string, mixed> $messageData
     */
    private function processInbound(array $messageData): void
    {
        $from = $messageData['from'] ?? '';
        if (!$from) {
            return;
        }

        // Aceitar somente texto e botão — ignorar audio, imagem, sticker, recibo etc.
        $type = $messageData['type'] ?? '';
        if (!in_array($type, ['text', 'button'], true)) {
            return;
        }

        $contextId    = $messageData['context']['id'] ?? null;
        $inboundWamid = $messageData['id'] ?? '';
        $receivedAt   = isset($messageData['timestamp'])
            ? (new \DateTime())->setTimestamp((int) $messageData['timestamp'])
            : new \DateTime();

        try {
            // Idempotência de wamid de entrada — bloqueia retries da 360dialog antes do DB
            if ($inboundWamid) {
                $redis = $this->getRedis();
                if ($redis !== null) {
                    if (false !== $redis->get('dialoghsm:inbound:'.$inboundWamid)) {
                        return;
                    }
                }
            }

            $persisted = $contextId !== null
                // Scenario A: resposta direta ao HSM — correlação precisa via wamid
                ? $this->processInboundDirect($from, $contextId)
                // Scenario B: texto livre — correlação por telefone + janela de 24h
                : $this->processInboundFreeText($from, $receivedAt);

            if ($persisted && $inboundWamid) {
                $redis = $this->getRedis();
                if ($redis !== null) {
                    $redis->setEx('dialoghsm:inbound:'.$inboundWamid, 3600, '1');
                }
            }
        } catch (\Throwable) {
            // falha silenciosa — não interrompe o fluxo do webhook
        }
    }

    /**
     * Scenario A: context.id presente — localiza o MessageLog exato pelo wamid do HSM original.
     * Cache key usa o contextId para não bloquear respostas a outros HSMs do mesmo contato.
     */
    private function processInboundDirect(string $from, string $contextId): bool
    {
        $redis    = $this->getRedis();
        $cacheKey = self::REPLIED_CACHE_PREFIX . $contextId;

        if ($redis !== null && false !== $redis->get($cacheKey)) {
            return false;
        }

        $log = $this->logRepository->findByWamid($contextId);
        if (null === $log || null === $log->getLeadId()) {
            return false;
        }

        if ($log->getDateReplied() !== null) {
            return false; // idempotência via DB
        }

        $lead = $this->leadModel->getEntity($log->getLeadId());
        if (null === $lead) {
            return false;
        }

        $now = new \DateTime();
        $this->persistReply($log, $lead, $from, $now);

        if ($redis !== null) {
            $redis->setEx($cacheKey, self::REPLIED_CACHE_TTL, '1');
        }

        // Sincroniza o Hash do celular para bloquear duplicações via Scenario B
        $this->contactCache->markReplied($from);

        return true;
    }

    /**
     * Scenario B: sem context.id — fast path via Hash Redis (phone → wamid gravado no envio),
     * com fallback para consulta DB quando Redis indisponível ou chave expirou.
     */
    private function processInboundFreeText(string $from, \DateTimeInterface $receivedAt): bool
    {
        // Dedup via Hash: contato já respondeu na janela atual
        if ($this->contactCache->isReplied($from)) {
            return false;
        }

        // Fast path: Redis tem o wamid do último HSM → lookup indexado, sem query complexa
        $cachedWamid = $this->contactCache->getLastWamid($from);
        if ($cachedWamid !== null) {
            $log = $this->logRepository->findByWamid($cachedWamid);
            if ($log === null || $log->getLeadId() === null || $log->getDateReplied() !== null) {
                return false;
            }

            $lead = $this->leadModel->getEntity($log->getLeadId());
            if ($lead === null) {
                return false;
            }

            $now = new \DateTime();
            $this->persistReply($log, $lead, $from, $now);
            $this->contactCache->markReplied($from);

            return true;
        }

        // Fallback DB: Redis indisponível ou chave expirou
        $lead = $this->findLeadByMobile($from);
        if (null === $lead) {
            return false;
        }

        $since = (new \DateTime())->modify('-24 hours');
        $log   = $this->logRepository->findMostRecentForLead((int) $lead->getId(), $since, $receivedAt);
        if (null === $log) {
            return false;
        }

        $now = new \DateTime();
        $this->persistReply($log, $lead, $from, $now);
        $this->contactCache->markReplied($from);

        return true;
    }

    private function persistReply(MessageLog $log, Lead $lead, string $from, \DateTime $now): void
    {
        $log->setDateReplied($now);
        $this->em->persist($log);
        $this->em->flush();

        $this->pointModel->triggerAction('dialoghsm.message_replied', null, null, $lead, true);
        $this->eventLogWriter->writeReply($lead, $from, $now);
        $this->leadModel->setFieldValues($lead, ['dialoghsm_last_reply' => $now]);
        $this->leadModel->saveEntity($lead);
    }

    private function getRedis(): ?\Redis
    {
        if ($this->redisOverride !== null) {
            return $this->redisOverride;
        }

        if ($this->redis !== null) {
            try {
                $this->redis->ping();

                return $this->redis;
            } catch (\Throwable) {
                $this->redis = null;
            }
        }

        if ('' === $this->redisDsn || !str_starts_with($this->redisDsn, 'redis')) {
            return null;
        }

        try {
            $parsed = parse_url($this->redisDsn);
            $host   = $parsed['host'] ?? 'localhost';
            $port   = (int) ($parsed['port'] ?? 6379);
            $db     = isset($parsed['path']) ? (int) ltrim($parsed['path'], '/') : 0;

            $r = new \Redis();
            $r->connect($host, $port, 1.0);
            if ($db > 0) {
                $r->select($db);
            }
            $this->redis = $r;
        } catch (\Throwable) {
            return null;
        }

        return $this->redis;
    }

    private function findLeadByMobile(string $phone): ?Lead
    {
        /** @var LeadRepository $repo */
        $repo = $this->em->getRepository(Lead::class);

        // 360dialog envia sem '+'; Mautic pode ter gravado com ou sem
        $candidates = [$phone, '+' . ltrim($phone, '+')];

        foreach ($candidates as $candidate) {
            $results = $repo->getLeadsByFieldValue('mobile', $candidate);
            if (!empty($results)) {
                return reset($results);
            }
        }

        return null;
    }

    private function isValidTransition(?string $current, string $new): bool
    {
        $pending = MessageLog::STATUS_PENDING_WEBHOOK;

        return match ($new) {
            MessageLog::STATUS_SENT      => $current === $pending,
            MessageLog::STATUS_DELIVERED => in_array($current, [$pending, MessageLog::STATUS_SENT], true),
            MessageLog::STATUS_READ      => in_array($current, [$pending, MessageLog::STATUS_SENT, MessageLog::STATUS_DELIVERED], true),
            MessageLog::STATUS_FAILED    => in_array($current, [$pending, MessageLog::STATUS_SENT, MessageLog::STATUS_QUEUED], true),
            default                      => false,
        };
    }
}
