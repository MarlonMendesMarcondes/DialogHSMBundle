<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    // CustomObjectVariableSource depende de classes que só existem no fork do CustomObjectsBundle:
    // carregada pelo glob, quebraria o container numa instalação sem ele (registro condicional abaixo).
    $excludes   = array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, ['Message', 'Service/Variable/CustomObjectVariableSource.php']);
    $services->load('MauticPlugin\\DialogHSMBundle\\', '../')
        ->exclude('../{'.implode(',', $excludes).'}');

    $services->load('MauticPlugin\\DialogHSMBundle\\Entity\\', '../Entity/*Repository.php');

    $services->alias('mautic.dialoghsm.model.whatsappnumber', \MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel::class);
    $services->alias('mautic.dialoghsm.model.whatsappmessage', \MauticPlugin\DialogHSMBundle\Model\WhatsAppMessageModel::class);

    $services->set(\MauticPlugin\DialogHSMBundle\Security\Permissions\DialogHSMPermissions::class)
        ->autowire()
        ->tag('mautic.permissions');

    $services->set(\MauticPlugin\DialogHSMBundle\Service\BulkRateLimiter::class)
        ->autowire()
        ->arg('$redisDsn', '%env(default::MAUTIC_MESSENGER_DSN_WHATSAPP_DIRECT)%');

    $services->set(\MauticPlugin\DialogHSMBundle\Service\RedisContactCache::class)
        ->autowire()
        ->arg('$redisDsn', '%env(default::MAUTIC_MESSENGER_DSN_WHATSAPP_DIRECT)%');

    $services->set(\MauticPlugin\DialogHSMBundle\Service\WebhookProcessor::class)
        ->autowire()
        ->arg('$redisDsn', '%env(default::MAUTIC_MESSENGER_DSN_WHATSAPP_DIRECT)%');

    $services->set(\MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber::class)
        ->autowire()
        ->autoconfigure()
        ->arg('$directTransportDsn', '%env(MAUTIC_MESSENGER_DSN_WHATSAPP_DIRECT)%');

    // Substitui o serviço buggy do core (ver CampaignAuditServiceFix::addWarningForUnpublishedEmails).
    $services->set(\Mautic\CampaignBundle\Service\CampaignAuditService::class, \MauticPlugin\DialogHSMBundle\Service\CampaignAuditServiceFix::class)
        ->autowire();

    // Origem "Campo de Custom Object" das variáveis: só com o fork do CustomObjectsBundle instalado.
    if (class_exists(\MauticPlugin\CustomObjectsBundle\Segment\SegmentItemResolver::class)) {
        $services->set(\MauticPlugin\DialogHSMBundle\Service\Variable\CustomObjectVariableSource::class)
            ->autowire();
    }

    $services->set(\MauticPlugin\DialogHSMBundle\Service\Variable\TemplateVariableResolver::class)
        ->autowire()
        ->arg('$customObjects', service(\MauticPlugin\DialogHSMBundle\Service\Variable\CustomObjectVariableSource::class)->nullOnInvalid());

    $services->set(\MauticPlugin\DialogHSMBundle\EventListener\ApiKeyEncryptionSubscriber::class)
        ->autowire()
        ->tag('doctrine.event_listener', ['event' => 'postLoad'])
        ->tag('doctrine.event_listener', ['event' => 'prePersist'])
        ->tag('doctrine.event_listener', ['event' => 'preUpdate'])
        ->tag('doctrine.event_listener', ['event' => 'postPersist'])
        ->tag('doctrine.event_listener', ['event' => 'postUpdate']);
    
        // Ensure controllers with constructor dependencies are instantiated from the container
        $services->set(\MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController::class)
            ->public()
            ->autowire()
            ->tag('controller.service_arguments');

        $services->set(\MauticPlugin\DialogHSMBundle\Controller\MessageLogController::class)
            ->public()
            ->autowire()
            ->tag('controller.service_arguments');

        $services->set(\MauticPlugin\DialogHSMBundle\Controller\WebhookController::class)
            ->public()
            ->autowire()
            ->tag('controller.service_arguments');

};
