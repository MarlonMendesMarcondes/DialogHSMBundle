<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\CampaignRepository;
use Mautic\CampaignBundle\Service\CampaignAuditService;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\EmailRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Substitui o Mautic\CampaignBundle\Service\CampaignAuditService (via DI em Config/services.php)
 * para corrigir um bug do core: ao salvar uma campanha publicada sem nenhuma ação de e-mail,
 * fetchEmailIdsById() retorna [] e o findBy(['id' => []]) original gera "WHERE id IN ()",
 * SQL inválido que quebra o save com SQLSTATE 1064. Campanhas 100% WhatsApp (DialogHSM)
 * disparam esse caso sempre.
 */
class CampaignAuditServiceFix extends CampaignAuditService
{
    public function __construct(
        private FlashBag $flashBag,
        private UrlGeneratorInterface $urlGenerator,
        private CampaignRepository $campaignRepository,
        private EmailRepository $emailRepository,
    ) {
        parent::__construct($flashBag, $urlGenerator, $campaignRepository, $emailRepository);
    }

    public function addWarningForUnpublishedEmails(Campaign $campaign): void
    {
        $emailIds = $this->campaignRepository->fetchEmailIdsById($campaign->getId());

        if (empty($emailIds)) {
            return;
        }

        $emails = $this->emailRepository->findBy(['id' => $emailIds]);

        foreach ($emails as $email) {
            if (!$email->isPublished()) {
                $this->setEmailWarningFlashMessage($email);
            }
        }
    }

    private function setEmailWarningFlashMessage(Email $email): void
    {
        $this->flashBag->add(
            'mautic.core.notice.campaign.unpublished.email',
            [
                '%name%'      => $email->getName(),
                '%menu_link%' => 'mautic_email_index',
                '%url%'       => $this->urlGenerator->generate('mautic_email_action', [
                    'objectAction' => 'edit',
                    'objectId'     => $email->getId(),
                ]),
            ],
            FlashBag::LEVEL_WARNING,
        );
    }
}
