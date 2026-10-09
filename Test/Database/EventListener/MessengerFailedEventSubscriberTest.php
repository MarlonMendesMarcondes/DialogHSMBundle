<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Database\EventListener;

use MauticPlugin\DialogHSMBundle\EventListener\MessengerFailedEventSubscriber;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Test\Database\DatabaseTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

class MessengerFailedEventSubscriberTest extends DatabaseTestCase
{
    public function testDlqOfMarketingMessageUpdatesTheQueuedLogInsteadOfCreatingAnother(): void
    {
        $leadId = $this->createLead('+5511900000901');
        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $leadId, 'status' => 'queued', 'template_name' => 'template_teste',
            'phone_number' => '+5511900000901', 'date_sent' => $this->utcNow(),
        ]);
        $queuedId = (int) $this->connection->lastInsertId();

        // Marketing Messages / broadcast: queueLogId é o id numérico do log
        $message = new SendWhatsAppMessage(
            leadId: $leadId, phone: '+5511900000901', apiKey: 'K', baseUrl: 'https://127.0.0.1/m',
            payloadData: [], templateName: 'template_teste', queueLogId: (string) $queuedId,
        );

        $this->getService(MessengerFailedEventSubscriber::class)
            ->onMessageFailed(new WorkerMessageFailedEvent(new Envelope($message), 'whatsapp', new \RuntimeException('falhou 3x')));

        $rows = $this->connection->fetchAllAssociative('SELECT id, status FROM dialog_hsm_message_log WHERE lead_id = ? ORDER BY id', [$leadId]);

        $this->assertSame([['id' => $queuedId, 'status' => 'dlq']], array_map(static fn ($r) => ['id' => (int) $r['id'], 'status' => $r['status']], $rows));
    }
}
