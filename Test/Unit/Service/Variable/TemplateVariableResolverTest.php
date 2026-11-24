<?php

declare(strict_types=1);

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\DialogHSMBundle\Service\Variable\CustomObjectVariableSource;
use MauticPlugin\DialogHSMBundle\Service\Variable\TemplateVariableResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TemplateVariableResolverTest extends TestCase
{
    /**
     * @param array<string, array{value: mixed, type: string}> $fields
     */
    private function contact(array $fields): Lead&MockObject
    {
        $lead = $this->createMock(Lead::class);
        $lead->method('getId')->willReturn(7);
        $lead->method('getFields')->willReturn(['core' => array_map(static fn ($f) => $f, $fields)]);
        $lead->method('getFieldValue')->willReturnCallback(static fn (string $alias) => $fields[$alias]['value'] ?? null);
        $lead->method('getField')->willReturnCallback(static fn (string $alias) => isset($fields[$alias]) ? ['type' => $fields[$alias]['type']] : false);

        return $lead;
    }

    private function resolver(?CustomObjectVariableSource $customObjects = null): TemplateVariableResolver
    {
        return new TemplateVariableResolver($this->createMock(LeadModel::class), $customObjects);
    }

    public function testTextAndStaticValuesPassThrough(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('{contactfield=firstname}', $resolver->resolve('{contactfield=firstname}', null));
        $this->assertSame('Direito', $resolver->resolve(['source' => 'static', 'value' => 'Direito'], $this->contact([])));
    }

    public function testContactFieldIsFormattedAndCalculated(): void
    {
        $contact = $this->contact([
            'firstname'  => ['value' => 'Maria', 'type' => 'text'],
            'prova_data' => ['value' => '2026-12-25', 'type' => 'date'],
            'pontos'     => ['value' => '10', 'type' => 'number'],
        ]);
        $resolver = $this->resolver();

        $this->assertSame('Maria', $resolver->resolve(['source' => 'field', 'field' => 'firstname'], $contact));
        $this->assertSame('28/12/2026', $resolver->resolve(['source' => 'field', 'field' => 'prova_data', 'dateOffsetDays' => '3'], $contact));
        $this->assertSame('12/22/2026', $resolver->resolve(['source' => 'field', 'field' => 'prova_data', 'dateOffsetDays' => '-3'], $contact, [], 'en'));
        $this->assertSame('15', $resolver->resolve(['source' => 'field', 'field' => 'pontos', 'dateOffsetDays' => '5,0'], $contact));
    }

    public function testEmptyOrUnknownFieldIsEmpty(): void
    {
        $contact = $this->contact(['firstname' => ['value' => null, 'type' => 'text']]);

        $this->assertSame('', $this->resolver()->resolve(['source' => 'field', 'field' => 'firstname'], $contact));
        $this->assertSame('', $this->resolver()->resolve(['source' => 'field', 'field' => 'nao_existe'], $contact));
        $this->assertSame('', $this->resolver()->resolve(['source' => 'field', 'field' => ''], $contact));
    }

    public function testWithoutContactOnlyStaticValuesAreKnown(): void
    {
        $this->assertSame('', $this->resolver()->resolve(['source' => 'field', 'field' => 'firstname'], null));
    }

    public function testCustomObjectWithoutTheForkIsEmpty(): void
    {
        $resolver = $this->resolver();

        $this->assertFalse($resolver->hasCustomObjects());
        $this->assertSame('', $resolver->resolve(['source' => 'custom_object', 'customObject' => 'curso', 'customObjectField' => 'nome'], $this->contact([])));
    }

    public function testCustomObjectIsDelegatedWithSegmentsLanguageAndCalculation(): void
    {
        if (!class_exists(\MauticPlugin\CustomObjectsBundle\Segment\SegmentItemResolver::class)) {
            $this->markTestSkipped('Fork do CustomObjectsBundle não instalado.');
        }

        $contact  = $this->contact([]);
        $segments = ['segmento'];
        $source   = $this->createMock(CustomObjectVariableSource::class);
        $source->expects($this->once())->method('resolve')
            ->with($contact, $segments, 'curso', 'inicio', 'en', '-2')
            ->willReturn('12/23/2026');

        $value = $this->resolver($source)->resolve(
            ['source' => 'custom_object', 'customObject' => 'curso', 'customObjectField' => 'inicio', 'dateOffsetDays' => '-2'],
            $contact,
            $segments,
            'en'
        );

        $this->assertSame('12/23/2026', $value);
    }

    public function testDescriptionForTheValidationMessage(): void
    {
        $this->assertSame('{contactfield=firstname}', TemplateVariableResolver::describe(['source' => 'field', 'field' => 'firstname']));
        $this->assertSame('{customobject=curso.nome}', TemplateVariableResolver::describe(['source' => 'custom_object', 'customObject' => 'curso', 'customObjectField' => 'nome']));
        $this->assertNull(TemplateVariableResolver::describe(['source' => 'static', 'value' => 'x']));
        $this->assertNull(TemplateVariableResolver::describe('texto'));
    }

    public function testCalculationText(): void
    {
        $this->assertSame('-0.5', TemplateVariableResolver::calculationText(['dateOffsetDays' => '-0,5']));
        $this->assertSame('13', TemplateVariableResolver::calculationText(['dateOffsetDays' => 13]));
        $this->assertSame('0', TemplateVariableResolver::calculationText(['dateOffsetDays' => 'abc']));
        $this->assertSame('0', TemplateVariableResolver::calculationText([]));
    }

    public function testSegmentsOfToleratesMissingOwnerOrList(): void
    {
        $withLists = new class() {
            public function getLists(): array
            {
                return ['segmento'];
            }
        };
        $withoutLists = new class() {
            public function getLists(): mixed
            {
                return null;
            }
        };

        $this->assertSame(['segmento'], TemplateVariableResolver::segmentsOf($withLists));
        $this->assertSame([], TemplateVariableResolver::segmentsOf($withoutLists));
        $this->assertSame([], TemplateVariableResolver::segmentsOf(null));
    }
}
