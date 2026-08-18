<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMPartnerApi;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Service\BalanceAlertService;
use MauticPlugin\DialogHSMBundle\Service\BalanceHistoryRecorder;
use MauticPlugin\DialogHSMBundle\Service\PartnerConfigProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Consulta o saldo de todos os números configurados via 360dialog Partner API
 * e atualiza o cache local (WhatsAppNumber::balance/balanceCurrency/balanceUpdatedAt).
 *
 * Pensado para rodar via cron (ex.: a cada hora). Respeita o rate limit da Partner
 * API — 10 req/hora por canal, 200 req/hora por WABA — espaçando as chamadas em
 * MIN_INTERVAL_SECONDS (3600/200 = 18s), suficiente mesmo se todos os números
 * compartilharem a mesma conta parceira.
 */
#[AsCommand(
    name: 'dialoghsm:balance:sync',
    description: 'Consulta e atualiza o saldo dos números WhatsApp via 360dialog Partner API'
)]
class SyncChannelBalanceCommand extends Command
{
    private const MIN_INTERVAL_SECONDS = 18;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DialogHSMPartnerApi $partnerApi,
        private readonly PartnerConfigProvider $partnerConfigProvider,
        private readonly BalanceAlertService $balanceAlertService,
        private readonly BalanceHistoryRecorder $balanceHistoryRecorder,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('DialogHSM — Sincronização de Saldo (Partner API)');

        $partnerId     = $this->partnerConfigProvider->getPartnerId();
        $partnerApiKey = $this->partnerConfigProvider->getPartnerApiKey();

        if (empty($partnerId) || empty($partnerApiKey)) {
            $io->error('Partner ID / Partner API Key não configurados em Integrações > 360dialog WhatsApp > Config.');

            return Command::FAILURE;
        }

        $numbers = $this->entityManager->getRepository(WhatsAppNumber::class)->findAll();

        $eligible = array_values(array_filter(
            $numbers,
            static fn (WhatsAppNumber $n): bool => !empty($n->getClientId()) && !empty($n->getChannelId())
        ));

        $skipped = count($numbers) - count($eligible);
        if ($skipped > 0) {
            $io->note(sprintf('%d número(s) ignorado(s) por falta de Client ID/Channel ID.', $skipped));
        }

        if (empty($eligible)) {
            $io->warning('Nenhum número com Client ID e Channel ID configurados. Nada a fazer.');

            return Command::SUCCESS;
        }

        $updated = 0;
        $failed  = 0;

        foreach ($eligible as $index => $number) {
            if ($index > 0) {
                sleep(self::MIN_INTERVAL_SECONDS);
            }

            $result = $this->partnerApi->getChannelBalance(
                $partnerId,
                $partnerApiKey,
                $number->getClientId(),
                $number->getChannelId()
            );

            if ($result['success']) {
                $number->setBalanceInfo($result['balance'], $result['currency'], new \DateTime());
                $number->setBalanceUsageSnapshot($result['usage']);
                $this->entityManager->persist($number);
                $this->entityManager->flush();

                $this->balanceAlertService->checkAndNotify($number, $result['balance'], $result['currency']);

                $this->balanceHistoryRecorder->recordIfNewRecharge(
                    $number,
                    $result['last_renewal_date'],
                    $result['last_renewal_amount'],
                    $result['balance'],
                    $result['currency']
                );

                $io->text(sprintf(
                    '  <fg=green>OK</> %s — %.2f %s',
                    $number->getName() ?? $number->getId(),
                    $result['balance'] ?? 0.0,
                    strtoupper((string) $result['currency'])
                ));
                ++$updated;
            } else {
                $this->logger->error('DialogHSM: falha ao sincronizar saldo', [
                    'number' => $number->getName(),
                    'error'  => $result['error'],
                ]);
                $io->text(sprintf('  <fg=red>FAIL</> %s — %s', $number->getName() ?? $number->getId(), $result['error']));
                ++$failed;
            }
        }

        $io->newLine();
        $io->success(sprintf('%d atualizado(s), %d falha(s), %d ignorado(s).', $updated, $failed, $skipped));

        return $failed > 0 && 0 === $updated ? Command::FAILURE : Command::SUCCESS;
    }
}
