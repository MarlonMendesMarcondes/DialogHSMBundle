<?php

declare(strict_types=1);

return [
    'name'        => '360dialog WhatsApp',
    'description' => 'Envia mensagens WhatsApp HSM via API 360dialog',
    'version'     => '1.3.0',
    'author'      => 'DialogHSM',
    'routes'      => [
        'main' => [
            'mautic_dialoghsm_number_index' => [
                'path'       => '/dialoghsm/numbers/{page}',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController::indexAction',
            ],
            'mautic_dialoghsm_number_action' => [
                'path'       => '/dialoghsm/numbers/{objectAction}/{objectId}',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController::executeAction',
            ],
            'mautic_dialoghsm_log_index' => [
                'path'       => '/dialoghsm/logs/{page}',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\MessageLogController::indexAction',
                'defaults'   => ['page' => 1],
            ],
        ],
        'public' => [
            'mautic_dialoghsm_webhook' => [
                'path'       => '/dialoghsm/webhook/{token}',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\WebhookController::handleAction',
                'methods'    => ['POST'],
            ],
        ],
        'api'    => [],
    ],
    'menu' => [
        'main' => [
            'items' => [
                'dialoghsm.menu.numbers' => [
                    'route'    => 'mautic_dialoghsm_number_index',
                    'parent'   => 'mautic.core.channels',
                    'priority' => -1,
                ],
                'dialoghsm.menu.logs' => [
                    'route'    => 'mautic_dialoghsm_log_index',
                    'parent'   => 'mautic.core.channels',
                    'priority' => -2,
                ],
            ],
        ],
    ],
    'services' => [
        'integrations' => [
            'mautic.integration.dialoghsm' => [
                'class' => \MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration::class,
                'tags'  => [
                    'mautic.integration',
                    'mautic.basic_integration',
                ],
            ],
            'dialoghsm.integration.configuration' => [
                'class' => \MauticPlugin\DialogHSMBundle\Integration\Support\ConfigSupport::class,
                'tags'  => [
                    'mautic.config_integration',
                ],
            ],
        ],
    ],
];
