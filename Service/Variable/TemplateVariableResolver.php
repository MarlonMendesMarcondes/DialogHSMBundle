<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service\Variable;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;

/**
 * Transforma uma variável do formulário guiado em texto para um contato, na hora do envio.
 *
 * Formato da variável (hsm_params.body[i]):
 *   "texto"                                         valor fixo (formato antigo; aceita token)
 *   {source: 'static', value: '...'}                valor fixo (aceita token {contactfield=...})
 *   {source: 'field', field: 'alias', dateOffsetDays: '0'}
 *   {source: 'custom_object', customObject: 'alias', customObjectField: 'alias', dateOffsetDays: '0'}
 *
 * dateOffsetDays é o "Cálculo": dias numa data, valor somado num número (mesmo nome e regra do
 * N8nDispatchBundle). Valor fixo volta como está — o token é resolvido depois, junto com os
 * outros pares do payload.
 */
class TemplateVariableResolver
{
    public const SOURCE_STATIC        = 'static';
    public const SOURCE_FIELD         = 'field';
    public const SOURCE_CUSTOM_OBJECT = 'custom_object';

    public function __construct(
        private LeadModel $leadModel,
        private ?CustomObjectVariableSource $customObjects = null,
    ) {
    }

    /**
     * Segmentos de uma campanha ou mensagem (getLists()), tolerante a dono ausente ou lista nula:
     * sem segmentos, a variável de Custom Object sai vazia em vez de quebrar o envio.
     *
     * @return iterable<mixed>
     */
    public static function segmentsOf(mixed $owner): iterable
    {
        $lists = is_object($owner) && method_exists($owner, 'getLists') ? $owner->getLists() : null;

        return is_iterable($lists) ? $lists : [];
    }

    public function hasCustomObjects(): bool
    {
        return null !== $this->customObjects;
    }

    public function customObjects(): ?CustomObjectVariableSource
    {
        return $this->customObjects;
    }

    /**
     * @param iterable<mixed> $segments segmentos de origem (campanha) ou da mensagem (broadcast)
     */
    public function resolve(mixed $entry, ?Lead $contact, iterable $segments = [], string $language = LocaleConventions::DEFAULT_LOCALE): string
    {
        if (!is_array($entry)) {
            return is_scalar($entry) ? (string) $entry : '';
        }

        $source = (string) ($entry['source'] ?? self::SOURCE_STATIC);

        if (self::SOURCE_STATIC === $source || null === $contact) {
            // Sem contato (checagem prévia da campanha) não há o que ler: só o valor fixo importa.
            return self::SOURCE_STATIC === $source ? (string) ($entry['value'] ?? '') : '';
        }

        return match ($source) {
            self::SOURCE_FIELD         => $this->resolveContactField($contact, (string) ($entry['field'] ?? ''), $language, self::calculationText($entry)),
            self::SOURCE_CUSTOM_OBJECT => null === $this->customObjects ? '' : $this->customObjects->resolve(
                $contact,
                $segments,
                (string) ($entry['customObject'] ?? ''),
                (string) ($entry['customObjectField'] ?? ''),
                $language,
                self::calculationText($entry)
            ),
            default => '',
        };
    }

    /**
     * Descrição da origem para a mensagem da pré-validação ("token {contactfield=firstname}"),
     * no mesmo formato do token digitado; null para valor fixo.
     */
    public static function describe(mixed $entry): ?string
    {
        if (!is_array($entry)) {
            return null;
        }

        return match ((string) ($entry['source'] ?? self::SOURCE_STATIC)) {
            self::SOURCE_FIELD         => '{contactfield='.($entry['field'] ?? '').'}',
            self::SOURCE_CUSTOM_OBJECT => '{customobject='.($entry['customObject'] ?? '').'.'.($entry['customObjectField'] ?? '').'}',
            default                    => null,
        };
    }

    /**
     * Texto da caixa de cálculo ('13', '-0.5'; vírgula vale como ponto). Qualquer outra coisa é
     * '0'. Texto, e não número, para nenhum float arredondar um decimal.
     *
     * @param array<string, mixed> $entry
     */
    public static function calculationText(array $entry): string
    {
        $amount = $entry['dateOffsetDays'] ?? 0;
        if (!is_int($amount) && !is_string($amount)) {
            return '0';
        }

        $amount = str_replace(',', '.', trim((string) $amount));

        return 1 === preg_match('/^[+-]?\d+(\.\d+)?$/', $amount) ? $amount : '0';
    }

    private function resolveContactField(Lead $contact, string $alias, string $language, string $calculation): string
    {
        if ('' === $alias) {
            return '';
        }

        // Contato carregado sem os campos personalizados hidratados: getFieldValue() leria vazio.
        if ([] === $contact->getFields()) {
            $contact->setFields($this->leadModel->getRepository()->getFieldValues($contact->getId()));
        }

        $value = $contact->getFieldValue($alias);
        if (null === $value || is_array($value)) {
            return is_array($value) ? implode(', ', $value) : '';
        }

        $field = $contact->getField($alias);
        $type  = is_array($field) ? (string) ($field['type'] ?? '') : '';

        return FieldCalculator::apply((string) $value, $type, $language, $calculation);
    }
}
