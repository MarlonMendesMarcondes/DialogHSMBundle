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
        $payload = json_decode($request->getContent(), true) ?? [];

        try {
            $status = $this->processor->process($phoneNumber, $payload);
        } catch (\Throwable $e) {
            // Falha transitória (DB, Doctrine, etc.): responder erro para que a Meta
            // reentregue o webhook dentro da janela de retry de 7 dias, em vez de
            // devolver 200 e descartar o evento silenciosamente.
            $this->logger->error('DialogHSM webhook error: '.$e->getMessage(), [
                'phone'     => $phoneNumber,
                'exception' => $e,
            ]);

            return new JsonResponse(null, 503);
        }

        return new JsonResponse(null, $status);
    }
}
