<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

/**
 * Conversão entre o formulário guiado de template (template, idioma, um valor por variável) e
 * os pares rótulo/valor do Payload Data que DialogHSMApi::buildPayload() entende.
 *
 * Usada pelas ações de campanha novas (campos hsm_* nas propriedades do evento) e pela Mensagem
 * WhatsApp / Marketing Messages (chave "hsm" dentro do payloadData da entidade). O envio é um só:
 * tudo vira a mesma lista de pares antes de resolver os tokens do contato.
 *
 * Formato dos valores guiados (params):
 *   {"header": "https://...", "body": ["...", "..."], "buttons": {"1": {"type": "url", "value": "..."}}}
 */
final class TemplatePayload
{
    /** Chave do payloadData da Mensagem WhatsApp com os campos guiados. */
    public const STORED_KEY = 'hsm';

    /**
     * Rótulos que buildPayload() lê como controle, e não como variável do body. Mesma lista que a
     * Mensagem WhatsApp usa para montar "vars" automaticamente quando o item não existe.
     */
    private const CONTROL_LABELS = ['content', 'template', 'url_arquivo', 'buttons', 'buttons_vars', 'limited_time_offer', 'language', 'vars'];

    /**
     *   template / language → content / language
     *   body[i]             → rótulo "{{i+1}}", listado em vars na ordem do template
     *   header              → url_arquivo
     *   buttons[i]          → buttons / buttons_vars, com posição vazia nos índices sem
     *                         variável (o índice enviado é a posição do botão no template)
     *
     * vars sai sempre, mesmo vazio: sem ele, a Mensagem WhatsApp transformaria qualquer rótulo
     * avançado em variável do body.
     *
     * @param array<string, mixed>|string|null $params array ou o JSON guardado no formulário
     *
     * @return list<array{label: string, value: string}> vazio quando não há template
     */
    public static function toList(string $template, string $language, array|string|null $params): array
    {
        $template = trim($template);
        if ('' === $template) {
            return [];
        }

        if (!is_array($params)) {
            $params = json_decode((string) $params, true);
            $params = is_array($params) ? $params : [];
        }

        $language = trim($language);
        $list     = [
            ['label' => 'content', 'value' => $template],
            ['label' => 'language', 'value' => '' !== $language ? $language : 'pt_BR'],
        ];

        $labels = [];
        foreach (array_values(is_array($params['body'] ?? null) ? $params['body'] : []) as $i => $value) {
            $label    = '{{'.($i + 1).'}}';
            $labels[] = $label;
            $list[]   = ['label' => $label, 'value' => is_scalar($value) ? (string) $value : ''];
        }
        $list[] = ['label' => 'vars', 'value' => implode(',', $labels)];

        $header = is_scalar($params['header'] ?? null) ? trim((string) $params['header']) : '';
        if ('' !== $header) {
            $list[] = ['label' => 'url_arquivo', 'value' => $header];
        }

        $buttons = [];
        foreach (is_array($params['buttons'] ?? null) ? $params['buttons'] : [] as $index => $button) {
            $type = is_array($button) && is_scalar($button['type'] ?? null) ? strtolower(trim((string) $button['type'])) : '';
            if (ctype_digit((string) $index) && '' !== $type) {
                $buttons[(int) $index] = [$type, is_scalar($button['value'] ?? null) ? (string) $button['value'] : ''];
            }
        }
        if ([] !== $buttons) {
            $types  = [];
            $values = [];
            for ($i = 0, $max = max(array_keys($buttons)); $i <= $max; ++$i) {
                $types[]  = $buttons[$i][0] ?? '';
                $values[] = $buttons[$i][1] ?? '';
            }
            $list[] = ['label' => 'buttons', 'value' => implode(',', $types)];
            $list[] = ['label' => 'buttons_vars', 'value' => implode(',', $values)];
        }

        return $list;
    }

    /**
     * Campos guiados primeiro; um rótulo repetido nos pares avançados é ignorado.
     *
     * @param list<array{label: string, value: string}> $guided
     * @param array<mixed>                              $advanced pares rótulo/valor (formato do SortableList)
     *
     * @return list<mixed>
     */
    public static function merge(array $guided, array $advanced): array
    {
        $taken = array_column($guided, 'label');
        $list  = $guided;

        foreach ($advanced as $item) {
            if (is_array($item) && isset($item['label']) && is_scalar($item['label'])
                && in_array(trim((string) $item['label']), $taken, true)) {
                continue;
            }
            $list[] = $item;
        }

        return $list;
    }

    /**
     * payloadData da Mensagem WhatsApp no formato que o envio lê (['list' => pares]). Mensagem
     * gravada antes do formulário guiado (sem a chave "hsm") passa sem mudança.
     *
     * @param array<mixed> $payloadData
     *
     * @return array<mixed>
     */
    public static function expandStored(string $templateName, array $payloadData): array
    {
        $stored = $payloadData[self::STORED_KEY] ?? null;
        if (!is_array($stored)) {
            return $payloadData;
        }

        $advanced = $payloadData['list'] ?? [];

        return ['list' => self::merge(
            self::toList($templateName, (string) ($stored['language'] ?? ''), $stored['params'] ?? []),
            is_array($advanced) ? $advanced : []
        )];
    }

    /**
     * Pares de uma Mensagem WhatsApp antiga → campos guiados, para ela abrir no formulário novo
     * sem perder nada: o que vira campo guiado sai da lista; o resto fica como avançado.
     *
     * Sem "vars", a Mensagem WhatsApp usava como variáveis do body todos os rótulos que não são
     * de controle, na ordem — a conversão segue a mesma regra.
     *
     * @param array<mixed> $list pares rótulo/valor
     *
     * @return array{language: string, params: array{header: string, body: list<string>, buttons: array<int, array{type: string, value: string}>}, advanced: list<array{label: string, value: string}>}
     */
    public static function fromLegacyList(array $list): array
    {
        $pairs = [];
        foreach ($list as $item) {
            if (is_array($item) && isset($item['label'], $item['value']) && is_scalar($item['label']) && is_scalar($item['value'])
                && '' !== trim((string) $item['label'])) {
                $pairs[] = ['label' => trim((string) $item['label']), 'value' => (string) $item['value']];
            }
        }

        $valueOf  = static function (string $label) use ($pairs): ?string {
            foreach ($pairs as $pair) {
                if ($pair['label'] === $label) {
                    return $pair['value'];
                }
            }

            return null;
        };
        $consumed = ['content' => true, 'template' => true, 'language' => true, 'vars' => true, 'url_arquivo' => true, 'buttons' => true, 'buttons_vars' => true];

        $vars = $valueOf('vars');
        if (null !== $vars) {
            $bodyLabels = array_values(array_filter(array_map('trim', explode(',', $vars)), static fn (string $l): bool => '' !== $l));
        } else {
            $bodyLabels = array_values(array_filter(
                array_column($pairs, 'label'),
                static fn (string $l): bool => !in_array($l, self::CONTROL_LABELS, true)
            ));
        }

        $body = [];
        foreach ($bodyLabels as $label) {
            $body[]           = $valueOf($label) ?? '';
            $consumed[$label] = true;
        }

        $buttons = [];
        $types   = array_map('trim', explode(',', (string) ($valueOf('buttons') ?? '')));
        $values  = array_map('trim', explode(',', (string) ($valueOf('buttons_vars') ?? '')));
        foreach ($types as $index => $type) {
            if ('' !== $type) {
                $buttons[$index] = ['type' => strtolower($type), 'value' => $values[$index] ?? ''];
            }
        }

        return [
            'language' => trim((string) ($valueOf('language') ?? '')) ?: 'pt_BR',
            'params'   => ['header' => trim((string) ($valueOf('url_arquivo') ?? '')), 'body' => $body, 'buttons' => $buttons],
            'advanced' => array_values(array_filter($pairs, static fn (array $p): bool => !isset($consumed[$p['label']]))),
        ];
    }
}
