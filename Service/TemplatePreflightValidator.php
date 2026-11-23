<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use MauticPlugin\DialogHSMBundle\DTO\PreflightResult;
use MauticPlugin\DialogHSMBundle\DTO\TemplateDefinition;
use MauticPlugin\DialogHSMBundle\DTO\TemplateLookup;

/**
 * Pré-validação do envio de template, chamada depois de os tokens serem resolvidos e o payload
 * montado, logo antes da chamada à API. Barra os erros que a Meta devolveria de forma previsível:
 *
 *   #132001 template inexistente no idioma, ou não aprovado
 *   #132000 quantidade de parâmetros diferente da definição (header, body, botões, carrossel)
 *   #131008 parâmetro obrigatório ausente ou vazio depois de resolver os tokens
 *   #132012 formato do parâmetro diferente do template (tipo de mídia do header)
 *   #100    quebra de linha, tab ou mais de 4 espaços seguidos num parâmetro de texto
 *
 * Normalização de espaços (decisão): \n, \t e sequências de espaços viram um espaço só, e as
 * pontas são aparadas. O texto continua dizendo a mesma coisa — "Olá\nMaria" vira "Olá Maria"
 * — e a Meta recusaria o original com #100. O parâmetro que fica vazio depois disso é rejeitado:
 * normalizar não inventa conteúdo.
 *
 * Regra de ouro: sem dado, o envio segue. Lista de templates indisponível (falha na consulta,
 * provider Meta direto), contagem desconhecida (variável nomeada, tipo de botão novo) ou lista
 * truncada nunca reprovam. A única checagem que dispensa a definição é o parâmetro de texto vazio
 * no header/body, que a Meta recusa sempre.
 */
class TemplatePreflightValidator
{
    /**
     * Quanto tempo o modo lido da configuração vale no processo: o worker processa centenas de
     * mensagens por execução e ler a integração a cada uma pesaria no banco.
     */
    private const MODE_CACHE_SECONDS = 30;

    // sub_type do envio → tipo do botão na definição do template
    private const BUTTON_TYPES = [
        'url'          => 'URL',
        'quick_reply'  => 'QUICK_REPLY',
        'copy_code'    => 'COPY_CODE',
        'phone_number' => 'PHONE_NUMBER',
    ];

    private ?string $cachedMode = null;
    private int $modeExpiresAt  = 0;

    public function __construct(
        private TemplateCategoryResolver $resolver,
        private PartnerConfigProvider $configProvider,
    ) {
    }

    /**
     * @return string PartnerConfigProvider::PREFLIGHT_*
     */
    public function getMode(): string
    {
        if (null === $this->cachedMode || time() >= $this->modeExpiresAt) {
            $this->cachedMode    = $this->configProvider->getPreflightMode();
            $this->modeExpiresAt = time() + self::MODE_CACHE_SECONDS;
        }

        return $this->cachedMode;
    }

    /**
     * @param array<string, mixed> $template bloco "template" do payload (name, language, components)
     * @param array{body_labels?: list<string>, tokens?: array<string, string>} $hints rótulos das
     *                                       variáveis do body e o valor original (com token) de cada
     *                                       uma, só para a mensagem de erro ficar legível
     */
    public function validate(string $apiKey, string $baseUrl, array $template, array $hints = []): PreflightResult
    {
        $template   = $this->normalizeTextParameters($template);
        $components = is_array($template['components'] ?? null) ? $template['components'] : [];

        $reason = $this->checkEmptyTextParameters($components, $hints);
        if (null !== $reason) {
            return PreflightResult::invalid($reason, $template);
        }

        $name     = (string) ($template['name'] ?? '');
        $language = (string) ($template['language']['code'] ?? '');

        $lookup = $this->resolver->findTemplate($apiKey, $baseUrl, $name, $language);
        if (null === $lookup) {
            return PreflightResult::valid($template);
        }

        $reason = $this->checkAgainstDefinition($lookup, $name, $language, $components, $hints);

        // A lista em cache pode ser anterior à criação/edição do template: antes de reprovar,
        // confere com a lista atual (o resolver limita a uma recarga por número a cada 5 min).
        if (null !== $reason) {
            $fresh = $this->resolver->findTemplate($apiKey, $baseUrl, $name, $language, refresh: true);

            if (null === $fresh) {
                return PreflightResult::valid($template);
            }

            if ($fresh->fetchedAt !== $lookup->fetchedAt) {
                $reason = $this->checkAgainstDefinition($fresh, $name, $language, $components, $hints);
            }
        }

        return null === $reason
            ? PreflightResult::valid($template)
            : PreflightResult::invalid($reason, $template);
    }

    /**
     * \n, \t e espaços repetidos → um espaço; pontas aparadas. Vale para todo parâmetro de
     * texto: header, body, botões e cards do carrossel.
     */
    public static function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @param array<string, mixed> $template
     *
     * @return array<string, mixed>
     */
    private function normalizeTextParameters(array $template): array
    {
        if (!is_array($template['components'] ?? null)) {
            return $template;
        }

        foreach ($template['components'] as $i => $component) {
            if (!is_array($component)) {
                continue;
            }

            $template['components'][$i] = $this->normalizeComponent($component);

            foreach (is_array($component['cards'] ?? null) ? $component['cards'] : [] as $c => $card) {
                foreach (is_array($card['components'] ?? null) ? $card['components'] : [] as $k => $cardComponent) {
                    if (is_array($cardComponent)) {
                        $template['components'][$i]['cards'][$c]['components'][$k] = $this->normalizeComponent($cardComponent);
                    }
                }
            }
        }

        return $template;
    }

    /**
     * @param array<string, mixed> $component
     *
     * @return array<string, mixed>
     */
    private function normalizeComponent(array $component): array
    {
        foreach (is_array($component['parameters'] ?? null) ? $component['parameters'] : [] as $p => $parameter) {
            if (is_array($parameter) && 'text' === ($parameter['type'] ?? null) && is_string($parameter['text'] ?? null)) {
                $component['parameters'][$p]['text'] = self::normalizeText($parameter['text']);
            }
        }

        return $component;
    }

    /**
     * Header e body com parâmetro de texto vazio: a Meta recusa com #131008 em qualquer template.
     * Botão vazio só é checado com a definição em mãos (checkButtons): o plugin envia um parâmetro
     * por botão configurado, e nem todo botão tem variável.
     *
     * @param array<int, mixed>                                                  $components
     * @param array{body_labels?: list<string>, tokens?: array<string, string>} $hints
     */
    private function checkEmptyTextParameters(array $components, array $hints): ?string
    {
        foreach ($components as $component) {
            $type = strtolower((string) ($component['type'] ?? ''));
            if (!in_array($type, ['header', 'body'], true)) {
                continue;
            }

            foreach (array_values($this->parameters($component)) as $position => $parameter) {
                if ('text' === ($parameter['type'] ?? null) && '' === (string) ($parameter['text'] ?? '')) {
                    return sprintf(
                        'parâmetro %d do %s vazio%s',
                        $position + 1,
                        strtoupper($type),
                        'body' === $type ? $this->describeBodyVariable($position, $hints) : ''
                    );
                }
            }
        }

        return null;
    }

    /**
     * @param array{body_labels?: list<string>, tokens?: array<string, string>} $hints
     */
    private function describeBodyVariable(int $position, array $hints): string
    {
        $label = $hints['body_labels'][$position] ?? null;
        if (null === $label || '' === $label) {
            return '';
        }

        $token = $hints['tokens'][$label] ?? null;

        return null !== $token && '' !== $token
            ? sprintf(' (variável "%s", token %s)', $label, $token)
            : sprintf(' (variável "%s")', $label);
    }

    /**
     * @param array<int, mixed>                                                  $components
     * @param array{body_labels?: list<string>, tokens?: array<string, string>} $hints
     */
    private function checkAgainstDefinition(TemplateLookup $lookup, string $name, string $language, array $components, array $hints): ?string
    {
        $definition = $lookup->definition;

        if (null === $definition) {
            return [] === $lookup->languages
                ? sprintf('template "%s" não existe neste número', $name)
                : sprintf('template "%s" não existe no idioma %s (idiomas do template: %s)', $name, $language, implode(', ', $lookup->languages));
        }

        if (null !== $definition->status && TemplateDefinition::STATUS_APPROVED !== $definition->status) {
            return sprintf('template "%s" (%s) está com status %s na Meta, não APPROVED', $name, $language, $definition->status);
        }

        $byType = [];
        foreach ($components as $component) {
            if (is_array($component)) {
                $byType[strtolower((string) ($component['type'] ?? ''))][] = $component;
            }
        }

        return $this->checkHeader($definition, $byType['header'][0] ?? null)
            ?? $this->checkBody($definition, $byType['body'][0] ?? null)
            ?? $this->checkButtons($definition->buttons, $byType['button'] ?? [])
            ?? $this->checkCarousel($definition, $byType['carousel'][0] ?? null);
    }

    /**
     * @param array<string, mixed>|null $component
     */
    private function checkHeader(TemplateDefinition $definition, ?array $component): ?string
    {
        $parameters = null === $component ? [] : $this->parameters($component);
        $sentType   = $parameters[0]['type'] ?? null;
        $format     = $definition->headerFormat;

        if (null === $format) {
            return null === $sentType
                ? null
                : sprintf('o template não tem HEADER, mas a mensagem envia um (%s)', $sentType);
        }

        if (TemplateDefinition::HEADER_TEXT === $format) {
            if (null === $definition->headerVars) {
                return null;
            }

            if (count($parameters) !== $definition->headerVars) {
                return sprintf('HEADER de texto do template espera %d parâmetro(s), a mensagem envia %d', $definition->headerVars, count($parameters));
            }

            return null !== $sentType && 'text' !== $sentType
                ? sprintf('HEADER do template é TEXT, mas a mensagem envia %s', $sentType)
                : null;
        }

        $expectedType = strtolower($format);

        if (null === $sentType) {
            return sprintf('HEADER do template é %s, mas a mensagem não envia mídia (url_arquivo vazio ou sem extensão .jpg, .jpeg, .png, .mp4 ou .pdf)', $format);
        }

        return $sentType !== $expectedType
            ? sprintf('HEADER do template é %s, mas a mensagem envia %s', $format, $sentType)
            : null;
    }

    /**
     * @param array<string, mixed>|null $component
     */
    private function checkBody(TemplateDefinition $definition, ?array $component): ?string
    {
        if (null === $definition->bodyVars) {
            return null;
        }

        $sent = null === $component ? 0 : count($this->parameters($component));

        return $sent !== $definition->bodyVars
            ? sprintf('BODY do template espera %d parâmetro(s), a mensagem envia %d', $definition->bodyVars, $sent)
            : null;
    }

    /**
     * @param list<array{type: string, vars: int|null}> $defined
     * @param array<int, mixed>                         $sentButtons componentes "button" do envio
     * @param string                                    $where       ' do card N' para os botões de um card
     */
    private function checkButtons(array $defined, array $sentButtons, string $where = ''): ?string
    {
        $sentByIndex = [];

        foreach ($sentButtons as $button) {
            if (!is_array($button)) {
                continue;
            }

            $index = (int) ($button['index'] ?? -1);

            if (!isset($defined[$index])) {
                return sprintf('a mensagem envia o botão %d%s, mas o template tem %d botão(ões) ali', $index, $where, count($defined));
            }

            $subType      = strtolower((string) ($button['sub_type'] ?? ''));
            $expectedType = self::BUTTON_TYPES[$subType] ?? null;

            if (null !== $expectedType && $expectedType !== $defined[$index]['type'] && isset(array_flip(self::BUTTON_TYPES)[$defined[$index]['type']])) {
                return sprintf('o botão %d%s do template é %s, mas a mensagem envia %s', $index, $where, $defined[$index]['type'], $subType);
            }

            $sentByIndex[$index] = $button;
        }

        foreach ($defined as $index => $button) {
            if (null === $button['vars'] || 0 === $button['vars']) {
                continue;
            }

            if (!isset($sentByIndex[$index])) {
                return sprintf('o botão %d%s (%s) do template tem variável, mas a mensagem não envia valor para ele (buttons/buttons_vars)', $index, $where, $button['type']);
            }

            $parameter = $this->parameters($sentByIndex[$index])[0] ?? null;
            $value     = is_array($parameter) ? ($parameter['text'] ?? $parameter['coupon_code'] ?? $parameter['payload'] ?? '') : '';

            if ('' === trim((string) $value)) {
                return sprintf('parâmetro do botão %d%s (%s) vazio', $index, $where, $button['type']);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $component
     */
    private function checkCarousel(TemplateDefinition $definition, ?array $component): ?string
    {
        $defined = $definition->carouselCards;

        if (null === $defined) {
            return null === $component ? null : 'a mensagem envia carrossel, mas o template não é de carrossel';
        }

        $cards = null === $component || !is_array($component['cards'] ?? null) ? [] : array_values($component['cards']);

        if ([] === $cards) {
            return sprintf('o template é carrossel com %d card(s), mas a mensagem não envia cards', count($defined));
        }

        if (count($cards) !== count($defined)) {
            return sprintf('o carrossel do template tem %d card(s), a mensagem envia %d', count($defined), count($cards));
        }

        foreach ($defined as $position => $cardDefinition) {
            $byType = [];
            foreach (is_array($cards[$position]['components'] ?? null) ? $cards[$position]['components'] : [] as $cardComponent) {
                if (is_array($cardComponent)) {
                    $byType[strtolower((string) ($cardComponent['type'] ?? ''))][] = $cardComponent;
                }
            }

            $headerType = $this->parameters($byType['header'][0] ?? [])[0]['type'] ?? null;
            $expected   = null !== $cardDefinition['headerFormat'] ? strtolower($cardDefinition['headerFormat']) : null;

            if (null !== $expected && $headerType !== $expected) {
                return sprintf('o card %d do template tem HEADER %s, mas a mensagem envia %s', $position, $cardDefinition['headerFormat'], $headerType ?? 'nenhum');
            }

            $bodyVars = $cardDefinition['bodyVars'];
            $bodySent = count($this->parameters($byType['body'][0] ?? []));

            if (null !== $bodyVars && $bodySent !== $bodyVars) {
                return sprintf('o BODY do card %d do template espera %d parâmetro(s), a mensagem envia %d', $position, $bodyVars, $bodySent);
            }

            $reason = $this->checkButtons($cardDefinition['buttons'], $byType['button'] ?? [], sprintf(' do card %d', $position));
            if (null !== $reason) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $component
     *
     * @return list<array<string, mixed>>
     */
    private function parameters(array $component): array
    {
        $parameters = $component['parameters'] ?? [];

        return is_array($parameters) ? array_values(array_filter($parameters, 'is_array')) : [];
    }
}
