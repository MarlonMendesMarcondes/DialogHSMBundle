<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\DTO;

/**
 * Resultado de uma busca de template na lista em cache de um número.
 *
 * definition null com a lista disponível = o template não existe naquele idioma; languages diz
 * em quais idiomas o nome existe (vazio = o nome não existe no número). Lista indisponível não
 * chega aqui: o resolver devolve null e a pré-validação deixa o envio seguir.
 */
final class TemplateLookup
{
    /**
     * @param list<string> $languages idiomas em que existe um template com este nome
     */
    public function __construct(
        public readonly ?TemplateDefinition $definition,
        public readonly array $languages,
        public readonly int $fetchedAt,
    ) {
    }

    public function ageSeconds(?int $now = null): int
    {
        return max(0, ($now ?? time()) - $this->fetchedAt);
    }
}
