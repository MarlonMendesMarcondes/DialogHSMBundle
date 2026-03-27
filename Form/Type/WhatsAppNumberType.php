<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Mautic\CoreBundle\Form\EventListener\CleanFormSubscriber;
use Mautic\CoreBundle\Form\EventListener\FormExitSubscriber;
use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class WhatsAppNumberType extends AbstractType
{
    public function __construct(private UrlGeneratorInterface $router)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventSubscriber(new CleanFormSubscriber(['baseUrl' => 'url']));
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
            PasswordType::class,
            [
                'label'        => 'dialoghsm.number.apikey',
                'label_attr'   => ['class' => 'control-label'],
                'attr'         => ['class' => 'form-control', 'maxlength' => 250],
                'required'     => false,
                'always_empty' => false,
            ]
        );

        // Se o campo apiKey for enviado vazio, manter o valor existente (não sobrescrever)
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data   = $event->getData();
            $form   = $event->getForm();
            $entity = $form->getData();

            if (empty($data['apiKey']) && $entity instanceof WhatsAppNumber && !empty($entity->getApiKey())) {
                $data['apiKey'] = $entity->getApiKey();
                $event->setData($data);
            }
        });

        $builder->add(
            'baseUrl',
            TextType::class,
            [
                'label'      => 'dialoghsm.number.base_url',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'placeholder' => 'dialoghsm.number.base_url.placeholder',
                ],
                'required' => false,
            ]
        );

        $builder->add(
            'queueName',
            TextType::class,
            [
                'label'      => 'dialoghsm.number.queue_name',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'placeholder' => 'dialoghsm.number.queue_name.placeholder',
                    'tooltip'     => 'dialoghsm.number.queue_name.tooltip',
                ],
                'required' => false,
                'help'     => 'dialoghsm.number.queue_name.help',
            ]
        );

        $builder->add(
            'batchQueueName',
            TextType::class,
            [
                'label'      => 'dialoghsm.number.batch_queue_name',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'placeholder' => 'dialoghsm.number.batch_queue_name.placeholder',
                    'tooltip'     => 'dialoghsm.number.batch_queue_name.tooltip',
                ],
                'required' => false,
                'help'     => 'dialoghsm.number.batch_queue_name.help',
            ]
        );

        $entity = $builder->getData();
        $token  = $entity instanceof WhatsAppNumber ? $entity->getWebhookToken() : null;

        if (null !== $token) {
            $webhookUrl = $this->router->generate(
                'mautic_dialoghsm_webhook',
                ['token' => $token],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        } else {
            $webhookUrl = null;
        }

        $builder->add(
            'webhookUrl',
            TextType::class,
            [
                'label'      => 'dialoghsm.number.webhook_url',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'    => 'form-control',
                    'readonly' => true,
                ],
                'data'     => $webhookUrl ?? 'dialoghsm.number.webhook_url.pending',
                'mapped'   => false,
                'required' => false,
                'help'     => 'dialoghsm.number.webhook_url.help',
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
