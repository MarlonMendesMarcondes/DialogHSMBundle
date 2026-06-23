<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Command;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\LeadEventLog as CampaignLeadEventLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'dialoghsm:backfill-campaign-metadata',
    description: 'Retroativamente popula metadata.whatsapp em campaign_lead_event_log para as ações DialogHSM'
)]
class BackfillCampaignMetadataCommand extends Command
{
    private const BATCH_SIZE = 500;

    private const ACTION_TYPES = [
        'dialoghsm.send_whatsapp',
        'dialoghsm.send_whatsapp_queue',
        'dialoghsm.send_whatsapp_message',
    ];

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
            'Mostra quantos registros seriam atualizados sem efetuar alterações'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('DialogHSM — Backfill de metadata em campaign_lead_event_log');

        if ($dryRun) {
            $io->note('Modo dry-run: nenhuma alteração será gravada.');
        }

        $conn         = $this->em->getConnection();
        $campaignMeta = $this->em->getClassMetadata(CampaignLeadEventLog::class);
        $clelTable    = $campaignMeta->getTableName();
        $logTable     = $this->em->getClassMetadata(MessageLog::class)->getTableName();

        $actionPlaceholders = implode(', ', array_fill(0, count(self::ACTION_TYPES), '?'));

        $total = (int) $conn->fetchOne(
            "SELECT COUNT(DISTINCT cle.id)
             FROM {$clelTable} cle
             INNER JOIN campaign_events ce ON ce.id = cle.event_id
             INNER JOIN {$logTable} m ON m.campaign_event_id = cle.event_id AND m.lead_id = cle.lead_id
             WHERE ce.type IN ({$actionPlaceholders})
               AND (cle.metadata IS NULL OR cle.metadata NOT LIKE '%\"whatsapp\"%')",
            self::ACTION_TYPES
        );

        $io->text("Registros a processar: {$total}");
        $io->newLine();

        if (0 === $total) {
            $io->success('Nenhum registro para atualizar.');

            return Command::SUCCESS;
        }

        $offset  = 0;
        $updated = 0;
        $skipped = 0;

        $io->progressStart($total);

        while (true) {
            $rows = $conn->fetchAllAssociative(
                "SELECT cle.id AS cle_id, cle.metadata,
                        m.template_name, m.sender_name, m.phone_number, m.wamid,
                        m.status, m.date_sent, m.error_message
                 FROM {$clelTable} cle
                 INNER JOIN campaign_events ce ON ce.id = cle.event_id
                 INNER JOIN {$logTable} m ON m.campaign_event_id = cle.event_id AND m.lead_id = cle.lead_id
                 WHERE ce.type IN ({$actionPlaceholders})
                   AND (cle.metadata IS NULL OR cle.metadata NOT LIKE '%\"whatsapp\"%')
                 ORDER BY cle.id ASC
                 LIMIT ? OFFSET ?",
                [...self::ACTION_TYPES, self::BATCH_SIZE, $offset],
                [...array_fill(0, count(self::ACTION_TYPES), ParameterType::STRING), ParameterType::INTEGER, ParameterType::INTEGER]
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $existing = [];
                if (!empty($row['metadata'])) {
                    $existing = json_decode($row['metadata'], true) ?? [];
                }

                $waData = array_filter([
                    'template_name' => $row['template_name'],
                    'sender_name'   => $row['sender_name'],
                    'phone_number'  => $row['phone_number'],
                    'wamid'         => $row['wamid'],
                    'status'        => $row['status'],
                    'date_sent'     => $row['date_sent'],
                    'error_message' => $row['error_message'],
                ], static fn ($v) => $v !== null && $v !== '');

                if (empty($waData)) {
                    ++$skipped;
                    continue;
                }

                $existing['whatsapp'] = $waData;

                if (!$dryRun) {
                    $conn->update(
                        $clelTable,
                        ['metadata' => json_encode($existing)],
                        ['id' => (int) $row['cle_id']],
                        ['metadata' => ParameterType::STRING, 'id' => ParameterType::INTEGER]
                    );
                }

                ++$updated;
            }

            $io->progressAdvance(count($rows));
            $offset += self::BATCH_SIZE;
        }

        $io->progressFinish();
        $io->newLine();

        $label = $dryRun ? 'seriam atualizados' : 'atualizados';
        $io->success(sprintf(
            '%d registros %s, %d ignorados (sem dados WhatsApp).%s',
            $updated,
            $label,
            $skipped,
            $dryRun ? ' (dry-run — sem alterações reais)' : ''
        ));

        return Command::SUCCESS;
    }
}
