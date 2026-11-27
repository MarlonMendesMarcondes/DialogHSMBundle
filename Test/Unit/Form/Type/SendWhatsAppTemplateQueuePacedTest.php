<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Form\Type\SendWhatsAppTemplateQueueType;
use MauticPlugin\DialogHSMBundle\Pacing\PacedSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Opção "Batch — lotes programados" no formulário da ação de fila nova.
 */
class SendWhatsAppTemplateQueuePacedTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>> campo → opções
     */
    private function build(?bool $enabled): array
    {
        $settings = null;

        if (null !== $enabled) {
            $settings = $this->createMock(PacedSettings::class);
            $settings->method('isEnabled')->willReturn($enabled);
        }

        $fields  = [];
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, ?string $class = null, array $options = []) use (&$fields, $builder) {
            $fields[$name] = $options;

            return $builder;
        });
        $builder->method('remove')->willReturnCallback(function (string $name) use (&$fields, $builder) {
            unset($fields[$name]);

            return $builder;
        });
        $builder->method('addEventListener')->willReturn($builder);

        (new SendWhatsAppTemplateQueueType($settings))->buildForm($builder, []);

        return $fields;
    }

    public function testEnabledAddsTheOptionAndTheLotFields(): void
    {
        $fields = $this->build(true);

        $this->assertSame(['bulk', 'batch', PacedSettings::QUEUE_MODE], array_values($fields['queue_override']['choices']));
        $this->assertArrayHasKey('paced_lot_size', $fields);
        $this->assertArrayHasKey('paced_lot_interval', $fields);

        $showOn = json_decode($fields['paced_lot_size']['attr']['data-show-on'], true);
        $this->assertSame(['campaignevent_properties_queue_override' => PacedSettings::QUEUE_MODE], $showOn);
        $this->assertSame(PacedSettings::MIN_INTERVAL_MINUTES, $fields['paced_lot_interval']['attr']['min']);
    }

    public function testDisabledKeepsTheFormAsBefore(): void
    {
        $fields = $this->build(false);

        $this->assertSame(['bulk', 'batch'], array_values($fields['queue_override']['choices']));
        $this->assertArrayNotHasKey('paced_lot_size', $fields);
        $this->assertArrayNotHasKey('paced_lot_interval', $fields);
    }

    public function testWithoutSettingsServiceKeepsTheFormAsBefore(): void
    {
        $fields = $this->build(null);

        $this->assertSame(['bulk', 'batch'], array_values($fields['queue_override']['choices']));
        $this->assertArrayNotHasKey('paced_lot_size', $fields);
    }
}
