<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Database\Entity;

use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Test\Database\DatabaseTestCase;

class MessageLogRepositoryTest extends DatabaseTestCase
{
    private MessageLogRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->em->getRepository(MessageLog::class);
        // prune() age sobre a tabela inteira: começa vazia dentro da transação do teste
        $this->connection->executeStatement('DELETE FROM dialog_hsm_message_log');
    }

    private function insertLog(string $status, string $sentModifier, array $extra = []): int
    {
        $this->connection->insert('dialog_hsm_message_log', $extra + [
            'lead_id' => 1, 'status' => $status, 'template_name' => 'template_teste',
            'phone_number' => '+5511900000500', 'date_sent' => $this->utcNow($sentModifier),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return int[]
     */
    private function remainingIds(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM dialog_hsm_message_log ORDER BY id'));
    }

    public function testPruneByAgeRemovesOldLogsButKeepsMessagesStillInTheQueue(): void
    {
        $oldSent   = $this->insertLog('delivered', '-40 days');
        $oldQueued = $this->insertLog('queued', '-40 days');
        $recent    = $this->insertLog('sent', '-1 day');

        $this->repository->prune(maxRecords: 0, maxDays: 30);

        $this->assertNotContains($oldSent, $this->remainingIds());
        $this->assertContains($oldQueued, $this->remainingIds(), "Log 'queued' de mensagem ainda no RabbitMQ (ex.: campanha pausada) não pode ser apagado.");
        $this->assertContains($recent, $this->remainingIds());
    }

    public function testPruneByCountRemovesOldestButKeepsMessagesStillInTheQueue(): void
    {
        $queued = $this->insertLog('queued', '-10 days');
        $old    = $this->insertLog('sent', '-9 days');
        $newer  = $this->insertLog('sent', '-1 day');

        $this->repository->prune(maxRecords: 2, maxDays: 0);

        $this->assertSame([$queued, $newer], $this->remainingIds());
        $this->assertNotContains($old, $this->remainingIds());
    }

    public function testFindByCampaignEventAndLeadReturnsTheMostRecentSend(): void
    {
        $this->insertLog('delivered', '-2 days', ['lead_id' => 7, 'campaign_event_id' => 3]);
        $latest = $this->insertLog('queued', 'now', ['lead_id' => 7, 'campaign_event_id' => 3]);
        $this->insertLog('sent', 'now', ['lead_id' => 8, 'campaign_event_id' => 3]);

        $this->assertSame($latest, $this->repository->findByCampaignEventAndLead(3, 7)?->getId());
        $this->assertNull($this->repository->findByCampaignEventAndLead(4, 7));
    }

    public function testFindByWamidFindsTheTemporaryQueueId(): void
    {
        $id = $this->insertLog('queued', 'now', ['wamid' => 'uuid-da-fila']);

        $this->assertSame($id, $this->repository->findByWamid('uuid-da-fila')?->getId());
    }

    public function testChartShowsMessagesWaitingForMetaConfirmation(): void
    {
        $this->insertLog('pending_webhook', '-1 minute');
        $this->insertLog('pending_webhook', '-1 minute');
        $this->insertLog('delivered', '-1 minute');

        $today = (new \DateTime('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $chart = $this->repository->getChartData(1, 'America/Sao_Paulo');

        $this->assertSame(2, $chart[$today]['pending_webhook'] ?? null, 'pending_webhook era descartado do gráfico.');
        $this->assertSame(1, $chart[$today]['sent'], 'Funil cumulativo preservado: delivered conta em sent.');
    }
}
