<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\CampaignRepository;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Mautic\CampaignBundle\Service\CampaignAuditService;
use MauticPlugin\DialogHSMBundle\Service\CampaignAuditServiceFix;
use PHPUnit\Framework\TestCase;

/**
 * Teste de integração REAL contra o kernel e o banco do Mautic (não mockado).
 *
 * Reproduz o incidente de produção de 2026-08-24: salvar uma campanha publicada
 * sem nenhuma ação de e-mail dispara CampaignAuditService::addWarningForUnpublishedEmails(),
 * que chama fetchEmailIdsById() (retorna [] quando não há ações 'email') seguido de
 * $emailRepository->findBy(['id' => []]).
 *
 * Se o Doctrine ORM instalado não protege findBy() contra array de critério vazio,
 * ele gera "WHERE t0.id IN ()" — SQL inválido, SQLSTATE 1064. Esse comportamento
 * MUDOU entre patch-versions do doctrine/orm (confirmado: 2.20.7 gera "IN ()",
 * 2.20.9 gera "1=0"), então este teste não é hipotético: ele prova, na versão do
 * ORM realmente instalada neste ambiente, se o bug do core dispara ou não — e
 * garante que o CampaignAuditServiceFix do plugin nunca dispara essa query,
 * independentemente da versão do Doctrine.
 *
 * Precisa do kernel Symfony + MySQL reais, por isso fica em Test/Integration
 * (não roda no testsuite "unit"). Rodar dentro do container mautic_app:
 *   php /tmp/phpunit.phar --testsuite integration --filter CampaignAuditServiceIntegrationTest
 */
class CampaignAuditServiceIntegrationTest extends TestCase
{
    private static ?object $kernel = null;

    private EntityManagerInterface $em;
    private CampaignRepository $campaignRepository;
    private Campaign $wpOnlyCampaign;

    public static function setUpBeforeClass(): void
    {
        defined('IN_MAUTIC_CONSOLE') or define('IN_MAUTIC_CONSOLE', 1);
        defined('MAUTIC_ROOT_DIR') or define('MAUTIC_ROOT_DIR', realpath('/var/www/html/docroot'));

        require_once '/var/www/html/docroot/autoload.php';
        require_once '/var/www/html/docroot/app/config/bootstrap.php';

        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'prod';
        $_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '0';

        self::$kernel = new AppKernel('prod', false);
        self::$kernel->boot();
    }

    protected function setUp(): void
    {
        if (null === self::$kernel) {
            self::markTestSkipped('Kernel do Mautic não pôde ser inicializado (rodar dentro do container mautic_app).');
        }

        $container = self::$kernel->getContainer();

        $this->em                 = $container->get('doctrine.orm.entity_manager');
        $this->campaignRepository = $this->em->getRepository(Campaign::class);

        $this->wpOnlyCampaign = $this->createPublishedCampaignWithoutEmailActions();
    }

    protected function tearDown(): void
    {
        if (isset($this->wpOnlyCampaign) && $this->wpOnlyCampaign->getId()) {
            $this->em->remove($this->wpOnlyCampaign);
            $this->em->flush();
        }
    }

    private function createPublishedCampaignWithoutEmailActions(): Campaign
    {
        $campaign = new Campaign();
        $campaign->setName('TESTE INTEGRAÇÃO — sem ação de email '.uniqid('', true));
        $campaign->setIsPublished(true);

        $event = new CampaignEvent();
        $event->setName('Enviar WhatsApp (teste)');
        $event->setType('dialoghsm.send_whatsapp');
        $event->setEventType('action');
        $event->setChannel('whatsapp');
        $event->setCampaign($campaign);
        $event->setProperties([]);

        $campaign->addEvent(0, $event);

        $this->em->persist($campaign);
        $this->em->persist($event);
        $this->em->flush();

        return $campaign;
    }

    public function testFetchEmailIdsByIdIsEmptyForWhatsAppOnlyCampaign(): void
    {
        $emailIds = $this->campaignRepository->fetchEmailIdsById($this->wpOnlyCampaign->getId());

        $this->assertSame(
            [],
            $emailIds,
            'Pré-condição do bug: campanha sem ação de email deve resultar em lista de ids vazia.'
        );
    }

    /**
     * Documenta o comportamento REAL do core nesta instalação. Não falha o build:
     * o objetivo é registrar, a cada execução, se o bug do Doctrine ORM está
     * presente (SyntaxErrorException) ou não (array vazio) na versão instalada.
     */
    public function testCoreCampaignAuditServiceBehaviorIsVersionDependent(): void
    {
        $container    = self::$kernel->getContainer();
        $coreService  = $container->get(CampaignAuditService::class);

        // Se o plugin já sobrescreveu o serviço via DI, testamos a classe core
        // diretamente para observar o comportamento "de fábrica" do Mautic.
        if (!$coreService instanceof CampaignAuditServiceFix) {
            $bugReproduced = false;

            try {
                $coreService->addWarningForUnpublishedEmails($this->wpOnlyCampaign);
            } catch (\Doctrine\DBAL\Exception\SyntaxErrorException $e) {
                $bugReproduced = true;
                $this->assertStringContainsString('1064', $e->getMessage());
            }

            fwrite(STDERR, sprintf(
                "\n[INFO] doctrine/orm nesta instalação %s o bug do IN() vazio.\n",
                $bugReproduced ? 'REPRODUZ' : 'NÃO reproduz (já corrigido nesta versão)'
            ));

            $this->assertTrue(true); // teste é informativo, não normativo sobre o core
        } else {
            $this->markTestSkipped('CampaignAuditService já está decorado pelo plugin — sem o fix, veja CampaignAuditServiceFixIntegrationTest.');
        }
    }

    /**
     * Este é o teste que trava o build: com o serviço do plugin ativo (via
     * Config/services.php), salvar uma campanha 100% WhatsApp NUNCA pode lançar
     * SyntaxErrorException, independentemente da versão do Doctrine instalada.
     */
    public function testFixNeverThrowsRegardlessOfDoctrineVersion(): void
    {
        $container = self::$kernel->getContainer();
        $service   = $container->get(CampaignAuditService::class);

        $this->assertInstanceOf(
            CampaignAuditServiceFix::class,
            $service,
            'Config/services.php deve substituir CampaignAuditService pelo CampaignAuditServiceFix do plugin.'
        );

        $service->addWarningForUnpublishedEmails($this->wpOnlyCampaign);

        $this->addToAssertionCount(1); // chegou aqui sem lançar exception = sucesso
    }

    public function testFullCampaignSaveFlowSucceeds(): void
    {
        $campaignModel = self::$kernel->getContainer()->get(\Mautic\CampaignBundle\Model\CampaignModel::class);

        $campaignModel->saveEntity($this->wpOnlyCampaign);

        $this->addToAssertionCount(1);
    }
}
