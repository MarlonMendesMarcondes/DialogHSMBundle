<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Database;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Base dos testes de banco: kernel real do Mautic + MySQL real, sem mocks.
 *
 * O kernel é compilado num cache próprio apontando para o banco de teste (criado por
 * setup-test-database.sh), via MAUTIC_CONFIG_PARAMETERS — o db_name é compilado no container,
 * então reaproveitar o cache da aplicação gravaria no banco de desenvolvimento. Antes de
 * qualquer teste o nome do banco conectado é conferido; se não for o de teste, a suíte para.
 *
 * Cada teste roda dentro de uma transação desfeita no tearDown: nada fica gravado.
 *
 * Transportes de mensageria apontam para in-memory/null: nada é publicado no RabbitMQ nem no
 * Redis do ambiente, e nenhum envio chega à 360dialog.
 */
abstract class DatabaseTestCase extends TestCase
{
    public const DATABASE = 'mautic_dialoghsm_test';

    private const CACHE_DIR = '/tmp/dialoghsm-database-tests';

    private static ?\AppKernel $kernel = null;

    private static ?string $unavailableReason = null;

    protected EntityManagerInterface $em;

    protected Connection $connection;

    public static function setUpBeforeClass(): void
    {
        self::bootKernel();
    }

    protected function setUp(): void
    {
        if (null !== self::$unavailableReason) {
            $this->markTestSkipped(self::$unavailableReason);
        }

        $this->em         = $this->getService('doctrine.orm.entity_manager');
        $this->connection = $this->em->getConnection();

        if (self::DATABASE !== $this->connection->getDatabase()) {
            // Nunca deve acontecer — mas se acontecer, nenhum teste pode gravar nada.
            throw new \RuntimeException(sprintf('Conectado ao banco "%s", não ao banco de teste "%s". Abortando.', $this->connection->getDatabase(), self::DATABASE));
        }

        $this->em->clear();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (null !== self::$unavailableReason) {
            return;
        }

        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->em->clear();
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return T
     */
    protected function getService(string $id): object
    {
        return self::$kernel->getContainer()->get($id);
    }

    /**
     * Roda um comando do console no mesmo processo (e na mesma transação) do teste.
     *
     * @param array<string, mixed> $arguments
     */
    protected function runCommand(string $name, array $arguments = []): string
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        $output = new BufferedOutput();
        $application->run(new ArrayInput(['command' => $name] + $arguments + ['--no-interaction' => true]), $output);

        return $output->fetch();
    }

    // -------------------------------------------------------------------------
    // Fixtures — inserção direta via DBAL, dentro da transação do teste
    // -------------------------------------------------------------------------

    /**
     * Número com URL .invalid: se algum caminho tentar enviar de verdade, a chamada falha no DNS.
     *
     * @param array<string, mixed> $overrides
     */
    protected function createWhatsAppNumber(array $overrides = []): int
    {
        $this->connection->insert('dialog_hsm_numbers', $overrides + [
            'is_published'       => 1,
            'name'               => 'Teste DB '.bin2hex(random_bytes(4)),
            'phone_number'       => '+5511900000000',
            'api_key'            => 'TEST_FAKE_KEY',
            'base_url'           => 'https://dialoghsm-test.invalid/messages',
            'queue_name'         => 'dialoghsm_test_bulk',
            'batch_queue_name'   => 'dialoghsm_test_batch',
            'api_key_enc_marker' => 1,
            'provider'           => '360dialog',
            'date_added'         => $this->utcNow(),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    protected function createLead(?string $mobile = null, ?string $phone = null): int
    {
        $this->connection->insert('leads', [
            'is_published' => 1,
            'points'       => 0,
            'firstname'    => 'Teste DB',
            'mobile'       => $mobile,
            'phone'        => $phone,
            'date_added'   => $this->utcNow(),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createCampaign(array $overrides = []): int
    {
        $this->connection->insert('campaigns', $overrides + [
            'is_published'  => 1,
            'name'          => 'Teste DB',
            'allow_restart' => 0,
            'version'       => 1,
            'date_added'    => $this->utcNow(),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Ação "Enviar WhatsApp (RabbitMQ)" imediata, raiz da campanha.
     */
    protected function createQueueActionEvent(int $campaignId, int $numberId, string $mode = 'bulk'): int
    {
        $this->connection->insert('campaign_events', [
            'campaign_id'            => $campaignId,
            'name'                   => 'Enviar WhatsApp',
            'type'                   => 'dialoghsm.send_whatsapp_queue',
            'event_type'             => 'action',
            'event_order'            => 1,
            'properties'             => serialize([
                'whatsapp_number' => $numberId,
                'payload_data'    => ['list' => [['label' => 'content', 'value' => 'template_teste']]],
                'queue_override'  => $mode,
                'send_delay'      => 0,
            ]),
            'trigger_mode'           => 'immediate',
            'trigger_interval'       => 0,
            'trigger_interval_unit'  => 'd',
            'channel'                => 'whatsapp',
            'failed_count'           => 0,
            'trigger_restricted_dow' => 'a:0:{}',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    protected function addLeadToCampaign(int $campaignId, int $leadId, int $rotation = 1): void
    {
        $this->connection->insert('campaign_leads', [
            'campaign_id'      => $campaignId,
            'lead_id'          => $leadId,
            'date_added'       => $this->utcNow(),
            'manually_removed' => 0,
            'manually_added'   => 1,
            'rotation'         => $rotation,
        ]);
    }

    protected function utcNow(string $modifier = 'now'): string
    {
        return (new \DateTime($modifier, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private static function bootKernel(): void
    {
        if (null !== self::$kernel || null !== self::$unavailableReason) {
            return;
        }

        $reason = self::checkTestDatabase();
        if (null !== $reason) {
            self::$unavailableReason = $reason;

            return;
        }

        self::setEnv('MAUTIC_CONFIG_PARAMETERS', json_encode([
            'db_name'    => self::DATABASE,
            'cache_path' => self::CACHE_DIR.'/cache',
            'log_path'   => self::CACHE_DIR.'/logs',
        ]));
        self::setEnv('MAUTIC_DB_NAME', self::DATABASE);
        self::setEnv('MAUTIC_TABLE_PREFIX', '');
        self::setEnv('APP_ENV', 'prod');
        self::setEnv('MAUTIC_MESSENGER_DSN_WHATSAPP', 'in-memory://');
        self::setEnv('MAUTIC_MESSENGER_DSN_WHATSAPP_DIRECT', 'null://null');
        self::setEnv('MAUTIC_MESSENGER_DSN_WHATSAPP_FAILED', 'null://null');
        // O docker-compose local desliga a proteção de SSRF para a API falsa de desenvolvimento;
        // nos testes ela fica ligada, para nenhum envio chegar a host interno.
        self::setEnv('DIALOGHSM_DISABLE_SSRF_CHECK', '0');

        if (!defined('MAUTIC_ROOT_DIR')) {
            define('MAUTIC_ROOT_DIR', dirname(__DIR__, 4));
        }

        // debug=true: o Symfony recompila o container quando config/serviços do plugin mudam
        $kernel = new \AppKernel('prod', true);
        $kernel->boot();

        self::$kernel = $kernel;
    }

    /**
     * Confere, sem o kernel, que o banco de teste existe — senão os testes são pulados com a
     * instrução de como criá-lo, em vez de falhar.
     */
    private static function checkTestDatabase(): ?string
    {
        $host = getenv('MAUTIC_DB_HOST') ?: 'mysql';
        $port = getenv('MAUTIC_DB_PORT') ?: '3306';
        $user = getenv('MAUTIC_DB_USER') ?: 'mautic';
        $pass = getenv('MAUTIC_DB_PASSWORD') ?: '';

        try {
            $pdo    = new \PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, self::DATABASE), $user, $pass);
            $tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
        } catch (\PDOException $e) {
            return sprintf('Banco de teste "%s" indisponível (%s). Crie com Test/Database/setup-test-database.sh.', self::DATABASE, $e->getMessage());
        }

        return 0 === $tables
            ? sprintf('Banco de teste "%s" está vazio. Rode Test/Database/setup-test-database.sh.', self::DATABASE)
            : null;
    }

    private static function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }
}
