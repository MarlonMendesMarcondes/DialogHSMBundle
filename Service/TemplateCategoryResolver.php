<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Categoria (MARKETING, UTILITY, AUTHENTICATION) dos templates de um número 360dialog.
 *
 * Baixa a lista inteira de templates do número numa chamada e guarda o mapa nome → categoria em
 * cache: filtrar por nome na API da 360dialog não é documentado, e um template por envio seria
 * uma chamada extra a cada mensagem.
 *
 * Só consulta o host da 360dialog: a URL é fixa (sem SSRF via base_url) e o provider Meta direto
 * precisaria do ID da WABA, que o número não guarda. Nesses casos, e quando a consulta falha,
 * a categoria é desconhecida (null) — quem chama decide o que fazer sem ela.
 */
class TemplateCategoryResolver
{
    public const HOST_360DIALOG = 'waba-v2.360dialog.io';

    private const TEMPLATES_URL   = 'https://'.self::HOST_360DIALOG.'/message_templates';
    private const CACHE_TTL       = 3600;
    private const PAGE_LIMIT      = 1000;
    private const MAX_PAGES       = 20;
    private const CONNECT_TIMEOUT = 5;
    private const REQUEST_TIMEOUT = 15;

    /**
     * Números cuja consulta falhou neste processo: sem isto, um worker com a API fora do ar
     * tentaria de novo a cada mensagem.
     *
     * @var array<string, true>
     */
    private array $failedLookups = [];

    public function __construct(
        private Client $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return string|null categoria em maiúsculas, ou null quando não dá para saber
     */
    public function getCategory(string $apiKey, string $baseUrl, string $templateName): ?string
    {
        if ('' === $templateName || '' === $apiKey || self::HOST_360DIALOG !== parse_url($baseUrl, PHP_URL_HOST)) {
            return null;
        }

        $cacheKey = 'dialoghsm_template_categories_'.hash('sha256', $apiKey);

        if (isset($this->failedLookups[$cacheKey])) {
            return null;
        }

        try {
            $categories = $this->cache->get($cacheKey, function (ItemInterface $item) use ($apiKey): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->fetchCategories($apiKey);
            });
        } catch (\Throwable $e) {
            $this->failedLookups[$cacheKey] = true;

            $this->logger->warning('DialogHSM: falha ao consultar a categoria dos templates', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $categories[$templateName] ?? null;
    }

    /**
     * @return array<string, string|null> nome → categoria; null quando os idiomas do mesmo
     *                                    template têm categorias diferentes
     */
    private function fetchCategories(string $apiKey): array
    {
        $categories = [];
        $after      = null;

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $query = ['fields' => 'name,category', 'limit' => self::PAGE_LIMIT];
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

            $body      = json_decode((string) $response->getBody(), true);
            $templates = $body['data'] ?? $body['waba_templates'] ?? null;

            if (!is_array($templates)) {
                throw new \RuntimeException('Resposta da listagem de templates sem a lista');
            }

            foreach ($templates as $template) {
                $name     = $template['name'] ?? null;
                $category = $template['category'] ?? null;

                if (!is_string($name) || !is_string($category)) {
                    continue;
                }

                $category = strtoupper($category);

                $categories[$name] = array_key_exists($name, $categories) && $categories[$name] !== $category
                    ? null
                    : $category;
            }

            $after = $body['paging']['cursors']['after'] ?? null;

            if (!isset($body['paging']['next']) || !is_string($after) || '' === $after) {
                return $categories;
            }
        }

        return $categories;
    }
}
