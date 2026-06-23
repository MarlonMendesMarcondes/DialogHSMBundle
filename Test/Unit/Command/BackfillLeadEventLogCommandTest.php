<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use MauticPlugin\DialogHSMBundle\Command\BackfillLeadEventLogCommand;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class BackfillLeadEventLogCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private Connection&MockObject             $connection;
    private CommandTester                     $tester;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);

        $this->em->method('getConnection')->willReturn($this->connection);
        $this->em->method('getClassMetadata')->willReturnCallback(function (string $class) {
            $meta = $this->createMock(ClassMetadata::class);
            $meta->method('getTableName')->willReturn(
                $class === MessageLog::class ? 'dialog_hsm_message_log' : 'lead_event_log'
            );

            return $meta;
        });

        $this->tester = new CommandTester(new BackfillLeadEventLogCommand($this->em));
    }

    private function makeRow(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 1,
            'lead_id'            => 10,
            'status'             => MessageLog::STATUS_SENT,
            'template_name'      => 'template_a',
            'sender_name'        => 'Número Teste',
            'phone_number'       => '+5511999999999',
            'wamid'              => 'wamid.abc',
            'campaign_id'        => null,
            'date_sent'          => '2025-01-10 10:00:00',
            'date_delivered'     => null,
            'date_read'          => null,
            'error_message'      => null,
            'webhook_error_code' => null,
        ], $overrides);
    }

    private function mockRows(array $rows): void
    {
        $this->connection->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) {
                if (str_contains($sql, 'COUNT(*)')) {
                    return (string) count([]);
                }

                return false;
            });

        $calls = 0;
        $this->connection->method('fetchAllAssociative')
            ->willReturnCallback(function () use ($rows, &$calls) {
                return $calls++ === 0 ? $rows : [];
            });
    }

    // =========================================================================
    // Retorno e saída básica
    // =========================================================================

    public function testCommandReturnsSuccess(): void
    {
        $this->connection->method('fetchOne')->willReturn('0');
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $this->tester->execute([]);

        $this->assertSame(0, $this->tester->getStatusCode());
    }

    public function testOutputContainsTitle(): void
    {
        $this->connection->method('fetchOne')->willReturn('0');
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $this->tester->execute([]);

        $this->assertStringContainsString('DialogHSM', $this->tester->getDisplay());
    }

    // =========================================================================
    // Dry-run
    // =========================================================================

    public function testDryRunDoesNotInsert(): void
    {
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', false);

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$this->makeRow()], []);

        $this->connection->expects($this->never())->method('insert');

        $this->tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $this->tester->getStatusCode());
    }

    public function testDryRunOutputMentionsDryRun(): void
    {
        $this->connection->method('fetchOne')->willReturn('0');
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $this->tester->execute(['--dry-run' => true]);

        $this->assertStringContainsStringIgnoringCase('dry-run', $this->tester->getDisplay());
    }

    public function testDryRunReportsCountWithoutInserting(): void
    {
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', false, false);

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$this->makeRow()], []);

        $this->tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('1 eventos seriam criados', $this->tester->getDisplay());
    }

    // =========================================================================
    // Mapeamento de eventos por status/data
    // =========================================================================

    public function testRowWithDateSentCreatesSentEvent(): void
    {
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', false);

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$this->makeRow(['date_sent' => '2025-01-10 10:00:00'])], []);

        $insertedActions = [];
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$insertedActions): int {
                $insertedActions[] = $data['action'];

                return 1;
            });

        $this->tester->execute([]);

        $this->assertContains(MessageLog::STATUS_SENT, $insertedActions);
    }

    public function testRowWithDateDeliveredCreatesDeliveredEvent(): void
    {
        $row = $this->makeRow([
            'date_sent'      => '2025-01-10 10:00:00',
            'date_delivered' => '2025-01-10 10:05:00',
        ]);

        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', false, false);

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$row], []);

        $insertedActions = [];
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$insertedActions): int {
                $insertedActions[] = $data['action'];

                return 1;
            });

        $this->tester->execute([]);

        $this->assertContains(MessageLog::STATUS_DELIVERED, $insertedActions);
    }

    public function testRowWithDateReadCreatesReadEvent(): void
    {
        $row = $this->makeRow([
            'date_sent' => '2025-01-10 10:00:00',
            'date_read' => '2025-01-10 10:10:00',
        ]);

        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', false, false);

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$row], []);

        $insertedActions = [];
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$insertedActions): int {
                $insertedActions[] = $data['action'];

                return 1;
            });

        $this->tester->execute([]);

        $this->assertContains(MessageLog::STATUS_READ, $insertedActions);
    }

    public function testRowWithAllDatesCreatesThreeEvents(): void
    {
        $row = $this->makeRow([
            'date_sent'      => '2025-01-10 10:00:00',
            'date_delivered' => '2025-01-10 10:05:00',
            'date_read'      => '2025-01-10 10:10:00',
        ]);

        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', false, false, false);

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$row], []);

        $insertedActions = [];
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$insertedActions): int {
                $insertedActions[] = $data['action'];

                return 1;
            });

        $this->tester->execute([]);

        $this->assertCount(3, $insertedActions);
        $this->assertContains(MessageLog::STATUS_SENT, $insertedActions);
        $this->assertContains(MessageLog::STATUS_DELIVERED, $insertedActions);
        $this->assertContains(MessageLog::STATUS_READ, $insertedActions);
    }

    public function testFailedStatusWithDateSentCreatesFailedEvent(): void
    {
        $row = $this->makeRow([
            'status'     => MessageLog::STATUS_FAILED,
            'date_sent'  => '2025-01-10 10:00:00',
        ]);

        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', false, false);

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$row], []);

        $insertedActions = [];
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$insertedActions): int {
                $insertedActions[] = $data['action'];

                return 1;
            });

        $this->tester->execute([]);

        $this->assertContains(MessageLog::STATUS_FAILED, $insertedActions);
    }

    public function testDlqStatusCreatesFailedEvent(): void
    {
        $row = $this->makeRow([
            'status'    => MessageLog::STATUS_DLQ,
            'date_sent' => '2025-01-10 10:00:00',
        ]);

        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', false, false);

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$row], []);

        $insertedActions = [];
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$insertedActions): int {
                $insertedActions[] = $data['action'];

                return 1;
            });

        $this->tester->execute([]);

        $this->assertContains(MessageLog::STATUS_FAILED, $insertedActions);
    }

    public function testRowWithNullDateSentSkipsSentEvent(): void
    {
        $row = $this->makeRow(['date_sent' => null]);

        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1');

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$row], []);

        $this->connection->expects($this->never())->method('insert');

        $this->tester->execute([]);
    }

    // =========================================================================
    // Idempotência
    // =========================================================================

    public function testSkipsExistingEvents(): void
    {
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', '999');

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$this->makeRow()], []);

        $this->connection->expects($this->never())->method('insert');

        $this->tester->execute([]);

        $this->assertStringContainsString('1 já existiam', $this->tester->getDisplay());
    }

    // =========================================================================
    // Dados gravados no insert
    // =========================================================================

    public function testInsertContainsCorrectFields(): void
    {
        $row = $this->makeRow(['id' => 55, 'lead_id' => 10]);

        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', false);

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$row], []);

        $inserted = null;
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$inserted): int {
                $inserted = $data;

                return 1;
            });

        $this->tester->execute([]);

        $this->assertNotNull($inserted);
        $this->assertSame(LeadEventLogWriter::BUNDLE, $inserted['bundle']);
        $this->assertSame(LeadEventLogWriter::OBJECT, $inserted['object']);
        $this->assertSame(55, $inserted['object_id']);
        $this->assertSame(10, $inserted['lead_id']);
        $this->assertSame(MessageLog::STATUS_SENT, $inserted['action']);
    }

    public function testInsertPropertiesContainTemplateAndPhone(): void
    {
        $row = $this->makeRow([
            'template_name' => 'meu_template',
            'phone_number'  => '+5511912345678',
            'wamid'         => 'wamid.xyz',
        ]);

        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', false);

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$row], []);

        $inserted = null;
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$inserted): int {
                $inserted = $data;

                return 1;
            });

        $this->tester->execute([]);

        $props = json_decode($inserted['properties'], true);
        $this->assertSame('meu_template', $props['template_name']);
        $this->assertSame('+5511912345678', $props['phone_number']);
        $this->assertSame('wamid.xyz', $props['wamid']);
    }

    // =========================================================================
    // Resumo de saída
    // =========================================================================

    public function testOutputReportsTotalCreated(): void
    {
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', false);

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$this->makeRow()], []);

        $this->connection->method('insert')->willReturn(1);

        $this->tester->execute([]);

        $this->assertStringContainsString('1 eventos criados', $this->tester->getDisplay());
    }

    public function testEmptyTableReportsZero(): void
    {
        $this->connection->method('fetchOne')->willReturn('0');
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $this->tester->execute([]);

        $this->assertStringContainsString('0 eventos criados', $this->tester->getDisplay());
    }
}
