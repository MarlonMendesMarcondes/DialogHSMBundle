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
        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'transports' => [
                    'whatsapp' => [
                        'dsn' => '%env(MAUTIC_MESSENGER_DSN_WHATSAPP)%',
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
