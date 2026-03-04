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
     */
    private function buildApplication(): Application
    {
        $command = new ConsumeWhatsAppCommand(
            $this->mockIntegrationsHelper,
            $this->mockRepository,
        );

        $capturedArgs = &$this->capturedArgs;

        $fakeMessengerConsume = new class($capturedArgs) extends Command {
            public function __construct(private array &$captured)
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
                $this->captured = [
                    'receivers'   => $input->getArgument('receivers'),
                    '--limit'     => $input->getOption('limit'),
                    '--time-limit' => $input->getOption('time-limit'),
                    '--queues'    => $input->getOption('queues'),
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

    private function runCommand(array $input = []): int
    {
        $app     = $this->buildApplication();
        $command = $app->find('dialoghsm:consume');
        $tester  = new CommandTester($command);

        return $tester->execute($input);
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
        $this->mockRepository
            ->expects($this->once())
            ->method('getDistinctBulkQueueNames')
            ->willReturn(['educa', 'vendas']);

        $this->mockRepository->expects($this->never())->method('getDistinctBatchQueueNames');

        $this->runCommand(['--mode' => 'bulk']);

        $this->assertEquals(['educa', 'vendas'], $this->capturedArgs['--queues']);
    }

    public function testModeBatchCallsRepositoryBatchMethod(): void
    {
        $this->mockRepository
            ->expects($this->once())
            ->method('getDistinctBatchQueueNames')
            ->willReturn(['educa_lote', 'vendas_lote']);

        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');

        $this->runCommand(['--mode' => 'batch']);

        $this->assertEquals(['educa_lote', 'vendas_lote'], $this->capturedArgs['--queues']);
    }

    public function testExplicitQueueOptionTakesPriorityOverMode(): void
    {
        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');
        $this->mockRepository->expects($this->never())->method('getDistinctBatchQueueNames');

        $this->runCommand(['--queue' => 'minha_fila', '--mode' => 'bulk']);

        $this->assertEquals(['minha_fila'], $this->capturedArgs['--queues']);
    }

    public function testExplicitQueueOptionWithoutMode(): void
    {
        $this->mockRepository->expects($this->never())->method('getDistinctBulkQueueNames');

        $this->runCommand(['--queue' => 'educa']);

        $this->assertEquals(['educa'], $this->capturedArgs['--queues']);
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
}
