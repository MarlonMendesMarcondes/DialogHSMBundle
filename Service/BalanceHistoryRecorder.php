<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberBalanceHistory;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberBalanceHistoryRepository;

/**
 * Grava uma linha de histórico quando detecta uma NOVA recarga (carga) de
 * saldo — comparando last_renewal_date (vindo da Partner API) com a última
 * data já registrada para o número. Não gera 1 linha por execução do cron;
 * só cresce quando o cliente de fato recarrega.
 */
class BalanceHistoryRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function recordIfNewRecharge(
        WhatsAppNumber $number,
        ?string $lastRenewalDate,
        ?float $lastRenewalAmount,
        ?float $currentBalance,
        ?string $currency
    ): void {
        if (null === $lastRenewalDate || null === $number->getId()) {
            return;
        }

        try {
            $rechargeDate = new \DateTime($lastRenewalDate);
            // Trunca microssegundos: o MySQL DATETIME não os armazena, então
            // comparar direto contra o valor recém-parseado da API (que traz
            // milissegundos, ex. "13:51:11.821") faria toda checagem ver a
            // data recém-lida como "mais recente" que a mesma data já salva.
            $rechargeDate->setTime(
                (int) $rechargeDate->format('H'),
                (int) $rechargeDate->format('i'),
                (int) $rechargeDate->format('s')
            );
        } catch (\Throwable) {
            return;
        }

        $repository = $this->getRepository();
        $latest     = $repository->findLatestForNumber($number->getId());

        if (null !== $latest && $latest->getRechargeDate() >= $rechargeDate) {
            return;
        }

        $history = new WhatsAppNumberBalanceHistory();
        $history->setWhatsAppNumberId($number->getId());
        $history->setRechargeDate($rechargeDate);
        $history->setRechargeAmount($lastRenewalAmount);
        $history->setBalanceAtSync($currentBalance);
        $history->setCurrency($currency);
        $history->setDateAdded(new \DateTime());

        $this->entityManager->persist($history);
        $this->entityManager->flush();
    }

    private function getRepository(): WhatsAppNumberBalanceHistoryRepository
    {
        $repository = $this->entityManager->getRepository(WhatsAppNumberBalanceHistory::class);
        \assert($repository instanceof WhatsAppNumberBalanceHistoryRepository);

        return $repository;
    }
}
