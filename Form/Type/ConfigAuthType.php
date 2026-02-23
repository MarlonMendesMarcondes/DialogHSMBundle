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
