<?php

declare(strict_types=1);

use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Command\ConsumeWhatsAppCommand;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\Pacing\PacedWorker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * Lotes programados no dialoghsm:consume: --mode=paced roda o worker no próprio processo e o
 * --mode=bulk sobe o worker paced como processo filho, ao lado das filas.
 */
class ConsumeWhatsAppCommandPacedTest extends TestCase
{
    private IntegrationsHelper&MockObject $integrationsHelper;
    private WhatsAppNumberRepository&MockObject $repository;
    private PacedWorker&MockObject $worker;

    /** @var array<int, string[]> */
    private array $processArgs = [];

    protected function setUp(): void
    {
        $this->integrationsHelper = $this->createMock(IntegrationsHelper::class);
        $this->integrationsHelper->method('getIntegration')->willThrowException(new \Exception('Integration not found'));
        $this->repository  = $this->createMock(WhatsAppNumberRepository::class);
        $this->worker      = $this->createMock(PacedWorker::class);
        $this->processArgs = [];
    }

    private function tester(?PacedWorker $worker): CommandTester
    {
        $args    = &$this->processArgs;
        $command = new ConsumeWhatsAppCommand(
            $this->integrationsHelper,
            $this->repository,
            function (array $processArgs) use (&$args): Process {
                $args[] = $processArgs;

                return new Process(['bash', '-c', 'exit 0']);
            },
            $worker,
        );

        return new CommandTester($command);
    }

    public function testPacedModeRunsTheWorkerInProcessWithTheTimeLimit(): void
    {
        $this->worker->expects($this->once())->method('run')->with(55)->willReturn(Command::SUCCESS);
        $this->repository->expects($this->never())->method('getDistinctBulkQueueNames');

        $exit = $this->tester($this->worker)->execute(['--mode' => 'paced', '--time-limit' => '55']);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame([], $this->processArgs, 'o modo paced não sobe processo de fila');
    }

    public function testPacedModeWithoutWorkerFails(): void
    {
        $tester = $this->tester(null);

        $this->assertSame(Command::FAILURE, $tester->execute(['--mode' => 'paced']));
        $this->assertStringContainsString('indisponível', $tester->getDisplay());
    }

    public function testBulkModeStartsThePacedWorkerNextToTheQueues(): void
    {
        $this->repository->method('getDistinctBulkQueueNames')->willReturn(['bulk_a']);
        $this->worker->method('shouldRun')->willReturn(true);
        $this->worker->expects($this->never())->method('run');

        $tester = $this->tester($this->worker);
        $tester->execute(['--mode' => 'bulk', '--time-limit' => '55']);

        $this->assertCount(2, $this->processArgs);
        $this->assertContains('--queues=bulk_a', $this->processArgs[0]);
        $this->assertSame(['dialoghsm:consume', '--mode=paced', '--time-limit=55'], array_slice($this->processArgs[1], 2));
        $this->assertStringContainsString('starting 2 worker', $tester->getDisplay());
    }

    public function testBulkModeWithoutPendingOrFeatureDoesNotStartThePacedWorker(): void
    {
        $this->repository->method('getDistinctBulkQueueNames')->willReturn(['bulk_a']);
        $this->worker->method('shouldRun')->willReturn(false);

        $this->tester($this->worker)->execute(['--mode' => 'bulk']);

        $this->assertCount(1, $this->processArgs);
        $this->assertContains('--queues=bulk_a', $this->processArgs[0]);
    }

    public function testBulkModeWithoutQueuesStillStartsThePacedWorker(): void
    {
        // Lotes programados não usam fila do RabbitMQ: número sem fila bulk ainda envia lotes
        $this->repository->method('getDistinctBulkQueueNames')->willReturn([]);
        $this->worker->method('shouldRun')->willReturn(true);

        $this->tester($this->worker)->execute(['--mode' => 'bulk']);

        $this->assertCount(1, $this->processArgs);
        $this->assertContains('--mode=paced', $this->processArgs[0]);
    }

    public function testBatchModeNeverStartsThePacedWorker(): void
    {
        $this->repository->method('getDistinctBatchQueueNames')->willReturn(['batch_a']);
        $this->worker->expects($this->never())->method('shouldRun');

        $this->tester($this->worker)->execute(['--mode' => 'batch']);

        $this->assertCount(1, $this->processArgs);
        $this->assertContains('--queues=batch_a', $this->processArgs[0]);
    }

    public function testExplicitQueueNeverStartsThePacedWorker(): void
    {
        $this->worker->expects($this->never())->method('shouldRun');

        $this->tester($this->worker)->execute(['--mode' => 'bulk', '--queue' => 'bulk_a']);

        $this->assertCount(1, $this->processArgs);
        $this->assertContains('--queues=bulk_a', $this->processArgs[0]);
    }
}
