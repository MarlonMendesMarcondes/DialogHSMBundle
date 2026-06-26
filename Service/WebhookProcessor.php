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
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
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
        } catch (\Throwable $e) {
            $this->logger->error('DialogHSM: processInbound falhou', [
                'from'      => $from,
                'type'      => $type,
                'contextId' => $contextId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
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
        foreach ($this->getBRPhoneCandidates($from) as $candidate) {
            $this->contactCache->markReplied($candidate);
        }

        return true;
    }

    /**
     * Scenario B: sem context.id — fast path via Hash Redis (phone → wamid gravado no envio),
     * com fallback para consulta DB quando Redis indisponível, chave expirou ou wamid stale.
     *
     * isReplied NÃO é usado como guard inicial: evita bloqueio falso quando a resposta
     * chega antes do setLastSent do próximo HSM (race entre worker e webhook).
     * A idempotência é garantida por date_replied IS NULL na query e pelo check explícito.
     */
    private function processInboundFreeText(string $from, \DateTimeInterface $receivedAt): bool
    {
        // Fast path: Redis tem o wamid do último HSM → lookup indexado, sem query complexa.
        // Tenta também com o número normalizado (BR: 12→13 dígitos, adiciona o 9º dígito).
        $fromCandidates  = $this->getBRPhoneCandidates($from);
        $cachedWamid     = null;
        $effectiveFrom   = $from;
        foreach ($fromCandidates as $candidate) {
            $wamid = $this->contactCache->getLastWamid($candidate);
            if ($wamid !== null) {
                $cachedWamid   = $wamid;
                $effectiveFrom = $candidate;
                break;
            }
        }
        $from = $effectiveFrom;
        if ($cachedWamid !== null) {
            $log = $this->logRepository->findByWamid($cachedWamid);

            if ($log === null) {
                // wamid no Redis não encontrado no DB (stale ou race condition) — tenta fallback DB
                $this->logger->warning('DialogHSM: Scenario B fast-path: wamid Redis não encontrado no DB, caindo no fallback', [
                    'from'        => $from,
                    'cachedWamid' => $cachedWamid,
                ]);
            } elseif ($log->getLeadId() === null) {
                $this->logger->warning('DialogHSM: Scenario B fast-path: log sem lead_id', [
                    'from'        => $from,
                    'cachedWamid' => $cachedWamid,
                    'logId'       => $log->getId(),
                ]);

                return false;
            } elseif ($log->getDateReplied() !== null) {
                $this->logger->info('DialogHSM: Scenario B fast-path: já respondido (idempotência)', [
                    'from'        => $from,
                    'cachedWamid' => $cachedWamid,
                    'dateReplied' => $log->getDateReplied()->format('Y-m-d H:i:s'),
                ]);

                return false;
            } else {
                $lead = $this->leadModel->getEntity($log->getLeadId());
                if ($lead === null) {
                    $this->logger->warning('DialogHSM: Scenario B fast-path: lead não encontrado', [
                        'from'   => $from,
                        'leadId' => $log->getLeadId(),
                    ]);

                    return false;
                }

                $this->logger->info('DialogHSM: Scenario B fast-path: reply registrado via Redis', [
                    'from'        => $from,
                    'cachedWamid' => $cachedWamid,
                    'leadId'      => $lead->getId(),
                ]);
                $this->persistReply($log, $lead, $from, new \DateTime());
                $this->contactCache->markReplied($from);

                return true;
            }
        } else {
            $this->logger->info('DialogHSM: Scenario B: Redis sem wamid para o contato, usando fallback DB', [
                'from' => $from,
            ]);
        }

        // Fallback DB: Redis indisponível, chave expirou ou wamid stale (log não encontrado acima).
        // isReplied guarda apenas este caminho (mais caro) — não o fast path acima.
        if ($this->contactCache->isReplied($from)) {
            $this->logger->info('DialogHSM: Scenario B fallback DB: contato já marcado como respondido no Redis', [
                'from' => $from,
            ]);

            return false;
        }

        $lead = $this->findLeadByMobile($from);
        if (null === $lead) {
            $this->logger->warning('DialogHSM: Scenario B fallback DB: lead não encontrado pelo mobile', [
                'from' => $from,
            ]);

            return false;
        }

        $since = (new \DateTime())->modify('-24 hours');
        $log   = $this->logRepository->findMostRecentForLead((int) $lead->getId(), $since);
        if (null === $log) {
            $this->logger->warning('DialogHSM: Scenario B fallback DB: nenhum HSM recente encontrado para o lead', [
                'from'   => $from,
                'leadId' => $lead->getId(),
                'since'  => $since->format('Y-m-d H:i:s'),
            ]);

            return false;
        }

        $this->logger->info('DialogHSM: Scenario B fallback DB: reply registrado via DB', [
            'from'   => $from,
            'wamid'  => $log->getWamid(),
            'leadId' => $lead->getId(),
        ]);
        $this->persistReply($log, $lead, $from, new \DateTime());
        foreach ($this->getBRPhoneCandidates($from) as $candidate) {
            $this->contactCache->markReplied($candidate);
        }

        return true;
    }

    private function persistReply(MessageLog $log, Lead $lead, string $from, \DateTime $now): void
    {
        $log->setDateReplied($now);
        $this->em->persist($log);
        $this->em->flush();

        $this->pointModel->triggerAction('dialoghsm.message_replied', null, null, $lead, true);
        $this->eventLogWriter->writeReply($lead, $from, $now, $log);
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

        foreach ($this->getBRPhoneCandidates($phone) as $candidate) {
            $results = $repo->getLeadsByFieldValue('mobile', $candidate);
            if (!empty($results)) {
                return reset($results);
            }
        }

        return null;
    }

    /**
     * Retorna variações do número para lidar com o 9º dígito brasileiro.
     * A 360dialog envia números no formato antigo (12 dígitos: 55+DDD+8 dígitos),
     * mas o Mautic pode ter gravado no formato novo (13 dígitos: 55+DDD+9+8 dígitos).
     *
     * @return string[]
     */
    private function getBRPhoneCandidates(string $phone): array
    {
        $clean      = ltrim($phone, '+');
        $candidates = [$clean, '+' . $clean];

        // BR 12→13: adiciona o 9 após o DDD (ex: 554499067833 → 5544999067833)
        if (12 === strlen($clean) && str_starts_with($clean, '55')) {
            $with9 = '55' . substr($clean, 2, 2) . '9' . substr($clean, 4);
            $candidates[] = $with9;
            $candidates[] = '+' . $with9;
        }

        // BR 13→12: remove o 9 após o DDD (ex: 5544999067833 → 554499067833)
        if (13 === strlen($clean) && str_starts_with($clean, '55')) {
            $without9 = '55' . substr($clean, 2, 2) . substr($clean, 5);
            $candidates[] = $without9;
            $candidates[] = '+' . $without9;
        }

        return array_unique($candidates);
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
