<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\SortableListType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;

class SendWhatsAppType extends AbstractType
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
            'payload_data',
            SortableListType::class,
            [
                'required'        => false,
                'label'           => 'dialoghsm.campaign.payload_data',
                'option_required' => false,
                'with_labels'     => true,
            ]
        );

        // Convert legacy data format to new payload_data format
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }

            // Migrate old fields to payload_data if needed
            if (!isset($data['payload_data']) && (isset($data['template_name']) || isset($data['body_params']))) {
                $list = [];

                if (!empty($data['template_name'])) {
                    $list[] = ['label' => 'content', 'value' => $data['template_name']];
                    $list[] = ['label' => 'template', 'value' => $data['template_name']];
                }

                if (!empty($data['body_params'])) {
                    $bodyParams = $data['body_params'];
                    if (is_string($bodyParams)) {
                        foreach (explode("\n", $bodyParams) as $line) {
                            $line = trim($line);
                            if ('' !== $line) {
                                $list[] = ['label' => 'param', 'value' => $line];
                            }
                        }
                    }
                }

                $data['payload_data'] = ['list' => $list];
                $event->setData($data);
            }
        });
    }

    public function getBlockPrefix(): string
    {
        return 'dialoghsm_send_whatsapp';
    }
}
