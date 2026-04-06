<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

class DialogHSMApi
{
    public function __construct(
        private Client $httpClient,
        private LoggerInterface $logger,
    ) {
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
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'json'        => $payload,
                'http_errors' => false,
            ]);

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
     */
    private function buildHeaderParameter(string $url): ?array
    {
        if (!$this->isSafeUrl($url)) {
            $this->logger->warning('DialogHSM: URL de mídia rejeitada (SSRF ou esquema inválido)', [
                'url' => $url,
            ]);

            return null;
        }

        $lowerUrl = strtolower($url);

        if (preg_match('/\.(jpg|jpeg|png)$/', $lowerUrl)) {
            return [
                'type'  => 'image',
                'image' => ['link' => $url],
            ];
        }

        if (str_ends_with($lowerUrl, '.mp4')) {
            return [
                'type'  => 'video',
                'video' => ['link' => $url],
            ];
        }

        if (str_ends_with($lowerUrl, '.pdf')) {
            return [
                'type'     => 'document',
                'document' => ['link' => $url],
            ];
        }

        return null;
    }

    /**
     * Valida que a URL é segura para uso como mídia externa.
     * Rejeita: esquemas não-HTTPS, IPs privados/loopback/link-local, hostnames internos.
     */
    private function isSafeUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        if ('https' !== strtolower($parsed['scheme'])) {
            return false;
        }

        $host = strtolower($parsed['host']);

        // Rejeita localhost e variações
        if (in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return false;
        }

        // Resolve o host para IP e valida
        $ip = filter_var($host, FILTER_VALIDATE_IP)
            ? $host
            : gethostbyname($host);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Rejeita IPs privados, loopback e link-local
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        return true;
    }
}
