<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Database\Entity;

use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessageRepository;
use MauticPlugin\DialogHSMBundle\Test\Database\DatabaseTestCase;

class WhatsAppMessageRepositoryTest extends DatabaseTestCase
{
    private WhatsAppMessageRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->em->getRepository(WhatsAppMessage::class);
    }

    private function createBroadcast(int ...$segmentIds): int
    {
        $this->connection->insert('dialog_hsm_whatsapp_messages', [
            'name'               => 'Broadcast teste',
            'whatsapp_number_id' => $this->createWhatsAppNumber(),
            'template_name'      => 'template_teste',
            'is_published'       => 1,
            'payload_data'       => json_encode(['content' => 'template_teste']),
        ]);
        $messageId = (int) $this->connection->lastInsertId();

        foreach ($segmentIds as $segmentId) {
            $this->connection->insert('dialog_hsm_wa_msg_list_xref', ['whatsapp_message_id' => $messageId, 'leadlist_id' => $segmentId]);
        }

        return $messageId;
    }

    private function createSegment(int ...$leadIds): int
    {
        $alias = 'teste-db-'.bin2hex(random_bytes(4));
        $this->connection->insert('lead_lists', [
            'is_published' => 1, 'name' => $alias, 'alias' => $alias, 'public_name' => $alias,
            'filters' => 'a:0:{}', 'is_global' => 0, 'is_preference_center' => 0,
        ]);
        $segmentId = (int) $this->connection->lastInsertId();

        foreach ($leadIds as $leadId) {
            $this->connection->insert('lead_lists_leads', [
                'leadlist_id' => $segmentId, 'lead_id' => $leadId, 'date_added' => $this->utcNow(),
                'manually_removed' => 0, 'manually_added' => 1,
            ]);
        }

        return $segmentId;
    }

    public function testContactInSeveralSegmentsOfTheBroadcastComesOnce(): void
    {
        $lead      = $this->createLead('+5511900000301');
        $messageId = $this->createBroadcast($this->createSegment($lead), $this->createSegment($lead));

        $pending = $this->repository->getPendingContacts($messageId);

        $this->assertCount(1, $pending, 'Contato em 2 segmentos da mensagem recebia um HSM por segmento.');
        $this->assertSame($lead, (int) $pending[0]['id']);
    }

    public function testEmptyMobileFallsBackToPhone(): void
    {
        $lead      = $this->createLead('', '+5511900000302');
        $messageId = $this->createBroadcast($this->createSegment($lead));

        $pending = $this->repository->getPendingContacts($messageId);

        $this->assertSame('+5511900000302', $pending[0]['phone']);
    }

    public function testContactThatAlreadyReceivedIsNotPending(): void
    {
        $sent      = $this->createLead('+5511900000303');
        $notSent   = $this->createLead('+5511900000304');
        $messageId = $this->createBroadcast($this->createSegment($sent, $notSent));

        $this->connection->insert('dialog_hsm_message_log', [
            'lead_id' => $sent, 'whatsapp_message_id' => $messageId, 'status' => 'sent',
            'template_name' => 'template_teste', 'phone_number' => '+5511900000303', 'date_sent' => $this->utcNow(),
        ]);

        $pending = $this->repository->getPendingContacts($messageId);

        $this->assertSame([$notSent], array_map(static fn (array $row): int => (int) $row['id'], $pending));
    }
}
