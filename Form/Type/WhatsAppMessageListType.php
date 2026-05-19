<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\EntityLookupType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WhatsAppMessageListType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'modal_route'         => 'mautic_dialoghsm_message_action',
            'modal_header'        => 'dialoghsm.whatsapp_message.new',
            'model'               => 'dialoghsm.whatsappmessage',
            'model_lookup_method' => 'getLookupResults',
            'lookup_arguments'    => [
                'type'   => 'dialoghsm.whatsappmessage',
                'filter' => '$data',
                'limit'  => 0,
                'start'  => 0,
            ],
            'ajax_lookup_action' => 'dialoghsm:getLookupChoiceList',
            'multiple'           => false,
            'required'           => false,
        ]);
    }

    public function getParent(): string
    {
        return EntityLookupType::class;
    }
}
