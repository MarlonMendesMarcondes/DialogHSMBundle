<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service\Variable;

use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Segment\SegmentItemMatcher;
use MauticPlugin\CustomObjectsBundle\Segment\SegmentItemResolver;
use Psr\Log\LoggerInterface;

/**
 * Origem "Campo de Custom Object" das variáveis do template.
 *
 * Depende do SegmentItemResolver/SegmentItemMatcher, que existem só no fork do CustomObjectsBundle
 * mantido junto com o N8nDispatchBundle. Por isso o serviço é registrado só quando essas classes
 * existem (Config/services.php) e fica fora do carregamento automático: numa instalação sem o fork
 * o container continua compilando e a origem simplesmente não aparece no formulário.
 *
 * Um contato pode ter vários itens do mesmo objeto: valem os que atendem às condições dos
 * segmentos de origem (campanha) ou da própria mensagem (broadcast). Sobrando mais de um, os
 * valores são juntados com ", ".
 */
class CustomObjectVariableSource
{
    private const SEPARATOR = ', ';

    public function __construct(
        private CustomObjectModel $customObjectModel,
        private SegmentItemResolver $itemResolver,
        private SegmentItemMatcher $itemMatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param iterable<mixed> $segments segmentos que dizem quais itens contam
     */
    public function resolve(Lead $contact, iterable $segments, string $objectAlias, string $fieldAlias, string $language, string $calculation): string
    {
        try {
            $customObject = $this->customObjectModel->fetchEntityByAlias($objectAlias);
        } catch (NotFoundException) {
            $this->logger->warning('DialogHSM: Custom Object da variável não encontrado', ['object' => $objectAlias]);

            return '';
        }

        $field = null;
        foreach ($customObject->getCustomFields() as $candidate) {
            if ($candidate instanceof CustomField && $candidate->getAlias() === $fieldAlias) {
                $field = $candidate;
            }
        }

        if (null === $field) {
            $this->logger->warning('DialogHSM: campo de Custom Object da variável não encontrado', ['object' => $objectAlias, 'field' => $fieldAlias]);

            return '';
        }

        $itemIds = $this->itemResolver->resolveItemIds($contact, $segments, $customObject);

        if (null === $itemIds) {
            // Os segmentos não têm condição sobre este objeto: não há como saber qual item vale.
            $this->logger->warning('DialogHSM: segmentos sem condição sobre o Custom Object da variável — valor vazio', [
                'object'  => $objectAlias,
                'lead_id' => $contact->getId(),
            ]);

            return '';
        }

        if ([] === $itemIds) {
            return '';
        }

        $type   = (string) $field->getType();
        $values = array_map(
            static fn ($value): string => DecimalFormatter::format(
                FieldCalculator::apply((string) $value, $type, $language, $calculation),
                $type,
                $language
            ),
            $this->itemMatcher->fetchFieldValues($itemIds, $field)
        );

        // Campo não preenchido vem como '' ou NULL: fica de fora, sem separador sobrando.
        return implode(self::SEPARATOR, array_filter($values, static fn (string $value): bool => '' !== $value));
    }

    /**
     * Objetos publicados e seus campos, para o seletor do formulário.
     *
     * @return list<array{alias: string, label: string, fields: list<array{alias: string, label: string, type: string}>}>
     */
    public function listObjects(): array
    {
        $objects = [];

        foreach ($this->customObjectModel->fetchAllPublishedEntities() as $object) {
            if (!$object instanceof CustomObject) {
                continue;
            }

            $fields = [];
            foreach ($object->getPublishedFields() as $field) {
                $fields[] = ['alias' => (string) $field->getAlias(), 'label' => (string) $field->getLabel(), 'type' => (string) $field->getType()];
            }

            $objects[] = [
                'alias'  => (string) $object->getAlias(),
                'label'  => (string) ($object->getNamePlural() ?: $object->getName()),
                'fields' => $fields,
            ];
        }

        return $objects;
    }
}
