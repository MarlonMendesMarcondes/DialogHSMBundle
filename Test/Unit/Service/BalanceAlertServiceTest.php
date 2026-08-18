<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Model\NotificationModel;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Entity\UserRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Service\BalanceAlertService;
use MauticPlugin\DialogHSMBundle\Service\PartnerConfigProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class BalanceAlertServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private NotificationModel&MockObject $notificationModel;
    private PartnerConfigProvider&MockObject $partnerConfigProvider;
    private TranslatorInterface&MockObject $translator;
    private MailHelper&MockObject $mailHelper;

    protected function setUp(): void
    {
        $this->em                    = $this->createMock(EntityManagerInterface::class);
        $this->notificationModel     = $this->createMock(NotificationModel::class);
        $this->partnerConfigProvider = $this->createMock(PartnerConfigProvider::class);
        $this->translator            = $this->createMock(TranslatorInterface::class);
        $this->mailHelper            = $this->createMock(MailHelper::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $key, array $params = []) => $key
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeService(): BalanceAlertService
    {
        return new BalanceAlertService($this->em, $this->notificationModel, $this->partnerConfigProvider, $this->translator, $this->mailHelper);
    }

    private function makeNumber(?string $initialState = null, int $id = 1, string $name = 'Vendas'): WhatsAppNumber
    {
        $number = new WhatsAppNumber();
        $number->setName($name);
        $number->setBalanceAlertState($initialState);

        // Simula ID persistido via reflection (id não tem setter público)
        $ref = new \ReflectionProperty(WhatsAppNumber::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($number, $id);

        return $number;
    }

    private function mockAdminUsers(int $count = 1): void
    {
        $users = array_map(fn () => $this->createMock(User::class), range(1, $count));

        $repo = $this->createMock(UserRepository::class);
        $repo->method('getEntities')->willReturn($users);

        $this->em->method('getRepository')->with(User::class)->willReturn($repo);
    }

    // =========================================================================
    // Saldo nulo — nada acontece
    // =========================================================================

    public function testDoesNothingWhenBalanceIsNull(): void
    {
        $this->notificationModel->expects($this->never())->method('addNotification');
        $this->em->expects($this->never())->method('persist');

        $service = $this->makeService();
        $service->checkAndNotify($this->makeNumber('ok'), null, 'usd');
    }

    // =========================================================================
    // Primeira checagem — grava linha de base, sem notificar
    // =========================================================================

    public function testFirstCheckEverSetsBaselineWithoutNotifying(): void
    {
        $this->mockAdminUsers();
        $this->partnerConfigProvider->method('getBalanceAlertThreshold')->willReturn(10.0);
        $this->notificationModel->expects($this->never())->method('addNotification');

        $number = $this->makeNumber(null);
        $service = $this->makeService();
        $service->checkAndNotify($number, 5.0, 'usd');

        $this->assertSame('low', $number->getBalanceAlertState());
    }

    // =========================================================================
    // Transições que notificam
    // =========================================================================

    public function testTransitionOkToLowNotifiesLow(): void
    {
        $this->mockAdminUsers();
        $this->partnerConfigProvider->method('getBalanceAlertThreshold')->willReturn(10.0);

        $this->notificationModel->expects($this->once())
            ->method('addNotification')
            ->with(
                $this->anything(),
                'dialoghsm.balance_alert',
                false,
                $this->anything(),
                $this->stringContains('ri-alert-line'),
                null,
                $this->isInstanceOf(User::class),
                $this->stringContains('dialoghsm.balance.low.1'),
                $this->isInstanceOf(\DateTime::class)
            );

        $number = $this->makeNumber('ok');
        $this->makeService()->checkAndNotify($number, 5.0, 'usd');

        $this->assertSame('low', $number->getBalanceAlertState());
    }

    public function testTransitionOkToDepletedNotifiesDepleted(): void
    {
        $this->mockAdminUsers();

        $this->notificationModel->expects($this->once())
            ->method('addNotification')
            ->with(
                $this->anything(),
                'dialoghsm.balance_alert',
                false,
                $this->anything(),
                $this->stringContains('ri-error-warning-line'),
                null,
                $this->isInstanceOf(User::class),
                $this->stringContains('dialoghsm.balance.depleted.1'),
                $this->anything()
            );

        $number = $this->makeNumber('ok');
        $this->makeService()->checkAndNotify($number, 0.0, 'usd');

        $this->assertSame('depleted', $number->getBalanceAlertState());
    }

    public function testTransitionLowToDepletedNotifiesDepleted(): void
    {
        $this->mockAdminUsers();
        $this->notificationModel->expects($this->once())->method('addNotification');

        $number = $this->makeNumber('low');
        $this->makeService()->checkAndNotify($number, -0.5, 'usd');

        $this->assertSame('depleted', $number->getBalanceAlertState());
    }

    public function testTransitionDepletedToOkNotifiesRestored(): void
    {
        $this->mockAdminUsers();

        $this->notificationModel->expects($this->once())
            ->method('addNotification')
            ->with(
                $this->anything(),
                'dialoghsm.balance_alert',
                false,
                $this->anything(),
                $this->stringContains('ri-checkbox-circle-line'),
                null,
                $this->isInstanceOf(User::class),
                $this->stringContains('dialoghsm.balance.restored.1'),
                $this->anything()
            );

        $number = $this->makeNumber('depleted');
        $this->makeService()->checkAndNotify($number, 100.0, 'usd');

        $this->assertSame('ok', $number->getBalanceAlertState());
    }

    public function testTransitionLowToOkNotifiesRestored(): void
    {
        $this->mockAdminUsers();
        $this->partnerConfigProvider->method('getBalanceAlertThreshold')->willReturn(10.0);
        $this->notificationModel->expects($this->once())->method('addNotification');

        $number = $this->makeNumber('low');
        $this->makeService()->checkAndNotify($number, 50.0, 'usd');

        $this->assertSame('ok', $number->getBalanceAlertState());
    }

    // =========================================================================
    // Sem transição — não notifica
    // =========================================================================

    public function testNoTransitionDoesNotNotify(): void
    {
        $this->mockAdminUsers();
        $this->notificationModel->expects($this->never())->method('addNotification');

        $number = $this->makeNumber('ok');
        $this->makeService()->checkAndNotify($number, 48.15, 'usd');

        $this->assertSame('ok', $number->getBalanceAlertState());
    }

    public function testRepeatedDepletedDoesNotRenotify(): void
    {
        $this->mockAdminUsers();
        $this->notificationModel->expects($this->never())->method('addNotification');

        $number = $this->makeNumber('depleted');
        $this->makeService()->checkAndNotify($number, 0.0, 'usd');

        $this->assertSame('depleted', $number->getBalanceAlertState());
    }

    // =========================================================================
    // "low" é inalcançável sem limiar configurado
    // =========================================================================

    public function testLowStateUnreachableWithoutThreshold(): void
    {
        $this->mockAdminUsers();
        $this->partnerConfigProvider->method('getBalanceAlertThreshold')->willReturn(null);
        $this->notificationModel->expects($this->never())->method('addNotification');

        $number = $this->makeNumber('ok');
        $this->makeService()->checkAndNotify($number, 5.0, 'usd');

        $this->assertSame('ok', $number->getBalanceAlertState());
    }

    // =========================================================================
    // Persistência
    // =========================================================================

    public function testPersistsAndFlushesAfterCheck(): void
    {
        $this->mockAdminUsers();
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->makeService()->checkAndNotify($this->makeNumber('ok'), 48.15, 'usd');
    }

    // =========================================================================
    // Múltiplos admins
    // =========================================================================

    public function testNotifiesEachAdminUserSeparately(): void
    {
        $this->mockAdminUsers(3);
        $this->notificationModel->expects($this->exactly(3))->method('addNotification');

        $number = $this->makeNumber('ok');
        $this->makeService()->checkAndNotify($number, 0.0, 'usd');
    }

    // =========================================================================
    // Destinatários configurados (em vez do fallback de admins)
    // =========================================================================

    public function testUsesConfiguredRecipientsInsteadOfAdmins(): void
    {
        $this->partnerConfigProvider->method('getBalanceAlertRecipientIds')->willReturn([5, 9]);

        $configuredUsers = [$this->createMock(User::class), $this->createMock(User::class)];
        $repo            = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('findBy')->with(['id' => [5, 9]])->willReturn($configuredUsers);
        $repo->expects($this->never())->method('getEntities');
        $this->em->method('getRepository')->with(User::class)->willReturn($repo);

        $this->notificationModel->expects($this->exactly(2))->method('addNotification');

        $number = $this->makeNumber('ok');
        $this->makeService()->checkAndNotify($number, 0.0, 'usd');
    }

    public function testFallsBackToAdminsWhenNoRecipientsConfigured(): void
    {
        $this->mockAdminUsers(2);
        $this->partnerConfigProvider->method('getBalanceAlertRecipientIds')->willReturn([]);

        $this->notificationModel->expects($this->exactly(2))->method('addNotification');

        $number = $this->makeNumber('ok');
        $this->makeService()->checkAndNotify($number, 0.0, 'usd');
    }

    // =========================================================================
    // Envio por e-mail (opt-in)
    // =========================================================================

    public function testDoesNotSendEmailByDefault(): void
    {
        $this->mockAdminUsers();
        $this->partnerConfigProvider->method('getBalanceAlertSendEmail')->willReturn(false);

        $this->mailHelper->expects($this->never())->method('send');

        $number = $this->makeNumber('ok');
        $this->makeService()->checkAndNotify($number, 0.0, 'usd');
    }

    public function testSendsEmailWhenEnabled(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('admin@example.com');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('getEntities')->willReturn([$user]);
        $this->em->method('getRepository')->with(User::class)->willReturn($repo);

        $this->partnerConfigProvider->method('getBalanceAlertSendEmail')->willReturn(true);

        $this->mailHelper->expects($this->once())->method('reset');
        $this->mailHelper->expects($this->once())->method('setTo')->with(['admin@example.com']);
        $this->mailHelper->expects($this->once())->method('setSubject');
        $this->mailHelper->expects($this->once())->method('setBody');
        $this->mailHelper->expects($this->once())->method('send')->with(true);

        $number = $this->makeNumber('ok');
        $this->makeService()->checkAndNotify($number, 0.0, 'usd');
    }

    /**
     * Regressão: sem reset() entre envios, o MailHelper acumula estado interno
     * (queuedRecipients, flag "fatal") entre chamadas — uma falha no e-mail do
     * primeiro número podia silenciar o alerta de todos os seguintes no mesmo
     * cron. reset() precisa ser chamado uma vez POR e-mail enviado.
     */
    public function testResetsMailHelperBeforeEachEmailWhenMultipleNumbersAlertInSameRun(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('admin@example.com');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('getEntities')->willReturn([$user]);
        $this->em->method('getRepository')->with(User::class)->willReturn($repo);

        $this->partnerConfigProvider->method('getBalanceAlertSendEmail')->willReturn(true);

        $service = $this->makeService();

        $numberA = $this->makeNumber('ok', 1);
        $numberB = $this->makeNumber('ok', 2);

        $this->mailHelper->expects($this->exactly(2))->method('reset');
        $this->mailHelper->expects($this->exactly(2))->method('send');

        $service->checkAndNotify($numberA, 0.0, 'usd');
        $service->checkAndNotify($numberB, 0.0, 'usd');
    }

    public function testDoesNotSendEmailWhenNoRecipientHasEmail(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn(null);

        $repo = $this->createMock(UserRepository::class);
        $repo->method('getEntities')->willReturn([$user]);
        $this->em->method('getRepository')->with(User::class)->willReturn($repo);

        $this->partnerConfigProvider->method('getBalanceAlertSendEmail')->willReturn(true);

        $this->mailHelper->expects($this->never())->method('send');

        $number = $this->makeNumber('ok');
        $this->makeService()->checkAndNotify($number, 0.0, 'usd');
    }
}
