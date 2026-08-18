<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Model\NotificationModel;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Notifica os usuários admin do Mautic nas TRANSIÇÕES de estado do saldo de
 * um número:
 *   ok            -> low       ("saldo acabando" — abaixo do limiar configurado)
 *   ok|low        -> depleted  ("saldo acabou" — <= 0, disparos serão bloqueados)
 *   low|depleted  -> ok        ("saldo recarregado" — pronto para novos disparos)
 *
 * O estado anterior é persistido em WhatsAppNumber::balanceAlertState, então
 * o cron rodando de hora em hora só notifica quando o estado de fato muda —
 * sem repetir o mesmo aviso a cada execução enquanto o saldo continuar baixo.
 * Na primeira checagem de um número (estado anterior nulo), só grava a
 * linha de base, sem notificar — evita alarme falso ao configurar
 * client_id/channel_id de um número que já estava com saldo baixo há tempos.
 */
class BalanceAlertService
{
    private const STATE_OK       = 'ok';
    private const STATE_LOW      = 'low';
    private const STATE_DEPLETED = 'depleted';

    /** @var User[]|null */
    private ?array $adminUsers = null;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationModel $notificationModel,
        private PartnerConfigProvider $partnerConfigProvider,
        private TranslatorInterface $translator,
        private MailHelper $mailHelper,
    ) {
    }

    public function checkAndNotify(WhatsAppNumber $number, ?float $balance, ?string $currency): void
    {
        if (null === $balance) {
            return;
        }

        $newState      = $this->resolveState($balance);
        $previousState = $number->getBalanceAlertState();

        if (null !== $previousState && $previousState !== $newState) {
            $this->notify($number, $newState, $balance, $currency);
        }

        $number->setBalanceAlertState($newState);
        $this->entityManager->persist($number);
        $this->entityManager->flush();
    }

    private function resolveState(float $balance): string
    {
        if ($balance <= 0.0) {
            return self::STATE_DEPLETED;
        }

        $threshold = $this->partnerConfigProvider->getBalanceAlertThreshold();

        if (null !== $threshold && $balance < $threshold) {
            return self::STATE_LOW;
        }

        return self::STATE_OK;
    }

    private function notify(WhatsAppNumber $number, string $newState, float $balance, ?string $currency): void
    {
        [$transKey, $iconClass] = match ($newState) {
            self::STATE_DEPLETED => ['depleted', 'text-danger ri-error-warning-line'],
            self::STATE_LOW      => ['low', 'text-warning ri-alert-line'],
            self::STATE_OK       => ['restored', 'text-success ri-checkbox-circle-line'],
        };

        $header  = $this->translator->trans("dialoghsm.number.balance_alert.{$transKey}.header", ['%name%' => $number->getName()]);
        $message = $this->translator->trans("dialoghsm.number.balance_alert.{$transKey}.message", [
            '%name%'     => $number->getName(),
            '%balance%'  => number_format($balance, 2),
            '%currency%' => strtoupper((string) $currency),
        ]);

        $recipients = $this->getRecipients();

        foreach ($recipients as $user) {
            $this->notificationModel->addNotification(
                $message,
                'dialoghsm.balance_alert',
                false,
                $header,
                $iconClass,
                null,
                $user,
                "dialoghsm.balance.{$transKey}.".$number->getId(),
                new \DateTime('-5 minutes')
            );
        }

        if ($this->partnerConfigProvider->getBalanceAlertSendEmail()) {
            $this->sendEmail($recipients, $header, $message);
        }
    }

    /**
     * @param User[] $recipients
     */
    private function sendEmail(array $recipients, string $subject, string $body): void
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn (User $user): ?string => $user->getEmail(),
            $recipients
        ))));

        if (empty($emails)) {
            return;
        }

        $this->mailHelper->setTo($emails);
        $this->mailHelper->setSubject($subject);
        $this->mailHelper->setBody($body);
        $this->mailHelper->send(true);
    }

    /**
     * Destinatários configurados em Integrações > 360dialog WhatsApp > Config
     * (balance_alert_recipients). Se nenhum for escolhido, cai no fallback:
     * todos os usuários admin.
     *
     * @return User[]
     */
    private function getRecipients(): array
    {
        $ids = $this->partnerConfigProvider->getBalanceAlertRecipientIds();

        if (!empty($ids)) {
            return $this->entityManager->getRepository(User::class)->findBy(['id' => $ids]);
        }

        return $this->getAdminUsers();
    }

    /**
     * @return User[]
     */
    private function getAdminUsers(): array
    {
        if (null !== $this->adminUsers) {
            return $this->adminUsers;
        }

        $entities = $this->entityManager->getRepository(User::class)->getEntities([
            'filter' => [
                'force' => [
                    [
                        'column' => 'r.isAdmin',
                        'expr'   => 'eq',
                        'value'  => true,
                    ],
                ],
            ],
        ]);

        $this->adminUsers = is_array($entities) ? $entities : iterator_to_array($entities);

        return $this->adminUsers;
    }
}
