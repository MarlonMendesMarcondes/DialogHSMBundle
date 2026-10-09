<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Índices de dialog_hsm_message_log para as consultas quentes:
 * - campaign_event_lead_idx: findByCampaignEventAndLead (toda reexecução de ação de campanha e
 *   a proteção contra envio duplicado do consumidor);
 * - campaign_id_idx: relatórios e auditoria por campanha. Já era criado pela Version_1_1_0, mas
 *   ela só roda se a coluna campaign_id faltar — em bancos onde a coluna já existia o índice
 *   nunca foi criado;
 * - whatsapp_message_id_idx: estatísticas por mensagem de broadcast/Marketing Message;
 * - date_replied_idx: contagem de respostas do dashboard.
 *
 * Aplica enquanto faltar qualquer um deles (não pela existência de coluna).
 */
class Version_1_4_9 extends AbstractMigration
{
    public const INDEXES = [
        'campaign_event_lead_idx' => '`campaign_event_id`, `lead_id`',
        'campaign_id_idx'         => '`campaign_id`',
        'whatsapp_message_id_idx' => '`whatsapp_message_id`',
        'date_replied_idx'        => '`date_replied`',
    ];

    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table    = $this->concatPrefix('dialog_hsm_message_log');
            $existing = array_column(
                $this->entityManager->getConnection()->fetchAllAssociative("SHOW INDEX FROM `{$table}`"),
                'Key_name'
            );

            return [] !== array_diff(array_keys(self::INDEXES), $existing);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function up(): void
    {
        $table = $this->concatPrefix('dialog_hsm_message_log');

        foreach (self::INDEXES as $name => $columns) {
            $this->addSql("ALTER TABLE `{$table}` ADD INDEX IF NOT EXISTS `{$name}` ({$columns})");
        }
    }
}
