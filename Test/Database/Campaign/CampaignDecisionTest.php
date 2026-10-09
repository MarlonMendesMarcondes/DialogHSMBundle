<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Database\Campaign;

use MauticPlugin\DialogHSMBundle\Service\WebhookProcessor;
use MauticPlugin\DialogHSMBundle\Test\Database\DatabaseTestCase;

/**
 * Fluxo completo: ação HSM → nó "Entregue" → ação do core no caminho "sim", com o
 * campaigns:trigger e o RealTimeExecutioner reais.
 */
class CampaignDecisionTest extends DatabaseTestCase
{
    public function testDeliveredWebhookAdvancesTheContactThroughTheDecision(): void
    {
        $phone      = '+55119'.random_int(10000000, 99999999);
        $numberId   = $this->createWhatsAppNumber(['phone_number' => $phone]);
        $campaignId = $this->createCampaign();
        $actionId   = $this->createQueueActionEvent($campaignId, $numberId);

        $this->connection->insert('campaign_events', [
            'campaign_id' => $campaignId, 'parent_id' => $actionId, 'name' => 'Entregue?',
            'type' => 'dialoghsm.decision_delivered', 'event_type' => 'decision', 'event_order' => 2,
            'properties' => serialize([]), 'trigger_mode' => 'immediate', 'channel' => 'whatsapp',
            'failed_count' => 0, 'trigger_restricted_dow' => 'a:0:{}',
        ]);
        $decisionId = (int) $this->connection->lastInsertId();

        $this->connection->insert('campaign_events', [
            'campaign_id' => $campaignId, 'parent_id' => $decisionId, 'decision_path' => 'yes', 'name' => '+5 pontos',
            'type' => 'lead.changepoints', 'event_type' => 'action', 'event_order' => 3,
            'properties' => serialize(['points' => 5]), 'trigger_mode' => 'immediate', 'trigger_interval' => 0,
            'trigger_interval_unit' => 'd', 'failed_count' => 0, 'trigger_restricted_dow' => 'a:0:{}',
        ]);
        $pointsActionId = (int) $this->connection->lastInsertId();

        $leadId = $this->createLead('+5511900000950');
        $this->addLeadToCampaign($campaignId, $leadId);

        $this->runCommand('mautic:campaigns:trigger', ['-i' => $campaignId]);

        // O consumidor enviou e a Meta confirmou o envio
        $this->connection->executeStatement(
            "UPDATE dialog_hsm_message_log SET status = 'sent', wamid = 'wamid.decisao' WHERE lead_id = ? AND campaign_event_id = ?",
            [$leadId, $actionId]
        );
        // Cada webhook é uma requisição nova: nada do trigger fica em memória
        $this->em->clear();
        $devicesBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM lead_devices');

        // Como em produção: requisição HTTP anônima vinda da 360dialog
        $requestStack = $this->getService('request_stack');
        $requestStack->push(\Symfony\Component\HttpFoundation\Request::create('/dialoghsm/webhook/'.urlencode($phone), 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Go-http-client/2.0', 'REMOTE_ADDR' => '34.120.10.20',
        ]));

        try {
            $this->getService(WebhookProcessor::class)->process($phone, ['entry' => [['changes' => [['value' => ['statuses' => [
                ['id' => 'wamid.decisao', 'status' => 'delivered', 'timestamp' => (string) time()],
            ]]]]]]]);
        } finally {
            $requestStack->pop();
        }

        $this->assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM campaign_lead_event_log WHERE event_id = ? AND lead_id = ? AND is_scheduled = 0',
            [$pointsActionId, $leadId]
        ), 'A ação do caminho "sim" do nó "Entregue" não foi executada.');
        $this->assertSame(5, (int) $this->connection->fetchOne('SELECT points FROM leads WHERE id = ?', [$leadId]));
        $this->assertSame($devicesBefore, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM lead_devices'), 'O webhook não pode criar device de rastreamento para o contato.');
    }
}
