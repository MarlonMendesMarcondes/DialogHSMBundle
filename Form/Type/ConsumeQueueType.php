<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class ConsumeQueueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'whatsapp_number',
            WhatsAppNumberListType::class,
            [
                'label'       => 'dialoghsm.campaign.whatsapp_number',
                'label_attr'  => ['class' => 'control-label'],
                'multiple'    => false,
                'required'    => true,
                'constraints' => [new NotBlank()],
            ]
        );

        $builder->add(
            'limit',
            IntegerType::class,
            [
                'label'      => 'dialoghsm.campaign.consume.limit',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'min'     => 0,
                    'max'     => 10000,
                    'tooltip' => 'dialoghsm.campaign.consume.limit.tooltip',
                ],
                'required'    => false,
                'empty_data'  => 0,
                'data'        => 0,
                'constraints' => [new Range(['min' => 0, 'max' => 10000])],
                'help'        => 'dialoghsm.campaign.consume.limit.help',
            ]
        );

        $builder->add(
            'time_limit',
            IntegerType::class,
            [
                'label'      => 'dialoghsm.campaign.consume.time_limit',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'min'     => 0,
                    'max'     => 3600,
                    'tooltip' => 'dialoghsm.campaign.consume.time_limit.tooltip',
                ],
                'required'    => false,
                'empty_data'  => 30,
                'data'        => 30,
                'constraints' => [new Range(['min' => 0, 'max' => 3600])],
                'help'        => 'dialoghsm.campaign.consume.time_limit.help',
            ]
        );
    }

    public function getBlockPrefix(): string
    {
        return 'dialoghsm_consume_queue';
    }
}
