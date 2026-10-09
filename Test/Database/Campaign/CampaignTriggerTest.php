<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Database\Campaign;

use MauticPlugin\DialogHSMBundle\Test\Database\DatabaseTestCase;

/**
 * mautic:campaigns:trigger real, com a ação "Enviar WhatsApp (RabbitMQ)" do plugin.
 * O transporte é in-memory: o que vale é o que fica gravado no banco.
 */
class CampaignTriggerTest extends DatabaseTestCase
{
    private int $numberId;
    private int $campaignId;
    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->numberId   = $this->createWhatsAppNumber();
        $this->campaignId = $this->createCampaign();
        $this->eventId    = $this->createQueueActionEvent($this->campaignId, $this->numberId);
    }

    private function trigger(int $batchLimit = 100): string
    {
        return $this->runCommand('mautic:campaigns:trigger', ['-i' => $this->campaignId, '--batch-limit' => $batchLimit]);
    }

    /**
     * @return array<int, int> lead_id => quantidade de logs de envio
     */
    private function messageLogsPerLead(): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT lead_id, COUNT(*) FROM dialog_hsm_message_log WHERE campaign_event_id = ? GROUP BY lead_id',
            [$this->eventId]
        );

        return array_map('intval', $rows);
    }

    public function testOneTriggerRunQueuesEveryContactEvenWithSmallBatchLimit(): void
    {
        $leads = [];
        for ($i = 1; $i <= 5; ++$i) {
            $leads[] = $lead = $this->createLead(sprintf('+55119000004%02d', $i));
            $this->addLeadToCampaign($this->campaignId, $lead);
        }

        $this->trigger(batchLimit: 2);

        $this->assertCount(5, $this->messageLogsPerLead(), 'O trigger parava depois do primeiro lote (LogNotProcessedException).');
        $this->assertSame(5, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM campaign_lead_event_log WHERE event_id = ? AND is_scheduled = 0',
            [$this->eventId]
        ));
    }

    public function testContactReenteringTheCampaignReceivesTheMessageAgain(): void
    {
        $lead = $this->createLead('+5511900000410');
        $this->addLeadToCampaign($this->campaignId, $lead);

        // Rodada 1 já concluída: log de campanha da rotação 1 + envio entregue
        $this->connection->insert('campaign_lead_event_log', [
            'event_id' => $this->eventId, 'lead_id' => $lead, 'campaign_id' => $this->campaignId,
            'rotation' => 1, 'is_scheduled' => 0, 'system_triggered' => 1,
            'date_triggered' => $this->utcNow('-2 days'), 'metadata' => 'a:0:{}',
        ]);
        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $lead, 'campaign_id' => $this->campaignId, 'campaign_event_id' => $this->eventId,
            'status' => 'delivered', 'template_name' => 'template_teste', 'phone_number' => '+5511900000410',
            'wamid' => 'wamid.rodada1', 'date_sent' => $this->utcNow('-2 days'),
        ]);

        // Contato reentra na campanha (allow_restart / removido e readicionado)
        $this->connection->update('campaign_leads', ['rotation' => 2], ['campaign_id' => $this->campaignId, 'lead_id' => $lead]);

        $this->trigger();

        $this->assertSame(2, $this->messageLogsPerLead()[$lead] ?? 0, 'Na rotação 2 o plugin achava o envio da rotação 1 e concluía o contato sem enviar.');
        $this->assertSame('queued', $this->connection->fetchOne(
            'SELECT status FROM dialog_hsm_message_log WHERE campaign_event_id = ? AND lead_id = ? ORDER BY id DESC LIMIT 1',
            [$this->eventId, $lead]
        ));
    }

    public function testMessageAlreadyInFlightForTheSameRotationIsNotResent(): void
    {
        // Contato que versões anteriores deixaram is_scheduled=1 aguardando o webhook
        $lead = $this->createLead('+5511900000411');
        $this->addLeadToCampaign($this->campaignId, $lead);

        $this->connection->insert('campaign_lead_event_log', [
            'event_id' => $this->eventId, 'lead_id' => $lead, 'campaign_id' => $this->campaignId,
            'rotation' => 1, 'is_scheduled' => 1, 'system_triggered' => 1,
            'date_triggered' => $this->utcNow('-1 hour'), 'trigger_date' => $this->utcNow('-1 hour'), 'metadata' => 'a:0:{}',
        ]);
        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $lead, 'campaign_id' => $this->campaignId, 'campaign_event_id' => $this->eventId,
            'status' => 'pending_webhook', 'template_name' => 'template_teste', 'phone_number' => '+5511900000411',
            'wamid' => 'wamid.emtransito', 'date_sent' => $this->utcNow('-50 minutes'),
        ]);

        $this->trigger();

        $this->assertSame(1, $this->messageLogsPerLead()[$lead] ?? 0, 'Mensagem já enviada nesta rotação não pode ser reenviada.');
        $this->assertSame(0, (int) $this->connection->fetchOne(
            'SELECT is_scheduled FROM campaign_lead_event_log WHERE event_id = ? AND lead_id = ?',
            [$this->eventId, $lead]
        ), 'O contato em trânsito deve ser concluído.');
    }

    public function testQueuedLogExistsBeforeTheMessageIsPublished(): void
    {
        $lead = $this->createLead('+5511900000412');
        $this->addLeadToCampaign($this->campaignId, $lead);

        // O transporte in-memory não deixa observar a ordem; um listener no bus sim
        $logAtDispatch = null;
        $dispatcher    = $this->getService('event_dispatcher');
        $listener      = function (\Symfony\Component\Messenger\Event\SendMessageToTransportsEvent $event) use (&$logAtDispatch, $lead): void {
            $logAtDispatch = $this->connection->fetchOne(
                'SELECT status FROM dialog_hsm_message_log WHERE lead_id = ? AND wamid = ?',
                [$lead, $event->getEnvelope()->getMessage()->queueLogId]
            );
        };
        $dispatcher->addListener(\Symfony\Component\Messenger\Event\SendMessageToTransportsEvent::class, $listener);

        try {
            $this->trigger();
        } finally {
            $dispatcher->removeListener(\Symfony\Component\Messenger\Event\SendMessageToTransportsEvent::class, $listener);
        }

        $this->assertSame('queued', $logAtDispatch, 'Um worker rápido consumia antes do log existir e o queued ficava órfão.');
    }
}
