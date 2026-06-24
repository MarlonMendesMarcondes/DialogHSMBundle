<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\Event\WebhookMessageFailedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class WebhookProcessor
{
    public function __construct(
        private readonly WhatsAppNumberRepository $numberRepository,
        private readonly MessageLogRepository $logRepository,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LeadModel $leadModel,
        private readonly LeadEventLogWriter $eventLogWriter,
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

        $this->updateLeadStatus($log, $status);

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
