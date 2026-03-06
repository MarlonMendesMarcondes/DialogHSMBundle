<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Adds DialogHSM tracking columns to the leads table and registers them
 * as native Mautic lead fields so they appear in the contact edit form,
 * segments and token lists.
 *
 * isApplicable() returns true when EITHER the columns are missing from leads
 * OR the lead_fields entries have not been created yet — allowing this
 * migration to be re-evaluated safely after a partial apply.
 * up() uses IF NOT EXISTS / WHERE NOT EXISTS guards so it is fully idempotent.
 */
class Version_1_0_4 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('leads'));

            if (!$table->hasColumn('dialoghsm_status')) {
                return true;
            }
        } catch (SchemaException) {
            return false;
        }

        // Columns exist — check whether lead_fields entries are still missing.
        $count = (int) $this->entityManager->getConnection()->fetchOne(
            sprintf(
                "SELECT COUNT(*) FROM `%s` WHERE alias = 'dialoghsm_status'",
                $this->concatPrefix('lead_fields')
            )
        );

        return $count === 0;
    }

    protected function up(): void
    {
        $leadsTable  = $this->concatPrefix('leads');
        $fieldsTable = $this->concatPrefix('lead_fields');

        // ------------------------------------------------------------------
        // 1. Tracking columns on leads (safe to run even if already present)
        // ------------------------------------------------------------------
        $this->addSql("ALTER TABLE `{$leadsTable}`
            ADD COLUMN IF NOT EXISTS `dialoghsm_status`        VARCHAR(50)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `dialoghsm_last_response` VARCHAR(255) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `dialoghsm_last_sent`     DATETIME     DEFAULT NULL
        ");

        // ------------------------------------------------------------------
        // 2. Register as native Mautic lead fields (group: Core)
        //    column_is_not_created = 0 → tells Mautic the column already exists.
        //    WHERE NOT EXISTS guards against duplicate inserts.
        // ------------------------------------------------------------------
        $columns = '(label, alias, type, field_group, default_value,
                      is_required, is_fixed, is_visible, is_short_visible,
                      is_listable, is_publicly_updatable, is_unique_identifer,
                      is_index, char_length_limit, field_order, object,
                      properties, column_is_not_created, original_is_published_value,
                      is_published, date_added)';

        $this->addSql("INSERT INTO `{$fieldsTable}` {$columns}
            SELECT 'DialogHSM Status', 'dialoghsm_status', 'text', 'core', NULL,
                   0, 0, 1, 0, 1, 0, 0, 0, 50, 200, 'lead',
                   'a:0:{}', 0, 0, 1, NOW()
            FROM DUAL
            WHERE NOT EXISTS (
                SELECT 1 FROM `{$fieldsTable}` WHERE alias = 'dialoghsm_status'
            )
        ");

        $this->addSql("INSERT INTO `{$fieldsTable}` {$columns}
            SELECT 'DialogHSM Last Response', 'dialoghsm_last_response', 'text', 'core', NULL,
                   0, 0, 1, 0, 1, 0, 0, 0, 191, 201, 'lead',
                   'a:0:{}', 0, 0, 1, NOW()
            FROM DUAL
            WHERE NOT EXISTS (
                SELECT 1 FROM `{$fieldsTable}` WHERE alias = 'dialoghsm_last_response'
            )
        ");

        $this->addSql("INSERT INTO `{$fieldsTable}` {$columns}
            SELECT 'DialogHSM Last Sent', 'dialoghsm_last_sent', 'datetime', 'core', NULL,
                   0, 0, 1, 0, 1, 0, 0, 0, NULL, 202, 'lead',
                   'a:0:{}', 0, 0, 1, NOW()
            FROM DUAL
            WHERE NOT EXISTS (
                SELECT 1 FROM `{$fieldsTable}` WHERE alias = 'dialoghsm_last_sent'
            )
        ");
    }
}