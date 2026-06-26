<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\LeadBundle\Entity\TimelineTrait;

/**
 * @extends CommonRepository<MessageLog>
 */
class MessageLogRepository extends CommonRepository
{
    use TimelineTrait;

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
     * @return array<string, int>
     */
    public function getStatsByMessageId(int $whatsappMessageId): array
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();

        $rows = $conn->fetchAllAssociative(
            "SELECT status, COUNT(*) AS cnt FROM `{$tableName}` WHERE whatsapp_message_id = ? GROUP BY status",
            [$whatsappMessageId]
        );

        $stats = ['total' => 0, 'queued' => 0, 'pending_webhook' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0];
        foreach ($rows as $row) {
            $status        = (string) $row['status'];
            $count         = (int) $row['cnt'];
            $stats['total'] += $count;
            if (array_key_exists($status, $stats)) {
                $stats[$status] = $count;
            }
        }

        $stats['sent']      += $stats['delivered'] + $stats['read'];
        $stats['delivered'] += $stats['read'];

        return $stats;
    }

    public function getStatsByPeriod(\DateTimeInterface $from): array
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();

        $rows = $conn->fetchAllAssociative(
            "SELECT status, COUNT(*) AS cnt FROM `{$tableName}` WHERE date_sent >= ? GROUP BY status",
            [$from->format('Y-m-d H:i:s')]
        );

        $stats = ['total' => 0, 'queued' => 0, 'pending_webhook' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0];
        foreach ($rows as $row) {
            $status       = (string) $row['status'];
            $count        = (int) $row['cnt'];
            $stats['total'] += $count;
            if (array_key_exists($status, $stats)) {
                $stats[$status] = $count;
            }
        }

        // Funil cumulativo: cada status inclui mensagens que avançaram além dele
        $stats['sent']      += $stats['delivered'] + $stats['read'];
        $stats['delivered'] += $stats['read'];

        return $stats;
    }

    /**
     * Retorna envios agrupados por dia e status para os últimos N dias.
     *
     * @return array<string, array{sent:int, delivered:int, read:int, failed:int, dlq:int}>
     */
    public function getChartData(int $days = 7, string $timezone = 'UTC'): array
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();
        $tz        = new \DateTimeZone($timezone);
        $now       = new \DateTime('now', $tz);
        $from      = (clone $now)->modify("-{$days} days")->setTime(0, 0, 0);

        // Offset UTC do timezone configurado no Mautic (ex: -3h → '-03:00')
        // Necessário para que DATE() agrupe por dia local, não por dia UTC.
        $offsetSeconds = $tz->getOffset($now);
        $sign          = $offsetSeconds >= 0 ? '+' : '-';
        $abs           = abs($offsetSeconds);
        $utcOffset     = sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), ($abs % 3600) / 60);

        $rows = $conn->fetchAllAssociative(
            "SELECT DATE(date_sent + INTERVAL ? SECOND) AS day, status, COUNT(*) AS cnt
             FROM `{$tableName}`
             WHERE date_sent >= ?
             GROUP BY DATE(date_sent + INTERVAL ? SECOND), status
             ORDER BY day ASC",
            [$offsetSeconds, $from->format('Y-m-d H:i:s'), $offsetSeconds]
        );

        $empty = ['queued' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0];
        $data  = [];

        // Preenche todos os dias do período com zeros para o gráfico ficar contínuo
        for ($i = $days - 1; $i >= 0; --$i) {
            $day        = (clone $now)->modify("-{$i} days")->format('Y-m-d');
            $data[$day] = $empty;
        }

        foreach ($rows as $row) {
            $day    = (string) $row['day'];
            $status = (string) $row['status'];
            if (isset($data[$day]) && array_key_exists($status, $data[$day])) {
                $data[$day][$status] = (int) $row['cnt'];
            }
        }

        // Funil cumulativo por dia — cada status inclui mensagens que avançaram além dele,
        // consistente com getStatsByPeriod() e os cards do dashboard. NÃO REMOVER.
        foreach ($data as &$day) {
            $day['sent']      += $day['delivered'] + $day['read'];
            $day['delivered'] += $day['read'];
        }
        unset($day);

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

    public function findByCampaignEventAndLead(int $campaignEventId, int $leadId): ?MessageLog
    {
        $results = $this->createQueryBuilder('dhml')
            ->andWhere('dhml.campaignEventId = :eventId')
            ->andWhere('dhml.leadId = :leadId')
            ->setParameter('eventId', $campaignEventId)
            ->setParameter('leadId', $leadId)
            ->orderBy('dhml.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $results[0] ?? null;
    }

    public function hasLogForLead(int $leadId): bool
    {
        $count = (int) $this->createQueryBuilder('dhml')
            ->select('COUNT(dhml.id)')
            ->andWhere('dhml.leadId = :leadId')
            ->setParameter('leadId', $leadId)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Retorna o HSM mais recente enviado ao lead que ainda não foi marcado como respondido,
     * dentro da janela de tempo informada. Quando $before é fornecido, limita apenas a HSMs
     * enviados antes desse timestamp — evita atribuir resposta a HSM enviado após a mensagem
     * de entrada (Scenario B).
     */
    public function findMostRecentForLead(int $leadId, \DateTimeInterface $since, ?\DateTimeInterface $before = null): ?MessageLog
    {
        $qb = $this->createQueryBuilder('dhml')
            ->andWhere('dhml.leadId = :leadId')
            ->andWhere('dhml.dateSent >= :since')
            ->andWhere('dhml.dateReplied IS NULL')
            ->setParameter('leadId', $leadId)
            ->setParameter('since', $since);

        if ($before !== null) {
            $qb->andWhere('dhml.dateSent < :before')
               ->setParameter('before', $before);
        }

        $results = $qb
            ->orderBy('dhml.dateSent', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $results[0] ?? null;
    }

    /**
     * Conta respostas recebidas (date_replied preenchido) a partir de uma data.
     */
    public function countReplied(\DateTime $since): int
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();

        return (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM `{$tableName}` WHERE date_replied IS NOT NULL AND date_replied >= ?",
            [$since->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Tamanho de cada lote no prune() e deleteQueued(). Limita o lock por statement a ~1k linhas,
     * evitando travamento prolongado em tabelas grandes.
     */
    private const PRUNE_BATCH_SIZE = 1_000;

    /**
     * Deleta registros com status 'queued', opcionalmente filtrado por template e/ou número remetente.
     * Usa lotes de PRUNE_BATCH_SIZE para evitar table locks em volumes grandes.
     *
     * @return int Total de registros deletados
     */
    public function deleteQueued(?string $templateName = null, ?string $senderName = null, ?int $campaignId = null): int
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();
        $meta      = $this->getEntityManager()->getClassMetadata(MessageLog::class);

        $where  = ['status = :status'];
        $params = ['status' => MessageLog::STATUS_QUEUED];
        $types  = ['status' => \PDO::PARAM_STR];

        if (null !== $templateName) {
            $col                    = $meta->getColumnName('templateName');
            $where[]                = "`{$col}` = :templateName";
            $params['templateName'] = $templateName;
            $types['templateName']  = \PDO::PARAM_STR;
        }

        if (null !== $senderName) {
            $col                  = $meta->getColumnName('senderName');
            $where[]              = "`{$col}` = :senderName";
            $params['senderName'] = $senderName;
            $types['senderName']  = \PDO::PARAM_STR;
        }

        if (null !== $campaignId) {
            $col                   = $meta->getColumnName('campaignId');
            $where[]               = "`{$col}` = :campaignId";
            $params['campaignId']  = $campaignId;
            $types['campaignId']   = \PDO::PARAM_INT;
        }

        $whereClause = implode(' AND ', $where);
        $total       = 0;

        do {
            $deleted = (int) $conn->executeStatement(
                "DELETE FROM `{$tableName}` WHERE {$whereClause} LIMIT " . self::PRUNE_BATCH_SIZE,
                $params,
                $types
            );
            $total += $deleted;
        } while ($deleted === self::PRUNE_BATCH_SIZE);

        return $total;
    }

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

    /**
     * Retorna todos os eventos de timeline de um lead em UMA query,
     * divididos por status em PHP. Evita os 10 round-trips do método
     * anterior (2 por status × 5 status = COUNT + SELECT cada).
     *
     * @return array<string, array{total: int, results: array[]}>
     */
    public function getAllLogsForTimeline(int $leadId, array $options): array
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();

        $qb = $conn->createQueryBuilder()
            ->select('ml.id, ml.template_name, ml.phone_number, ml.status, ml.error_message, ml.webhook_error_code, ml.campaign_id, ml.sender_name, ml.date_sent, ml.date_delivered, ml.date_read, ml.lead_id')
            ->from($tableName, 'ml')
            ->where('ml.lead_id = :leadId')
            ->setParameter('leadId', $leadId);

        if (!empty($options['fromDate'])) {
            $qb->andWhere('ml.date_sent >= :dateFrom')
               ->setParameter('dateFrom', $options['fromDate']->format('Y-m-d H:i:s'));
        }
        if (!empty($options['toDate'])) {
            $qb->andWhere('ml.date_sent <= :dateTo')
               ->setParameter('dateTo', $options['toDate']->format('Y-m-d H:i:s'));
        }

        $allRows = $qb->executeQuery()->fetchAllAssociative();

        // Converter colunas de data para \DateTime (igual ao TimelineTrait)
        foreach ($allRows as &$row) {
            foreach (['date_sent', 'date_delivered', 'date_read'] as $col) {
                if (!empty($row[$col]) && !($row[$col] instanceof \DateTimeInterface)) {
                    $dt       = new \Mautic\CoreBundle\Helper\DateTimeHelper($row[$col], 'Y-m-d H:i:s', 'UTC');
                    $row[$col] = $dt->getLocalDateTime();
                }
            }
        }
        unset($row);

        // Separar em buckets por status (mesmas regras do método anterior)
        $buckets = [
            MessageLog::STATUS_SENT      => [],
            MessageLog::STATUS_DELIVERED => [],
            MessageLog::STATUS_READ      => [],
            MessageLog::STATUS_FAILED    => [],
            MessageLog::STATUS_DLQ       => [],
        ];

        foreach ($allRows as $row) {
            if (in_array($row['status'], ['sent', 'delivered', 'read'], true)) {
                $buckets[MessageLog::STATUS_SENT][] = $row;
            }
            if (!empty($row['date_delivered'])) {
                $buckets[MessageLog::STATUS_DELIVERED][] = $row;
            }
            if (!empty($row['date_read'])) {
                $buckets[MessageLog::STATUS_READ][] = $row;
            }
            if (MessageLog::STATUS_FAILED === $row['status']) {
                $buckets[MessageLog::STATUS_FAILED][] = $row;
            }
            if (MessageLog::STATUS_DLQ === $row['status']) {
                $buckets[MessageLog::STATUS_DLQ][] = $row;
            }
        }

        // Ordenar cada bucket pelo timestamp relevante e paginar
        $limit      = !empty($options['limit']) ? (int) $options['limit'] : null;
        $start      = !empty($options['start']) ? (int) $options['start'] : 0;
        $isPaginated = !empty($options['paginated']);

        $result = [];
        foreach ($buckets as $status => $rows) {
            usort($rows, static function (array $a, array $b) use ($status): int {
                $colA = match ($status) {
                    MessageLog::STATUS_DELIVERED => $a['date_delivered'],
                    MessageLog::STATUS_READ      => $a['date_read'],
                    default                      => $a['date_sent'],
                };
                $colB = match ($status) {
                    MessageLog::STATUS_DELIVERED => $b['date_delivered'],
                    MessageLog::STATUS_READ      => $b['date_read'],
                    default                      => $b['date_sent'],
                };

                // \DateTime ou null — DESC
                if ($colA === $colB) {
                    return 0;
                }
                if (null === $colA) {
                    return 1;
                }
                if (null === $colB) {
                    return -1;
                }

                return $colB <=> $colA;
            });

            $total   = count($rows);
            $sliced  = $limit !== null ? array_slice($rows, $start, $limit) : array_slice($rows, $start);

            $result[$status] = $isPaginated
                ? ['total' => $total, 'results' => $sliced]
                : $sliced;
        }

        return $result;
    }

    /**
     * Retorna os últimos N disparos agrupados por (template, campaign_id, data).
     *
     * As colunas são cumulativas (visão de funil):
     *   sent_plus      = status IN ('sent', 'delivered', 'read')  — chegou ao menos em "sent"
     *   delivered_plus = status IN ('delivered', 'read')          — chegou ao menos em "delivered"
     *   read_count     = status = 'read'
     *   replied_count  = date_replied IS NOT NULL (exato via context.id ou aproximado por telefone+tempo)
     *
     * @return array<int, array{template_name: string, campaign_id: int|null, date: string, total: int, sent_plus: int, delivered_plus: int, read_count: int, replied_count: int, failed: int, dlq: int}>
     */
    public function getGroupedDispatches(int $limit = 50): array
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();
        $msgTable  = $this->getEntityManager()->getClassMetadata(WhatsAppMessage::class)->getTableName();

        return $conn->fetchAllAssociative(
            "SELECT
                ml.template_name,
                ml.campaign_id,
                ml.whatsapp_message_id,
                wm.name                                  AS whatsapp_message_name,
                DATE(ml.date_sent)                       AS date,
                COUNT(*)                                 AS total,
                SUM(ml.status IN ('sent', 'delivered', 'read'))  AS sent_plus,
                SUM(ml.status IN ('delivered', 'read'))           AS delivered_plus,
                SUM(ml.status = 'read')                          AS read_count,
                SUM(ml.date_replied IS NOT NULL)                  AS replied_count,
                SUM(ml.status = 'failed')                        AS failed,
                SUM(ml.status = 'dlq')                           AS dlq
             FROM `{$tableName}` ml
             LEFT JOIN `{$msgTable}` wm ON wm.id = ml.whatsapp_message_id
             GROUP BY ml.template_name, ml.campaign_id, ml.whatsapp_message_id, DATE(ml.date_sent)
             ORDER BY MAX(ml.date_sent) DESC
             LIMIT {$limit}"
        );
    }
}
