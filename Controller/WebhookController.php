<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Controller;

use MauticPlugin\DialogHSMBundle\Service\WebhookProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class WebhookController extends AbstractController
{
    public function __construct(
        private readonly WebhookProcessor $processor,
        private readonly LoggerInterface $logger,
    ) {}

    public function processAction(Request $request, string $phoneNumber): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true) ?? [];
            $this->processor->process($phoneNumber, $payload);
        } catch (\Throwable $e) {
            $this->logger->error('DialogHSM webhook error: '.$e->getMessage(), [
                'phone'     => $phoneNumber,
                'exception' => $e,
            ]);
        }

        return new JsonResponse(null, 200);
    }
}
