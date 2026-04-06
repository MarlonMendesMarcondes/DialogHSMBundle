<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\DialogHSMBundle\Command\EncryptApiKeysCommand;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\EventListener\ApiKeyEncryptionSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class EncryptApiKeysCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EncryptionHelper&MockObject       $encryption;
    private Connection&MockObject             $connection;
    private CommandTester                     $tester;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->encryption = $this->createMock(EncryptionHelper::class);
        $this->connection = $this->createMock(Connection::class);

        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('getTableName')->willReturn('dialog_hsm_numbers');

        $this->em->method('getConnection')->willReturn($this->connection);
        $this->em->method('getClassMetadata')->willReturn($meta);

        $this->tester = new CommandTester(
            new EncryptApiKeysCommand($this->em, $this->encryption)
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeNumber(string $name, ?string $apiKey, int $id = 1): WhatsAppNumber&MockObject
    {
        $number = $this->createMock(WhatsAppNumber::class);
        $number->method('getName')->willReturn($name);
        $number->method('getId')->willReturn($id);
        $number->method('getApiKey')->willReturn($apiKey);

        return $number;
    }

    private function mockRepository(array $numbers): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn($numbers);
        $this->em->method('getRepository')->willReturn($repo);
    }

    // =========================================================================
    // Retorno e saída
    // =========================================================================

    public function testCommandReturnsSuccess(): void
    {
        $this->mockRepository([]);

        $this->tester->execute([]);

        $this->assertSame(0, $this->tester->getStatusCode());
    }

    public function testCommandOutputContainsTitle(): void
    {
        $this->mockRepository([]);

        $this->tester->execute([]);

        $this->assertStringContainsString('DialogHSM', $this->tester->getDisplay());
    }

    // =========================================================================
    // Sem números
    // =========================================================================

    public function testNoNumbersOutputsZeroProcessed(): void
    {
        $this->mockRepository([]);
        $this->connection->expects($this->never())->method('executeStatement');

        $this->tester->execute([]);

        $this->assertStringContainsString('0 de 0', $this->tester->getDisplay());
    }

    // =========================================================================
    // Números com chave vazia — ignorados
    // =========================================================================

    public function testSkipsNumbersWithEmptyApiKey(): void
    {
        $this->mockRepository([$this->makeNumber('Vendas', null)]);
        $this->connection->expects($this->never())->method('executeStatement');
        $this->encryption->expects($this->never())->method('encrypt');

        $this->tester->execute([]);

        $this->assertStringContainsString('0 de 1', $this->tester->getDisplay());
    }

    // =========================================================================
    // Chaves já criptografadas — ignoradas
    // =========================================================================

    public function testSkipsAlreadyEncryptedKeys(): void
    {
        $number = $this->makeNumber('Vendas', ApiKeyEncryptionSubscriber::ENC_PREFIX.'enc|iv');
        $this->mockRepository([$number]);

        $this->connection->expects($this->never())->method('executeStatement');
        $this->encryption->expects($this->never())->method('encrypt');

        $this->tester->execute([]);

        $this->assertStringContainsString('0 de 1', $this->tester->getDisplay());
        $this->assertStringContainsStringIgnoringCase('SKIP', $this->tester->getDisplay());
    }

    // =========================================================================
    // Chaves plaintext — criptografadas
    // =========================================================================

    public function testEncryptsPlaintextKey(): void
    {
        $plain  = 'chave-plaintext-12345678';
        $number = $this->makeNumber('Vendas', $plain, 42);
        $this->mockRepository([$number]);

        $this->encryption->method('encrypt')->willReturn('enc|iv');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('dialog_hsm_numbers'),
                $this->callback(fn($p) =>
                    $p['id'] === 42 &&
                    str_starts_with($p['encrypted'], ApiKeyEncryptionSubscriber::ENC_PREFIX)
                )
            );

        $this->tester->execute([]);

        $this->assertStringContainsString('1 de 1', $this->tester->getDisplay());
        $this->assertStringContainsStringIgnoringCase('ENCRYPT', $this->tester->getDisplay());
    }

    public function testEncryptedValueUsesEncPrefix(): void
    {
        $plain  = 'chave-plaintext-12345678';
        $number = $this->makeNumber('Vendas', $plain, 1);
        $this->mockRepository([$number]);

        $this->encryption->method('encrypt')->willReturn('rawenc|iv');

        $capturedParams = null;
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams): int {
                $capturedParams = $params;

                return 1;
            });

        $this->tester->execute([]);

        $this->assertNotNull($capturedParams);
        $this->assertSame(
            ApiKeyEncryptionSubscriber::ENC_PREFIX.'rawenc|iv',
            $capturedParams['encrypted']
        );
    }

    public function testEncryptsMultiplePlaintextKeys(): void
    {
        $numbers = [
            $this->makeNumber('Vendas', 'key-plaintext-111111111', 1),
            $this->makeNumber('Suporte', 'key-plaintext-222222222', 2),
        ];
        $this->mockRepository($numbers);

        $this->encryption->method('encrypt')->willReturn('enc|iv');
        $this->connection->expects($this->exactly(2))->method('executeStatement');

        $this->tester->execute([]);

        $this->assertStringContainsString('2 de 2', $this->tester->getDisplay());
    }

    // =========================================================================
    // Modo --dry-run
    // =========================================================================

    public function testDryRunDoesNotExecuteSql(): void
    {
        $number = $this->makeNumber('Vendas', 'chave-plaintext-12345678');
        $this->mockRepository([$number]);

        $this->encryption->expects($this->never())->method('encrypt');
        $this->connection->expects($this->never())->method('executeStatement');

        $this->tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $this->tester->getStatusCode());
    }

    public function testDryRunOutputMentionsDryRun(): void
    {
        $this->mockRepository([]);

        $this->tester->execute(['--dry-run' => true]);

        $this->assertStringContainsStringIgnoringCase('dry-run', $this->tester->getDisplay());
    }

    public function testDryRunStillReportsHowManyWouldBeEncrypted(): void
    {
        $numbers = [
            $this->makeNumber('Vendas', 'plaintext-key-111111111', 1),
            $this->makeNumber('Suporte', ApiKeyEncryptionSubscriber::ENC_PREFIX.'enc|iv', 2),
        ];
        $this->mockRepository($numbers);

        $this->tester->execute(['--dry-run' => true]);

        // 1 seria criptografada, 1 ignorada
        $this->assertStringContainsString('1 de 2', $this->tester->getDisplay());
    }

    // =========================================================================
    // Mix: plaintext + já criptografada + vazia
    // =========================================================================

    public function testMixedNumbersProcessesOnlyPlaintext(): void
    {
        $numbers = [
            $this->makeNumber('A', 'plaintext-key-111111111', 1),
            $this->makeNumber('B', ApiKeyEncryptionSubscriber::ENC_PREFIX.'enc|iv', 2),
            $this->makeNumber('C', null, 3),
        ];
        $this->mockRepository($numbers);

        $this->encryption->method('encrypt')->willReturn('enc|iv');
        $this->connection->expects($this->once())->method('executeStatement');

        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('1 de 3', $output);
        $this->assertStringContainsString('2 ignoradas', $output);
    }
}
