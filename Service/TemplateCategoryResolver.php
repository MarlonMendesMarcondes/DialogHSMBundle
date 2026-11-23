<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use MauticPlugin\DialogHSMBundle\DTO\TemplateDefinition;
use MauticPlugin\DialogHSMBundle\DTO\TemplateLookup;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Templates de um número 360dialog: categoria (MARKETING, UTILITY, AUTHENTICATION) e a definição
 * usada pela pré-validação (status, idioma, variáveis por componente, formato do header).
 *
 * Baixa a lista inteira de templates do número numa chamada e guarda em cache: filtrar por nome
 * na API da 360dialog não é documentado, e um template por envio seria uma chamada extra a cada
 * mensagem. A mesma chamada serve a categoria e a definição — nenhuma chamada a mais por mensagem.
 *
 * Só consulta o host da 360dialog: a URL é fixa (sem SSRF via base_url) e o provider Meta direto
 * precisaria do ID da WABA, que o número não guarda. Nesses casos, e quando a consulta falha,
 * categoria e definição ficam desconhecidas (null) — quem chama decide o que fazer sem elas.
 */
class TemplateCategoryResolver
{
    public const HOST_360DIALOG = 'waba-v2.360dialog.io';

    private const TEMPLATES_URL   = 'https://'.self::HOST_360DIALOG.'/message_templates';
    private const CACHE_PREFIX    = 'dialoghsm_templates_v2_';
    private const CACHE_TTL       = 3600;
    private const PAGE_LIMIT      = 1000;
    private const MAX_PAGES       = 20;
    private const CONNECT_TIMEOUT = 5;
    private const REQUEST_TIMEOUT = 15;

    /**
     * Idade mínima da lista para aceitar recarregá-la antes do TTL. A pré-validação pede o
     * recarregamento quando reprovaria um envio (template criado ou editado depois do cache):
     * este piso limita isso a uma chamada por número a cada 5 minutos, por mais mensagens que
     * reprovem.
     */
    public const MIN_REFRESH_AGE = 300;

    // Campos padrão da Graph API; parameter_format não entra no filtro porque nem toda versão
    // o aceita, e um campo inválido derrubaria a listagem inteira (e a categoria junto).
    private const FIELDS = 'name,category,language,status,components';

    /**
     * Números cuja consulta falhou neste processo: sem isto, um worker com a API fora do ar
     * tentaria de novo a cada mensagem.
     *
     * @var array<string, true>
     */
    private array $failedLookups = [];

    /**
     * Última tentativa de recarregar a lista, por número, neste processo. Uma recarga que falha
     * não muda o fetched_at do cache — sem isto, cada envio reprovado tentaria de novo.
     *
     * @var array<string, int>
     */
    private array $lastRefreshAttempt = [];

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock injetável para testes; padrão time()
     */
    public function __construct(
        private Client $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return string|null categoria em maiúsculas, ou null quando não dá para saber
     */
    public function getCategory(string $apiKey, string $baseUrl, string $templateName): ?string
    {
        if ('' === $templateName) {
            return null;
        }

        return $this->loadTemplates($apiKey, $baseUrl)['categories'][$templateName] ?? null;
    }

    /**
     * Definição do template no idioma informado, a partir da lista em cache do número.
     *
     * @param bool $refresh recarrega a lista antes do TTL, se ela tiver mais de MIN_REFRESH_AGE
     *                      segundos (usado quando a lista em cache reprovaria o envio)
     *
     * @return TemplateLookup|null null quando a lista não está disponível (host que não é da
     *                             360dialog, falha na consulta): a definição é desconhecida
     */
    public function findTemplate(string $apiKey, string $baseUrl, string $templateName, string $language, bool $refresh = false): ?TemplateLookup
    {
        if ('' === $templateName) {
            return null;
        }

        $data = $this->loadTemplates($apiKey, $baseUrl, $refresh);
        if (null === $data) {
            return null;
        }

        $byLanguage = $data['templates'][$templateName] ?? [];
        $definition = isset($byLanguage[$language]) ? TemplateDefinition::fromArray($byLanguage[$language]) : null;

        if (null === $definition && (isset($data['unparsed'][$templateName]) || !$data['complete'])) {
            return null;
        }

        return new TemplateLookup($definition, array_map('strval', array_keys($byLanguage)), (int) $data['fetched_at']);
    }

    /**
     * Todos os templates do número, para o formulário da ação de campanha escolher o template.
     *
     * @param bool $refresh recarrega a lista antes do TTL (mesmo piso de MIN_REFRESH_AGE)
     *
     * @return list<TemplateDefinition>|null null quando a lista não está disponível
     */
    public function listTemplates(string $apiKey, string $baseUrl, bool $refresh = false): ?array
    {
        $data = $this->loadTemplates($apiKey, $baseUrl, $refresh);
        if (null === $data) {
            return null;
        }

        $definitions = [];
        foreach ($data['templates'] as $byLanguage) {
            foreach ($byLanguage as $definition) {
                $definitions[] = TemplateDefinition::fromArray($definition);
            }
        }

        usort($definitions, static fn (TemplateDefinition $a, TemplateDefinition $b): int => [$a->name, $a->language] <=> [$b->name, $b->language]);

        return $definitions;
    }

    /**
     * @return array{fetched_at: int, complete: bool, categories: array<string, string|null>, templates: array<string, array<string, array<string, mixed>>>, unparsed: array<string, true>}|null
     */
    private function loadTemplates(string $apiKey, string $baseUrl, bool $refresh = false): ?array
    {
        if ('' === $apiKey || self::HOST_360DIALOG !== parse_url($baseUrl, PHP_URL_HOST)) {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX.hash('sha256', $apiKey);

        if (isset($this->failedLookups[$cacheKey])) {
            return null;
        }

        try {
            $data = $this->cache->get($cacheKey, function (ItemInterface $item) use ($apiKey): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->fetchTemplates($apiKey);
            });
        } catch (\Throwable $e) {
            $this->failedLookups[$cacheKey] = true;

            $this->logger->warning('DialogHSM: falha ao consultar os templates do número', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($refresh && $this->canRefresh($cacheKey, (int) ($data['fetched_at'] ?? 0))) {
            $data = $this->refreshTemplates($apiKey, $cacheKey) ?? $data;
        }

        return $data;
    }

    private function canRefresh(string $cacheKey, int $fetchedAt): bool
    {
        $now = ($this->clock)();

        if ($now - $fetchedAt < self::MIN_REFRESH_AGE) {
            return false;
        }

        if ($now - ($this->lastRefreshAttempt[$cacheKey] ?? 0) < self::MIN_REFRESH_AGE) {
            return false;
        }

        $this->lastRefreshAttempt[$cacheKey] = $now;

        return true;
    }

    /**
     * Baixa a lista de novo e só então troca o cache: se a consulta falhar, a lista antiga
     * continua valendo até o TTL.
     *
     * @return array{fetched_at: int, complete: bool, categories: array<string, string|null>, templates: array<string, array<string, array<string, mixed>>>, unparsed: array<string, true>}|null
     */
    private function refreshTemplates(string $apiKey, string $cacheKey): ?array
    {
        try {
            $fresh = $this->fetchTemplates($apiKey);
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: falha ao recarregar os templates do número — mantida a lista em cache', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, static function (ItemInterface $item) use ($fresh): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $fresh;
        });

        return $fresh;
    }

    /**
     * categories: nome → categoria, null quando os idiomas do mesmo template têm categorias
     * diferentes; templates: nome → idioma → TemplateDefinition::toArray().
     *
     * @return array{fetched_at: int, complete: bool, categories: array<string, string|null>, templates: array<string, array<string, array<string, mixed>>>, unparsed: array<string, true>}
     */
    private function fetchTemplates(string $apiKey): array
    {
        $categories = [];
        $templates  = [];
        $unparsed   = [];
        $after      = null;
        $complete   = false;

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $query = ['fields' => self::FIELDS, 'limit' => self::PAGE_LIMIT];
            if (null !== $after) {
                $query['after'] = $after;
            }

            $response = $this->httpClient->request('GET', self::TEMPLATES_URL, [
                'headers'         => ['D360-API-KEY' => $apiKey],
                'query'           => $query,
                'http_errors'     => false,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout'         => self::REQUEST_TIMEOUT,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf('HTTP %d ao listar templates', $status));
            }

            $body = json_decode((string) $response->getBody(), true);
            $list = $body['data'] ?? $body['waba_templates'] ?? null;

            if (!is_array($list)) {
                throw new \RuntimeException('Resposta da listagem de templates sem a lista');
            }

            foreach ($list as $template) {
                if (!is_array($template)) {
                    continue;
                }

                $name     = $template['name'] ?? null;
                $category = $template['category'] ?? null;

                if (is_string($name) && is_string($category)) {
                    $category = strtoupper($category);

                    $categories[$name] = array_key_exists($name, $categories) && $categories[$name] !== $category
                        ? null
                        : $category;
                }

                // Item fora do formato esperado (sem idioma, por exemplo) não derruba a lista, mas
                // o nome fica marcado: "não achei a definição" desse template é falta de dado,
                // não prova de que ele não existe — e falta de dado nunca bloqueia envio.
                $definition = TemplateDefinition::fromApi($template);
                if (null !== $definition) {
                    $templates[$definition->name][$definition->language] = $definition->toArray();
                } elseif (is_string($name) && '' !== $name) {
                    $unparsed[$name] = true;
                }
            }

            $after = $body['paging']['cursors']['after'] ?? null;

            if (!isset($body['paging']['next']) || !is_string($after) || '' === $after) {
                $complete = true;
                break;
            }
        }

        // complete=false: parou em MAX_PAGES. Template ausente de uma lista truncada pode estar
        // nas páginas não lidas, então a ausência não é usada para reprovar envio.
        return [
            'fetched_at' => ($this->clock)(),
            'complete'   => $complete,
            'categories' => $categories,
            'templates'  => $templates,
            'unparsed'   => $unparsed,
        ];
    }
}
