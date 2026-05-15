<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
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
            $errors = $statusData['errors'] ?? [];
            if (!empty($errors)) {
                $first = $errors[0];
                $log->setWebhookErrorCode((int) ($first['code'] ?? 0) ?: null);
                $log->setErrorMessage($first['title'] ?? null);
            }
        }

        $this->em->persist($log);
        $this->em->flush();

        if (MessageLog::STATUS_FAILED === $status) {
            $this->dispatcher->dispatch(new WebhookMessageFailedEvent($log));
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
