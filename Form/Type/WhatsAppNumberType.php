<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Mautic\CoreBundle\Form\EventListener\CleanFormSubscriber;
use Mautic\CoreBundle\Form\EventListener\FormExitSubscriber;
use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WhatsAppNumberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventSubscriber(new CleanFormSubscriber([]));
        $builder->addEventSubscriber(new FormExitSubscriber('dialoghsm.whatsappnumber', $options));

        $builder->add(
            'name',
            TextType::class,
            [
                'label'      => 'dialoghsm.number.name',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
                'required'   => true,
            ]
        );

        $builder->add(
            'phoneNumber',
            TextType::class,
            [
                'label'      => 'dialoghsm.number.phone',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'placeholder' => '+5511999999999',
                ],
                'required' => true,
            ]
        );

        $builder->add(
            'apiKey',
            TextareaType::class,
            [
                'label'      => 'dialoghsm.number.apikey',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class' => 'form-control',
                    'rows'  => 3,
                ],
                'required' => true,
            ]
        );

        $builder->add('isPublished', YesNoButtonGroupType::class);

        $builder->add('buttons', FormButtonsType::class);

        if (!empty($options['action'])) {
            $builder->setAction($options['action']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WhatsAppNumber::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'dialoghsm_whatsappnumber';
    }
}
