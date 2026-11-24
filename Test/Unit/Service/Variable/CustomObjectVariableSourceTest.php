<?php

declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Segment\SegmentItemMatcher;
use MauticPlugin\CustomObjectsBundle\Segment\SegmentItemResolver;
use MauticPlugin\DialogHSMBundle\Service\Variable\CustomObjectVariableSource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Origem "Campo de Custom Object". Só existe com o fork do CustomObjectsBundle instalado: sem ele o
 * teste é pulado, como o serviço nem é registrado (Config/services.php).
 */
class CustomObjectVariableSourceTest extends TestCase
{
    private CustomObjectModel&MockObject $model;
    private SegmentItemResolver&MockObject $items;
    private SegmentItemMatcher&MockObject $matcher;
    private CustomObjectVariableSource $source;

    protected function setUp(): void
    {
        if (!class_exists(SegmentItemResolver::class)) {
            $this->markTestSkipped('Fork do CustomObjectsBundle não instalado.');
        }

        $this->model   = $this->createMock(CustomObjectModel::class);
        $this->items   = $this->createMock(SegmentItemResolver::class);
        $this->matcher = $this->createMock(SegmentItemMatcher::class);
        $this->source  = new CustomObjectVariableSource($this->model, $this->items, $this->matcher, new NullLogger());
    }

    private function object(string $fieldType = 'date'): CustomObject&MockObject
    {
        $field = $this->createMock(CustomField::class);
        $field->method('getAlias')->willReturn('inicio');
        $field->method('getLabel')->willReturn('Início');
        $field->method('getType')->willReturn($fieldType);

        $object = $this->createMock(CustomObject::class);
        $object->method('getAlias')->willReturn('curso');
        $object->method('getName')->willReturn('Curso');
        $object->method('getNamePlural')->willReturn('Cursos');
        $object->method('getCustomFields')->willReturn(new ArrayCollection([$field]));
        $object->method('getPublishedFields')->willReturn(new ArrayCollection([$field]));

        return $object;
    }

    public function testValuesOfTheSegmentItemsAreFormattedAndJoined(): void
    {
        $contact  = $this->createMock(Lead::class);
        $segments = ['segmento'];
        $object   = $this->object();

        $this->model->method('fetchEntityByAlias')->with('curso')->willReturn($object);
        $this->items->expects($this->once())->method('resolveItemIds')->with($contact, $segments, $object)->willReturn([1, 2, 3]);
        $this->matcher->method('fetchFieldValues')->willReturn(['2026-12-25', '', '2027-01-10']);

        $this->assertSame('27/12/2026, 12/01/2027', $this->source->resolve($contact, $segments, 'curso', 'inicio', 'pt_BR', '2'));
    }

    public function testSegmentsWithoutConditionOnTheObjectGiveEmpty(): void
    {
        $this->model->method('fetchEntityByAlias')->willReturn($this->object());
        $this->items->method('resolveItemIds')->willReturn(null);

        $this->assertSame('', $this->source->resolve($this->createMock(Lead::class), [], 'curso', 'inicio', 'pt_BR', '0'));
    }

    public function testUnknownObjectOrFieldGivesEmpty(): void
    {
        $this->model->method('fetchEntityByAlias')->willReturnCallback(function (string $alias) {
            if ('curso' !== $alias) {
                throw new NotFoundException('nao existe');
            }

            return $this->object();
        });

        $this->assertSame('', $this->source->resolve($this->createMock(Lead::class), [], 'nao_existe', 'inicio', 'pt_BR', '0'));
        $this->assertSame('', $this->source->resolve($this->createMock(Lead::class), [], 'curso', 'campo_errado', 'pt_BR', '0'));
    }

    public function testListsObjectsWithTheirFieldsForTheForm(): void
    {
        $this->model->method('fetchAllPublishedEntities')->willReturn([$this->object('int')]);

        $this->assertSame([[
            'alias'  => 'curso',
            'label'  => 'Cursos',
            'fields' => [['alias' => 'inicio', 'label' => 'Início', 'type' => 'int']],
        ]], $this->source->listObjects());
    }
}
