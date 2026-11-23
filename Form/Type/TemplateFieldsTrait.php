<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\SortableListType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Campos de template das ações de campanha novas (_v2): o usuário escolhe o template do número
 * numa lista e preenche um campo por variável, em vez de montar os pares do Payload Data à mão.
 *
 * O formulário guarda os campos estruturados (hsm_template, hsm_language, hsm_params); o
 * CampaignSubscriber os converte no mesmo payload de antes na hora do envio — o caminho de
 * envio é um só para as versões legada e nova.
 *
 * Os nomes têm o prefixo hsm_ porque template_name já é um campo antigo que o PRE_SET_DATA do
 * SendWhatsAppType migra para payload_data.
 */
trait TemplateFieldsTrait
{
    private function addTemplateFields(FormBuilderInterface $builder): void
    {
        // Texto comum: o JS do tema troca por um seletor quando a lista de templates do número
        // está disponível. Sem a lista (provider Meta direto, 360dialog fora do ar), o usuário
        // digita o nome — o formulário continua funcionando.
        $builder->add(
            'hsm_template',
            TextType::class,
            [
                'label'       => 'dialoghsm.campaign.template.name',
                'label_attr'  => ['class' => 'control-label'],
                // Obrigatório só no servidor (NotBlank): no modo lista o campo fica oculto e o
                // required do HTML travaria o envio do formulário sem mostrar o motivo.
                'required'    => false,
                'attr'        => ['class' => 'form-control'],
                'constraints' => [new NotBlank(), new Length(['max' => 512])],
            ]
        );

        $builder->add(
            'hsm_language',
            TextType::class,
            [
                'label'       => 'dialoghsm.campaign.template.language',
                'label_attr'  => ['class' => 'control-label'],
                'required'    => false,
                'empty_data'  => 'pt_BR',
                'attr'        => ['class' => 'form-control', 'placeholder' => 'pt_BR'],
                'constraints' => [new Length(['max' => 20])],
            ]
        );

        // Valores das variáveis em JSON, mantido pelo JS: {"header": "...", "body": ["..."],
        // "buttons": {"1": "..."}}. Um campo por variável não cabe num form estático, porque a
        // quantidade depende do template escolhido.
        $builder->add('hsm_params', HiddenType::class, ['required' => false]);

        // O Payload Data continua disponível para o que o seletor não cobre (ex.:
        // limited_time_offer). Os campos acima têm prioridade sobre um rótulo repetido aqui.
        $builder->add(
            'payload_data',
            SortableListType::class,
            [
                'required'        => false,
                'label'           => 'dialoghsm.campaign.template.advanced',
                'option_required' => false,
                'with_labels'     => true,
            ]
        );
    }
}
