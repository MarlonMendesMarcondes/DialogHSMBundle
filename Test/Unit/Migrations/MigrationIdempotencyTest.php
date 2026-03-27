<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManager;
use MauticPlugin\DialogHSMBundle\Migrations\Version_1_0_0;
use MauticPlugin\DialogHSMBundle\Migrations\Version_1_0_1;
use MauticPlugin\DialogHSMBundle\Migrations\Version_1_0_2;
use MauticPlugin\DialogHSMBundle\Migrations\Version_1_0_3;
use MauticPlugin\DialogHSMBundle\Migrations\Version_1_0_4;
use MauticPlugin\DialogHSMBundle\Migrations\Version_1_0_5;
use MauticPlugin\DialogHSMBundle\Migrations\Version_1_0_6;
use MauticPlugin\DialogHSMBundle\Migrations\Version_1_0_7;
use MauticPlugin\DialogHSMBundle\Migrations\Version_1_0_8;
use MauticPlugin\DialogHSMBundle\Migrations\Version_1_1_0;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Testes de idempotência e aplicabilidade das migrações do DialogHSMBundle.
 *
 * Cada migração é testada em três cenários:
 *   1. isApplicable() = true  → estado do banco antes da migration
 *   2. isApplicable() = false → estado do banco depois da migration
 *   3. SQL gerado pelo up()   → contém IF NOT EXISTS / WHERE NOT EXISTS
 *
 * Não requer banco real — usa Schema e EntityManager mockados.
 */
class MigrationIdempotencyTest extends TestCase
{
    private EntityManager&MockObject $mockEm;
    private Connection&MockObject    $mockConn;

    protected function setUp(): void
    {
        $this->mockConn = $this->createMock(Connection::class);
        $this->mockEm   = $this->createMock(EntityManager::class);
        $this->mockEm->method('getConnection')->willReturn($this->mockConn);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function migration(string $class): AbstractMigration
    {
        return new $class($this->mockEm, '');
    }

    /** Invoca método protected via Reflection. */
    private function callProtected(object $object, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invoke($object, ...$args);
    }

    /** Schema onde a tabela NÃO existe (getTable lança SchemaException). */
    private function schemaWithoutTable(): Schema&MockObject
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getTable')->willThrowException(
            new SchemaException('Table not found')
        );

        return $schema;
    }

    /**
     * Schema onde a tabela existe com as colunas informadas.
     *
     * @param string[] $columns colunas presentes na tabela
     */
    private function schemaWithTable(array $columns = []): Schema&MockObject
    {
        $table = $this->createMock(Table::class);
        $table->method('hasColumn')->willReturnCallback(
            fn (string $col) => in_array($col, $columns, true)
        );

        $schema = $this->createMock(Schema::class);
        $schema->method('getTable')->willReturn($table);

        return $schema;
    }

    /**
     * Retorna o array privado $queries acumulado pelo addSql() via Reflection.
     *
     * @return string[]
     */
    private function collectSql(AbstractMigration $migration): array
    {
        $this->callProtected($migration, 'up');
        $ref = new \ReflectionProperty(AbstractMigration::class, 'queries');
        $ref->setAccessible(true);

        return $ref->getValue($migration);
    }

    // =========================================================================
    // Version_1_0_0 — cria dialog_hsm_message_log
    // =========================================================================

    public function testV100ApplicableWhenTableAbsent(): void
    {
        $migration = $this->migration(Version_1_0_0::class);
        $this->assertTrue($this->callProtected($migration, 'isApplicable', $this->schemaWithoutTable()));
    }

    public function testV100NotApplicableWhenTableExists(): void
    {
        $migration = $this->migration(Version_1_0_0::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithTable()));
    }

    public function testV100SqlCreatesTableWithIfNotExists(): void
    {
        $sql = implode(' ', $this->collectSql($this->migration(Version_1_0_0::class)));
        $this->assertStringContainsStringIgnoringCase('CREATE TABLE IF NOT EXISTS', $sql);
        $this->assertStringContainsString('dialog_hsm_message_log', $sql);
    }

    // =========================================================================
    // Version_1_0_1 — cria dialog_hsm_numbers
    // =========================================================================

    public function testV101ApplicableWhenTableAbsent(): void
    {
        $migration = $this->migration(Version_1_0_1::class);
        $this->assertTrue($this->callProtected($migration, 'isApplicable', $this->schemaWithoutTable()));
    }

    public function testV101NotApplicableWhenTableExists(): void
    {
        $migration = $this->migration(Version_1_0_1::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithTable()));
    }

    public function testV101SqlCreatesTableWithIfNotExists(): void
    {
        $sql = implode(' ', $this->collectSql($this->migration(Version_1_0_1::class)));
        $this->assertStringContainsStringIgnoringCase('CREATE TABLE IF NOT EXISTS', $sql);
        $this->assertStringContainsString('dialog_hsm_numbers', $sql);
    }

    // =========================================================================
    // Version_1_0_2 — adiciona http_status_code
    // =========================================================================

    public function testV102ApplicableWhenColumnAbsent(): void
    {
        $migration = $this->migration(Version_1_0_2::class);
        // tabela existe, mas http_status_code não
        $this->assertTrue($this->callProtected($migration, 'isApplicable', $this->schemaWithTable([])));
    }

    public function testV102NotApplicableWhenColumnPresent(): void
    {
        $migration = $this->migration(Version_1_0_2::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithTable(['http_status_code'])));
    }

    public function testV102NotApplicableWhenTableAbsent(): void
    {
        // sem tabela → SchemaException → isApplicable retorna false
        $migration = $this->migration(Version_1_0_2::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithoutTable()));
    }

    public function testV102SqlIsIdempotent(): void
    {
        $sql = implode(' ', $this->collectSql($this->migration(Version_1_0_2::class)));
        $this->assertStringContainsStringIgnoringCase('ADD COLUMN IF NOT EXISTS', $sql);
        $this->assertStringContainsString('http_status_code', $sql);
    }

    // =========================================================================
    // Version_1_0_3 — adiciona base_url
    // =========================================================================

    public function testV103ApplicableWhenColumnAbsent(): void
    {
        $migration = $this->migration(Version_1_0_3::class);
        $this->assertTrue($this->callProtected($migration, 'isApplicable', $this->schemaWithTable([])));
    }

    public function testV103NotApplicableWhenColumnPresent(): void
    {
        $migration = $this->migration(Version_1_0_3::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithTable(['base_url'])));
    }

    public function testV103NotApplicableWhenTableAbsent(): void
    {
        $migration = $this->migration(Version_1_0_3::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithoutTable()));
    }

    public function testV103SqlIsIdempotent(): void
    {
        $sql = implode(' ', $this->collectSql($this->migration(Version_1_0_3::class)));
        $this->assertStringContainsStringIgnoringCase('ADD COLUMN IF NOT EXISTS', $sql);
        $this->assertStringContainsString('base_url', $sql);
    }

    // =========================================================================
    // Version_1_0_4 — adiciona colunas dialoghsm_* em leads + lead_fields
    // =========================================================================

    public function testV104ApplicableWhenDialoghsmStatusAbsent(): void
    {
        $migration = $this->migration(Version_1_0_4::class);
        // tabela leads existe mas coluna ausente → aplicável
        $this->assertTrue($this->callProtected($migration, 'isApplicable', $this->schemaWithTable([])));
    }

    public function testV104NotApplicableWhenColumnPresentAndFieldRegistered(): void
    {
        // Coluna existe E lead_field já cadastrado → não aplicável
        $this->mockConn
            ->method('fetchOne')
            ->willReturn('1'); // count = 1, campo já existe

        $migration = $this->migration(Version_1_0_4::class);
        $this->assertFalse(
            $this->callProtected($migration, 'isApplicable', $this->schemaWithTable(['dialoghsm_status']))
        );
    }

    public function testV104ApplicableWhenColumnPresentButFieldNotRegistered(): void
    {
        // Coluna existe mas lead_field ausente → ainda aplicável
        $this->mockConn
            ->method('fetchOne')
            ->willReturn('0'); // count = 0, campo não registrado

        $migration = $this->migration(Version_1_0_4::class);
        $this->assertTrue(
            $this->callProtected($migration, 'isApplicable', $this->schemaWithTable(['dialoghsm_status']))
        );
    }

    public function testV104NotApplicableWhenTableAbsent(): void
    {
        $migration = $this->migration(Version_1_0_4::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithoutTable()));
    }

    public function testV104SqlUsesIfNotExistsAndWhereNotExists(): void
    {
        $this->mockConn->method('fetchOne')->willReturn('0');

        $sql = implode(' ', $this->collectSql($this->migration(Version_1_0_4::class)));
        $this->assertStringContainsStringIgnoringCase('ADD COLUMN IF NOT EXISTS', $sql);
        $this->assertStringContainsStringIgnoringCase('WHERE NOT EXISTS', $sql);
        $this->assertStringContainsString('dialoghsm_status', $sql);
        $this->assertStringContainsString('dialoghsm_last_response', $sql);
        $this->assertStringContainsString('dialoghsm_last_sent', $sql);
    }

    // =========================================================================
    // Version_1_0_5 — registra campos em lead_fields
    // =========================================================================

    public function testV105ApplicableWhenFieldNotRegistered(): void
    {
        $this->mockConn
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('0'); // count = 0 na primeira chamada

        $migration = $this->migration(Version_1_0_5::class);
        $this->assertTrue(
            $this->callProtected($migration, 'isApplicable', $this->schemaWithTable(['dialoghsm_status']))
        );
    }

    public function testV105NotApplicableWhenFieldAlreadyRegistered(): void
    {
        $this->mockConn
            ->method('fetchOne')
            ->willReturn('1'); // campo já está em lead_fields

        $migration = $this->migration(Version_1_0_5::class);
        $this->assertFalse(
            $this->callProtected($migration, 'isApplicable', $this->schemaWithTable(['dialoghsm_status']))
        );
    }

    public function testV105SqlHasWhereNotExistsGuard(): void
    {
        $this->mockConn->method('fetchOne')->willReturn('0');

        $sql = implode(' ', $this->collectSql($this->migration(Version_1_0_5::class)));
        // Cada INSERT deve ter proteção contra duplicata
        $this->assertStringContainsStringIgnoringCase('WHERE NOT EXISTS', $sql);
        $this->assertStringContainsString('dialoghsm_status', $sql);
        $this->assertStringContainsString('dialoghsm_last_response', $sql);
        $this->assertStringContainsString('dialoghsm_last_sent', $sql);
    }

    public function testV105NotApplicableWhenTableAbsent(): void
    {
        $migration = $this->migration(Version_1_0_5::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithoutTable()));
    }

    public function testV105NotApplicableWhenColumnAbsent(): void
    {
        // Tabela existe mas não tem a coluna dialoghsm_status → retorna false (pré-requisito não atendido)
        $migration = $this->migration(Version_1_0_5::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithTable([])));
    }

    // =========================================================================
    // Version_1_0_6 — adiciona queue_name
    // =========================================================================

    public function testV106ApplicableWhenColumnAbsent(): void
    {
        $migration = $this->migration(Version_1_0_6::class);
        $this->assertTrue($this->callProtected($migration, 'isApplicable', $this->schemaWithTable([])));
    }

    public function testV106NotApplicableWhenColumnPresent(): void
    {
        $migration = $this->migration(Version_1_0_6::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithTable(['queue_name'])));
    }

    public function testV106NotApplicableWhenTableAbsent(): void
    {
        $migration = $this->migration(Version_1_0_6::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithoutTable()));
    }

    public function testV106SqlIsIdempotent(): void
    {
        $sql = implode(' ', $this->collectSql($this->migration(Version_1_0_6::class)));
        $this->assertStringContainsStringIgnoringCase('ADD COLUMN IF NOT EXISTS', $sql);
        $this->assertStringContainsString('queue_name', $sql);
    }

    // =========================================================================
    // Version_1_0_7 — adiciona batch_queue_name
    // =========================================================================

    public function testV107ApplicableWhenColumnAbsent(): void
    {
        $migration = $this->migration(Version_1_0_7::class);
        $this->assertTrue($this->callProtected($migration, 'isApplicable', $this->schemaWithTable([])));
    }

    public function testV107NotApplicableWhenColumnPresent(): void
    {
        $migration = $this->migration(Version_1_0_7::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithTable(['batch_queue_name'])));
    }

    public function testV107NotApplicableWhenTableAbsent(): void
    {
        $migration = $this->migration(Version_1_0_7::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithoutTable()));
    }

    public function testV107SqlIsIdempotent(): void
    {
        $sql = implode(' ', $this->collectSql($this->migration(Version_1_0_7::class)));
        $this->assertStringContainsStringIgnoringCase('ADD COLUMN IF NOT EXISTS', $sql);
        $this->assertStringContainsString('batch_queue_name', $sql);
    }

    // =========================================================================
    // Version_1_0_8 — adiciona sender_name
    // =========================================================================

    public function testV108ApplicableWhenColumnAbsent(): void
    {
        $migration = $this->migration(Version_1_0_8::class);
        $this->assertTrue($this->callProtected($migration, 'isApplicable', $this->schemaWithTable([])));
    }

    public function testV108NotApplicableWhenColumnPresent(): void
    {
        $migration = $this->migration(Version_1_0_8::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithTable(['sender_name'])));
    }

    public function testV108NotApplicableWhenTableAbsent(): void
    {
        $migration = $this->migration(Version_1_0_8::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithoutTable()));
    }

    public function testV108SqlIsIdempotent(): void
    {
        $sql = implode(' ', $this->collectSql($this->migration(Version_1_0_8::class)));
        $this->assertStringContainsStringIgnoringCase('ADD COLUMN IF NOT EXISTS', $sql);
        $this->assertStringContainsString('sender_name', $sql);
    }

    // =========================================================================
    // Version_1_1_0 — adiciona campaign_id e campaign_event_id
    // =========================================================================

    public function testV110ApplicableWhenColumnAbsent(): void
    {
        $migration = $this->migration(Version_1_1_0::class);
        $this->assertTrue($this->callProtected($migration, 'isApplicable', $this->schemaWithTable([])));
    }

    public function testV110NotApplicableWhenColumnPresent(): void
    {
        $migration = $this->migration(Version_1_1_0::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithTable(['campaign_id'])));
    }

    public function testV110NotApplicableWhenTableAbsent(): void
    {
        $migration = $this->migration(Version_1_1_0::class);
        $this->assertFalse($this->callProtected($migration, 'isApplicable', $this->schemaWithoutTable()));
    }

    public function testV110SqlAddsCampaignIdColumn(): void
    {
        $sql = implode(' ', $this->collectSql($this->migration(Version_1_1_0::class)));
        $this->assertStringContainsStringIgnoringCase('ADD COLUMN IF NOT EXISTS', $sql);
        $this->assertStringContainsString('campaign_id', $sql);
    }

    public function testV110SqlAddsCampaignEventIdColumn(): void
    {
        $sql = implode(' ', $this->collectSql($this->migration(Version_1_1_0::class)));
        $this->assertStringContainsString('campaign_event_id', $sql);
    }

    public function testV110SqlAddsIndexWithIfNotExists(): void
    {
        $sql = implode(' ', $this->collectSql($this->migration(Version_1_1_0::class)));
        $this->assertStringContainsStringIgnoringCase('ADD INDEX IF NOT EXISTS', $sql);
        $this->assertStringContainsString('campaign_id_idx', $sql);
    }

    // =========================================================================
    // Contrato geral: nenhuma migration gera SQL vazio
    // =========================================================================

    /**
     * @dataProvider allMigrationsProvider
     */
    public function testEachMigrationGeneratesAtLeastOneSqlStatement(string $class): void
    {
        $this->mockConn->method('fetchOne')->willReturn('0');

        $queries = $this->collectSql($this->migration($class));
        $this->assertNotEmpty($queries, "Migration {$class} não gerou nenhum SQL");
    }

    public static function allMigrationsProvider(): array
    {
        return [
            [Version_1_0_0::class],
            [Version_1_0_1::class],
            [Version_1_0_2::class],
            [Version_1_0_3::class],
            [Version_1_0_4::class],
            [Version_1_0_5::class],
            [Version_1_0_6::class],
            [Version_1_0_7::class],
            [Version_1_0_8::class],
            [Version_1_1_0::class],
        ];
    }
}
