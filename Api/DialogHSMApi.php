<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

class DialogHSMApi
{
    /**
     * Sem timeout o Guzzle espera para sempre: uma requisição pendurada prendia o worker até o
     * time-limit do cron, e o dialoghsm:consume matava (SIGKILL) todos os workers irmãos.
     */
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const REQUEST_TIMEOUT_SECONDS = 30;

    private const URL_OK          = 'ok';
    private const URL_BLOCKED     = 'blocked';
    private const URL_DNS_FAILURE = 'dns_failure';

    /** @var callable(string): string */
    private $hostnameResolver;

    /**
     * @param callable(string): string|null $hostnameResolver Injetável para testes (evita DNS real).
     *                                                         Padrão: gethostbyname().
     */
    public function __construct(
        private Client $httpClient,
        private LoggerInterface $logger,
        ?callable $hostnameResolver = null,
    ) {
        $this->hostnameResolver = $hostnameResolver ?? static fn (string $h): string => gethostbyname($h);
    }

    /**
     * Envia mensagem WhatsApp HSM via 360dialog API.
     *
     * @param array<string, string> $payloadData key-value pairs configurados na campanha
     *
     * @return array{success: bool, response: array|null, error: string|null, http_status: int|null, wamid: string|null, retryable: bool}
     */
    public function sendMessage(string $apiKey, string $baseUrl, string $mobile, array $payloadData): array
    {
        $url = rtrim($baseUrl, '/');

        // DIALOGHSM_DISABLE_SSRF_CHECK=1 desativa a validação SSRF.
        // Usar APENAS em desenvolvimento local com fake API.
        // Nunca definir em produção.
        $ssrfCheckEnabled = '1' !== getenv('DIALOGHSM_DISABLE_SSRF_CHECK');

        $parsedBase = parse_url($url);
        $baseHost   = $parsedBase['host'] ?? '';
        $basePort   = (int) ($parsedBase['port'] ?? 443);
        $resolvedIp = null;

        if ($ssrfCheckEnabled) {
            // Valida o endpoint antes de fazer qualquer request (previne SSRF via baseUrl configurada).
            // resolveUrlSafely() resolve o hostname UMA vez; o IP é fixado via CURLOPT_RESOLVE abaixo
            // para que um DNS rebinding entre a validação e o request seja ineficaz.
            ['result' => $urlCheck, 'ip' => $resolvedIp] = $this->resolveUrlSafely($url);

            // DNS sem resposta é instabilidade, não configuração errada: vale retry. Antes caía no
            // mesmo caminho da URL bloqueada e a mensagem virava falha definitiva.
            if (self::URL_DNS_FAILURE === $urlCheck) {
                $this->logger->warning('DialogHSM: falha ao resolver o DNS da API', ['url' => $url]);

                return [
                    'success'     => false,
                    'response'    => null,
                    'error'       => sprintf('Falha ao resolver o DNS de %s.', $baseHost),
                    'http_status' => null,
                    'wamid'       => null,
                    'retryable'   => true,
                ];
            }

            if (self::URL_OK !== $urlCheck) {
                $this->logger->error('DialogHSM: URL da API rejeitada (SSRF/esquema inválido)', ['url' => $url]);

                return [
                    'success'     => false,
                    'response'    => null,
                    'error'       => 'URL da API inválida ou aponta para endereço interno.',
                    'http_status' => null,
                    'wamid'       => null,
                    'retryable'   => false,  // erro de configuração — retry não resolve
                ];
            }
        } else {
            $this->logger->warning('DialogHSM: verificação SSRF desabilitada (DIALOGHSM_DISABLE_SSRF_CHECK=1)', [
                'url' => $url,
            ]);
        }

        // Fora do try da requisição: dado inválido no payload (ex.: data de limited_time_offer)
        // é erro permanente — no catch genérico abaixo viraria retry.
        try {
            $payload = $this->buildPayload($mobile, $payloadData);
        } catch (\Throwable $e) {
            $this->logger->error('DialogHSM: payload inválido', ['error' => $e->getMessage()]);

            return [
                'success'     => false,
                'response'    => null,
                'error'       => 'Payload inválido: '.$e->getMessage(),
                'http_status' => null,
                'wamid'       => null,
                'retryable'   => false,
            ];
        }

        $this->logger->debug('DialogHSM: Enviando mensagem', [
            'url'      => $url,
            'template' => $payload['template']['name'] ?? '',
        ]);

        $isMeta  = str_contains($url, 'graph.facebook.com');
        $headers = $isMeta
            ? ['Authorization' => 'Bearer '.$apiKey, 'Content-Type' => 'application/json']
            : ['D360-API-KEY' => $apiKey, 'Content-Type' => 'application/json'];

        try {
            $requestOptions = [
                'headers'         => $headers,
                'json'            => $payload,
                'http_errors'     => false,
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'timeout'         => self::REQUEST_TIMEOUT_SECONDS,
            ];

            // Fixa o IP já resolvido e validado: mesmo que o DNS seja alterado entre a validação
            // acima e a abertura do socket (DNS rebinding), o curl usará este IP e não vai
            // re-resolver. Só disponível quando a validação SSRF está ativa.
            if (null !== $resolvedIp) {
                $requestOptions['curl'] = [
                    \CURLOPT_RESOLVE => ["{$baseHost}:{$basePort}:{$resolvedIp}"],
                ];
            }

            $response = $this->httpClient->request('POST', $url, $requestOptions);

            $statusCode   = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);


            if ($statusCode >= 200 && $statusCode < 300) {
                $wamid = $responseBody['messages'][0]['id'] ?? null;

                $this->logger->info('DialogHSM: Mensagem enviada com sucesso', [
                    'http_status' => $statusCode,
                    'template'    => $payload['template']['name'] ?? '',
                    'wamid'       => $wamid,
                ]);

                return [
                    'success'     => true,
                    'response'    => $responseBody,
                    'error'       => null,
                    'http_status' => $statusCode,
                    'wamid'       => $wamid,
                    'retryable'   => false,
                ];
            }

            $firstError  = is_array($responseBody['errors'] ?? null) ? ($responseBody['errors'][0] ?? null) : null;
            $errorDetail = $responseBody['error']['message']
                ?? ($firstError['details'] ?? null)
                ?? $responseBody['message']
                ?? json_encode($responseBody);

            // 429 (rate limit) e 5xx (falha do servidor) são transitórios — vale retry.
            // 4xx (exceto 429) são erros de validação/autenticação — retry não resolve.
            $retryable = ($statusCode === 429 || $statusCode >= 500);

            $this->logger->error('DialogHSM: API retornou erro', [
                'http_status' => $statusCode,
                'retryable'   => $retryable,
                'error'       => $errorDetail,
            ]);

            return [
                'success'     => false,
                'response'    => $responseBody,
                'error'       => "HTTP {$statusCode}: {$errorDetail}",
                'http_status' => $statusCode,
                'wamid'       => null,
                'retryable'   => $retryable,
            ];
        } catch (RequestException $e) {
            $statusCode   = null;
            $responseBody = null;

            if ($e->hasResponse()) {
                $statusCode   = $e->getResponse()->getStatusCode();
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);
            }

            // Sem resposta = erro de rede (timeout, DNS) = transitório.
            // Com resposta: aplica mesma classificação do bloco HTTP acima.
            $retryable = ($statusCode === null || $statusCode === 429 || $statusCode >= 500);

            $this->logger->error('DialogHSM: Erro ao enviar mensagem', [
                'http_status' => $statusCode,
                'retryable'   => $retryable,
                'error'       => $e->getMessage(),
            ]);

            return [
                'success'     => false,
                'response'    => $responseBody,
                'error'       => $e->getMessage(),
                'http_status' => $statusCode,
                'wamid'       => null,
                'retryable'   => $retryable,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('DialogHSM: Erro inesperado', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success'     => false,
                'response'    => null,
                'error'       => $e->getMessage(),
                'http_status' => null,
                'wamid'       => null,
                'retryable'   => true,  // causa desconhecida — tenta novamente por segurança
            ];
        }
    }
    /**
     * Monta o payload no formato esperado pela 360dialog.
     */
    private function buildPayload(string $mobile, array $data): array
    {
        $variables   = $data['vars'] ?? '';
        $urlArquivo  = $data['url_arquivo'] ?? '';
        $buttons     = $data['buttons'] ?? '';
        $buttonsVars = $data['buttons_vars'] ?? '';

        // Montar components
        $components = [];

        // Header component (se tem url_arquivo)
        if ('' !== $urlArquivo) {
            $headerParams = $this->buildHeaderParameter($urlArquivo);
            if (null !== $headerParams) {
                $components[] = [
                    'type'       => 'header',
                    'parameters' => [$headerParams],
                ];
            }
        }

        // Body component (se tem vars)
        if ('' !== $variables) {
            $bodyParameters = [];
            $varItems = array_map('trim', explode(',', $variables));

            foreach ($varItems as $varName) {
                $bodyParameters[] = [
                    'type' => 'text',
                    'text' => $data[$varName] ?? '',
                ];
            }

            $components[] = [
                'type'       => 'body',
                'parameters' => $bodyParameters,
            ];
        }

        // Limited time offer component (se tem limited_time_offer)
        $limitedTimeOffer = $data['limited_time_offer'] ?? '';
        if ('' !== $limitedTimeOffer) {
            $expirationMs = (new \DateTime($limitedTimeOffer))->getTimestamp() * 1000;
            $components[] = [
                'type'       => 'limited_time_offer',
                'parameters' => [
                    [
                        'type'               => 'limited_time_offer',
                        'limited_time_offer' => [
                            'expiration_time_ms' => $expirationMs,
                        ],
                    ],
                ],
            ];
        }

        // Button components (se tem buttons)
        if ('' !== $buttons) {
            $buttonNodes = array_map('trim', explode(',', $buttons));
            $buttonVars  = '' !== $buttonsVars ? array_map('trim', explode(',', $buttonsVars)) : [];

            foreach ($buttonNodes as $idx => $buttonType) {
                $components[] = [
                    'type'       => 'button',
                    'sub_type'   => $buttonType,
                    'index'      => $idx,
                    'parameters' => [
                        [
                            'type' => 'text',
                            'text' => $buttonVars[$idx] ?? '',
                        ],
                    ],
                ];
            }
        }

        // Formatar telefone: preserva código de país existente.
        // Se começar com '+': remove o '+', limpa dígitos e recoloca '+'.
        // Se não começar com '+' mas tiver >= 12 dígitos começando com '55':
        //   assume que já possui DDI Brasil → apenas adiciona '+'.
        // Caso contrário: assume número local brasileiro → prefixa com '+55'.
        $cleanmobile   = preg_replace('/\D/', '', $mobile);
        $startWithPlus = str_starts_with(trim($mobile), '+');

        if ($startWithPlus || (strlen($cleanmobile) >= 12 && str_starts_with($cleanmobile, '55'))) {
            $formattedmobile = '+'.$cleanmobile;
        } else {
            $formattedmobile = '+55'.$cleanmobile;
        }

        // Montar payload apenas com os campos esperados pela API 360dialog.
        // Campos de controle interno do plugin (vars, buttons, url_arquivo, etc.)
        // não devem ser enviados à API.
        return [
            'recipient_type'    => 'individual',
            'messaging_product' => 'whatsapp',
            'type'              => 'template',
            'to'                => $formattedmobile,
            'template'          => [
                'name'       => $data['content'] ?? '',
                'language'   => ['code' => $data['language'] ?? 'pt_BR'],
                'components' => $components,
            ],
        ];
    }

    /**
     * Monta o parâmetro de header baseado na extensão do arquivo.
     * Retorna null se a URL não usar HTTPS ou não tiver extensão reconhecida.
     *
     * Não fazemos validação SSRF aqui: a URL vai no payload JSON e quem
     * a busca é a 360dialog, não este servidor. Validar DNS a partir do
     * servidor de produção causaria rejeição indevida quando o hostname
     * resolve para loopback localmente (ex: próprio domínio do servidor).
     */
    private function buildHeaderParameter(string $url): ?array
    {
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        if ('https' !== strtolower($parsed['scheme'])) {
            $this->logger->warning('DialogHSM: URL de mídia ignorada (não é HTTPS)', ['url' => $url]);

            return null;
        }

        $lowerUrl = strtolower($url);

        if (preg_match('/\.(jpg|jpeg|png)(\?.*)?$/', $lowerUrl)) {
            return [
                'type'  => 'image',
                'image' => ['link' => $url],
            ];
        }

        if (preg_match('/\.mp4(\?.*)?$/', $lowerUrl)) {
            return [
                'type'  => 'video',
                'video' => ['link' => $url],
            ];
        }

        if (preg_match('/\.pdf(\?.*)?$/', $lowerUrl)) {
            return [
                'type'     => 'document',
                'document' => ['link' => $url],
            ];
        }

        return null;
    }

    /**
     * Resolve o hostname da URL, valida que o IP resultante é público e retorna o IP.
     * result = URL_BLOCKED se a URL for inválida, não usar HTTPS ou o IP for privado/reservado;
     * URL_DNS_FAILURE se o hostname não resolver (instabilidade de DNS, retryable).
     *
     * O retorno do IP (em vez de bool) permite ao chamador fixar o IP na requisição via
     * CURLOPT_RESOLVE, eliminando a janela de DNS rebinding (TOCTOU).
     *
     * @return array{result: string, ip: string|null}
     */
    private function resolveUrlSafely(string $url): array
    {
        $parsed = parse_url($url);

        $blocked = ['result' => self::URL_BLOCKED, 'ip' => null];

        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return $blocked;
        }

        if ('https' !== strtolower($parsed['scheme'])) {
            return $blocked;
        }

        $host = strtolower($parsed['host']);

        // Rejeita localhost e variações antes de tentar resolver
        if (in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return $blocked;
        }

        // Se já é um IP literal, valida diretamente sem resolução DNS
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            $ip = ($this->hostnameResolver)($host);
            // gethostbyname() retorna o host original se a resolução falhar
            if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return ['result' => self::URL_DNS_FAILURE, 'ip' => null];
            }
        }

        // Rejeita IPs privados, loopback e link-local
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $blocked;
        }

        return ['result' => self::URL_OK, 'ip' => $ip];
    }

}
