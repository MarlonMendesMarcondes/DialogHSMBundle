<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class MultiWebhookService
{
    private const BASE_URI  = 'https://waba.360dialog.io';
    private const DEST_NAME = 'mautic';

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * Returns current multi_webhook state from 360dialog.
     *
     * @return array<string, mixed>
     */
    public function check(string $apiKey): array
    {
        try {
            $resp = $this->makeClient($apiKey)->get('/multi_webhook');

            return json_decode((string) $resp->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            $this->logger->error('MultiWebhook check failed', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Ensures multi_webhook is enabled and the "mautic" destination points to $webhookUrl.
     *
     * @return array{success: bool, action: string, message: string}
     */
    public function register(string $apiKey, string $webhookUrl): array
    {
        $client = $this->makeClient($apiKey);

        // 1. Get current state
        try {
            $state = json_decode((string) $client->get('/multi_webhook')->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            return ['success' => false, 'action' => 'check', 'message' => $e->getMessage()];
        }

        // 2. Enable if not already enabled
        if (empty($state['enabled'])) {
            try {
                $client->post('/multi_webhook', ['json' => ['enabled' => true]]);
            } catch (GuzzleException $e) {
                if (!str_contains($e->getMessage(), 'already enabled')) {
                    return ['success' => false, 'action' => 'enable', 'message' => $e->getMessage()];
                }
            }
        }

        // 3. Find existing "mautic" destination
        $existing = null;
        foreach ($state['destinations'] ?? [] as $dest) {
            if (($dest['name'] ?? '') === self::DEST_NAME) {
                $existing = $dest;
                break;
            }
        }

        // 4. Create or update
        try {
            if (null === $existing) {
                $client->put('/multi_webhook', ['json' => [
                    'destinations' => [['name' => self::DEST_NAME, 'url' => $webhookUrl]],
                ]]);
                $action = 'created';
            } elseif ($existing['url'] !== $webhookUrl) {
                $client->patch('/multi_webhook', ['json' => [
                    'name' => self::DEST_NAME,
                    'url'  => $webhookUrl,
                ]]);
                $action = 'updated';
            } else {
                $action = 'unchanged';
            }
        } catch (GuzzleException $e) {
            $action = null === $existing ? 'create' : 'update';

            return ['success' => false, 'action' => $action, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'action' => $action, 'message' => 'OK'];
    }

    protected function makeClient(string $apiKey): Client
    {
        return new Client([
            'base_uri' => self::BASE_URI,
            'headers'  => [
                'D360-API-KEY' => $apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 10,
        ]);
    }
}
