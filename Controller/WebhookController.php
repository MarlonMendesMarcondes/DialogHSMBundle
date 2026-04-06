<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CacheBundle\Cache\CacheProviderInterface;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends AbstractController
{
    /**
     * Status priority: higher index = more advanced state.
     *
     * @var array<string, int>
     */
    private const STATUS_PRIORITY = [
        MessageLog::STATUS_FAILED    => 0,
        MessageLog::STATUS_SENT      => 1,
        MessageLog::STATUS_DELIVERED => 2,
        MessageLog::STATUS_READ      => 3,
    ];

    /** Máximo de requisições por IP por janela de tempo. */
    public const RATE_LIMIT     = 60;

    /** Duração da janela em segundos. */
    public const RATE_WINDOW    = 60;

    public function __construct(
        private WhatsAppNumberRepository $numberRepository,
        private MessageLogRepository $messageLogRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private CacheProviderInterface $cache,
    ) {
    }

    public function handleAction(Request $request, string $token): Response
    {
        // Rate limiting por IP (janela fixa)
        if ($this->isRateLimited($request)) {
            $this->logger->warning('DialogHSM Webhook: rate limit excedido', [
                'ip' => $request->getClientIp(),
            ]);

            return new Response('Too Many Requests', Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Valida token
        $number = $this->numberRepository->findByWebhookToken($token);

        if (null === $number) {
            $this->logger->warning('DialogHSM Webhook: token inválido', ['token' => substr($token, 0, 8).'...']);

            return new Response('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }

        // Parseia payload
        $body = json_decode($request->getContent(), true);

        if (!is_array($body)) {
            return new Response('Bad Request', Response::HTTP_BAD_REQUEST);
        }

        // Formato 360dialog: {"statuses": [{"id": "wamid.xxx", "status": "delivered", ...}]}
        $statuses = $body['statuses'] ?? [];

        if (empty($statuses)) {
            return new Response('OK', Response::HTTP_OK);
        }

        foreach ($statuses as $statusEntry) {
            $wamid  = $statusEntry['id'] ?? null;
            $status = $statusEntry['status'] ?? null;

            if (!$wamid || !$status) {
                continue;
            }

            $mappedStatus = $this->mapStatus($status);

            if (null === $mappedStatus) {
                $this->logger->debug('DialogHSM Webhook: status desconhecido ignorado', ['status' => $status]);
                continue;
            }

            $log = $this->messageLogRepository->findByWamid($wamid);

            if (null === $log) {
                $this->logger->debug('DialogHSM Webhook: wamid não encontrado', ['wamid' => $wamid]);
                continue;
            }

            if ($this->isStatusAdvancement($log->getStatus(), $mappedStatus)) {
                $log->setStatus($mappedStatus);
                $this->entityManager->persist($log);
            }
        }

        $this->entityManager->flush();

        return new Response('OK', Response::HTTP_OK);
    }

    /**
     * Verifica se o IP excedeu o limite de requisições na janela atual.
     * Usa janela fixa de RATE_WINDOW segundos com limite de RATE_LIMIT chamadas.
     */
    private function isRateLimited(Request $request): bool
    {
        $ip      = $request->getClientIp() ?? 'unknown';
        $window  = (int) floor(time() / self::RATE_WINDOW);
        $cacheKey = 'dialoghsm_wh_rl_'.sha1($ip).'_'.$window;

        $item  = $this->cache->getItem($cacheKey);
        $count = $item->isHit() ? (int) $item->get() : 0;

        if ($count >= self::RATE_LIMIT) {
            return true;
        }

        $item->set($count + 1);
        $item->expiresAfter(self::RATE_WINDOW * 2); // TTL generoso para cobrir borda da janela
        $this->cache->save($item);

        return false;
    }

    /**
     * Mapeia status da 360dialog para constantes internas.
     * Retorna null para status desconhecidos (ex: "sent" da API, que não é um webhook status).
     */
    private function mapStatus(string $apiStatus): ?string
    {
        return match ($apiStatus) {
            'delivered' => MessageLog::STATUS_DELIVERED,
            'read'      => MessageLog::STATUS_READ,
            'failed'    => MessageLog::STATUS_FAILED,
            default     => null,
        };
    }

    /**
     * Retorna true se $newStatus é mais avançado que $currentStatus.
     * Impede retrocesso de estado (ex: read → delivered).
     */
    private function isStatusAdvancement(?string $currentStatus, string $newStatus): bool
    {
        $currentPriority = self::STATUS_PRIORITY[$currentStatus ?? ''] ?? -1;
        $newPriority     = self::STATUS_PRIORITY[$newStatus] ?? -1;

        return $newPriority > $currentPriority;
    }
}
