<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<MessageLog>
 */
class MessageLogRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'dhml';
    }

    /**
     * @param array{status?:string, dateFrom?:string, dateTo?:string, senderName?:string, contact?:string} $filters
     *
     * @return MessageLog[]
     */
    public function getLogs(int $start = 0, int $limit = 50, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('dhml');
        $this->applyFilters($qb, $filters);

        return $qb
            ->orderBy('dhml.dateSent', 'DESC')
            ->addOrderBy('dhml.id', 'DESC')
            ->setFirstResult($start)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{status?:string, dateFrom?:string, dateTo?:string, senderName?:string, contact?:string} $filters
     */
    public function countAll(array $filters = []): int
    {
        $qb = $this->createQueryBuilder('dhml')
            ->select('COUNT(dhml.id)');
        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Retorna lista ordenada de template names distintos presentes nos logs.
     *
     * @return string[]
     */
    public function getDistinctTemplateNames(): array
    {
        $rows = $this->createQueryBuilder('dhml')
            ->select('DISTINCT dhml.templateName')
            ->where('dhml.templateName IS NOT NULL')
            ->andWhere("dhml.templateName != ''")
            ->orderBy('dhml.templateName', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'templateName');
    }

    /**
     * Retorna lista ordenada de sender names distintos presentes nos logs.
     *
     * @return string[]
     */
    public function getDistinctSenderNames(): array
    {
        $rows = $this->createQueryBuilder('dhml')
            ->select('DISTINCT dhml.senderName')
            ->where('dhml.senderName IS NOT NULL')
            ->andWhere("dhml.senderName != ''")
            ->orderBy('dhml.senderName', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'senderName');
    }

    /**
     * @param array{status?:string, dateFrom?:string, dateTo?:string, senderName?:string, contact?:string, templateName?:string} $filters
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['status'])) {
            $qb->andWhere('dhml.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['dateFrom'])) {
            $qb->andWhere('dhml.dateSent >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($filters['dateFrom'] . ' 00:00:00'));
        }

        if (!empty($filters['dateTo'])) {
            $qb->andWhere('dhml.dateSent <= :dateTo')
               ->setParameter('dateTo', new \DateTime($filters['dateTo'] . ' 23:59:59'));
        }

        if (!empty($filters['senderName'])) {
            $qb->andWhere('dhml.senderName = :senderName')
               ->setParameter('senderName', $filters['senderName']);
        }

        if (!empty($filters['contact'])) {
            $contact = $filters['contact'];
            if (ctype_digit($contact)) {
                $qb->andWhere('dhml.leadId = :leadId')
                   ->setParameter('leadId', (int) $contact);
            } else {
                $qb->andWhere('dhml.phoneNumber LIKE :phone')
                   ->setParameter('phone', '%' . $contact . '%');
            }
        }

        if (!empty($filters['templateName'])) {
            $qb->andWhere('dhml.templateName = :templateName')
               ->setParameter('templateName', $filters['templateName']);
        }
    }

    /**
     * Retorna contagens por status para o período a partir de $from.
     *
     * Nota: 'delivered' e 'read' nunca são produzidos desde v1.3.1 (webhook removido),
     * mas são incluídos no array de retorno para exibir registros históricos corretamente.
     *
     * @return array{total:int, sent:int, delivered:int, read:int, failed:int, dlq:int}
     */
    public function getStatsByPeriod(\DateTimeInterface $from): array
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();

        $rows = $conn->fetchAllAssociative(
            "SELECT status, COUNT(*) AS cnt FROM `{$tableName}` WHERE date_sent >= ? GROUP BY status",
            [$from->format('Y-m-d H:i:s')]
        );

        $stats = ['total' => 0, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0];
        foreach ($rows as $row) {
            $status       = (string) $row['status'];
            $count        = (int) $row['cnt'];
            $stats['total'] += $count;
            if (array_key_exists($status, $stats)) {
                $stats[$status] = $count;
            }
        }

        return $stats;
    }

    /**
     * Retorna envios agrupados por dia e status para os últimos N dias.
     *
     * Nota: 'delivered' e 'read' nunca são produzidos desde v1.3.1 (webhook removido),
     * mas são incluídos no array para renderizar corretamente dados históricos no gráfico.
     *
     * @return array<string, array{sent:int, delivered:int, read:int, failed:int, dlq:int}>
     */
    public function getChartData(int $days = 7): array
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();
        $from      = (new \DateTime())->modify("-{$days} days")->setTime(0, 0, 0);

        $rows = $conn->fetchAllAssociative(
            "SELECT DATE(date_sent) AS day, status, COUNT(*) AS cnt
             FROM `{$tableName}`
             WHERE date_sent >= ?
             GROUP BY DATE(date_sent), status
             ORDER BY day ASC",
            [$from->format('Y-m-d H:i:s')]
        );

        $empty = ['queued' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0];
        $data  = [];

        // Preenche todos os dias do período com zeros para o gráfico ficar contínuo
        for ($i = $days - 1; $i >= 0; --$i) {
            $day        = (new \DateTime())->modify("-{$i} days")->format('Y-m-d');
            $data[$day] = $empty;
        }

        foreach ($rows as $row) {
            $day    = (string) $row['day'];
            $status = (string) $row['status'];
            if (isset($data[$day]) && array_key_exists($status, $data[$day])) {
                $data[$day][$status] = (int) $row['cnt'];
            }
        }

        return $data;
    }

    /**
     * Localiza um MessageLog pelo wamid (inclui UUIDs temporários de logs queued).
     * Retorna o registro mais recente caso haja duplicatas.
     */
    public function findByWamid(string $wamid): ?MessageLog
    {
        $results = $this->createQueryBuilder('dhml')
            ->andWhere('dhml.wamid = :wamid')
            ->setParameter('wamid', $wamid)
            ->orderBy('dhml.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $results[0] ?? null;
    }

    /**
     * Tamanho de cada lote no prune(). Limita o lock por statement a ~1k linhas,
     * evitando travamento prolongado em tabelas grandes.
     */
    private const PRUNE_BATCH_SIZE = 1_000;

    /**
     * Remove registros antigos por dois critérios aplicados em sequência:
     *
     *  1. Por idade (primário/padrão): remove registros com date_sent anterior a $maxDays dias.
     *     Desabilitado somente quando $maxDays = 0.
     *
     *  2. Por contagem (segurança/opcional): se após a limpeza por idade a tabela ainda
     *     ultrapassar $maxRecords, remove os mais antigos até o limite.
     *     Desabilitado quando $maxRecords = 0 (padrão).
     *
     * Ambas as deleções usam lotes de PRUNE_BATCH_SIZE para evitar table locks longos.
     * O loop de idade continua enquanto o lote estiver cheio (indica que há mais registros).
     * O loop de contagem continua até zerar o excesso ou até o banco retornar 0 linhas.
     *
     * @param int $maxRecords Limite máximo de registros. 0 = desabilitado (padrão).
     * @param int $maxDays    Registros mais antigos que este número de dias são removidos. 0 = desabilitado.
     */
    public function prune(int $maxRecords = 0, int $maxDays = 30): void
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();

        // Passo 1: deletar por idade em lotes
        if ($maxDays > 0) {
            do {
                $deleted = (int) $conn->executeStatement(
                    "DELETE FROM `{$tableName}` WHERE date_sent < DATE_SUB(NOW(), INTERVAL {$maxDays} DAY) LIMIT ".self::PRUNE_BATCH_SIZE
                );
            } while ($deleted === self::PRUNE_BATCH_SIZE);
        }

        // Passo 2: deletar por contagem em lotes (desabilitado quando maxRecords = 0)
        if ($maxRecords <= 0) {
            return;
        }

        $count = (int) $conn->fetchOne("SELECT COUNT(*) FROM `{$tableName}`");

        if ($count <= $maxRecords) {
            return;
        }

        $toDelete = $count - $maxRecords;

        while ($toDelete > 0) {
            $batch   = min(self::PRUNE_BATCH_SIZE, $toDelete);
            $deleted = (int) $conn->executeStatement(
                "DELETE FROM `{$tableName}` ORDER BY date_sent ASC, id ASC LIMIT {$batch}"
            );

            if (0 === $deleted) {
                break; // tabela esvaziou antes do previsto
            }

            $toDelete -= $deleted;
        }
    }
}
