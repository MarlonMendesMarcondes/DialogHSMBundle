<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

class DialogHSMApi
{
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
     * @return array{success: bool, response: array|null, error: string|null, http_status: int|null, wamid: string|null}
     */
    public function sendMessage(string $apiKey, string $baseUrl, string $mobile, array $payloadData): array
    {
        $url = rtrim($baseUrl, '/');

        // DIALOGHSM_DISABLE_SSRF_CHECK=1 desativa a validação SSRF.
        // Usar APENAS em desenvolvimento local com fake API (ex: /tmp/fake_360dialog.php no Docker).
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
            $resolvedIp = $this->resolveUrlSafely($url);

            if (null === $resolvedIp) {
                $this->logger->error('DialogHSM: URL da API rejeitada (SSRF/esquema inválido)', ['url' => $url]);

                return [
                    'success'     => false,
                    'response'    => null,
                    'error'       => 'URL da API inválida ou aponta para endereço interno.',
                    'http_status' => null,
                    'wamid'       => null,
                ];
            }
        } else {
            $this->logger->warning('DialogHSM: verificação SSRF desabilitada (DIALOGHSM_DISABLE_SSRF_CHECK=1)', [
                'url' => $url,
            ]);
        }

        $payload = $this->buildPayload($mobile, $payloadData);

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
                'headers'     => $headers,
                'json'        => $payload,
                'http_errors' => false,
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
                ];
            }

            $firstError  = is_array($responseBody['errors'] ?? null) ? ($responseBody['errors'][0] ?? null) : null;
            $errorDetail = $responseBody['error']['message']
                ?? ($firstError['details'] ?? null)
                ?? $responseBody['message']
                ?? json_encode($responseBody);

            $this->logger->error('DialogHSM: API retornou erro', [
                'http_status' => $statusCode,
                'error'       => $errorDetail,
            ]);

            return [
                'success'     => false,
                'response'    => $responseBody,
                'error'       => "HTTP {$statusCode}: {$errorDetail}",
                'http_status' => $statusCode,
                'wamid'       => null,
            ];
        } catch (RequestException $e) {
            $statusCode   = null;
            $responseBody = null;

            if ($e->hasResponse()) {
                $statusCode   = $e->getResponse()->getStatusCode();
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);
            }

            $this->logger->error('DialogHSM: Erro ao enviar mensagem', [
                'http_status' => $statusCode,
                'error'       => $e->getMessage(),
            ]);

            return [
                'success'     => false,
                'response'    => $responseBody,
                'error'       => $e->getMessage(),
                'http_status' => $statusCode,
                'wamid'       => null,
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
     * Retorna null se a URL for inválida ou aponte para endereços internos (SSRF).
     *
     * Nota sobre DNS rebinding: a URL de mídia é enviada para o 360dialog, que a busca
     * em seus próprios servidores. Não é possível fixar o IP via CURLOPT_RESOLVE para
     * requests feitos por terceiros. Como mitigação, substituímos o hostname pelo IP já
     * resolvido e validado na URL enviada ao payload — o 360dialog receberá o IP diretamente,
     * eliminando a janela de rebinding.
     */
    private function buildHeaderParameter(string $url): ?array
    {
        $resolvedIp = $this->resolveUrlSafely($url);

        if (null === $resolvedIp) {
            $this->logger->warning('DialogHSM: URL de mídia rejeitada (SSRF ou esquema inválido)', [
                'url' => $url,
            ]);

            return null;
        }

        // Substitui o hostname pelo IP resolvido na URL enviada ao 360dialog.
        // Isso elimina a janela de DNS rebinding: o 360dialog conecta diretamente
        // ao IP que validamos, sem fazer nova resolução DNS.
        $pinned = $this->pinHostToIp($url, $resolvedIp);

        $lowerUrl = strtolower($pinned);

        if (preg_match('/\.(jpg|jpeg|png)(\?.*)?$/', $lowerUrl)) {
            return [
                'type'  => 'image',
                'image' => ['link' => $pinned],
            ];
        }

        if (preg_match('/\.mp4(\?.*)?$/', $lowerUrl)) {
            return [
                'type'  => 'video',
                'video' => ['link' => $pinned],
            ];
        }

        if (preg_match('/\.pdf(\?.*)?$/', $lowerUrl)) {
            return [
                'type'     => 'document',
                'document' => ['link' => $pinned],
            ];
        }

        return null;
    }

    /**
     * Resolve o hostname da URL, valida que o IP resultante é público e retorna o IP.
     * Retorna null se a URL for inválida, não usar HTTPS, ou o IP for privado/reservado.
     *
     * O retorno do IP (em vez de bool) permite ao chamador fixar o IP na requisição via
     * CURLOPT_RESOLVE, eliminando a janela de DNS rebinding (TOCTOU).
     */
    private function resolveUrlSafely(string $url): ?string
    {
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        if ('https' !== strtolower($parsed['scheme'])) {
            return null;
        }

        $host = strtolower($parsed['host']);

        // Rejeita localhost e variações antes de tentar resolver
        if (in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return null;
        }

        // Se já é um IP literal, valida diretamente sem resolução DNS
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            $ip = ($this->hostnameResolver)($host);
            // gethostbyname() retorna o host original se a resolução falhar
            if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return null;
            }
        }

        // Rejeita IPs privados, loopback e link-local
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        return $ip;
    }

    /**
     * Substitui o hostname da URL pelo IP já resolvido, preservando porta, path e query.
     * Exemplo: https://cdn.example.com/img.jpg + 1.2.3.4 → https://1.2.3.4/img.jpg
     *
     * O TLS continua funcional pois o SNI (Server Name Indication) ainda usa o hostname
     * original quando o cliente suporta — mas como 360dialog é quem faz o request, e a
     * intenção é apenas eliminar a re-resolução DNS, a substituição é suficiente.
     */
    private function pinHostToIp(string $url, string $ip): string
    {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return $url;
        }

        $rebuilt = $parsed['scheme'].'://'.$ip;

        if (isset($parsed['port'])) {
            $rebuilt .= ':'.$parsed['port'];
        }

        $rebuilt .= $parsed['path'] ?? '';

        if (!empty($parsed['query'])) {
            $rebuilt .= '?'.$parsed['query'];
        }

        if (!empty($parsed['fragment'])) {
            $rebuilt .= '#'.$parsed['fragment'];
        }

        return $rebuilt;
    }

}
