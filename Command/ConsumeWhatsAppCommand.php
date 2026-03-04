<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Command;

use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'dialoghsm:consume',
    description: 'Consume WhatsApp message queue (uses limit from plugin settings)'
)]
class ConsumeWhatsAppCommand extends Command
{
    public function __construct(
        private IntegrationsHelper $integrationsHelper,
        private WhatsAppNumberRepository $whatsAppNumberRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_OPTIONAL,
            'Override the consumer limit from plugin settings'
        );

        $this->addOption(
            'queue',
            null,
            InputOption::VALUE_OPTIONAL,
            'Consume only the specified RabbitMQ queue by exact name.'
        );

        $this->addOption(
            'mode',
            null,
            InputOption::VALUE_OPTIONAL,
            'Consume queues by type: "bulk" (queue_name of each number) or "batch" (batch_queue_name of each number). Overridden by --queue if both are set.'
        );

        $this->addOption(
            'time-limit',
            null,
            InputOption::VALUE_OPTIONAL,
            'Stop consumer after this many seconds (0 = no time limit).'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit') !== null
            ? max(1, (int) $input->getOption('limit'))
            : $this->getConsumerLimit();

        $queues = $this->resolveQueues($input);

        if (empty($queues)) {
            $output->writeln(sprintf('<info>DialogHSM: consuming all whatsapp queues (limit=%d)</info>', $limit));
        } elseif (count($queues) === 1) {
            $output->writeln(sprintf('<info>DialogHSM: consuming queue "%s" (limit=%d)</info>', $queues[0], $limit));
        } else {
            $output->writeln(sprintf('<info>DialogHSM: consuming queues [%s] (limit=%d)</info>', implode(', ', $queues), $limit));
        }

        $subCommand = $this->getApplication()->find('messenger:consume');
        $timeLimit  = $input->getOption('time-limit') !== null
            ? max(0, (int) $input->getOption('time-limit'))
            : 60;

        $subArgs = [
            'command'   => 'messenger:consume',
            'receivers' => ['whatsapp'],
            '--limit'   => (string) $limit,
        ];

        if (!empty($queues)) {
            $subArgs['--queues'] = $queues;
        }

        if ($timeLimit > 0) {
            $subArgs['--time-limit'] = (string) $timeLimit;
        }

        $subInput = new ArrayInput($subArgs);
        $subInput->setInteractive(false);

        return $subCommand->run($subInput, $output);
    }

    /**
     * @return string[]
     */
    private function resolveQueues(InputInterface $input): array
    {
        if ($input->getOption('queue')) {
            return [(string) $input->getOption('queue')];
        }

        return match ($input->getOption('mode')) {
            'bulk'  => $this->whatsAppNumberRepository->getDistinctBulkQueueNames(),
            'batch' => $this->whatsAppNumberRepository->getDistinctBatchQueueNames(),
            default => [],
        };
    }

    private function getConsumerLimit(): int
    {
        try {
            $integration = $this->integrationsHelper->getIntegration(DialogHSMIntegration::NAME);
            $apiKeys     = $integration->getIntegrationConfiguration()->getApiKeys() ?? [];

            return max(1, (int) ($apiKeys['consumer_limit'] ?? 50));
        } catch (\Exception) {
            return 50;
        }
    }
}
