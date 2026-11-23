<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use Symfony\Component\Form\FormBuilderInterface;

/**
 * Formulário da ação "Enviar WhatsApp (360dialog)" nova: o da versão legada com os campos de
 * template guiados (ver TemplateFieldsTrait).
 */
class SendWhatsAppTemplateType extends SendWhatsAppType
{
    use TemplateFieldsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $this->addTemplateFields($builder);
    }

    public function getBlockPrefix(): string
    {
        return 'dialoghsm_send_whatsapp_template';
    }
}
