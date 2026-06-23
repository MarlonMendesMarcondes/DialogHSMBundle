<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Command;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'dialoghsm:backfill-lead-event-log',
    description: 'Retroativamente popula lead_event_log com os eventos de envio WhatsApp existentes em dialog_hsm_message_log'
)]
class BackfillLeadEventLogCommand extends Command
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Mostra quantos eventos seriam criados sem efetuar alterações'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('DialogHSM — Backfill de lead_event_log');

        if ($dryRun) {
            $io->note('Modo dry-run: nenhuma alteração será gravada.');
        }

        $conn          = $this->em->getConnection();
        $logTable      = $this->em->getClassMetadata(MessageLog::class)->getTableName();
        $eventTable    = $this->em->getClassMetadata(LeadEventLog::class)->getTableName();

        $total     = (int) $conn->fetchOne("SELECT COUNT(*) FROM {$logTable} m INNER JOIN leads l ON l.id = m.lead_id");
        $io->text("Total de registros em dialog_hsm_message_log: {$total}");
        $io->newLine();

        $offset  = 0;
        $created = 0;
        $skipped = 0;

        $io->progressStart($total);

        while (true) {
            $rows = $conn->fetchAllAssociative(
                "SELECT m.id, m.lead_id, m.status, m.template_name, m.sender_name, m.phone_number, m.wamid, m.campaign_id, m.date_sent, m.date_delivered, m.date_read, m.error_message, m.webhook_error_code
                 FROM {$logTable} m
                 INNER JOIN leads l ON l.id = m.lead_id
                 ORDER BY m.id ASC
                 LIMIT :limit OFFSET :offset",
                ['limit' => self::BATCH_SIZE, 'offset' => $offset],
                ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $events = $this->resolveEvents($row);

                foreach ($events as [$action, $date]) {
                    $exists = $conn->fetchOne(
                        "SELECT id FROM {$eventTable}
                         WHERE bundle = :bundle AND object = :object AND action = :action AND object_id = :object_id
                         LIMIT 1",
                        [
                            'bundle'    => LeadEventLogWriter::BUNDLE,
                            'object'    => LeadEventLogWriter::OBJECT,
                            'action'    => $action,
                            'object_id' => (int) $row['id'],
                        ]
                    );

                    if ($exists !== false) {
                        ++$skipped;
                        continue;
                    }

                    if (!$dryRun) {
                        $conn->insert($eventTable, [
                            'lead_id'    => (int) $row['lead_id'],
                            'bundle'     => LeadEventLogWriter::BUNDLE,
                            'object'     => LeadEventLogWriter::OBJECT,
                            'object_id'  => (int) $row['id'],
                            'action'     => $action,
                            'date_added' => $date,
                            'properties' => json_encode(array_filter([
                                'template_name'      => $row['template_name'],
                                'sender_name'        => $row['sender_name'],
                                'phone_number'       => $row['phone_number'],
                                'wamid'              => $row['wamid'],
                                'campaign_id'        => $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null,
                                'date_sent'          => $row['date_sent'],
                                'date_delivered'     => $row['date_delivered'],
                                'date_read'          => $row['date_read'],
                                'error_message'      => $row['error_message'],
                                'webhook_error_code' => $row['webhook_error_code'] !== null ? (int) $row['webhook_error_code'] : null,
                            ], static fn ($v) => $v !== null && $v !== '')),
                        ]);
                    }

                    ++$created;
                }
            }

            $io->progressAdvance(count($rows));
            $offset += self::BATCH_SIZE;
        }

        $io->progressFinish();
        $io->newLine();

        $label = $dryRun ? 'seriam criados' : 'criados';
        $io->success(sprintf(
            '%d eventos %s, %d já existiam (ignorados).%s',
            $created,
            $label,
            $skipped,
            $dryRun ? ' (dry-run — sem alterações reais)' : ''
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<array{string, string}>  [action, date_added]
     */
    private function resolveEvents(array $row): array
    {
        $events = [];

        if (!empty($row['date_sent'])) {
            $events[] = [MessageLog::STATUS_SENT, $row['date_sent']];
        }

        if (!empty($row['date_delivered'])) {
            $events[] = [MessageLog::STATUS_DELIVERED, $row['date_delivered']];
        }

        if (!empty($row['date_read'])) {
            $events[] = [MessageLog::STATUS_READ, $row['date_read']];
        }

        if (in_array($row['status'], [MessageLog::STATUS_FAILED, MessageLog::STATUS_DLQ], true) && !empty($row['date_sent'])) {
            $events[] = [MessageLog::STATUS_FAILED, $row['date_sent']];
        }

        return $events;
    }
}
