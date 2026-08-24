<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\CampaignRepository;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Mautic\CampaignBundle\EventListener\CampaignSubscriber as CoreCampaignSubscriber;
use Mautic\CampaignBundle\Event\CampaignEvent as CoreCampaignEventWrapper;
use Mautic\CampaignBundle\Service\CampaignAuditService;
use Mautic\EmailBundle\Entity\Email;
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

    /** @var array<int, object> entidades extra criadas por teste, removidas no tearDown */
    private array $extraEntities = [];

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
        }

        foreach ($this->extraEntities as $entity) {
            if (\Doctrine\ORM\UnitOfWork::STATE_MANAGED === $this->em->getUnitOfWork()->getEntityState($entity)) {
                $this->em->remove($entity);
            }
        }

        $this->em->flush();
        $this->extraEntities = [];
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

    private function createEmail(bool $published): Email
    {
        $email = new Email();
        $email->setName('TESTE INTEGRAÇÃO — email '.uniqid('', true));
        $email->setSubject('Assunto de teste');
        $email->setIsPublished($published);

        $this->em->persist($email);
        $this->em->flush();

        $this->extraEntities[] = $email;

        return $email;
    }

    private function addEmailAction(Campaign $campaign, Email $email): CampaignEvent
    {
        $event = new CampaignEvent();
        $event->setName('Enviar email (teste)');
        $event->setType('email.send');
        $event->setEventType('action');
        $event->setChannel('email');
        $event->setChannelId($email->getId());
        $event->setCampaign($campaign);
        $event->setProperties([]);

        $campaign->addEvent(1, $event);
        $this->em->persist($event);
        $this->em->flush();

        // Não entra em $extraEntities: Campaign::events tem cascadeAll(),
        // então remover a campanha no tearDown já remove este evento.
        return $event;
    }

    /**
     * A forma como o FlashBag do core acessa a sessão mudou entre Mautic 5 e 7:
     * no Mautic 5 ele recebe uma Session injetada direto (propriedade $session);
     * no Mautic 7 ele usa $requestStack->getSession() (a propriedade não existe
     * mais — confirmado via ReflectionException rodando este teste lá). Fora de
     * uma requisição HTTP real (CLI/PHPUnit), getSession() só funciona se
     * empurrarmos uma Request com sessão no RequestStack primeiro.
     */
    private function getFlashBagSession(): \Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $container = self::$kernel->getContainer();
        $flashBag  = $container->get(\Mautic\CoreBundle\Service\FlashBag::class);

        if ((new \ReflectionClass($flashBag))->hasProperty('session')) {
            $property = new \ReflectionProperty($flashBag, 'session');
            $property->setAccessible(true);

            return $property->getValue($flashBag);
        }

        $requestStack = $container->get('request_stack');
        $request      = $requestStack->getCurrentRequest();

        if (null === $request || !$request->hasSession()) {
            // "session.factory" é privado/inlined no container compilado (Mautic 7) —
            // não precisamos do serviço real, só de uma Session funcional pra inspecionar
            // o FlashBag que o core escreve nela.
            $session = new \Symfony\Component\HttpFoundation\Session\Session(
                new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
            );
            $request = $request ?? new \Symfony\Component\HttpFoundation\Request();
            $request->setSession($session);

            if (null === $requestStack->getCurrentRequest()) {
                $requestStack->push($request);
            }
        }

        return $requestStack->getSession();
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

    /**
     * Garante que o fix não é "silenciar tudo": campanha mista (WhatsApp + email)
     * deve continuar funcionando exatamente como antes — fetchEmailIdsById()
     * retorna o id do email, e ele é carregado normalmente.
     */
    public function testMixedChannelCampaignStillLoadsLinkedEmail(): void
    {
        $email = $this->createEmail(published: true);
        $this->addEmailAction($this->wpOnlyCampaign, $email);

        $emailIds = $this->campaignRepository->fetchEmailIdsById($this->wpOnlyCampaign->getId());
        // Hydratação de int como string varia entre versões/drivers do Doctrine (visto no Mautic 7);
        // o que importa aqui é o valor, não o tipo exato retornado pelo core.
        $this->assertSame([$email->getId()], array_map('intval', $emailIds));

        $service = self::$kernel->getContainer()->get(CampaignAuditService::class);
        $service->addWarningForUnpublishedEmails($this->wpOnlyCampaign);

        $this->addToAssertionCount(1); // não lança exception com email vinculado
    }

    /**
     * Comportamento original preservado: email NÃO publicado vinculado a uma
     * campanha publicada deve gerar um flash warning — o fix não pode
     * silenciar esse aviso legítimo.
     */
    public function testWarnsWhenLinkedEmailIsUnpublished(): void
    {
        $email = $this->createEmail(published: false);
        $this->addEmailAction($this->wpOnlyCampaign, $email);

        $session = $this->getFlashBagSession();
        $session->getFlashBag()->clear();

        $service = self::$kernel->getContainer()->get(CampaignAuditService::class);
        $service->addWarningForUnpublishedEmails($this->wpOnlyCampaign);

        $warnings = $session->getFlashBag()->peek('warning');
        $this->assertNotEmpty(
            $warnings,
            'Email não publicado vinculado a campanha publicada deve gerar flash warning.'
        );
    }

    /**
     * Espelho do teste anterior: email PUBLICADO não deve gerar nenhum warning.
     */
    public function testDoesNotWarnWhenLinkedEmailIsPublished(): void
    {
        $email = $this->createEmail(published: true);
        $this->addEmailAction($this->wpOnlyCampaign, $email);

        $session = $this->getFlashBagSession();
        $session->getFlashBag()->clear();

        $service = self::$kernel->getContainer()->get(CampaignAuditService::class);
        $service->addWarningForUnpublishedEmails($this->wpOnlyCampaign);

        $warnings = $session->getFlashBag()->peek('warning');
        $this->assertEmpty(
            $warnings,
            'Email publicado não deve gerar flash warning.'
        );
    }

    /**
     * Dispara o CampaignSubscriber REAL do core (não mockado) via
     * CAMPAIGN_POST_SAVE, exatamente como o CampaignController::saveAction
     * dispara em produção — para garantir que a proteção vale no caminho
     * completo do evento, não só chamando addWarningForUnpublishedEmails()
     * isoladamente.
     */
    public function testCoreCampaignSubscriberPostSaveDoesNotThrowForWhatsAppOnlyCampaign(): void
    {
        $container      = self::$kernel->getContainer();
        $coreSubscriber = $container->get(CoreCampaignSubscriber::class);

        $event = new CoreCampaignEventWrapper($this->wpOnlyCampaign, isNew: false);

        $coreSubscriber->onCampaignPostSave($event);

        $this->addToAssertionCount(1); // chegou aqui sem lançar exception = sucesso
    }
}
