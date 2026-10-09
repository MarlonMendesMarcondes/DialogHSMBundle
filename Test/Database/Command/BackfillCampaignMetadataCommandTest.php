<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Database\Command;

use Mautic\CampaignBundle\Entity\LeadEventLog;
use MauticPlugin\DialogHSMBundle\Test\Database\DatabaseTestCase;

class BackfillCampaignMetadataCommandTest extends DatabaseTestCase
{
    public function testFillsEveryActionLogInMauticFormatAcrossSeveralBatches(): void
    {
        $campaignId = $this->createCampaign();
        $eventId    = $this->createQueueActionEvent($campaignId, $this->createWhatsAppNumber());

        // Mais que um lote (500): com paginação por OFFSET só ~metade era preenchida
        $total = 1001;
        for ($i = 0; $i < $total; ++$i) {
            $leadId = $this->createLead(sprintf('+5511%09d', $i));
            $this->connection->insert('campaign_lead_event_log', [
                'event_id' => $eventId, 'lead_id' => $leadId, 'campaign_id' => $campaignId, 'rotation' => 1,
                'is_scheduled' => 0, 'system_triggered' => 1, 'date_triggered' => $this->utcNow(), 'metadata' => serialize([]),
            ]);
            $this->connection->insert('dialog_hsm_message_log', [
                'lead_id' => $leadId, 'campaign_event_id' => $eventId, 'status' => 'delivered', 'template_name' => 'template_teste',
                'phone_number' => sprintf('+5511%09d', $i), 'date_sent' => $this->utcNow(),
            ]);
        }

        $this->runCommand('dialoghsm:backfill-campaign-metadata');

        $missing = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM campaign_lead_event_log WHERE event_id = ? AND metadata NOT LIKE '%\"whatsapp\"%'",
            [$eventId]
        );
        $this->assertSame(0, $missing, 'Registros pulados pela paginação.');

        $this->em->clear();
        $sample = $this->em->getRepository(LeadEventLog::class)->findOneBy(['event' => $eventId]);
        $this->assertSame('delivered', $sample->getMetadata()['whatsapp']['status'] ?? null, 'Backfill gravava JSON, que o Mautic lê como [].');
    }
}
