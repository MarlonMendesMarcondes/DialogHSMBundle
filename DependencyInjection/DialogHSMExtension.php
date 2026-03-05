<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\DependencyInjection;

use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class DialogHSMExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        // Define default value for the env var so the container compiles even when
        // RabbitMQ is not configured. Without this, Symfony throws at compile time
        // if MAUTIC_MESSENGER_DSN_WHATSAPP is absent — breaking the entire app.
        $container->setParameter('env(MAUTIC_MESSENGER_DSN_WHATSAPP)', 'null://null');

        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'transports' => [
                    'whatsapp' => [
                        'dsn'             => '%env(MAUTIC_MESSENGER_DSN_WHATSAPP)%',
                        'options'         => [
                            'auto_setup' => false,
                            'exchange'   => ['name' => '', 'type' => 'direct'],
                        ],
                        'retry_strategy'  => [
                            'max_retries' => 0,
                        ],
                    ],
                ],
                'routing' => [
                    SendWhatsAppMessage::class => 'whatsapp',
                ],
            ],
        ]);
    }

    /**
     * @param mixed[] $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Config'));
        $loader->load('services.php');
    }
}
