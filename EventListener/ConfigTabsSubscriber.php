<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injeta o script das abas da configuração do plugin (Resources/js/config-tabs.js) e as poucas regras de estilo
 * dela. O script só age quando o modal de configuração do DialogHSM está na tela (data-onload-callback do
 * FormTheme/config_auth.html.twig). Roda mesmo com a integração desligada: é nessa tela que ela é ligada.
 *
 * JS e CSS vão inline, sem mautic:assets:generate (que sobrescreveria o media/css/app.css do tema).
 */
class ConfigTabsSubscriber implements EventSubscriberInterface
{
    private const SCRIPT = __DIR__.'/../Resources/js/config-tabs.js';

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => 'injectViewCustomContent',
        ];
    }

    public function injectViewCustomContent(CustomContentEvent $event): void
    {
        if ('page.header.left' !== $event->getContext()) {
            return;
        }

        $script = file_get_contents(self::SCRIPT);
        if (false === $script) {
            return;
        }

        $event->addContent(
            // o modal de configuração do core tem 600px: alargado só quando é o do DialogHSM, para caber as abas
            '<style>.modal-dialog:has(.dialoghsm-config){width:900px;max-width:95vw}'
            .'.dialoghsm-config-group{margin-bottom:8px}.dialoghsm-config-group__title{margin:16px 0 4px}'
            .'.dialoghsm-config-group__help{margin-bottom:12px}'
            .'.dialoghsm-changelog{max-height:60vh;overflow-y:auto}.dialoghsm-changelog__day{margin-bottom:12px}'
            .'.dialoghsm-changelog__day ul{margin:4px 0 0;padding-left:20px}</style>'
            .'<script>'.$script.';dialoghsmConfigTabsApi.install(mQuery,Mautic);</script>'
        );
    }
}
