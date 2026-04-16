<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ConfigAuthType extends AbstractType
{
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
