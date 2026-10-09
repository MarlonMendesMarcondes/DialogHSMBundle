<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Database\Service;

use Mautic\CampaignBundle\Entity\LeadEventLog;
use MauticPlugin\DialogHSMBundle\Service\WebhookProcessor;
use MauticPlugin\DialogHSMBundle\Test\Database\DatabaseTestCase;

/**
 * Webhook de status gravando no banco real: MessageLog, metadata da ação na campanha
 * (campaign_lead_event_log) e o JSON_SET do WebhookCampaignSubscriber no MariaDB.
 */
class WebhookProcessorTest extends DatabaseTestCase
{
    private WebhookProcessor $processor;
    private string $phone;
    private int $leadId;
    private int $eventId;
    private int $campaignLogId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = $this->getService(WebhookProcessor::class);
        $this->phone     = '+55119'.random_int(10000000, 99999999);
        $numberId        = $this->createWhatsAppNumber(['phone_number' => $this->phone]);

        $campaignId    = $this->createCampaign();
        $this->eventId = $this->createQueueActionEvent($campaignId, $numberId);
        $this->leadId  = $this->createLead('+5511900000700');
        $this->addLeadToCampaign($campaignId, $this->leadId);

        // Ação já concluída: a ação HSM conclui o contato ao enfileirar, antes do webhook
        $this->connection->insert('campaign_lead_event_log', [
            'event_id' => $this->eventId, 'lead_id' => $this->leadId, 'campaign_id' => $campaignId,
            'rotation' => 1, 'is_scheduled' => 0, 'system_triggered' => 1,
            'date_triggered' => $this->utcNow('-5 minutes'), 'metadata' => serialize([]),
        ]);
        $this->campaignLogId = (int) $this->connection->lastInsertId();
    }

    private function createSentLog(string $wamid, string $status = 'pending_webhook'): int
    {
        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $this->leadId, 'campaign_event_id' => $this->eventId, 'status' => $status,
            'template_name' => 'template_teste', 'phone_number' => '+5511900000700',
            'wamid' => $wamid, 'date_sent' => $this->utcNow('-4 minutes'),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function webhook(string $wamid, string $status, array $errors = []): int
    {
        $entry = ['id' => $wamid, 'status' => $status, 'timestamp' => (string) time(), 'recipient_id' => '5511900000700'];
        if ($errors) {
            $entry['errors'] = $errors;
        }

        $code = $this->processor->process($this->phone, ['entry' => [['changes' => [['value' => ['statuses' => [$entry]]]]]]]);
        $this->em->clear();

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageLog(int $id): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM dialog_hsm_message_log WHERE id = ?', [$id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignLogMetadata(): array
    {
        // Lido como o Mautic lê (timeline, API, tela da campanha): pela entidade
        $this->em->clear();

        return $this->em->find(LeadEventLog::class, $this->campaignLogId)->getMetadata();
    }

    public function testStatusSequenceIsPersistedWithDates(): void
    {
        $id = $this->createSentLog('wamid.seq');

        $this->assertSame(200, $this->webhook('wamid.seq', 'sent'));
        $this->webhook('wamid.seq', 'delivered');
        $this->webhook('wamid.seq', 'read');

        $log = $this->messageLog($id);
        $this->assertSame('read', $log['status']);
        $this->assertNotNull($log['date_delivered']);
        $this->assertNotNull($log['date_read']);
    }

    public function testStatusIsWrittenOnTheCompletedCampaignActionLog(): void
    {
        $this->createSentLog('wamid.meta');

        $this->webhook('wamid.meta', 'delivered');

        $this->assertSame('delivered', $this->campaignLogMetadata()['whatsapp']['status'] ?? null, 'O log da ação já está concluído (is_scheduled=0) quando o webhook chega.');
    }

    public function testMetaFailureMarksTheCampaignActionLogAsFailed(): void
    {
        $id = $this->createSentLog('wamid.fail', 'sent');

        $this->webhook('wamid.fail', 'failed', [['code' => 131026, 'title' => 'Message undeliverable']]);

        $log = $this->messageLog($id);
        $this->assertSame('failed', $log['status']);
        $this->assertSame(131026, (int) $log['webhook_error_code']);
        $this->assertSame(1, $this->campaignLogMetadata()['failed'] ?? null, 'O UPDATE com JSON_SET do WebhookCampaignSubscriber precisa rodar no MariaDB.');
    }

    public function testReadBeforeDeliveredAlsoRecordsDelivery(): void
    {
        $id = $this->createSentLog('wamid.ordem', 'sent');

        $this->webhook('wamid.ordem', 'read');
        $this->webhook('wamid.ordem', 'delivered'); // chega atrasado e é descartado

        $log = $this->messageLog($id);
        $this->assertSame('read', $log['status']);
        $this->assertNotNull($log['date_delivered']);
    }

    public function testRepeatedWebhookDoesNotChangeTheLog(): void
    {
        $id = $this->createSentLog('wamid.rep', 'sent');

        $this->webhook('wamid.rep', 'delivered');
        $first = $this->messageLog($id);
        $this->webhook('wamid.rep', 'delivered');

        $this->assertSame($first, $this->messageLog($id));
    }

    public function testUnknownNumberIsRejected(): void
    {
        $this->assertSame(404, $this->processor->process('+5500000000000', ['entry' => []]));
    }

    public function testFreeTextReplyIsNotAttributedToAMessageThatWasNeverSent(): void
    {
        $phone    = '+55119'.random_int(10000000, 99999999);
        $this->createWhatsAppNumber(['phone_number' => $phone]);
        $leadId   = $this->createLead('+5511900000902');

        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $leadId, 'status' => 'delivered', 'template_name' => 'hsm_entregue',
            'phone_number' => '+5511900000902', 'wamid' => 'wamid.entregue', 'date_sent' => $this->utcNow('-2 hours'),
        ]);
        $deliveredId = (int) $this->connection->lastInsertId();
        // Mais recente, mas ainda na fila (ex.: campanha pausada) — o contato nunca a recebeu
        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $leadId, 'status' => 'queued', 'template_name' => 'hsm_na_fila',
            'phone_number' => '+5511900000902', 'wamid' => 'uuid-na-fila', 'date_sent' => $this->utcNow('-5 minutes'),
        ]);

        $this->processor->process($phone, ['entry' => [['changes' => [['value' => ['messages' => [
            ['from' => '5511900000902', 'id' => 'wamid.in.'.random_int(1, 999999), 'type' => 'text', 'timestamp' => (string) time(), 'text' => ['body' => 'quero']],
        ]]]]]]]);

        $replied = $this->connection->fetchFirstColumn('SELECT id FROM dialog_hsm_message_log WHERE lead_id = ? AND date_replied IS NOT NULL', [$leadId]);

        $this->assertSame([$deliveredId], array_map('intval', $replied), 'A resposta foi atribuída à mensagem que ainda está na fila.');
    }

    public function testReplyQuotingANonHsmMessageIsStillCounted(): void
    {
        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $this->leadId, 'status' => 'delivered', 'template_name' => 'hsm_entregue',
            'phone_number' => '+5511900000700', 'wamid' => 'wamid.hsm.citado', 'date_sent' => $this->utcNow('-1 hour'),
        ]);
        $hsmId = (int) $this->connection->lastInsertId();

        // O contato cita uma mensagem do atendente (Chatwoot), não o HSM
        $this->processor->process($this->phone, ['entry' => [['changes' => [['value' => ['messages' => [[
            'from' => '5511900000700', 'id' => 'wamid.in.'.random_int(1, 999999), 'type' => 'text',
            'timestamp' => (string) time(), 'text' => ['body' => 'ok'], 'context' => ['id' => 'wamid.do.atendente'],
        ]]]]]]]]);

        $this->assertNotNull($this->messageLog($hsmId)['date_replied'], 'Resposta citando mensagem que não é HSM era descartada.');
    }

    public function testLateWebhookOfAnOldRotationDoesNotTouchTheNewRotation(): void
    {
        // Rotação 2 do mesmo contato no mesmo evento, iniciada depois do envio da rotação 1
        $this->connection->update('campaign_lead_event_log', ['date_triggered' => $this->utcNow('-3 days')], ['id' => $this->campaignLogId]);
        $this->connection->insert('campaign_lead_event_log', [
            'event_id' => $this->eventId, 'lead_id' => $this->leadId, 'campaign_id' => (int) $this->connection->fetchOne('SELECT campaign_id FROM campaign_lead_event_log WHERE id = ?', [$this->campaignLogId]),
            'rotation' => 2, 'is_scheduled' => 0, 'system_triggered' => 1,
            'date_triggered' => $this->utcNow('-1 hour'), 'metadata' => serialize([]),
        ]);
        $rotation2Id = (int) $this->connection->lastInsertId();

        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $this->leadId, 'campaign_event_id' => $this->eventId, 'status' => 'delivered', 'template_name' => 'template_teste',
            'phone_number' => '+5511900000700', 'wamid' => 'wamid.rotacao1', 'date_sent' => $this->utcNow('-3 days'),
        ]);

        $this->webhook('wamid.rotacao1', 'read'); // chega com dias de atraso

        $this->em->clear();
        $this->assertSame([], $this->em->find(LeadEventLog::class, $rotation2Id)->getMetadata(), 'Status do envio antigo foi gravado na rotação nova.');
        $this->assertSame('read', $this->campaignLogMetadata()['whatsapp']['status'] ?? null);
    }

    private function timelineCount(string $action): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM lead_event_log WHERE lead_id = ? AND bundle = '".\MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter::BUNDLE."' AND action = ?",
            [$this->leadId, $action]
        );
    }

    public function testConcurrentDuplicateStatusRunsSideEffectsOnce(): void
    {
        $id = $this->createSentLog('wamid.corrida', 'sent');

        // Esta requisição carregou o log ainda 'sent' ...
        $this->em->find(\MauticPlugin\DialogHSMBundle\Entity\MessageLog::class, $id);
        // ... enquanto outra requisição (mesmo webhook reentregue) já aplicou o delivered
        $this->connection->update('dialog_hsm_message_log', ['status' => 'delivered', 'date_delivered' => $this->utcNow()], ['id' => $id]);

        $this->processor->process($this->phone, ['entry' => [['changes' => [['value' => ['statuses' => [
            ['id' => 'wamid.corrida', 'status' => 'delivered', 'timestamp' => (string) time()],
        ]]]]]]]);

        $this->assertSame(0, $this->timelineCount('delivered'), 'A requisição atrasada repetia timeline, pontos, decisão e webhook do Mautic.');
    }

    public function testConcurrentDifferentStatusesAreBothApplied(): void
    {
        $id = $this->createSentLog('wamid.ordem.par', 'sent');

        $this->em->find(\MauticPlugin\DialogHSMBundle\Entity\MessageLog::class, $id);
        // delivered aplicado por outra requisição enquanto esta processava o read
        $this->connection->update('dialog_hsm_message_log', ['status' => 'delivered', 'date_delivered' => $this->utcNow()], ['id' => $id]);

        $this->processor->process($this->phone, ['entry' => [['changes' => [['value' => ['statuses' => [
            ['id' => 'wamid.ordem.par', 'status' => 'read', 'timestamp' => (string) time()],
        ]]]]]]]);

        $this->em->clear();
        $this->assertSame('read', $this->messageLog($id)['status'], 'O read não pode se perder por causa da corrida com o delivered.');
    }

    public function testConcurrentReplyIsRegisteredOnce(): void
    {
        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $this->leadId, 'status' => 'delivered', 'template_name' => 'template_teste',
            'phone_number' => '+5511900000700', 'wamid' => 'wamid.resp.corrida', 'date_sent' => $this->utcNow('-1 hour'),
        ]);
        $id = (int) $this->connection->lastInsertId();

        $this->em->find(\MauticPlugin\DialogHSMBundle\Entity\MessageLog::class, $id);
        $this->connection->update('dialog_hsm_message_log', ['date_replied' => $this->utcNow()], ['id' => $id]);

        $this->processor->process($this->phone, ['entry' => [['changes' => [['value' => ['messages' => [[
            'from' => '5511900000700', 'id' => 'wamid.in.'.random_int(1, 999999), 'type' => 'text',
            'timestamp' => (string) time(), 'text' => ['body' => 'oi'], 'context' => ['id' => 'wamid.resp.corrida'],
        ]]]]]]]]);

        $this->assertSame(0, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM lead_event_log WHERE lead_id = ? AND bundle = '".\MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter::BUNDLE."' AND action = 'replied'",
            [$this->leadId]
        ), 'Resposta já registrada por outra requisição não pode repetir pontos e decisão.');
    }

    private function makeActionStillScheduled(): void
    {
        // Campanha com data: o core só grava is_scheduled=0 no fim do lote do trigger
        $this->connection->update('campaign_lead_event_log', ['is_scheduled' => 1, 'trigger_date' => $this->utcNow('-1 minute')], ['id' => $this->campaignLogId]);
    }

    public function testDeliveredArrivingBeforeTheActionIsRecordedIsDeferred(): void
    {
        $id = $this->createSentLog('wamid.cedo', 'sent');
        $this->makeActionStillScheduled();

        try {
            $this->webhook('wamid.cedo', 'delivered');
            $this->fail('O webhook precisava ser adiado (503): o nó "Entregue" ainda não acha a ação.');
        } catch (\MauticPlugin\DialogHSMBundle\Exception\WebhookRetryLaterException) {
        }

        $this->assertSame('sent', $this->messageLog($id)['status'], 'Nada pode ser gravado antes de adiar, senão a reentrega seria rejeitada.');
    }

    public function testStuckActionOfInactiveCampaignIsNotDeferred(): void
    {
        $id = $this->createSentLog('wamid.travado', 'sent');
        $this->makeActionStillScheduled();
        $this->connection->update('campaigns', ['is_published' => 0], ['id' => (int) $this->connection->fetchOne('SELECT campaign_id FROM campaign_lead_event_log WHERE id = ?', [$this->campaignLogId])]);

        $this->assertSame(200, $this->webhook('wamid.travado', 'delivered'));
        $this->assertSame('delivered', $this->messageLog($id)['status']);
    }

    public function testLateWebhookOfAnOlderSendDoesNotOverwriteTheContactStatus(): void
    {
        $this->createSentLog('wamid.antigo', 'delivered');
        $this->connection->update('dialog_hsm_message_log', ['date_sent' => $this->utcNow('-3 days')], ['wamid' => 'wamid.antigo']);
        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $this->leadId, 'status' => 'failed', 'template_name' => 'template_teste',
            'phone_number' => '+5511900000700', 'wamid' => 'wamid.novo', 'date_sent' => $this->utcNow('-1 minute'),
        ]);
        $this->connection->update('leads', ['dialoghsm_status' => 'failed_meta'], ['id' => $this->leadId]);

        $this->webhook('wamid.antigo', 'read'); // envio de 3 dias atrás

        $this->assertSame('failed_meta', $this->connection->fetchOne('SELECT dialoghsm_status FROM leads WHERE id = ?', [$this->leadId]));
    }
}
