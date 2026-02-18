<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\EntityLookupType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WhatsAppNumberListType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'modal_route'         => 'mautic_dialoghsm_number_action',
            'modal_header'        => 'dialoghsm.number.new',
            'model'               => 'dialoghsm.whatsappnumber',
            'model_lookup_method' => 'getLookupResults',
            // Use a plain array instead of a Closure to avoid Core expecting an array
            'lookup_arguments'    => [
                'type'   => WhatsAppNumberType::class,
                'filter' => '$data',
                'limit'  => 0,
                'start'  => 0,
            ],
            'ajax_lookup_action' => 'dialoghsm:getLookupChoiceList',
            'multiple'           => false,
            'required'           => true,
        ]);
    }

    public function getParent(): string
    {
        return EntityLookupType::class;
    }
}
