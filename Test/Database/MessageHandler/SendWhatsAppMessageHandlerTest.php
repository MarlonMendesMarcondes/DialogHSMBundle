<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Database\MessageHandler;

use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Test\Database\DatabaseTestCase;

class SendWhatsAppMessageHandlerTest extends DatabaseTestCase
{
    public function testConsumingFromTheQueueAlsoPrunesOldLogs(): void
    {
        $this->connection->executeStatement('DELETE FROM dialog_hsm_message_log');

        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => 1, 'status' => 'delivered', 'template_name' => 'template_teste',
            'phone_number' => '+5511900000600', 'date_sent' => $this->utcNow('-40 days'),
        ]);
        $oldLogId = (int) $this->connection->lastInsertId();

        // Log 'queued' criado no dispatch, como o CampaignSubscriber faz
        $lead = $this->createLead('+5511900000601');
        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $lead, 'status' => 'queued', 'template_name' => 'template_teste',
            'phone_number' => '+5511900000601', 'wamid' => 'uuid-fila-1', 'date_sent' => $this->utcNow(),
        ]);

        /** @var SendWhatsAppMessageHandler $handler */
        $handler = $this->getService(SendWhatsAppMessageHandler::class);

        // IP interno: recusado pela proteção de SSRF antes de qualquer conexão (falha permanente).
        // O que importa é o housekeeping depois de gravar o log.
        $handler(new SendWhatsAppMessage(
            leadId:             $lead,
            phone:              '+5511900000601',
            apiKey:             'TEST_FAKE_KEY',
            baseUrl:            'https://127.0.0.1/messages',
            payloadData:        ['content' => 'template_teste'],
            templateName:       'template_teste',
            queueLogId:         'uuid-fila-1',
        ));

        $this->assertFalse(
            $this->connection->fetchOne('SELECT 1 FROM dialog_hsm_message_log WHERE id = ?', [$oldLogId]),
            'O prune não rodava no caminho da fila: log_max_days era ignorado e a tabela crescia sem limite.'
        );
    }

    public function testMessageOfInactiveCampaignStaysQueuedAndIsNotSent(): void
    {
        $campaignId = $this->createCampaign(['is_published' => 0]);
        $lead       = $this->createLead('+5511900000602');
        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $lead, 'campaign_id' => $campaignId, 'status' => 'queued', 'template_name' => 'template_teste',
            'phone_number' => '+5511900000602', 'wamid' => 'uuid-fila-pausada', 'date_sent' => $this->utcNow(),
        ]);

        $this->getService(\MauticPlugin\DialogHSMBundle\Service\WorkerQueueContext::class)->setCurrentQueueName('dialoghsm_test_bulk');

        /** @var SendWhatsAppMessageHandler $handler */
        $handler = $this->getService(SendWhatsAppMessageHandler::class);
        $result  = $handler(new SendWhatsAppMessage(
            leadId:       $lead,
            phone:        '+5511900000602',
            apiKey:       'TEST_FAKE_KEY',
            baseUrl:      'https://127.0.0.1/messages',
            payloadData:  ['content' => 'template_teste'],
            templateName: 'template_teste',
            campaignId:   $campaignId,
            queueLogId:   'uuid-fila-pausada',
        ));

        $this->assertTrue($result['paused'] ?? false);
        $this->assertSame('queued', $this->connection->fetchOne("SELECT status FROM dialog_hsm_message_log WHERE wamid = 'uuid-fila-pausada'"));
    }

    public function testMessageOfDeletedCampaignIsDiscardedWithReason(): void
    {
        $lead = $this->createLead('+5511900000603');
        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $lead, 'campaign_id' => 999999999, 'status' => 'queued', 'template_name' => 'template_teste',
            'phone_number' => '+5511900000603', 'wamid' => 'uuid-fila-removida', 'date_sent' => $this->utcNow(),
        ]);

        /** @var SendWhatsAppMessageHandler $handler */
        $handler = $this->getService(SendWhatsAppMessageHandler::class);
        $handler(new SendWhatsAppMessage(
            leadId:       $lead,
            phone:        '+5511900000603',
            apiKey:       'TEST_FAKE_KEY',
            baseUrl:      'https://127.0.0.1/messages',
            payloadData:  ['content' => 'template_teste'],
            templateName: 'template_teste',
            campaignId:   999999999,
            queueLogId:   'uuid-fila-removida',
        ));

        $row = $this->connection->fetchAssociative("SELECT status, error_message FROM dialog_hsm_message_log WHERE lead_id = ?", [$lead]);
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('Campanha removida', (string) $row['error_message']);
    }

    public function testMessageWhoseQueuedLogWasClearedIsNotSent(): void
    {
        // "Limpar mensagens pendentes" apaga o log; a mensagem continua no RabbitMQ
        $campaignId = $this->createCampaign(['is_published' => 0]);
        $lead       = $this->createLead('+5511900000604');

        /** @var SendWhatsAppMessageHandler $handler */
        $handler = $this->getService(SendWhatsAppMessageHandler::class);
        $result  = $handler(new SendWhatsAppMessage(
            leadId:       $lead,
            phone:        '+5511900000604',
            apiKey:       'TEST_FAKE_KEY',
            baseUrl:      'https://127.0.0.1/messages',
            payloadData:  ['content' => 'template_teste'],
            templateName: 'template_teste',
            campaignId:   $campaignId, // pausada: o cancelamento vale antes da retenção
            queueLogId:   'uuid-removido',
        ));

        $this->assertTrue($result['cancelled'] ?? false);
        $this->assertArrayNotHasKey('paused', $result, 'Mensagem cancelada não pode voltar para a fila.');
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM dialog_hsm_message_log WHERE lead_id = ?', [$lead]), 'Nenhum log novo pode ser recriado.');
    }
}
