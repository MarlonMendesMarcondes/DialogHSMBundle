<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Database\Model;

use Mautic\ChannelBundle\Event\ChannelBroadcastEvent;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppMessageModel;
use MauticPlugin\DialogHSMBundle\Test\Database\DatabaseTestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Broadcast completo: segmentos → contatos pendentes → MessageLog 'queued' → lote para o
 * transporte direto (null:// nos testes, nada é enviado).
 */
class WhatsAppMessageModelTest extends DatabaseTestCase
{
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

    private function broadcast(int ...$segmentIds): array
    {
        return $this->broadcastWith(null, ...$segmentIds);
    }

    private function broadcastWith(?callable $configure, int ...$segmentIds): array
    {
        $this->connection->insert('dialog_hsm_whatsapp_messages', [
            'name' => 'Broadcast teste', 'whatsapp_number_id' => $this->createWhatsAppNumber(),
            'template_name' => 'template_teste', 'is_published' => 1,
            'payload_data' => json_encode(['content' => 'template_teste']),
        ]);
        $messageId = (int) $this->connection->lastInsertId();
        foreach ($segmentIds as $segmentId) {
            $this->connection->insert('dialog_hsm_wa_msg_list_xref', ['whatsapp_message_id' => $messageId, 'leadlist_id' => $segmentId]);
        }

        /** @var WhatsAppMessageModel $model */
        $model   = $this->getService(WhatsAppMessageModel::class);
        $message = $this->em->find(WhatsAppMessage::class, $messageId);

        $event = new ChannelBroadcastEvent('dialoghsm', $messageId, new NullOutput());
        if (null !== $configure) {
            $configure($event);
        }

        return [$messageId, $model->sendToLists($message, $event)];
    }

    private function logsForBroadcast(int $messageId): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT lead_id FROM dialog_hsm_message_log WHERE whatsapp_message_id = ? ORDER BY lead_id',
            [$messageId]
        ));
    }

    public function testEachContactGetsExactlyOneQueuedLog(): void
    {
        $inBoth   = $this->createLead('+5511900000801');
        $onlyOne  = $this->createLead('', '+5511900000802');
        $noPhone  = $this->createLead();

        [$messageId] = $this->broadcast($this->createSegment($inBoth, $onlyOne, $noPhone), $this->createSegment($inBoth));

        $this->assertSame([$inBoth, $onlyOne], $this->logsForBroadcast($messageId));
    }

    public function testSecondRunDoesNotResendToWhoAlreadyHasALog(): void
    {
        $lead = $this->createLead('+5511900000803');
        [$messageId] = $this->broadcast($this->createSegment($lead));

        /** @var WhatsAppMessageModel $model */
        $model = $this->getService(WhatsAppMessageModel::class);
        $model->sendToLists($this->em->find(WhatsAppMessage::class, $messageId), new ChannelBroadcastEvent('dialoghsm', $messageId, new NullOutput()));

        $this->assertSame([$lead], $this->logsForBroadcast($messageId));
    }

    /**
     * @return int[]
     */
    private function fiveLeads(): array
    {
        return array_map(fn (int $i): int => $this->createLead(sprintf('+55119000008%02d', 10 + $i)), range(1, 5));
    }

    public function testLimitCapsTheContactsOfOneRun(): void
    {
        [$messageId] = $this->broadcastWith(fn (ChannelBroadcastEvent $e) => $e->setLimit(2), $this->createSegment(...$this->fiveLeads()));

        $this->assertCount(2, $this->logsForBroadcast($messageId), '--limit era ignorado: a base inteira saía em toda execução.');
    }

    public function testSmallBatchStillReachesEveryContact(): void
    {
        $leads = $this->fiveLeads();
        [$messageId] = $this->broadcastWith(fn (ChannelBroadcastEvent $e) => $e->setBatch(2), $this->createSegment(...$leads));

        $this->assertSame($leads, $this->logsForBroadcast($messageId));
    }

    public function testContactIdRangeIsRespected(): void
    {
        $leads = $this->fiveLeads();
        [$messageId] = $this->broadcastWith(function (ChannelBroadcastEvent $e) use ($leads): void {
            $e->setMinContactIdFilter($leads[1]);
            $e->setMaxContactIdFilter($leads[3]);
        }, $this->createSegment(...$leads));

        $this->assertSame(array_slice($leads, 1, 3), $this->logsForBroadcast($messageId));
    }
}
