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
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Service\TemplatePayload;

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

        $numberTransformer = new IdToEntityModelTransformer($this->em, WhatsAppNumber::class, 'id', false);
        $childBuilder      = $builder->create('whatsAppNumber', WhatsAppNumberListType::class, [
            'label'      => 'dialoghsm.number.menu_item',
            'label_attr' => ['class' => 'control-label'],
            'required'   => true,
        ]);
        // EntityLookupChoiceLoader::onFormPostSetData expects integer IDs, not entities.
        // POST_SET_DATA fires with raw model data (entity/proxy), so we convert it to ID first.
        $childBuilder->addEventListener(
            FormEvents::POST_SET_DATA,
            static function (FormEvent $event): void {
                $data = $event->getData();
                if ($data instanceof WhatsAppNumber) {
                    $event->setData($data->getId());
                } elseif (null === $data) {
                    $event->setData(null);
                }
            },
            100
        );
        $childBuilder->addModelTransformer($numberTransformer);
        $builder->add($childBuilder);

        $builder->add('templateName', TextType::class, [
            'label'       => 'dialoghsm.whatsapp_message.template_name',
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => [
                'class'       => 'form-control',
                'placeholder' => 'dialoghsm.whatsapp_message.template_name.placeholder',
            ],
            // Obrigatório só no servidor (NotBlank): com a lista de templates o campo fica oculto
            // atrás do seletor, e o required do HTML travaria o envio sem mostrar o motivo.
            'required'    => false,
            'constraints' => [new NotBlank(['message' => 'dialoghsm.whatsapp_message.template_name.required'])],
        ]);

        // Formulário guiado (mesmo das ações de campanha novas): idioma e valores das variáveis
        // não são colunas da entidade — vão para a chave "hsm" do payloadData (sem migration).
        $builder->add('hsm_language', TextType::class, [
            'label'      => 'dialoghsm.campaign.template.language',
            'label_attr' => ['class' => 'control-label'],
            'mapped'     => false,
            'required'   => false,
            'attr'       => ['class' => 'form-control', 'placeholder' => 'pt_BR'],
        ]);

        $builder->add('hsm_params', HiddenType::class, [
            'mapped'   => false,
            'required' => false,
        ]);

        // Pares rótulo/valor que o formulário guiado não cobre (ex.: limited_time_offer).
        $builder->add('payloadData', SortableListType::class, [
            'required'        => false,
            'label'           => 'dialoghsm.campaign.template.advanced',
            'option_required' => false,
            'with_labels'     => true,
        ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, [$this, 'loadGuidedFields']);
        $builder->addEventListener(FormEvents::POST_SUBMIT, [$this, 'storeGuidedFields']);

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

    /**
     * Abre a mensagem no formulário guiado. Mensagem salva antes dele (sem a chave "hsm") tem os
     * pares convertidos em campos guiados; o que não vira campo continua nos pares avançados, e
     * ela segue enviando como antes até ser salva de novo.
     */
    public function loadGuidedFields(FormEvent $event): void
    {
        $entity  = $event->getData();
        $form    = $event->getForm();
        $payload = $entity instanceof WhatsAppMessage ? $entity->getPayloadData() : [];
        $stored  = $payload[TemplatePayload::STORED_KEY] ?? null;
        $list    = is_array($payload['list'] ?? null) ? $payload['list'] : [];

        if (is_array($stored)) {
            $language = (string) ($stored['language'] ?? '');
            $params   = is_array($stored['params'] ?? null) ? $stored['params'] : [];
            $advanced = $list;
        } elseif ([] !== $list) {
            ['language' => $language, 'params' => $params, 'advanced' => $advanced] = TemplatePayload::fromLegacyList($list);
        } else {
            $language = 'pt_BR';
            $params   = [];
            $advanced = [];
        }

        $form->get('hsm_language')->setData($language);
        $form->get('hsm_params')->setData(json_encode($params));
        $form->get('payloadData')->setData(['list' => $advanced]);
    }

    /**
     * Grava os campos guiados na chave "hsm" do payloadData, ao lado dos pares avançados. O envio
     * junta os dois (TemplatePayload::expandStored).
     */
    public function storeGuidedFields(FormEvent $event): void
    {
        $entity = $event->getData();
        if (!$entity instanceof WhatsAppMessage) {
            return;
        }

        $form     = $event->getForm();
        $payload  = $entity->getPayloadData();
        $advanced = is_array($payload['list'] ?? null) ? $payload['list'] : [];
        $params   = json_decode((string) $form->get('hsm_params')->getData(), true);

        $entity->setPayloadData([
            'list'                       => array_values($advanced),
            TemplatePayload::STORED_KEY => [
                'language' => trim((string) $form->get('hsm_language')->getData()) ?: 'pt_BR',
                'params'   => is_array($params) ? $params : [],
            ],
        ]);
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
