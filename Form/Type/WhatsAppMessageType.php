<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Form\DataTransformer\IdToEntityModelTransformer;
use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Mautic\CoreBundle\Form\Type\PublishDownDateType;
use Mautic\CoreBundle\Form\Type\PublishUpDateType;
use Mautic\CoreBundle\Form\Type\SortableListType;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Form\Type\LeadListType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;

class WhatsAppMessageType extends AbstractType
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label'       => 'mautic.core.name',
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => ['class' => 'form-control'],
            'required'    => true,
            'constraints' => [new NotBlank(['message' => 'mautic.core.name.required'])],
        ]);

        $builder->add('whatsAppNumber', WhatsAppNumberListType::class, [
            'label'      => 'dialoghsm.number.menu_item',
            'label_attr' => ['class' => 'control-label'],
            'required'   => true,
        ]);

        $builder->add('templateName', TextType::class, [
            'label'       => 'dialoghsm.whatsapp_message.template_name',
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => [
                'class'       => 'form-control',
                'placeholder' => 'dialoghsm.whatsapp_message.template_name.placeholder',
            ],
            'required'    => true,
            'constraints' => [new NotBlank(['message' => 'dialoghsm.whatsapp_message.template_name.required'])],
        ]);

        $builder->add('payloadData', SortableListType::class, [
            'required'        => false,
            'label'           => 'dialoghsm.campaign.payload_data',
            'option_required' => false,
            'with_labels'     => true,
        ]);

        $transformer = new IdToEntityModelTransformer($this->em, LeadList::class, 'id', true);
        $builder->add(
            $builder->create('lists', LeadListType::class, [
                'label'      => 'mautic.lead.list.form.lists',
                'label_attr' => ['class' => 'control-label'],
                'multiple'   => true,
                'expanded'   => false,
                'required'   => false,
            ])->addModelTransformer($transformer)
        );

        $builder->add('isPublished', YesNoButtonGroupType::class);

        $builder->add('publishUp', PublishUpDateType::class);

        $builder->add('publishDown', PublishDownDateType::class);

        $builder->add('buttons', FormButtonsType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WhatsAppMessage::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'dialoghsm_whatsapp_message';
    }
}
