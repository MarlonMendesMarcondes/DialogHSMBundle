<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Doctrine\DBAL\Connection;
use MauticPlugin\DialogHSMBundle\Event\WebhookMessageFailedEvent;
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

        // Usa metadata.failed (padrão Mautic) — a tabela campaign_lead_event_log não
        // tem coluna `failed`. Guarda com JSON_VALID pois registros antigos usam
        // PHP serialize (a:0:{}), não JSON. Só atualiza entradas já disparadas.
        $this->connection->executeStatement(
            "UPDATE `{$table}`
             SET metadata = JSON_SET(
               IF(JSON_VALID(COALESCE(metadata, '{}')), COALESCE(metadata, '{}'), '{}'),
               '$.failed', 1,
               '$.reason', 'dialoghsm.campaign.error.webhook_failed'
             )
             WHERE id = (
               SELECT t.id FROM (
                 SELECT id FROM `{$table}`
                 WHERE event_id = :eventId AND lead_id = :leadId
                   AND (metadata IS NULL OR NOT JSON_VALID(metadata) OR JSON_EXTRACT(metadata, '$.failed') IS NULL)
                   AND is_scheduled = 0 AND date_triggered IS NOT NULL
                 ORDER BY id DESC LIMIT 1
               ) AS t
             )",
            ['eventId' => $log->getCampaignEventId(), 'leadId' => $log->getLeadId()]
        );
    }
}
