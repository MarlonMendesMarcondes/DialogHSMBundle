<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Pacing;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\Exception\TransientApiException;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Service\BulkRateLimiter;
use MauticPlugin\DialogHSMBundle\Service\CampaignActivityChecker;
use MauticPlugin\DialogHSMBundle\Service\PaymentBlockPauseService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Envia as mensagens vencidas dos lotes programados (dialoghsm:consume --mode=paced).
 *
 * Trilha própria, fora do RabbitMQ: o lote sai no horário mesmo que as filas bulk/batch do número
 * estejam com backlog, e tem throttle próprio (namespace "paced").
 *
 * O envio passa pelo mesmo SendWhatsAppMessageHandler do consumo da fila, com o mesmo contrato:
 * log 'queued' apagado cancela, campanha removida falha, número despublicado falha. Como este
 * processo não consome fila do RabbitMQ, o que o handler devolveria à fila (campanha desativada,
 * número pausado por pagamento) volta como RecoverableMessageHandlingException, e o erro
 * transitório como TransientApiException: nos dois casos a mensagem é reagendada aqui.
 */
class PacedWorker
{
    public const MAX_ATTEMPTS = 4;

    private const CLAIM_BATCH          = 50;
    private const MAX_IDLE_SECONDS     = 30;
    private const INACTIVE_RECHECK_MIN = 15;

    /** Espera, em minutos, antes da 2ª, 3ª e 4ª tentativa após erro transitório. */
    private const BACKOFF_MINUTES = [1, 2, 4];

    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private PacedQueue $queue,
        private PacedSettings $settings,
        private SendWhatsAppMessageHandler $handler,
        private WhatsAppNumberRepository $numberRepository,
        private MessageLogRepository $messageLogRepository,
        private CampaignActivityChecker $campaignActivityChecker,
        private BulkRateLimiter $rateLimiter,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ?PaymentBlockPauseService $paymentPause = null,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Deve subir junto com o bulk? Com a chave ligada, ou desligada mas ainda com mensagens
     * agendadas (desligar a chave não abandona o que já foi agendado).
     */
    public function shouldRun(): bool
    {
        try {
            return $this->settings->isEnabled() || $this->queue->hasPending();
        } catch (\Throwable) {
            return false; // tabela ainda não criada (migration pendente)
        }
    }

    /**
     * @param int $timeLimit segundos (0 = sem limite, para supervisord)
     */
    public function run(int $timeLimit, OutputInterface $output): int
    {
        $deadline = $timeLimit > 0 ? time() + $timeLimit : PHP_INT_MAX;
        $token    = bin2hex(random_bytes(8));
        $sent     = 0;

        $output->writeln('<info>DialogHSM: worker dos lotes programados iniciado</info>');

        try {
            while (time() < $deadline) {
                $processed = $this->tick($token, $deadline);

                if (null === $processed) {
                    ($this->sleeper)($this->idleSeconds($deadline - time()));
                    continue;
                }

                $sent += $processed;
            }
        } catch (\Throwable $e) {
            $this->logger->error('DialogHSM: worker dos lotes programados interrompido', ['error' => $e->getMessage()]);
            $output->writeln(sprintf('<error>DialogHSM: worker dos lotes programados interrompido: %s</error>', $e->getMessage()));

            try {
                $this->queue->release($token);
            } catch (\Throwable) {
                // a reserva expira sozinha em PacedQueue::CLAIM_TTL_MINUTES
            }

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>DialogHSM: worker dos lotes programados encerrado (%d processadas)</info>', $sent));

        return Command::SUCCESS;
    }

    /**
     * Um ciclo: reserva as mensagens vencidas e processa até o prazo.
     *
     * @return int|null mensagens que saíram da fila, ou null se não havia nada a fazer
     *                  (fora da janela ou nenhuma vencida)
     */
    public function tick(string $token, int $deadline): ?int
    {
        if (!$this->settings->calendar()->isOpen(new \DateTimeImmutable())) {
            return null;
        }

        $rows = $this->queue->claimDue($token, self::CLAIM_BATCH);

        if ([] === $rows) {
            return null;
        }

        $processed = 0;

        foreach ($rows as $row) {
            if (time() >= $deadline) {
                break;
            }

            if ($this->process($row)) {
                ++$processed;
            }
        }

        // Reservas que sobraram (prazo no meio do lote) voltam para a fila na hora
        $this->queue->release($token);
        $this->entityManager->clear();

        return $processed;
    }

    /**
     * Quanto dormir sem trabalho: até a próxima mensagem vencer, no máximo MAX_IDLE_SECONDS.
     */
    private function idleSeconds(int $remaining): int
    {
        $next = $this->settings->calendar()->isOpen(new \DateTimeImmutable()) ? $this->queue->secondsUntilNextDue() : null;

        return max(1, min(self::MAX_IDLE_SECONDS, $remaining, $next ?? self::MAX_IDLE_SECONDS));
    }

    /**
     * @param array{id: int|string, whatsapp_number_id: int|string, queue_log_id: string, payload: string, attempts: int|string} $row
     *
     * @return bool true quando a mensagem saiu da fila (enviada, cancelada ou falha definitiva)
     */
    private function process(array $row): bool
    {
        $id         = (int) $row['id'];
        $queueLogId = (string) $row['queue_log_id'];

        try {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $number  = $this->numberRepository->find((int) $row['whatsapp_number_id']);
            $apiKey  = $number?->getApiKey();

            if (null === $number || null === $apiKey || '' === $apiKey) {
                $this->failLog($queueLogId, 'Número WhatsApp removido ou sem chave de API');
                $this->queue->delete($id);

                return true;
            }

            $campaignId = isset($payload['campaignId']) ? (int) $payload['campaignId'] : null;

            if (null !== $campaignId && CampaignActivityChecker::INACTIVE === $this->campaignActivityChecker->getStatus($campaignId)) {
                $this->queue->postpone($id, $this->minutesFromNow(self::INACTIVE_RECHECK_MIN), false);

                return false;
            }

            $message = PacedScheduler::fromPayload($payload, $apiKey);

            $this->rateLimiter->throttlePaced($message->whatsAppNumberName, $this->settings->getRatePerMinute());
            $this->handler->__invoke($message, skipHousekeeping: false, skipRateLimit: true, skipRetry: false);
            $this->queue->delete($id);

            return true;
        } catch (RecoverableMessageHandlingException $e) {
            // Campanha desativada ou número pausado por pagamento: segura sem gastar tentativa
            $minutes = null !== $this->paymentPause && isset($apiKey) && '' !== (string) $apiKey && $this->paymentPause->isPaused((string) $apiKey)
                ? max(1, intdiv($this->paymentPause->getRecheckSeconds(), 60))
                : self::INACTIVE_RECHECK_MIN;

            $this->queue->postpone($id, $this->minutesFromNow($minutes), false);

            return false;
        } catch (TransientApiException $e) {
            return $this->retryOrFail($row, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('DialogHSM: erro ao enviar mensagem de lote programado', [
                'paced_message_id' => $id,
                'error'            => $e->getMessage(),
            ]);

            return $this->retryOrFail($row, $e->getMessage());
        }
    }

    /**
     * @param array{id: int|string, queue_log_id: string, attempts: int|string} $row
     */
    private function retryOrFail(array $row, string $error): bool
    {
        $attempts = (int) $row['attempts'] + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->failLog((string) $row['queue_log_id'], $error);
            $this->queue->delete((int) $row['id']);

            return true;
        }

        $this->queue->postpone((int) $row['id'], $this->minutesFromNow(self::BACKOFF_MINUTES[$attempts - 1] ?? 4), true);

        return false;
    }

    /**
     * Mesmo encerramento de CampaignSubscriber::markQueuedLogFailed: o log 'queued' vira 'failed'
     * e perde o UUID provisório do wamid.
     */
    private function failLog(string $queueLogId, string $error): void
    {
        try {
            $log = $this->messageLogRepository->findByWamid($queueLogId);

            if (null === $log) {
                return; // log apagado: envio já cancelado
            }

            $log->setStatus(MessageLog::STATUS_FAILED);
            $log->setWamid(null);
            $log->setErrorMessage(mb_substr($error, 0, 500));
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: falha ao marcar log de lote programado como falha', [
                'queue_log_id' => $queueLogId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private function minutesFromNow(int $minutes): \DateTimeImmutable
    {
        return new \DateTimeImmutable('+'.$minutes.' minutes', new \DateTimeZone('UTC'));
    }
}
