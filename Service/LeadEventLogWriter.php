<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\LeadEventLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;

class LeadEventLogWriter
{
    public const BUNDLE = 'DialogHSMBundle';
    public const OBJECT = 'whatsapp_message';

    private LeadEventLogRepository $eventLogRepository;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        /** @var LeadEventLogRepository $repo */
        $repo                     = $em->getRepository(LeadEventLog::class);
        $this->eventLogRepository = $repo;
    }

    public const ACTION_REPLIED = 'replied';

    /**
     * Grava um evento em lead_event_log para o status informado.
     * Idempotente: ignora se já existe (bundle, object, action, object_id).
     */
    public function write(MessageLog $log, string $action, \DateTimeInterface $date): void
    {
        if ($this->exists((int) $log->getId(), $action)) {
            return;
        }

        $lead = $this->em->getReference(Lead::class, $log->getLeadId());

        $entry = new LeadEventLog();
        $entry
            ->setLead($lead)
            ->setBundle(self::BUNDLE)
            ->setObject(self::OBJECT)
            ->setObjectId((int) $log->getId())
            ->setAction($action)
            ->setDateAdded($this->normalizeToUtc($date))
            ->setProperties($this->buildProperties($log));

        $this->eventLogRepository->saveEntity($entry);
        $this->eventLogRepository->detachEntity($entry);
    }

    /**
     * Captura todos os campos disponíveis no MessageLog no momento da escrita.
     * Timestamps futuros (date_delivered, date_read) ficarão null nos eventos anteriores —
     * cada evento reflete o estado da mensagem no momento em que ocorreu.
     *
     * @return array<string, mixed>
     */
    /**
     * Grava um evento de resposta inbound em lead_event_log.
     * Não tem idempotência — dedup é responsabilidade do Redis cache em WebhookProcessor.
     */
    public function writeReply(Lead $lead, string $fromPhone, \DateTimeInterface $date): void
    {
        $entry = new LeadEventLog();
        $entry
            ->setLead($lead)
            ->setBundle(self::BUNDLE)
            ->setObject(self::OBJECT)
            ->setObjectId((int) $lead->getId())
            ->setAction(self::ACTION_REPLIED)
            ->setDateAdded($this->normalizeToUtc($date))
            ->setProperties(['phone_number' => $fromPhone]);

        $this->eventLogRepository->saveEntity($entry);
        $this->eventLogRepository->detachEntity($entry);
    }

    private function buildProperties(MessageLog $log): array
    {
        $fmt = static fn (?\DateTimeInterface $dt): ?string => $dt === null ? null :
            \DateTime::createFromInterface($dt)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return array_filter([
            'template_name'      => $log->getTemplateName(),
            'sender_name'        => $log->getSenderName(),
            'phone_number'       => $log->getPhoneNumber(),
            'wamid'              => $log->getWamid(),
            'campaign_id'        => $log->getCampaignId(),
            'date_sent'          => $fmt($log->getDateSent()),
            'date_delivered'     => $fmt($log->getDateDelivered()),
            'date_read'          => $fmt($log->getDateRead()),
            'error_message'      => $log->getErrorMessage(),
            'webhook_error_code' => $log->getWebhookErrorCode(),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    public function countReplied(\DateTime $since): int
    {
        $conn  = $this->em->getConnection();
        $table = $this->em->getClassMetadata(LeadEventLog::class)->getTableName();

        return (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE bundle = :bundle AND object = :object AND action = :action AND date_added >= :since",
            [
                'bundle' => self::BUNDLE,
                'object' => self::OBJECT,
                'action' => self::ACTION_REPLIED,
                'since'  => $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ]
        );
    }

    private function normalizeToUtc(\DateTimeInterface $date): \DateTime
    {
        $utc = \DateTime::createFromInterface($date);
        $utc->setTimezone(new \DateTimeZone('UTC'));

        return $utc;
    }

    private function exists(int $objectId, string $action): bool
    {
        $conn  = $this->em->getConnection();
        $table = $this->em->getClassMetadata(LeadEventLog::class)->getTableName();

        $result = $conn->fetchOne(
            "SELECT id FROM {$table} WHERE bundle = :bundle AND object = :object AND action = :action AND object_id = :object_id LIMIT 1",
            [
                'bundle'    => self::BUNDLE,
                'object'    => self::OBJECT,
                'action'    => $action,
                'object_id' => $objectId,
            ]
        );

        return $result !== false;
    }
}
