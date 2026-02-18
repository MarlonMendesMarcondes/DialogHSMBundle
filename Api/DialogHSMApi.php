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
     * @return array{success: bool, response: array|null, error: string|null, http_status: int|null}
     */
    public function sendMessage(string $apiKey, string $baseUrl, string $mobile, array $payloadData): array
    {
        $base = rtrim($baseUrl, '/');
        $path = parse_url($base, PHP_URL_PATH) ?? '';

        // If the configured base URL already points to an endpoint (eg. marketing_messages or messages),
        // use it as-is. Otherwise append the legacy '/messages' path.
        if (str_contains($path, 'marketing_messages') || str_contains($path, 'messages') || str_ends_with($base, 'marketing_messages') || str_ends_with($base, 'messages')) {
            $url = $base;
        } else {
            $url = $base . '/messages';
        }

        $payload = $this->buildPayload($mobile, $payloadData);

        $this->logger->debug('DialogHSM: Enviando payload', [
            'url'     => $url,
            'mobile'   => $mobile,
            'payload' => $payload,
        ]);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'D360-API-KEY' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json'        => $payload,
                'http_errors' => false,
            ]);

            $statusCode   = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);


                        // Log payload for debugging (plugin scope)
                        $this->logger->debug('DialogHSM: sending payload to 360dialog', ['url' => $url, 'payload' => $payload]);
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('DialogHSM: Mensagem enviada com sucesso', [
                    'mobile'      => $mobile,
                    'http_status' => $statusCode,
                    'template'    => $payload['template'] ?? '',
                ]);

                return [
                    'success'     => true,
                    'response'    => ['api_response' => $responseBody, 'payload_sent' => $payload],
                    'error'       => null,
                    'http_status' => $statusCode,
                ];
            }

            $errorDetail = $responseBody['error']['message']
                ?? $responseBody['errors'][0]['details']
                ?? $responseBody['message']
                ?? json_encode($responseBody);

            $this->logger->error('DialogHSM: API retornou erro', [
                'mobile'       => $mobile,
                'http_status' => $statusCode,
                'response'    => $responseBody,
            ]);

            return [
                'success'     => false,
                'response'    => ['api_response' => $responseBody, 'payload_sent' => $payload],
                'error'       => "HTTP {$statusCode}: {$errorDetail}",
                'http_status' => $statusCode,
            ];
        } catch (RequestException $e) {
            $statusCode   = null;
            $responseBody = null;

            if ($e->hasResponse()) {
                $statusCode   = $e->getResponse()->getStatusCode();
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);
            }

            $this->logger->error('DialogHSM: Erro ao enviar mensagem', [
                'mobile'       => $mobile,
                'http_status' => $statusCode,
                'error'       => $e->getMessage(),
            ]);

            return [
                'success'     => false,
                'response'    => $responseBody,
                'error'       => $e->getMessage(),
                'http_status' => $statusCode,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('DialogHSM: Erro inesperado', [
                'mobile' => $mobile,
                'error' => $e->getMessage(),
            ]);

            return [
                'success'     => false,
                'response'    => null,
                'error'       => $e->getMessage(),
                'http_status' => null,
            ];
        }
    }
    /**
     * Monta o payload no formato esperado pela 360dialog.
     * Replica a lógica do n8n: monta components a partir de vars, buttons, buttons_vars e url_arquivo.
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

        // Montar telefone com +55 se não começa com +
        $cleanmobile      = preg_replace('/\D/', '', $mobile);
        $startWithPlus   = str_starts_with(trim($mobile), '+');
        $formattedmobile  = ($startWithPlus ? '+' : '+55').$cleanmobile;

        // Montar payload final com todos os dados
        $payload = $data;
        $payload['recipient_type']    = 'individual';
        $payload['messaging_product'] = 'whatsapp';
        $payload['type']              = 'template';
        $payload['to']                = $formattedmobile;
        $payload['receivers']         = $mobile;

        // Montar objeto de template no formato esperado pela 360dialog
        $payload['template'] = [
            'name'       => $data['content'] ?? '',
            'language'   => ['code' => $data['language'] ?? 'pt_BR'],
            'components' => $components,
        ];

        // Remover apenas url_arquivo (já foi processado no header component)
        unset($payload['url_arquivo']);

        return $payload;
    }

    /**
     * Monta o parâmetro de header baseado na extensão do arquivo.
     */
    private function buildHeaderParameter(string $url): ?array
    {
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
}
