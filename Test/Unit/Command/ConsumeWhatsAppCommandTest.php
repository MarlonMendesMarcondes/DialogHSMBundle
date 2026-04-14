<?php

declare(strict_types=1);

use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Command\ConsumeWhatsAppCommand;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

class ConsumeWhatsAppCommandTest extends TestCase
{
    private IntegrationsHelper&MockObject $mockIntegrationsHelper;
    private WhatsAppNumberRepository&MockObject $mockRepository;

    /** @var array<string, mixed> Argumentos capturados pelo sub-comando fake (caminho legado sem modo) */
    private array $capturedArgs = [];

    protected function setUp(): void
    {
        $this->mockIntegrationsHelper = $this->createMock(IntegrationsHelper::class);
        $this->mockRepository         = $this->createMock(WhatsAppNumberRepository::class);
        $this->capturedArgs           = [];

        // Por padrão, simula integração não encontrada → getConsumerLimit() retorna 50
        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willThrowException(new \Exception('Integration not found'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Constrói uma Application com o comando real + um messenger:consume fake
     * que captura os argumentos recebidos em $this->capturedArgs.
     * Usado apenas nos testes do caminho legado (sem --mode, sem --queue).
     *
     * @param \Throwable|null $fakeException   Se informado, o fake messenger:consume lança essa exceção
     * @param callable|null   $processFactory  Injeta processFactory customizado no comando
     */
    private function buildApplication(?\Throwable $fakeException = null, ?callable $processFactory = null): Application
    {
        $command = new ConsumeWhatsAppCommand(
            $this->mockIntegrationsHelper,
            $this->mockRepository,
            $processFactory,
        );

        $capturedArgs = &$this->capturedArgs;

        $fakeMessengerConsume = new class($capturedArgs, $fakeException) extends Command {
            public function __construct(private array &$captured, private ?\Throwable $exception)
            {
                parent::__construct('messenger:consume');
            }

            protected function configure(): void
            {
                $this
                    ->addArgument('receivers', InputArgument::IS_ARRAY | InputArgument::OPTIONAL)
                    ->addOption('limit', null, InputOption::VALUE_REQUIRED)
                    ->addOption('time-limit', null, InputOption::VALUE_REQUIRED)
                    ->addOption('queues', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                if ($this->exception !== null) {
                    throw $this->exception;
                }

                $this->captured = [
                    'receivers'    => $input->getArgument('receivers'),
                    '--limit'      => $input->getOption('limit'),
                    '--time-limit' => $input->getOption('time-limit'),
                    '--queues'     => $input->getOption('queues'),
                ];

                return Command::SUCCESS;
            }
        };

        $app = new Application();
        $app->setAutoExit(false);
        $app->add($command);
        $app->add($fakeMessengerConsume);

        return $app;
    }

    private function runCommand(array $input = [], ?\Throwable $fakeException = null): int
    {
        $app     = $this->buildApplication($fakeException);
        $command = $app->find('dialoghsm:consume');
        $tester  = new CommandTester($command);

        return $tester->execute($input);
    }

    /**
     * Executa o comando e retorna o CommandTester para inspeção de output.
     */
    private function runCommandTester(array $input = [], ?\Throwable $fakeException = null, ?callable $processFactory = null): CommandTester
    {
        $app     = $this->buildApplication($fakeException, $processFactory);
        $command = $app->find('dialoghsm:consume');
        $tester  = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /**
     * processFactory que captura os argumentos passados a cada processo OS e retorna sucesso.
     *
     * @param array<array<string>> $capturedProcessArgs Referência preenchida com os args de cada processo
     */
    private function successProcessFactory(array &$capturedProcessArgs): callable
    {
        return function (array $args) use (&$capturedProcessArgs): Process {
            $capturedProcessArgs[] = $args;

            return new Process(['bash', '-c', 'exit 0']);
        };
    }

    // -------------------------------------------------------------------------
    // Testes: resolução de filas (resolveQueues)
    // -------------------------------------------------------------------------

    public function testNoModePassesNoQueuesOption(): void
    {
        // Sem --mode e sem --queue → caminho legado (runConsumer), sem filtro de fila
        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');
        $this->mockRepository->expects($this->never())->method('getDistinctBatchQueueNames');

        $this->runCommand([]);

        $this->assertEmpty($this->capturedArgs['--queues']);
    }

    public function testModeBulkCallsRepositoryBulkMethod(): void
    {
        // Uma fila → processo OS isolado (runParallel); verifica que repositório é consultado
        // e que o processo recebe --queues=queue_a
        $capturedProcessArgs = [];

        $this->mockRepository
            ->expects($this->once())
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['queue_a']);

        $this->mockRepository->expects($this->never())->method('getDistinctBatchQueueNames');

        $this->runCommandTester(['--mode' => 'bulk'], null, $this->successProcessFactory($capturedProcessArgs));

        $this->assertCount(1, $capturedProcessArgs);
        $this->assertContains('--queues=queue_a', $capturedProcessArgs[0]);
        $this->assertContains('messenger:consume', $capturedProcessArgs[0]);
        $this->assertContains('whatsapp', $capturedProcessArgs[0]);
    }

    public function testModeBatchCallsRepositoryBatchMethod(): void
    {
        $capturedProcessArgs = [];

        $this->mockRepository
            ->expects($this->once())
            ->method('getDistinctBatchQueueNames')
            ->willReturn(['queue_a_batch']);

        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');

        $this->runCommandTester(['--mode' => 'batch'], null, $this->successProcessFactory($capturedProcessArgs));

        $this->assertCount(1, $capturedProcessArgs);
        $this->assertContains('--queues=queue_a_batch', $capturedProcessArgs[0]);
    }

    public function testExplicitQueueOptionTakesPriorityOverMode(): void
    {
        // --queue prevalece sobre --mode: repositório não é consultado
        $capturedProcessArgs = [];

        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');
        $this->mockRepository->expects($this->never())->method('getDistinctBatchQueueNames');

        $this->runCommandTester(
            ['--queue' => 'queue_a', '--mode' => 'bulk'],
            null,
            $this->successProcessFactory($capturedProcessArgs)
        );

        $this->assertCount(1, $capturedProcessArgs);
        $this->assertContains('--queues=queue_a', $capturedProcessArgs[0]);
    }

    public function testExplicitQueueOptionWithoutMode(): void
    {
        $capturedProcessArgs = [];

        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');

        $this->runCommandTester(
            ['--queue' => 'queue_a'],
            null,
            $this->successProcessFactory($capturedProcessArgs)
        );

        $this->assertCount(1, $capturedProcessArgs);
        $this->assertContains('--queues=queue_a', $capturedProcessArgs[0]);
    }

    public function testUnknownModePassesNoQueues(): void
    {
        // Modo desconhecido → queues=[], mode não é bulk/batch → caminho legado
        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');
        $this->mockRepository->expects($this->never())->method('getDistinctBatchQueueNames');

        $this->runCommand(['--mode' => 'unknown_mode']);

        $this->assertEmpty($this->capturedArgs['--queues']);
    }

    public function testModeBulkWithEmptyRepositoryResultOutputsWarningAndSucceeds(): void
    {
        // 0 filas em modo bulk → aviso amigável + SUCCESS (nada para consumir)
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn([]);

        $tester = $this->runCommandTester(['--mode' => 'bulk']);

        $this->assertStringContainsString('nenhuma fila configurada', $tester->getDisplay());
        $this->assertStringContainsString('bulk', $tester->getDisplay());
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testModeBatchWithEmptyRepositoryResultOutputsWarningAndSucceeds(): void
    {
        $this->mockRepository
            ->method('getDistinctBatchQueueNames')
            ->willReturn([]);

        $tester = $this->runCommandTester(['--mode' => 'batch']);

        $this->assertStringContainsString('nenhuma fila configurada', $tester->getDisplay());
        $this->assertStringContainsString('batch', $tester->getDisplay());
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Testes: limit
    // -------------------------------------------------------------------------

    public function testExplicitLimitIsPassedToSubCommand(): void
    {
        // Sem modo → caminho legado (runConsumer)
        $this->runCommand(['--limit' => '10']);

        $this->assertEquals('10', $this->capturedArgs['--limit']);
    }

    public function testDefaultLimitFallsBackTo50WhenIntegrationFails(): void
    {
        // IntegrationsHelper já lança Exception no setUp() → fallback = 50
        $this->runCommand([]);

        $this->assertEquals('50', $this->capturedArgs['--limit']);
    }

    // -------------------------------------------------------------------------
    // Testes: time-limit
    // -------------------------------------------------------------------------

    public function testDefaultTimeLimitIs60(): void
    {
        $this->runCommand([]);

        $this->assertEquals('60', $this->capturedArgs['--time-limit']);
    }

    public function testExplicitTimeLimitIsRespected(): void
    {
        $this->runCommand(['--time-limit' => '120']);

        $this->assertEquals('120', $this->capturedArgs['--time-limit']);
    }

    public function testTimeLimitZeroIsNotPassedToSubCommand(): void
    {
        // time-limit=0 significa "sem limite": não deve ser repassado (fica null no sub-comando)
        $this->runCommand(['--time-limit' => '0']);

        $this->assertNull($this->capturedArgs['--time-limit']);
    }

    // -------------------------------------------------------------------------
    // Testes: receivers passado ao sub-comando (caminho legado)
    // -------------------------------------------------------------------------

    public function testReceiversAlwaysContainsWhatsapp(): void
    {
        // Caminho legado (sem modo) sempre passa receiver 'whatsapp'
        $this->runCommand([]);

        $this->assertEquals(['whatsapp'], $this->capturedArgs['receivers']);
    }

    // -------------------------------------------------------------------------
    // Testes: limit a partir das configurações da integração
    // -------------------------------------------------------------------------

    public function testLimitFromIntegrationSettingsWhenConfigured(): void
    {
        $mockApiKeys = ['consumer_limit' => '100'];

        $mockConfig = new class($mockApiKeys) {
            public function __construct(private array $keys) {}
            public function getApiKeys(): array { return $this->keys; }
        };

        $mockIntegration = new class($mockConfig) {
            public function __construct(private $config) {}
            public function getIntegrationConfiguration() { return $this->config; }
        };

        $this->mockIntegrationsHelper = $this->createMock(\Mautic\IntegrationsBundle\Helper\IntegrationsHelper::class);
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($mockIntegration);

        $this->runCommand([]);

        $this->assertEquals('100', $this->capturedArgs['--limit']);
    }

    public function testExplicitLimitOfZeroIsEnforcedToAtLeastOne(): void
    {
        // max(1, 0) → 1: nunca permite limit=0 que consumiria mensagens indefinidamente
        $this->runCommand(['--limit' => '0']);

        $this->assertEquals('1', $this->capturedArgs['--limit']);
    }

    // -------------------------------------------------------------------------
    // Testes: guard clause — Application não disponível
    // -------------------------------------------------------------------------

    public function testReturnsFailureWhenApplicationIsNotAvailable(): void
    {
        // Comando criado mas NÃO adicionado a nenhuma Application → getApplication() === null
        // Isso só é atingido no caminho legado (queues=[])
        $command = new ConsumeWhatsAppCommand(
            $this->mockIntegrationsHelper,
            $this->mockRepository,
        );

        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('application not available', $tester->getDisplay());
    }

    // -------------------------------------------------------------------------
    // Testes: worker isolado por fila — QUALQUER quantidade >= 1 usa runParallel
    // -------------------------------------------------------------------------

    public function testSingleQueueFromBulkModeTriggersIsolatedWorker(): void
    {
        // 1 fila → runParallel (processo OS isolado), não caminho legado in-process
        $capturedProcessArgs = [];

        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['queue_a']);

        $tester = $this->runCommandTester(
            ['--mode' => 'bulk'],
            null,
            $this->successProcessFactory($capturedProcessArgs)
        );

        $output = $tester->getDisplay();

        $this->assertStringContainsString('starting 1 worker', $output);
        $this->assertCount(1, $capturedProcessArgs);
        $this->assertContains('--queues=queue_a', $capturedProcessArgs[0]);
    }

    public function testExplicitQueueOptionAlsoTriggersIsolatedWorker(): void
    {
        // --queue=X (fila única explícita) também usa processo OS isolado
        $capturedProcessArgs = [];

        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');

        $tester = $this->runCommandTester(
            ['--queue' => 'queue_a'],
            null,
            $this->successProcessFactory($capturedProcessArgs)
        );

        $output = $tester->getDisplay();

        $this->assertStringContainsString('starting 1 worker', $output);
        $this->assertCount(1, $capturedProcessArgs);
        $this->assertContains('--queues=queue_a', $capturedProcessArgs[0]);
    }

    public function testTwoQueuesFromBulkModeTriggerParallelWorkers(): void
    {
        $this->mockRepository
            ->expects($this->once())
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['queue_a', 'queue_b']);

        $tester = $this->runCommandTester(['--mode' => 'bulk']);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('starting 2 worker', $output);
        $this->assertStringContainsString('queue_a', $output);
        $this->assertStringContainsString('queue_b', $output);
    }

    public function testTwoQueuesFromBatchModeTriggerParallelWorkers(): void
    {
        $this->mockRepository
            ->expects($this->once())
            ->method('getDistinctBatchQueueNames')
            ->willReturn(['queue_a_batch', 'queue_b_batch']);

        $tester = $this->runCommandTester(['--mode' => 'batch']);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('starting 2 worker', $output);
        $this->assertStringContainsString('queue_a_batch', $output);
        $this->assertStringContainsString('queue_b_batch', $output);
    }

    public function testThreeQueuesOutputsCorrectWorkerCount(): void
    {
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['q1', 'q2', 'q3']);

        $tester = $this->runCommandTester(['--mode' => 'bulk']);

        $this->assertStringContainsString('starting 3 worker', $tester->getDisplay());
    }

    public function testParallelModeOutputIncludesLimitPerWorker(): void
    {
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['queue_a', 'queue_b']);

        $tester = $this->runCommandTester(['--mode' => 'bulk', '--limit' => '25']);

        $this->assertStringContainsString('limit=25 each', $tester->getDisplay());
    }

    public function testParallelModeOutputsWorkerStartedMessageForEachQueue(): void
    {
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['queue_a', 'queue_b']);

        $tester = $this->runCommandTester(['--mode' => 'bulk']);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('worker started for queue "queue_a"', $output);
        $this->assertStringContainsString('worker started for queue "queue_b"', $output);
    }

    // -------------------------------------------------------------------------
    // Testes: tratamento de erro — fila não existe no RabbitMQ
    // -------------------------------------------------------------------------

    public function testRunParallelShowsFriendlyMessageOnSingleQueueNotFound(): void
    {
        // 1 fila → runParallel → processo OS com NOT_FOUND no output
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['fila_x']);

        $processFactory = function (array $args): Process {
            $queueArg = array_values(array_filter($args, fn ($a) => str_starts_with($a, '--queues=')))[0] ?? '';
            $queue    = str_replace('--queues=', '', $queueArg);

            return new Process([
                'bash', '-c',
                "echo \"NOT_FOUND - no queue '{$queue}' in vhost '/'\" && exit 1",
            ]);
        };

        $tester = $this->runCommandTester(['--mode' => 'bulk'], null, $processFactory);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('não encontrada no RabbitMQ', $output);
        $this->assertStringContainsString('WhatsApp Numbers', $output);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testRunParallelShowsFriendlyMessageWhenProcessOutputContainsNotFound(): void
    {
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['fila_a', 'fila_b']);

        // processFactory que simula processo falhando com NOT_FOUND no output
        $processFactory = function (array $args): Process {
            $queueArg = array_values(array_filter($args, fn ($a) => str_starts_with($a, '--queues=')))[0] ?? '';
            $queue    = str_replace('--queues=', '', $queueArg);

            return new Process([
                'bash', '-c',
                "echo \"NOT_FOUND - no queue '{$queue}' in vhost '/'\" && exit 1",
            ]);
        };

        $tester = $this->runCommandTester(['--mode' => 'bulk'], null, $processFactory);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('não encontrada no RabbitMQ', $output);
        $this->assertStringContainsString('WhatsApp Numbers', $output);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testRunParallelShowsGenericErrorWhenProcessFailsWithOtherReason(): void
    {
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['fila_a', 'fila_b']);

        $processFactory = function (): Process {
            return new Process([
                'bash', '-c', 'echo "some unrelated error" && exit 1',
            ]);
        };

        $tester = $this->runCommandTester(['--mode' => 'bulk'], null, $processFactory);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('worker failed for queue', $output);
        $this->assertStringNotContainsString('ainda não existe no RabbitMQ', $output);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testRunParallelShowsFinishedMessageWhenProcessSucceeds(): void
    {
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['fila_a', 'fila_b']);

        $processFactory = function (): Process {
            return new Process([
                'bash', '-c', 'echo "worker done" && exit 0',
            ]);
        };

        $tester = $this->runCommandTester(['--mode' => 'bulk'], null, $processFactory);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('worker finished for queue', $output);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testSingleQueueWorkerAlsoReportsFinishedOnSuccess(): void
    {
        // 1 fila → runParallel → processo de sucesso → mensagem "finished"
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['fila_a']);

        $processFactory = function (): Process {
            return new Process(['bash', '-c', 'exit 0']);
        };

        $tester = $this->runCommandTester(['--mode' => 'bulk'], null, $processFactory);

        $this->assertStringContainsString('worker finished for queue "fila_a"', $tester->getDisplay());
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }
}
