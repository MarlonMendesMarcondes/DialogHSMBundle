<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class WhatsAppMessageSendType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('whatsAppMessage', WhatsAppMessageListType::class, [
            'label'       => 'dialoghsm.campaign.send_whatsapp_message.select',
            'label_attr'  => ['class' => 'control-label'],
            'required'    => true,
            'constraints' => [new NotBlank(['message' => 'dialoghsm.campaign.send_whatsapp_message.required'])],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'update_select' => 'campaignevent_properties_whatsAppMessage',
        ]);
    }
}
