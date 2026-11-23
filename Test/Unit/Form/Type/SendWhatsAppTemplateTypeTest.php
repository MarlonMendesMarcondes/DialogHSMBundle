<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Form\Type\SendWhatsAppTemplateQueueType;
use MauticPlugin\DialogHSMBundle\Form\Type\SendWhatsAppTemplateType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Formulários das ações novas: os campos da versão legada mais os campos guiados de template.
 */
class SendWhatsAppTemplateTypeTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null}> campo → tipo
     */
    private function build(object $type): array
    {
        $fields  = [];
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, ?string $class = null) use (&$fields, $builder) {
            $fields[$name] = $class;

            return $builder;
        });
        $builder->method('remove')->willReturnCallback(function (string $name) use (&$fields, $builder) {
            unset($fields[$name]);

            return $builder;
        });
        $builder->method('addEventListener')->willReturn($builder);

        $type->buildForm($builder, []);

        return $fields;
    }

    public function testDirectFormHasTemplateFieldsAndKeepsThrottleFields(): void
    {
        $fields = $this->build(new SendWhatsAppTemplateType());

        foreach (['whatsapp_number', 'hsm_template', 'hsm_language', 'hsm_params', 'payload_data', 'send_delay', 'batch_limit'] as $name) {
            $this->assertArrayHasKey($name, $fields);
        }
        $this->assertSame(HiddenType::class, $fields['hsm_params']);
        $this->assertSame('dialoghsm_send_whatsapp_template', (new SendWhatsAppTemplateType())->getBlockPrefix());
    }

    public function testQueueFormHasTemplateFieldsAndQueueType(): void
    {
        $fields = $this->build(new SendWhatsAppTemplateQueueType());

        foreach (['whatsapp_number', 'hsm_template', 'hsm_language', 'hsm_params', 'payload_data', 'queue_override'] as $name) {
            $this->assertArrayHasKey($name, $fields);
        }
        $this->assertArrayNotHasKey('send_delay', $fields);
        $this->assertSame('dialoghsm_send_whatsapp_template_queue', (new SendWhatsAppTemplateQueueType())->getBlockPrefix());
    }
}
