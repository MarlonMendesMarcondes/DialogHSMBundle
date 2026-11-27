<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Form\Type;

use MauticPlugin\DialogHSMBundle\Pacing\PacedSettings;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Formulário da ação "Enviar WhatsApp (360dialog) com RabbitMQ" nova: o da versão legada com os
 * campos de template guiados (ver TemplateFieldsTrait).
 *
 * Com os lotes programados ligados na configuração do plugin, o Tipo de Fila ganha a opção
 * "Batch — lotes programados" e os campos de tamanho do lote e intervalo.
 */
class SendWhatsAppTemplateQueueType extends SendWhatsAppQueueType
{
    use TemplateFieldsTrait;

    public function __construct(
        private ?PacedSettings $pacedSettings = null,
        private ?TranslatorInterface $translator = null,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $this->addTemplateFields($builder);

        if ($this->isPacedEnabled()) {
            $this->addPacedFields($builder);
        }
    }

    public function getBlockPrefix(): string
    {
        return 'dialoghsm_send_whatsapp_template_queue';
    }

    private function isPacedEnabled(): bool
    {
        try {
            return null !== $this->pacedSettings && $this->pacedSettings->isEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    private function addPacedFields(FormBuilderInterface $builder): void
    {
        $builder->add(
            'queue_override',
            ChoiceType::class,
            [
                'label'       => 'dialoghsm.campaign.queue_override',
                'label_attr'  => ['class' => 'control-label'],
                'attr'        => [
                    'class'   => 'form-control',
                    'tooltip' => 'dialoghsm.campaign.queue_override.tooltip',
                ],
                'choices'  => [
                    'dialoghsm.campaign.queue_override.choice.bulk'        => 'bulk',
                    'dialoghsm.campaign.queue_override.choice.batch'       => 'batch',
                    'dialoghsm.campaign.queue_override.choice.batch_paced' => PacedSettings::QUEUE_MODE,
                ],
                'required' => true,
                'help'     => 'dialoghsm.campaign.queue_override.help',
            ]
        );

        $showOn = json_encode(['campaignevent_properties_queue_override' => PacedSettings::QUEUE_MODE]);

        $builder->add(
            'paced_lot_size',
            IntegerType::class,
            [
                'label'      => 'dialoghsm.campaign.paced.lot_size',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'        => 'form-control',
                    'min'          => 1,
                    'data-show-on' => $showOn,
                ],
                'help'       => 'dialoghsm.campaign.paced.lot_size.help',
            ]
        );

        $builder->add(
            'paced_lot_interval',
            IntegerType::class,
            [
                'label'      => 'dialoghsm.campaign.paced.lot_interval',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'        => 'form-control',
                    'min'          => PacedSettings::MIN_INTERVAL_MINUTES,
                    'data-show-on' => $showOn,
                ],
                'help'       => 'dialoghsm.campaign.paced.lot_interval.help',
            ]
        );

        // Os dois campos só são obrigatórios com a opção de lotes programados escolhida
        $trans = fn (string $key): string => $this->translator?->trans($key) ?? $key;

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($trans): void {
            $form = $event->getForm();

            if (PacedSettings::QUEUE_MODE !== $form->get('queue_override')->getData()) {
                return;
            }

            if ((int) $form->get('paced_lot_size')->getData() < 1) {
                $form->get('paced_lot_size')->addError(new FormError($trans('dialoghsm.campaign.paced.lot_size.invalid')));
            }

            if ((int) $form->get('paced_lot_interval')->getData() < PacedSettings::MIN_INTERVAL_MINUTES) {
                $form->get('paced_lot_interval')->addError(new FormError($trans('dialoghsm.campaign.paced.lot_interval.invalid')));
            }
        });
    }
}
