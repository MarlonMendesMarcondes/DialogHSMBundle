<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;

class WebhookProcessor
{
    public function __construct(
        private readonly WhatsAppNumberRepository $numberRepository,
        private readonly MessageLogRepository $logRepository,
        private readonly EntityManagerInterface $em,
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
     * @param array<string, string> $statusData
     */
    private function processStatus(array $statusData): void
    {
        $wamid  = $statusData['id'] ?? '';
        $status = $statusData['status'] ?? '';

        if (!$wamid || !in_array($status, [MessageLog::STATUS_DELIVERED, MessageLog::STATUS_READ], true)) {
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
        }

        $this->em->persist($log);
        $this->em->flush();
    }

    private function isValidTransition(?string $current, string $new): bool
    {
        return match ($new) {
            MessageLog::STATUS_DELIVERED => $current === MessageLog::STATUS_SENT,
            MessageLog::STATUS_READ      => in_array($current, [MessageLog::STATUS_SENT, MessageLog::STATUS_DELIVERED], true),
            default                      => false,
        };
    }
}
