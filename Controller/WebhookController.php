<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Controller;

use MauticPlugin\DialogHSMBundle\Service\WebhookProcessor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class WebhookController extends AbstractController
{
    public function __construct(
        private readonly WebhookProcessor $processor,
    ) {}

    public function processAction(Request $request, string $phoneNumber): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $status  = $this->processor->process($phoneNumber, $payload);

        return new JsonResponse(null, $status);
    }
}
