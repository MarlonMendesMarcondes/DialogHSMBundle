<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ConfigAuthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $apiKey = null;
        $configProvider = $options['integration'];
        if ($configProvider->getIntegrationConfiguration() && $configProvider->getIntegrationConfiguration()->getApiKeys()) {
            $data   = $configProvider->getIntegrationConfiguration()->getApiKeys();
            $apiKey = $data['api_key'] ?? null;
        }

        $builder->add(
            'api_key',
            PasswordType::class,
            [
                'label'      => 'dialoghsm.config.api_key',
                'label_attr' => ['class' => 'control-label'],
                'required'   => true,
                'attr'       => [
                    'class' => 'form-control',
                ],
                'data'       => $apiKey,
            ]
        );

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
                'data'       => $data['base_url'] ?? 'https://waba-v2.360dialog.io/messages',
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
