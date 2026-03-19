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

class ConsumeWhatsAppCommandTest extends TestCase
{
    private IntegrationsHelper&MockObject $mockIntegrationsHelper;
    private WhatsAppNumberRepository&MockObject $mockRepository;

    /** @var array<string, mixed> Argumentos capturados pelo sub-comando fake */
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
     *
     * @param \Throwable|null $fakeException Se informado, o fake messenger:consume lança essa exceção
     * @param callable|null   $processFactory Injeta processFactory customizado no comando
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
     * Útil nos testes de modo paralelo onde capturedArgs não é populado.
     */
    private function runCommandTester(array $input = [], ?\Throwable $fakeException = null, ?callable $processFactory = null): CommandTester
    {
        $app     = $this->buildApplication($fakeException, $processFactory);
        $command = $app->find('dialoghsm:consume');
        $tester  = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    // -------------------------------------------------------------------------
    // Testes: resolução de filas (resolveQueues)
    // -------------------------------------------------------------------------

    public function testNoModePassesNoQueuesOption(): void
    {
        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');
        $this->mockRepository->expects($this->never())->method('getDistinctBatchQueueNames');

        $this->runCommand([]);

        $this->assertEmpty($this->capturedArgs['--queues']);
    }

    public function testModeBulkCallsRepositoryBulkMethod(): void
    {
        // Fila única → usa runConsumer (sub-comando interno), capturedArgs é populado
        $this->mockRepository
            ->expects($this->once())
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['queue_a']);

        $this->mockRepository->expects($this->never())->method('getDistinctBatchQueueNames');

        $this->runCommand(['--mode' => 'bulk']);

        $this->assertEquals(['queue_a'], $this->capturedArgs['--queues']);
    }

    public function testModeBatchCallsRepositoryBatchMethod(): void
    {
        // Fila única → usa runConsumer (sub-comando interno), capturedArgs é populado
        $this->mockRepository
            ->expects($this->once())
            ->method('getDistinctBatchQueueNames')
            ->willReturn(['queue_a_batch']);

        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');

        $this->runCommand(['--mode' => 'batch']);

        $this->assertEquals(['queue_a_batch'], $this->capturedArgs['--queues']);
    }

    public function testExplicitQueueOptionTakesPriorityOverMode(): void
    {
        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');
        $this->mockRepository->expects($this->never())->method('getDistinctBatchQueueNames');

        $this->runCommand(['--queue' => 'queue_a', '--mode' => 'bulk']);

        $this->assertEquals(['queue_a'], $this->capturedArgs['--queues']);
    }

    public function testExplicitQueueOptionWithoutMode(): void
    {
        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');

        $this->runCommand(['--queue' => 'queue_a']);

        $this->assertEquals(['queue_a'], $this->capturedArgs['--queues']);
    }

    public function testUnknownModePassesNoQueues(): void
    {
        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');
        $this->mockRepository->expects($this->never())->method('getDistinctBatchQueueNames');

        $this->runCommand(['--mode' => 'unknown_mode']);

        $this->assertEmpty($this->capturedArgs['--queues']);
    }

    public function testModeBulkWithEmptyRepositoryResultPassesEmptyQueues(): void
    {
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn([]);

        $this->runCommand(['--mode' => 'bulk']);

        $this->assertEmpty($this->capturedArgs['--queues']);
    }

    // -------------------------------------------------------------------------
    // Testes: limit
    // -------------------------------------------------------------------------

    public function testExplicitLimitIsPassedToSubCommand(): void
    {
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
    // Testes: receivers passado ao sub-comando
    // -------------------------------------------------------------------------

    public function testReceiversAlwaysContainsWhatsapp(): void
    {
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
    // Testes: modo paralelo (runParallel) — count($queues) > 1
    // -------------------------------------------------------------------------

    /**
     * Duas filas no modo bulk → mensagem "starting N parallel workers" no output.
     * Os processos OS falharão no ambiente de testes (sem console real),
     * mas o output de lançamento já confirma que runParallel() foi invocado.
     */
    public function testTwoQueuesFromBulkModeTriggerParallelWorkers(): void
    {
        $this->mockRepository
            ->expects($this->once())
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['queue_a', 'queue_b']);

        $tester = $this->runCommandTester(['--mode' => 'bulk']);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('starting 2 parallel workers', $output);
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

        $this->assertStringContainsString('starting 2 parallel workers', $output);
        $this->assertStringContainsString('queue_a_batch', $output);
        $this->assertStringContainsString('queue_b_batch', $output);
    }

    public function testThreeQueuesOutputsCorrectWorkerCount(): void
    {
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['q1', 'q2', 'q3']);

        $tester = $this->runCommandTester(['--mode' => 'bulk']);

        $this->assertStringContainsString('starting 3 parallel workers', $tester->getDisplay());
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

    /**
     * Fila única em --mode=bulk deve continuar usando runConsumer (sub-comando interno),
     * NÃO runParallel. Isso garante que a regressão não ocorre para o caso 1-número.
     */
    public function testSingleQueueFromBulkModeDoesNotTriggerParallel(): void
    {
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['queue_a']);

        $tester = $this->runCommandTester(['--mode' => 'bulk']);
        $output = $tester->getDisplay();

        $this->assertStringNotContainsString('parallel workers', $output);
        // sub-comando interno foi chamado → receivers está capturado
        $this->assertEquals(['whatsapp'], $this->capturedArgs['receivers']);
    }

    /**
     * --queue explícito (fila única) nunca ativa o modo paralelo,
     * mesmo que o repositório tivesse múltiplas filas.
     */
    public function testExplicitQueueOptionNeverTriggersParallel(): void
    {
        // Repositório NÃO deve ser consultado quando --queue está presente
        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');

        $tester = $this->runCommandTester(['--queue' => 'queue_a']);
        $output = $tester->getDisplay();

        $this->assertStringNotContainsString('parallel workers', $output);
        $this->assertEquals(['queue_a'], $this->capturedArgs['--queues']);
    }

    // -------------------------------------------------------------------------
    // Testes: tratamento de erro — fila não encontrada no RabbitMQ
    // -------------------------------------------------------------------------

    public function testRunConsumerShowsFriendlyMessageOnNotFoundQueue(): void
    {
        $exception = new \RuntimeException("Server channel error: 404, message: NOT_FOUND - no queue 'fila_x' in vhost '/'");

        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['fila_x']);

        $tester = $this->runCommandTester(['--mode' => 'bulk'], $exception);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('fila_x', $output);
        $this->assertStringContainsString('não encontrada no RabbitMQ', $output);
        $this->assertStringContainsString('WhatsApp Numbers', $output);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testRunConsumerRethrowsUnrelatedExceptions(): void
    {
        $exception = new \RuntimeException('Unexpected connection error');

        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['fila_x']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected connection error');

        $this->runCommand(['--mode' => 'bulk'], $exception);
    }

    public function testRunConsumerShowsFriendlyMessageOnNoQueueVariant(): void
    {
        // Variação da mensagem de erro com "no queue" sem "NOT_FOUND"
        $exception = new \RuntimeException("no queue 'fila_y' declared");

        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['fila_y']);

        $tester = $this->runCommandTester(['--mode' => 'bulk'], $exception);

        $this->assertStringContainsString('fila_y', $tester->getDisplay());
        $this->assertStringContainsString('não encontrada no RabbitMQ', $tester->getDisplay());
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testRunParallelShowsFriendlyMessageWhenProcessOutputContainsNotFound(): void
    {
        $this->mockRepository
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['fila_a', 'fila_b']);

        // processFactory que simula processo falhando com NOT_FOUND no output
        $processFactory = function (array $args): \Symfony\Component\Process\Process {
            // Extrai o nome da fila do argumento --queues=<nome>
            $queueArg = array_values(array_filter($args, fn ($a) => str_starts_with($a, '--queues=')))[0] ?? '';
            $queue    = str_replace('--queues=', '', $queueArg);

            return new \Symfony\Component\Process\Process([
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

        $processFactory = function (): \Symfony\Component\Process\Process {
            return new \Symfony\Component\Process\Process([
                'bash', '-c', 'echo "some unrelated error" && exit 1',
            ]);
        };

        $tester = $this->runCommandTester(['--mode' => 'bulk'], null, $processFactory);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('worker failed for queue', $output);
        $this->assertStringNotContainsString('não encontrada no RabbitMQ', $output);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

}
