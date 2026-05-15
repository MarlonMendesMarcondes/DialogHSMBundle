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

        // Marca o evento como failed=1 apenas quando já foi disparado (pass() chamado
        // previamente — fluxo de fila). Para contatos ainda em is_scheduled=1
        // (fluxo direto aguardando webhook), o resolveFromWebhookLog no próximo
        // batch cuida do roteamento; não tocamos a entrada para não interferir.
        $this->connection->executeStatement(
            "UPDATE `{$table}` SET failed = 1
             WHERE id = (
               SELECT t.id FROM (
                 SELECT id FROM `{$table}`
                 WHERE event_id = :eventId AND lead_id = :leadId
                   AND failed = 0 AND is_scheduled = 0 AND date_triggered IS NOT NULL
                 ORDER BY id DESC LIMIT 1
               ) AS t
             )",
            ['eventId' => $log->getCampaignEventId(), 'leadId' => $log->getLeadId()]
        );
    }
}
