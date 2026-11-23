<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\DTO;

/**
 * Resultado da pré-validação de um envio.
 *
 * template é o bloco "template" do payload com os parâmetros de texto já normalizados — é o que
 * deve ir para a API quando a pré-validação está em modo enforce.
 */
final class PreflightResult
{
    /**
     * @param array<string, mixed> $template
     */
    private function __construct(
        public readonly bool $valid,
        public readonly ?string $reason,
        public readonly array $template,
    ) {
    }

    /**
     * @param array<string, mixed> $template
     */
    public static function valid(array $template): self
    {
        return new self(true, null, $template);
    }

    /**
     * @param array<string, mixed> $template
     */
    public static function invalid(string $reason, array $template): self
    {
        return new self(false, $reason, $template);
    }
}
