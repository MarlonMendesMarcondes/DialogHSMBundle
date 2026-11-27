<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\UserBundle\Form\Type\UserListType;
use MauticPlugin\DialogHSMBundle\Pacing\PacedSettings;
use MauticPlugin\DialogHSMBundle\Service\Changelog;
use MauticPlugin\DialogHSMBundle\Service\PartnerConfigProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfigAuthType extends AbstractType
{
    /**
     * Grupos da tela de configuração (FormTheme/config_auth.html.twig). O primeiro fica na aba do
     * core, junto com "Ativo"; cada um dos outros vira uma aba própria (Resources/js/config-tabs.js).
     * Campo fora de todos os grupos aparece no fim do primeiro. "wide" ocupa a linha inteira.
     */
    public const GROUPS = [
        'connection'  => ['fields' => ['base_url', 'partner_id', 'partner_api_key'], 'wide' => ['base_url']],
        'queues'      => ['fields' => ['consumer_limit', 'batch_consumer_limit', 'bulk_rate_per_minute', 'batch_rate_per_minute'], 'wide' => []],
        'paced'       => ['fields' => ['paced_enabled', 'paced_rate_per_minute', 'business_hours_start', 'business_hours_end', 'business_hours_days'], 'wide' => ['business_hours_days']],
        'protections' => ['fields' => ['preflight_mode', 'payment_pause_enabled', 'payment_pause_minutes'], 'wide' => ['preflight_mode']],
        'balance'     => ['fields' => ['balance_alert_threshold', 'balance_alert_send_email', 'balance_alert_recipients'], 'wide' => ['balance_alert_recipients']],
        'logs'        => ['fields' => ['log_max_records', 'log_max_days'], 'wide' => []],
    ];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $data = [];
        $configProvider = $options['integration'];
        if ($configProvider->getIntegrationConfiguration() && $configProvider->getIntegrationConfiguration()->getApiKeys()) {
            $data = $configProvider->getIntegrationConfiguration()->getApiKeys();
        }

        $builder->add(
            'base_url',
            TextType::class,
            [
                'label'      => 'dialoghsm.config.base_url',
                'label_attr' => ['class' => 'control-label'],
                'required'   => true,
                'attr'       => [
                    'class'       => 'form-control',
                    'placeholder' => 'https://waba-v2.360dialog.io/messages',
                ],
                'data' => $data['base_url'] ?? 'https://waba-v2.360dialog.io/messages',
            ]
        );

        $builder->add(
            'consumer_limit',
            IntegerType::class,
            [
                'label'      => 'dialoghsm.config.consumer_limit',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'   => 'form-control',
                    'min'     => 1,
                    'max'     => 10000,
                    'tooltip' => 'dialoghsm.config.consumer_limit.tooltip',
                ],
                'data' => (int) ($data['consumer_limit'] ?? 50),
                'help' => 'dialoghsm.config.consumer_limit.help',
            ]
        );

        $builder->add(
            'batch_consumer_limit',
            IntegerType::class,
            [
                'label'      => 'dialoghsm.config.batch_consumer_limit',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'   => 'form-control',
                    'min'     => 1,
                    'max'     => 10000,
                    'tooltip' => 'dialoghsm.config.batch_consumer_limit.tooltip',
                ],
                'data' => (int) ($data['batch_consumer_limit'] ?? 100),
                'help' => 'dialoghsm.config.batch_consumer_limit.help',
            ]
        );

        $builder->add(
            'bulk_rate_per_minute',
            IntegerType::class,
            [
                'label'      => 'dialoghsm.config.bulk_rate_per_minute',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'   => 'form-control',
                    'min'     => 0,
                    'max'     => 100000,
                    'tooltip' => 'dialoghsm.config.bulk_rate_per_minute.tooltip',
                ],
                'data' => (int) ($data['bulk_rate_per_minute'] ?? 0),
                'help' => 'dialoghsm.config.bulk_rate_per_minute.help',
            ]
        );

        $builder->add(
            'batch_rate_per_minute',
            IntegerType::class,
            [
                'label'      => 'dialoghsm.config.batch_rate_per_minute',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'   => 'form-control',
                    'min'     => 0,
                    'max'     => 100000,
                    'tooltip' => 'dialoghsm.config.batch_rate_per_minute.tooltip',
                ],
                'data' => (int) ($data['batch_rate_per_minute'] ?? 0),
                'help' => 'dialoghsm.config.batch_rate_per_minute.help',
            ]
        );

        // Lotes programados ("Batch — lotes programados" na ação de campanha)
        $builder->add(
            'paced_enabled',
            YesNoButtonGroupType::class,
            [
                'label' => 'dialoghsm.config.paced_enabled',
                'data'  => (bool) ($data['paced_enabled'] ?? false),
                'help'  => 'dialoghsm.config.paced_enabled.help',
            ]
        );

        $builder->add(
            'paced_rate_per_minute',
            IntegerType::class,
            [
                'label'      => 'dialoghsm.config.paced_rate_per_minute',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class' => 'form-control',
                    'min'   => 0,
                    'max'   => 100000,
                ],
                'data' => (int) ($data['paced_rate_per_minute'] ?? PacedSettings::DEFAULT_RATE_PER_MINUTE),
                'help' => 'dialoghsm.config.paced_rate_per_minute.help',
            ]
        );

        foreach (['business_hours_start' => PacedSettings::DEFAULT_HOURS_START, 'business_hours_end' => PacedSettings::DEFAULT_HOURS_END] as $field => $default) {
            $builder->add(
                $field,
                TextType::class,
                [
                    'label'       => 'dialoghsm.config.'.$field,
                    'label_attr'  => ['class' => 'control-label'],
                    'required'    => false,
                    // Validação no navegador: valor inválido que chegue ao servidor cai no padrão
                    // (BusinessHoursCalendar::parseTime)
                    'attr'        => [
                        'class'       => 'form-control',
                        'placeholder' => $default,
                        'maxlength'   => 5,
                        'pattern'     => '([01]?[0-9]|2[0-3]):[0-5][0-9]|24:00',
                    ],
                    'data'        => $data[$field] ?? $default,
                    'help'        => 'dialoghsm.config.business_hours.help',
                ]
            );
        }

        $builder->add(
            'business_hours_days',
            ChoiceType::class,
            [
                'label'      => 'dialoghsm.config.business_hours_days',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'multiple'   => true,
                'expanded'   => true,
                'choices'    => [
                    'mautic.core.date.monday'    => 1,
                    'mautic.core.date.tuesday'   => 2,
                    'mautic.core.date.wednesday' => 3,
                    'mautic.core.date.thursday'  => 4,
                    'mautic.core.date.friday'    => 5,
                    'mautic.core.date.saturday'  => 6,
                    'mautic.core.date.sunday'    => 7,
                ],
                'data' => array_map('intval', is_array($data['business_hours_days'] ?? null) ? $data['business_hours_days'] : PacedSettings::DEFAULT_DAYS),
            ]
        );

        $builder->add(
            'log_max_records',
            IntegerType::class,
            [
                'label'      => 'dialoghsm.config.log_max_records',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'   => 'form-control',
                    'min'     => 0,       // 0 = desabilitado (sem limite por contagem)
                    'max'     => 10000000,
                    'tooltip' => 'dialoghsm.config.log_max_records.tooltip',
                ],
                'data' => (int) ($data['log_max_records'] ?? 0),
                'help' => 'dialoghsm.config.log_max_records.help',
            ]
        );

        $builder->add(
            'log_max_days',
            IntegerType::class,
            [
                'label'      => 'dialoghsm.config.log_max_days',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'   => 'form-control',
                    'min'     => 0,
                    'max'     => 3650,
                    'tooltip' => 'dialoghsm.config.log_max_days.tooltip',
                ],
                'data' => (int) ($data['log_max_days'] ?? 30),
                'help' => 'dialoghsm.config.log_max_days.help',
            ]
        );

        $builder->add(
            'balance_alert_threshold',
            NumberType::class,
            [
                'label'      => 'dialoghsm.config.balance_alert_threshold',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'scale'      => 2,
                'attr'       => [
                    'class'   => 'form-control',
                    'min'     => 0,
                    'tooltip' => 'dialoghsm.config.balance_alert_threshold.tooltip',
                ],
                'data' => (float) ($data['balance_alert_threshold'] ?? 10),
                'help' => 'dialoghsm.config.balance_alert_threshold.help',
            ]
        );

        $builder->add(
            'balance_alert_recipients',
            UserListType::class,
            [
                'label'      => 'dialoghsm.config.balance_alert_recipients',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'dialoghsm.config.balance_alert_recipients.tooltip',
                ],
                // UserListType é multiple: qualquer valor não-array derruba o form
                // inteiro com TransformationFailedException. O EncryptionService do
                // IntegrationsBundle devolve false quando não consegue decriptar uma
                // chave (ex: secret_key diferente), então normaliza aqui.
                'data' => is_array($data['balance_alert_recipients'] ?? null) ? $data['balance_alert_recipients'] : [],
                'help' => 'dialoghsm.config.balance_alert_recipients.help',
            ]
        );

        $builder->add(
            'balance_alert_send_email',
            YesNoButtonGroupType::class,
            [
                'label' => 'dialoghsm.config.balance_alert_send_email',
                'data'  => (bool) ($data['balance_alert_send_email'] ?? false),
                'help'  => 'dialoghsm.config.balance_alert_send_email.help',
            ]
        );

        // Feature flag da pré-validação: o padrão "log" só registra o que seria barrado, para
        // conferir falsos positivos em produção antes de passar para "enforce".
        $builder->add(
            'preflight_mode',
            ChoiceType::class,
            [
                'label'      => 'dialoghsm.config.preflight_mode',
                'label_attr' => ['class' => 'control-label'],
                'required'   => true,
                'choices'    => [
                    'dialoghsm.config.preflight_mode.off'     => PartnerConfigProvider::PREFLIGHT_OFF,
                    'dialoghsm.config.preflight_mode.log'     => PartnerConfigProvider::PREFLIGHT_LOG,
                    'dialoghsm.config.preflight_mode.enforce' => PartnerConfigProvider::PREFLIGHT_ENFORCE,
                ],
                'attr' => [
                    'class'   => 'form-control',
                    'tooltip' => 'dialoghsm.config.preflight_mode.tooltip',
                ],
                'data' => in_array($data['preflight_mode'] ?? null, [PartnerConfigProvider::PREFLIGHT_OFF, PartnerConfigProvider::PREFLIGHT_LOG, PartnerConfigProvider::PREFLIGHT_ENFORCE], true)
                    ? $data['preflight_mode']
                    : PartnerConfigProvider::PREFLIGHT_LOG,
                'help' => 'dialoghsm.config.preflight_mode.help',
            ]
        );

        $builder->add(
            'payment_pause_enabled',
            YesNoButtonGroupType::class,
            [
                'label' => 'dialoghsm.config.payment_pause_enabled',
                'data'  => (bool) ($data['payment_pause_enabled'] ?? true),
                'help'  => 'dialoghsm.config.payment_pause_enabled.help',
            ]
        );

        $builder->add(
            'payment_pause_minutes',
            IntegerType::class,
            [
                'label'      => 'dialoghsm.config.payment_pause_minutes',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class' => 'form-control',
                    'min'   => 5,
                    'max'   => 1440,
                ],
                'data' => (int) ($data['payment_pause_minutes'] ?? PartnerConfigProvider::PAYMENT_PAUSE_DEFAULT_MINUTES),
                'help' => 'dialoghsm.config.payment_pause_minutes.help',
            ]
        );

        $builder->add(
            'partner_id',
            TextType::class,
            [
                'label'      => 'dialoghsm.config.partner_id',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'dialoghsm.config.partner_id.tooltip',
                ],
                'data' => $data['partner_id'] ?? '',
                'help' => 'dialoghsm.config.partner_id.help',
            ]
        );

        $partnerApiKeyConfigured = !empty($data['partner_api_key']);
        $partnerApiKeyStatusBadge = $partnerApiKeyConfigured
            ? '<span class="label label-success"><i class="ri-shield-keyhole-line"></i> '
                .$this->translator->trans('dialoghsm.config.partner_api_key.configured').'</span>'
                .'&nbsp;'.$this->translator->trans('dialoghsm.config.partner_api_key.keep_current')
            : '<span class="label label-warning"><i class="ri-key-line"></i> '
                .$this->translator->trans('dialoghsm.config.partner_api_key.not_configured').'</span>';

        $builder->add(
            'partner_api_key',
            PasswordType::class,
            [
                'label'        => 'dialoghsm.config.partner_api_key',
                'label_attr'   => ['class' => 'control-label'],
                'required'     => false,
                'attr'         => ['class' => 'form-control', 'maxlength' => 250],
                'always_empty' => false,
                'data'         => $data['partner_api_key'] ?? '',
                'help'         => $partnerApiKeyStatusBadge,
                'help_html'    => true,
            ]
        );

        // Se o campo partner_api_key for enviado vazio, mantém o valor já
        // configurado em vez de apagar — mesmo padrão usado pra apiKey em
        // WhatsAppNumberType. Sem isso, salvar a tela de Config por qualquer
        // motivo (ex: mudar um limite) apagava a chave se o campo de senha
        // chegasse vazio no submit.
        $existingPartnerApiKey = $data['partner_api_key'] ?? '';
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($existingPartnerApiKey): void {
            $submitted = $event->getData();

            if (is_array($submitted) && empty($submitted['partner_api_key']) && !empty($existingPartnerApiKey)) {
                $submitted['partner_api_key'] = $existingPartnerApiKey;
                $event->setData($submitted);
            }
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['dialoghsm_groups']    = self::GROUPS;
        $view->vars['dialoghsm_changelog'] = Changelog::days();
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'integration' => null,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'dialoghsm_config_auth';
    }
}
