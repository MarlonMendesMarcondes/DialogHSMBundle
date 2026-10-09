<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Doctrine\DBAL\Connection;
use MauticPlugin\DialogHSMBundle\Event\WebhookMessageFailedEvent;
use MauticPlugin\DialogHSMBundle\Service\CampaignLogMetadata;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class WebhookCampaignSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Connection $connection) {}

    public static function getSubscribedEvents(): array
    {
        return [WebhookMessageFailedEvent::class => 'onMessageFailed'];
    }

    public function onMessageFailed(WebhookMessageFailedEvent $event): void
    {
        $log = $event->getLog();

        if (!$log->getCampaignEventId() || !$log->getLeadId()) {
            return;
        }

        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';
        $table  = $prefix . 'campaign_lead_event_log';

        // Usa metadata.failed (padrão Mautic) — a tabela campaign_lead_event_log não tem coluna
        // `failed`. Só marca entradas já disparadas, na execução do contato iniciada até o envio
        // (falha atrasada de um envio antigo não marca a rotação nova).
        $sentAt = \DateTime::createFromInterface($log->getDateSent() ?? new \DateTime())
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $row = $this->connection->fetchAssociative(
            "SELECT id, metadata FROM `{$table}`
             WHERE event_id = :eventId AND lead_id = :leadId
               AND is_scheduled = 0 AND date_triggered IS NOT NULL AND date_triggered <= :sentAt
             ORDER BY id DESC LIMIT 1",
            ['eventId' => $log->getCampaignEventId(), 'leadId' => $log->getLeadId(), 'sentAt' => $sentAt]
        );

        if (false === $row) {
            return;
        }

        $metadata = CampaignLogMetadata::decode($row['metadata']);
        if (isset($metadata['failed'])) {
            return;
        }

        $metadata['failed'] = 1;
        $metadata['reason'] = 'dialoghsm.campaign.error.webhook_failed';

        $this->connection->update($table, ['metadata' => CampaignLogMetadata::encode($metadata)], ['id' => $row['id']]);
    }
}
