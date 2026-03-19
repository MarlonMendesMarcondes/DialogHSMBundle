<?php

declare(strict_types=1);

use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Command\ConsumeWhatsAppCommand;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * Testes de integração para ConsumeWhatsAppCommand.
 *
 * Spawnam processos OS reais para verificar que o modo paralelo realmente
 * executa workers de forma concorrente. São mais lentos (~2-3 s) e dependem
 * do binário `php` disponível no PATH — rodar separadamente dos unit tests.
 *
 * phpunit --testsuite integration
 */
class ConsumeWhatsAppCommandIntegrationTest extends TestCase
{
    private IntegrationsHelper&MockObject $mockIntegrationsHelper;
    private WhatsAppNumberRepository&MockObject $mockRepository;

    protected function setUp(): void
    {
        $this->mockIntegrationsHelper = $this->createMock(IntegrationsHelper::class);
        $this->mockRepository         = $this->createMock(WhatsAppNumberRepository::class);

        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willThrowException(new \Exception('Integration not found'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Cria um ConsumeWhatsAppCommand com um processFactory que faz cada worker
     * dormir N segundos — simulando trabalho real sem depender do console Mautic.
     */
    private function buildCommandWithSleepingProcesses(int $sleepSeconds): ConsumeWhatsAppCommand
    {
        $processFactory = static fn (array $args): Process =>
            new Process(['php', '-r', "sleep($sleepSeconds);"]);

        return new ConsumeWhatsAppCommand(
            $this->mockIntegrationsHelper,
            $this->mockRepository,
            $processFactory,
        );
    }

    private function buildApp(ConsumeWhatsAppCommand $command): Application
    {
        $app = new Application();
        $app->setAutoExit(false);
        $app->add($command);

        return $app;
    }

    // -------------------------------------------------------------------------
    // Testes de concorrência real
    // -------------------------------------------------------------------------

    /**
     * 3 workers paralelos de 1 segundo cada devem terminar em ~1 segundo,
     * não em ~3 segundos (comportamento sequencial).
     *
     * Margem: aceita até 2.5 s para absorver overhead de processo no CI.
     */
    public function testParallelWorkersRunConcurrentlyNotSequentially(): void
    {
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['queue_a', 'queue_b', 'queue_c']);

        $tester = new CommandTester(
            $this->buildApp($this->buildCommandWithSleepingProcesses(sleepSeconds: 1))
                ->find('dialoghsm:consume')
        );

        $start = microtime(true);
        $tester->execute(['--mode' => 'bulk', '--time-limit' => '0']);
        $elapsed = microtime(true) - $start;

        // Paralelo: ~1 s. Sequencial seria ~3 s.
        $this->assertLessThan(2.5, $elapsed, 'Workers devem rodar em paralelo (~1s), não sequencialmente (~3s).');
        $this->assertGreaterThan(0.8, $elapsed, 'Cada worker dorme 1s — tempo total não pode ser instantâneo.');
    }

    /**
     * O tempo total escala com o worker mais lento, não com a soma.
     * queue_a dorme 1s, queue_b dorme 2s → total ~2s (não ~3s).
     */
    public function testParallelTotalTimeIsBoundByLongestWorker(): void
    {
        $sleepByQueue = ['queue_a' => 1, 'queue_b' => 2];

        $processFactory = static function (array $args) use ($sleepByQueue): Process {
            $queue = 'queue_a';
            foreach ($args as $arg) {
                if (str_starts_with($arg, '--queues=')) {
                    $queue = substr($arg, strlen('--queues='));
                    break;
                }
            }

            return new Process(['php', '-r', sprintf('sleep(%d);', $sleepByQueue[$queue] ?? 1)]);
        };

        $command = new ConsumeWhatsAppCommand(
            $this->mockIntegrationsHelper,
            $this->mockRepository,
            $processFactory,
        );

        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['queue_a', 'queue_b']);

        $tester = new CommandTester($this->buildApp($command)->find('dialoghsm:consume'));

        $start = microtime(true);
        $tester->execute(['--mode' => 'bulk', '--time-limit' => '0']);
        $elapsed = microtime(true) - $start;

        // Soma sequencial seria 3s. Paralelo deve ser ~2s (o mais lento).
        $this->assertGreaterThan(1.8, $elapsed, 'Deve aguardar o worker mais lento (2s).');
        $this->assertLessThan(3.0, $elapsed, 'Não deve somar os tempos sequencialmente (~3s).');
    }
}
