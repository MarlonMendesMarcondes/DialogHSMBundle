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
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'dialoghsm:consume',
    description: 'Consume WhatsApp message queue (uses limit from plugin settings)'
)]
class ConsumeWhatsAppCommand extends Command
{
    /** @var callable(string[]): Process */
    private $processFactory;

    public function __construct(
        private IntegrationsHelper $integrationsHelper,
        private WhatsAppNumberRepository $whatsAppNumberRepository,
        ?callable $processFactory = null,
    ) {
        parent::__construct();
        $this->processFactory = $processFactory ?? static fn (array $args): Process => new Process($args);
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

        $queues    = $this->resolveQueues($input);
        $timeLimit = $input->getOption('time-limit') !== null
            ? max(0, (int) $input->getOption('time-limit'))
            : 60;

        // Múltiplas filas (--mode=bulk ou --mode=batch): workers paralelos
        // Cada fila roda em processo separado com seu próprio --limit
        if (count($queues) > 1) {
            $output->writeln(sprintf(
                '<info>DialogHSM: starting %d parallel workers (limit=%d each): [%s]</info>',
                count($queues),
                $limit,
                implode(', ', $queues)
            ));

            return $this->runParallel($output, $queues, $limit, $timeLimit);
        }

        // Fila única ou sem fila: comportamento original via sub-comando interno
        $app = $this->getApplication();
        if (null === $app) {
            $output->writeln('<error>DialogHSM: application not available.</error>');

            return Command::FAILURE;
        }

        $subCommand = $app->find('messenger:consume');

        if (empty($queues)) {
            $output->writeln(sprintf('<info>DialogHSM: consuming all whatsapp queues (limit=%d)</info>', $limit));
        } else {
            $output->writeln(sprintf('<info>DialogHSM: consuming queue "%s" (limit=%d)</info>', $queues[0], $limit));
        }

        return $this->runConsumer($subCommand, $output, $queues, $limit, $timeLimit);
    }

    /**
     * Inicia um processo OS independente por fila e aguarda todos terminarem.
     *
     * @param string[] $queues
     */
    private function runParallel(OutputInterface $output, array $queues, int $limit, int $timeLimit): int
    {
        $phpBinary   = (new PhpExecutableFinder())->find(false) ?: PHP_BINARY;
        $consolePath = $_SERVER['argv'][0] ?? 'bin/console';

        $processes = [];

        foreach ($queues as $queue) {
            $args = [
                $phpBinary,
                $consolePath,
                'messenger:consume',
                'whatsapp',
                '--queues='.$queue,
                '--limit='.$limit,
            ];

            if ($timeLimit > 0) {
                $args[] = '--time-limit='.$timeLimit;
            }

            $process = ($this->processFactory)($args);
            $process->setTimeout($timeLimit > 0 ? $timeLimit + 30 : null);
            $process->start(function (string $type, string $buffer) use ($queue, $output): void {
                foreach (explode("\n", rtrim($buffer)) as $line) {
                    if ('' !== $line) {
                        $output->writeln(sprintf('[%s] %s', $queue, $line));
                    }
                }
            });

            $processes[$queue] = $process;
            $output->writeln(sprintf(
                '<info>DialogHSM: worker started for queue "%s" (PID: %d)</info>',
                $queue,
                $process->getPid() ?? 0
            ));
        }

        $exitCode = Command::SUCCESS;

        foreach ($processes as $queue => $process) {
            $process->wait();

            if ($process->isSuccessful()) {
                $output->writeln(sprintf('<info>DialogHSM: worker finished for queue "%s"</info>', $queue));
            } else {
                $processOutput = $process->getOutput().$process->getErrorOutput();

                if (str_contains($processOutput, 'NOT_FOUND') || str_contains($processOutput, 'no queue')) {
                    // Fila não existe no RabbitMQ — pode indicar nome errado ou fila não configurada.
                    $output->writeln(sprintf(
                        '<error>DialogHSM: fila "%s" não encontrada no RabbitMQ. Verifique a configuração dos WhatsApp Numbers.</error>',
                        $queue
                    ));
                    $exitCode = Command::FAILURE;
                } else {
                    $output->writeln(sprintf(
                        '<error>DialogHSM: worker failed for queue "%s" (exit: %d)</error>',
                        $queue,
                        $process->getExitCode() ?? -1
                    ));
                    $exitCode = Command::FAILURE;
                }
            }
        }

        return $exitCode;
    }

    private function runConsumer(
        Command $subCommand,
        OutputInterface $output,
        array $queues,
        int $limit,
        int $timeLimit
    ): int {
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

        try {
            return $subCommand->run($subInput, $output);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'NOT_FOUND') || str_contains($e->getMessage(), 'no queue')) {
                $queue = $queues[0] ?? 'desconhecida';
                $output->writeln(sprintf(
                    '<error>DialogHSM: fila "%s" não encontrada no RabbitMQ. Verifique a configuração dos WhatsApp Numbers.</error>',
                    $queue
                ));

                return Command::FAILURE;
            }

            throw $e;
        }
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
