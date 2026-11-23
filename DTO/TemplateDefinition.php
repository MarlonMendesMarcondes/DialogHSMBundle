<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\DTO;

/**
 * O que a pré-validação precisa saber de um template aprovado na Meta, extraído da listagem
 * /message_templates da 360dialog (um nome + idioma).
 *
 * Contagem null = desconhecida (variáveis nomeadas, tipo de botão novo, componente fora do
 * formato esperado). A pré-validação pula a checagem nesse caso: sem dado, o envio segue.
 *
 * Guardado no cache como array (toArray/fromArray), e não como objeto serializado: uma mudança
 * nesta classe não pode quebrar a leitura do cache já gravado por um worker antigo.
 */
final class TemplateDefinition
{
    public const HEADER_TEXT     = 'TEXT';
    public const HEADER_IMAGE    = 'IMAGE';
    public const HEADER_VIDEO    = 'VIDEO';
    public const HEADER_DOCUMENT = 'DOCUMENT';
    public const HEADER_LOCATION = 'LOCATION';

    public const STATUS_APPROVED = 'APPROVED';

    /**
     * @param list<array{type: string, vars: int|null}>                                                                      $buttons
     * @param list<array{headerFormat: string|null, bodyVars: int|null, buttons: list<array{type: string, vars: int|null}>}>|null $carouselCards null = não é carrossel
     */
    public function __construct(
        public readonly string $name,
        public readonly string $language,
        public readonly ?string $status,
        public readonly ?string $category,
        public readonly ?string $headerFormat,
        public readonly ?int $headerVars,
        public readonly ?int $bodyVars,
        public readonly array $buttons,
        public readonly ?array $carouselCards,
    ) {
    }

    /**
     * @param array<string, mixed> $template item de data[] da listagem de templates
     */
    public static function fromApi(array $template): ?self
    {
        $name     = $template['name'] ?? null;
        $language = $template['language'] ?? null;

        if (!is_string($name) || '' === $name || !is_string($language) || '' === $language) {
            return null;
        }

        // parameter_format=NAMED: variáveis {{nome}} em vez de {{1}}. O plugin só envia
        // parâmetros posicionais, mas a contagem não é confiável — fica desconhecida.
        $named = 'NAMED' === strtoupper((string) ($template['parameter_format'] ?? ''));

        $headerFormat  = null;
        $headerVars    = 0;
        $bodyVars      = 0;
        $buttons       = [];
        $carouselCards = null;

        foreach (is_array($template['components'] ?? null) ? $template['components'] : [] as $component) {
            if (!is_array($component)) {
                continue;
            }

            switch (strtoupper((string) ($component['type'] ?? ''))) {
                case 'HEADER':
                    $headerFormat = strtoupper((string) ($component['format'] ?? self::HEADER_TEXT));
                    $headerVars   = self::HEADER_TEXT === $headerFormat
                        ? self::countVars($component['text'] ?? '', $named)
                        : 0;
                    break;
                case 'BODY':
                    $bodyVars = self::countVars($component['text'] ?? '', $named);
                    break;
                case 'BUTTONS':
                    $buttons = self::parseButtons($component['buttons'] ?? [], $named);
                    break;
                case 'CAROUSEL':
                    $carouselCards = self::parseCards($component['cards'] ?? [], $named);
                    break;
            }
        }

        return new self(
            $name,
            $language,
            is_string($template['status'] ?? null) ? strtoupper($template['status']) : null,
            is_string($template['category'] ?? null) ? strtoupper($template['category']) : null,
            $headerFormat,
            $headerVars,
            $bodyVars,
            $buttons,
            $carouselCards,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'          => $this->name,
            'language'      => $this->language,
            'status'        => $this->status,
            'category'      => $this->category,
            'headerFormat'  => $this->headerFormat,
            'headerVars'    => $this->headerVars,
            'bodyVars'      => $this->bodyVars,
            'buttons'       => $this->buttons,
            'carouselCards' => $this->carouselCards,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['language'] ?? ''),
            $data['status'] ?? null,
            $data['category'] ?? null,
            $data['headerFormat'] ?? null,
            $data['headerVars'] ?? null,
            $data['bodyVars'] ?? null,
            is_array($data['buttons'] ?? null) ? $data['buttons'] : [],
            is_array($data['carouselCards'] ?? null) ? $data['carouselCards'] : null,
        );
    }

    /**
     * Quantidade de variáveis {{n}} distintas no texto; null quando há variável nomeada
     * ({{primeiro_nome}}), que o plugin não sabe preencher por nome.
     */
    public static function countVars(mixed $text, bool $named = false): ?int
    {
        if (!is_string($text) || '' === $text) {
            return 0;
        }

        if (!preg_match_all('/\{\{\s*([^{}\s]+)\s*\}\}/', $text, $matches)) {
            return 0;
        }

        $placeholders = array_unique($matches[1]);

        if ($named || [] !== array_filter($placeholders, static fn (string $p): bool => !ctype_digit($p))) {
            return null;
        }

        return count($placeholders);
    }

    /**
     * Variáveis de cada botão, na ordem do template (o índice do botão no envio é essa posição).
     * URL só tem variável se a URL tiver {{1}}; COPY_CODE sempre exige o código. Tipos sem
     * parâmetro conhecidos ficam com 0; tipos desconhecidos (OTP, FLOW, MPM...) com null.
     *
     * @return list<array{type: string, vars: int|null}>
     */
    private static function parseButtons(mixed $buttons, bool $named): array
    {
        $parsed = [];

        foreach (is_array($buttons) ? $buttons : [] as $button) {
            $type = strtoupper((string) (is_array($button) ? ($button['type'] ?? '') : ''));

            $vars = match ($type) {
                'URL'                         => self::countVars($button['url'] ?? '', $named),
                'COPY_CODE'                   => 1,
                'QUICK_REPLY', 'PHONE_NUMBER' => 0,
                default                       => null,
            };

            $parsed[] = ['type' => $type, 'vars' => $vars];
        }

        return $parsed;
    }

    /**
     * @return list<array{headerFormat: string|null, bodyVars: int|null, buttons: list<array{type: string, vars: int|null}>}>
     */
    private static function parseCards(mixed $cards, bool $named): array
    {
        $parsed = [];

        foreach (is_array($cards) ? $cards : [] as $card) {
            $headerFormat = null;
            $bodyVars     = 0;
            $buttons      = [];

            foreach (is_array($card['components'] ?? null) ? $card['components'] : [] as $component) {
                if (!is_array($component)) {
                    continue;
                }

                switch (strtoupper((string) ($component['type'] ?? ''))) {
                    case 'HEADER':
                        $headerFormat = strtoupper((string) ($component['format'] ?? ''));
                        break;
                    case 'BODY':
                        $bodyVars = self::countVars($component['text'] ?? '', $named);
                        break;
                    case 'BUTTONS':
                        $buttons = self::parseButtons($component['buttons'] ?? [], $named);
                        break;
                }
            }

            $parsed[] = ['headerFormat' => $headerFormat, 'bodyVars' => $bodyVars, 'buttons' => $buttons];
        }

        return $parsed;
    }
}
