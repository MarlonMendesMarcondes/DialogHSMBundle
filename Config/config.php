<?php

declare(strict_types=1);

return [
    'name'        => '360dialog WhatsApp',
    'description' => 'Envia mensagens WhatsApp HSM via API 360dialog',
    'version'     => '1.4.4',
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
            'mautic_dialoghsm_number_webhook_check' => [
                'path'       => '/dialoghsm/numbers/{objectId}/webhook/check',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController::webhookCheckAction',
                'methods'    => ['GET'],
            ],
            'mautic_dialoghsm_number_webhook_register' => [
                'path'       => '/dialoghsm/numbers/{objectId}/webhook/register',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController::webhookRegisterAction',
                'methods'    => ['POST'],
            ],
            'mautic_dialoghsm_log_simulate_status' => [
                'path'       => '/dialoghsm/logs/{logId}/simulate-status',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\MessageLogController::simulateStatusAction',
                'methods'    => ['POST'],
            ],
            'mautic_dialoghsm_log_purge_queued' => [
                'path'       => '/dialoghsm/logs/purge-queued',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\MessageLogController::purgeQueuedAction',
                'methods'    => ['GET', 'POST'],
            ],
            'mautic_dialoghsm_log_index' => [
                'path'       => '/dialoghsm/logs/{page}',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\MessageLogController::indexAction',
                'defaults'   => ['page' => 1],
            ],
            'mautic_dialoghsm_dashboard' => [
                'path'       => '/dialoghsm/dashboard',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\MessageLogController::dashboardAction',
            ],
            'mautic_dialoghsm_message_index' => [
                'path'       => '/dialoghsm/messages/{page}',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppMessageController::indexAction',
                'defaults'   => ['page' => 1],
            ],
            'mautic_dialoghsm_message_action' => [
                'path'       => '/dialoghsm/messages/{objectAction}/{objectId}',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppMessageController::executeAction',
                'defaults'   => ['objectId' => 0],
            ],
        ],
        'public' => [
            'mautic_dialoghsm_webhook' => [
                'path'       => '/dialoghsm/webhook/{phoneNumber}',
                'controller' => 'MauticPlugin\DialogHSMBundle\Controller\WebhookController::processAction',
                'methods'    => ['POST'],
            ],
        ],
        'api'    => [],
    ],
    'menu' => [
        'main' => [
            'items' => [
                // Dashboard → peer de Relatórios (mautic.report.reports = priority 20)
                'dialoghsm.menu.dashboard' => [
                    'route'     => 'mautic_dialoghsm_dashboard',
                    'iconClass' => 'ri-bar-chart-2-fill',
                    'priority'  => 15,
                    'checks'    => [
                        'integration' => [
                            'DialogHSM' => ['enabled' => true],
                        ],
                    ],
                ],
                // Messages → Canais (junto com Email para configurar disparos)
                'dialoghsm.menu.messages' => [
                    'route'    => 'mautic_dialoghsm_message_index',
                    'parent'   => 'mautic.core.channels',
                    'priority' => 0,
                    'checks'   => [
                        'integration' => [
                            'DialogHSM' => ['enabled' => true],
                        ],
                    ],
                ],
                // Grupo WhatsApp → Numbers + Logs
                'dialoghsm.menu.whatsapp' => [
                    'id'        => 'mautic_dialoghsm_whatsapp_root',
                    'iconClass' => 'ri-whatsapp-fill',
                    'priority'  => 35,
                ],
                'dialoghsm.menu.numbers' => [
                    'route'    => 'mautic_dialoghsm_number_index',
                    'parent'   => 'dialoghsm.menu.whatsapp',
                    'priority' => 0,
                    'checks'   => [
                        'integration' => [
                            'DialogHSM' => ['enabled' => true],
                        ],
                    ],
                ],
                'dialoghsm.menu.logs' => [
                    'route'    => 'mautic_dialoghsm_log_index',
                    'parent'   => 'dialoghsm.menu.whatsapp',
                    'priority' => -1,
                    'checks'   => [
                        'integration' => [
                            'DialogHSM' => ['enabled' => true],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'services' => [
        'models' => [],
        'events' => [],
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
