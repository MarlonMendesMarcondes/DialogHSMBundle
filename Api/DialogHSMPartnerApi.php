<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Cliente para a 360dialog Partner API (hub.360dialog.io), usada para consultar
 * saldo/uso por canal (WhatsApp number) — distinto da Client API (DialogHSMApi)
 * usada para enviar mensagens.
 *
 * Rate limit da 360dialog: 10 req/hora por canal, 200 req/hora por WABA.
 * Este cliente não implementa throttling — quem agenda as chamadas periódicas
 * (ex.: um Command) é responsável por respeitar esse limite.
 */
class DialogHSMPartnerApi
{
    private const BASE_URL = 'https://hub.360dialog.io/api/v2';

    public function __construct(
        private Client $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Consulta o saldo do canal (número) via Get Channel Balance.
     *
     * @return array{success: bool, balance: float|null, currency: string|null, response: array|null, error: string|null, http_status: int|null, retryable: bool, last_renewal_amount: float|null, last_renewal_date: string|null, usage: array<int, array{period_date: string, total_price: float}>}
     */
    public function getChannelBalance(string $partnerId, string $partnerApiKey, string $clientId, string $channelId): array
    {
        if ('' === $partnerId || '' === $partnerApiKey || '' === $clientId || '' === $channelId) {
            return [
                'success'             => false,
                'balance'             => null,
                'currency'            => null,
                'response'            => null,
                'error'               => 'partner_id, partner_api_key, client_id e channel_id são obrigatórios.',
                'http_status'         => null,
                'retryable'           => false,
                'last_renewal_amount' => null,
                'last_renewal_date'   => null,
                'usage'               => [],
            ];
        }

        $url = sprintf(
            '%s/partners/%s/clients/%s/channels/%s/info/balance',
            self::BASE_URL,
            rawurlencode($partnerId),
            rawurlencode($clientId),
            rawurlencode($channelId)
        );

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers'     => [
                    'X-API-Key' => $partnerApiKey,
                    'Accept'    => 'application/json',
                ],
                'http_errors' => false,
            ]);

            $statusCode   = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('DialogHSM Partner API: saldo consultado com sucesso', [
                    'client_id'  => $clientId,
                    'channel_id' => $channelId,
                    'balance'    => $responseBody['balance'] ?? null,
                ]);

                $lastRenewal = $responseBody['last_renewal'] ?? null;

                return [
                    'success'             => true,
                    'balance'             => isset($responseBody['balance']) ? (float) $responseBody['balance'] : null,
                    'currency'            => $responseBody['currency'] ?? null,
                    'response'            => $responseBody,
                    'error'               => null,
                    'http_status'         => $statusCode,
                    'retryable'           => false,
                    'last_renewal_amount' => isset($lastRenewal['amount']) ? (float) $lastRenewal['amount'] : null,
                    'last_renewal_date'   => $lastRenewal['date'] ?? null,
                    'usage'               => $this->normalizeUsage($responseBody['usage'] ?? []),
                ];
            }

            $errorDetail = $responseBody['error']['message']
                ?? $responseBody['message']
                ?? json_encode($responseBody);

            // 429 = rate limit (10/h por canal, 200/h por WABA) e 5xx = falha transitória.
            $retryable = (429 === $statusCode || $statusCode >= 500);

            $this->logger->error('DialogHSM Partner API: erro ao consultar saldo', [
                'client_id'   => $clientId,
                'channel_id'  => $channelId,
                'http_status' => $statusCode,
                'retryable'   => $retryable,
                'error'       => $errorDetail,
            ]);

            return [
                'success'             => false,
                'balance'             => null,
                'currency'            => null,
                'response'            => $responseBody,
                'error'               => "HTTP {$statusCode}: {$errorDetail}",
                'http_status'         => $statusCode,
                'retryable'           => $retryable,
                'last_renewal_amount' => null,
                'last_renewal_date'   => null,
                'usage'               => [],
            ];
        } catch (RequestException $e) {
            $statusCode   = null;
            $responseBody = null;

            if ($e->hasResponse()) {
                $statusCode   = $e->getResponse()->getStatusCode();
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);
            }

            $retryable = (null === $statusCode || 429 === $statusCode || $statusCode >= 500);

            $this->logger->error('DialogHSM Partner API: erro de rede ao consultar saldo', [
                'client_id'   => $clientId,
                'channel_id'  => $channelId,
                'http_status' => $statusCode,
                'retryable'   => $retryable,
                'error'       => $e->getMessage(),
            ]);

            return [
                'success'             => false,
                'balance'             => null,
                'currency'            => null,
                'response'            => $responseBody,
                'error'               => $e->getMessage(),
                'http_status'         => $statusCode,
                'retryable'           => $retryable,
                'last_renewal_amount' => null,
                'last_renewal_date'   => null,
                'usage'               => [],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('DialogHSM Partner API: erro inesperado ao consultar saldo', [
                'client_id'  => $clientId,
                'channel_id' => $channelId,
                'error'      => $e->getMessage(),
            ]);

            return [
                'success'             => false,
                'balance'             => null,
                'currency'            => null,
                'response'            => null,
                'error'               => $e->getMessage(),
                'http_status'         => null,
                'retryable'           => true,
                'last_renewal_amount' => null,
                'last_renewal_date'   => null,
                'usage'               => [],
            ];
        }
    }

    /**
     * Normaliza o array usage[] da resposta para {period_date, total_price},
     * suficiente para montar o gráfico de custo mensal sem carregar todos os
     * campos de categoria (marketing/utility/authentication, etc.).
     *
     * @param array<int, array<string, mixed>> $rawUsage
     *
     * @return array<int, array{period_date: string, total_price: float}>
     */
    private function normalizeUsage(array $rawUsage): array
    {
        $normalized = [];

        foreach ($rawUsage as $row) {
            if (!isset($row['period_date'])) {
                continue;
            }

            $normalized[] = [
                'period_date' => (string) $row['period_date'],
                'total_price' => isset($row['total_price']) ? (float) $row['total_price'] : 0.0,
            ];
        }

        return $normalized;
    }
}
