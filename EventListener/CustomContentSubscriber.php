<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Mautic\ChannelBundle\Entity\Message;
use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

class CustomContentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly IntegrationHelper $integrationHelper,
        private readonly Environment $twig,
        private readonly RouterInterface $router,
        private readonly ?MessageLogRepository $messageLogRepository = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => ['onInjectContent', 0],
        ];
    }

    public function onInjectContent(CustomContentEvent $event): void
    {
        $integration = $this->integrationHelper->getIntegrationObject(DialogHSMIntegration::NAME);
        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            return;
        }

        if ('page.header.right' === $event->getContext()) {
            $request = $this->requestStack?->getCurrentRequest();
            if (null === $request || 'mautic_dashboard_index' !== $request->attributes->get('_route')) {
                return;
            }

            // Esconde o botão "Visualizar relatório completo" (core do ReportBundle) só no
            // widget de Relatório do gráfico HSM na Dashboard. Não há opção nativa nem hook de
            // customContent dentro do widget do Report para desligar isso por widget — o :has()
            // via href evita depender de classe/id genéricos (label-success é reaproveitado em
            // vários outros lugares do Mautic, ex.: badges de "Publicado").
            $event->addContent('<style>a[href*="/reports/view/"]:has(.label-success){display:none;}</style>');

            return;
        }

        if ('tabs.below' === $event->getContext()) {
            $event->addContent($this->twig->render(
                '@DialogHSM/Channel/edit_button.html.twig',
                [
                    'listUrl'     => $this->router->generate('mautic_dialoghsm_message_index'),
                    'editBaseUrl' => $this->router->generate('mautic_dialoghsm_message_action', ['objectAction' => 'edit', 'objectId' => '__ID__']),
                ]
            ));

            return;
        }

        if ('details.stats.graph.below' === $event->getContext()) {
            $vars = $event->getVars();
            $item = $vars['item'] ?? null;
            if (!$item instanceof Message) {
                return;
            }

            $whatsappChannel   = null;
            foreach ($item->getChannels() as $channel) {
                if ('whatsapp' === $channel->getChannel()) {
                    $whatsappChannel = $channel;
                    break;
                }
            }
            if (!$whatsappChannel) {
                return;
            }

            $whatsappMessageId = (int) $whatsappChannel->getChannelId();
            if (!$whatsappMessageId) {
                return;
            }

            if (!$this->messageLogRepository) {
                return;
            }

            $stats       = $this->messageLogRepository->getStatsByMessageId($whatsappMessageId);
            $dashboardUrl = $this->router->generate('mautic_dialoghsm_dashboard');

            $event->addContent($this->twig->render(
                '@DialogHSM/Channel/message_stats.html.twig',
                [
                    'stats'        => $stats,
                    'dashboardUrl' => $dashboardUrl,
                ]
            ));
        }
    }
}
