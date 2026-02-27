<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

class SendWhatsAppQueueType extends SendWhatsAppType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder->add(
            'queue_override',
            ChoiceType::class,
            [
                'label'       => 'dialoghsm.campaign.queue_override',
                'label_attr'  => ['class' => 'control-label'],
                'attr'        => [
                    'class'   => 'form-control',
                    'tooltip' => 'dialoghsm.campaign.queue_override.tooltip',
                ],
                'choices'     => [
                    'dialoghsm.campaign.queue_override.choice.queue' => 'queue',
                    'dialoghsm.campaign.queue_override.choice.batch' => 'batch',
                ],
                'required'    => false,
                'placeholder' => 'dialoghsm.campaign.queue_override.placeholder',
                'empty_data'  => '',
                'help'        => 'dialoghsm.campaign.queue_override.help',
            ]
        );
    }

    public function getBlockPrefix(): string
    {
        return 'dialoghsm_send_whatsapp_queue';
    }
}
