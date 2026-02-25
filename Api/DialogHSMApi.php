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
        $url = rtrim($baseUrl, '/');

        $payload = $this->buildPayload($mobile, $payloadData);

        $this->logger->debug('DialogHSM: Enviando mensagem', [
            'url'      => $url,
            'template' => $payload['template']['name'] ?? '',
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


            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('DialogHSM: Mensagem enviada com sucesso', [
                    'http_status' => $statusCode,
                    'template'    => $payload['template']['name'] ?? '',
                ]);

                return [
                    'success'     => true,
                    'response'    => $responseBody,
                    'error'       => null,
                    'http_status' => $statusCode,
                ];
            }

            $errorDetail = $responseBody['error']['message']
                ?? $responseBody['errors'][0]['details']
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
        $cleanmobile     = preg_replace('/\D/', '', $mobile);
        $startWithPlus   = str_starts_with(trim($mobile), '+');
        $formattedmobile = ($startWithPlus ? '+' : '+55').$cleanmobile;

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
