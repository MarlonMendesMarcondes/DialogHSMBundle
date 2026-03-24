<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Registers DialogHSM tracking fields in Mautic's custom field registry (lead_fields).
 *
 * The columns already exist in the leads table (added by Version_1_0_4).
 * This migration creates the lead_fields entries so the fields appear
 * in the contact editing form and custom field management.
 */
class Version_1_0_5 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            // Prerequisite: columns must exist in leads table (Version_1_0_4 ran)
            $table = $schema->getTable($this->concatPrefix('leads'));
            if (!$table->hasColumn('dialoghsm_status')) {
                return false;
            }

            // Check if the fields are already registered in lead_fields
            $count = (int) $this->entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM `' . $this->concatPrefix('lead_fields') . '` WHERE `alias` = ?',
                ['dialoghsm_status']
            );

            return $count === 0;
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $table    = $this->concatPrefix('lead_fields');
        $now      = date('Y-m-d H:i:s');
        $maxOrder = (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COALESCE(MAX(field_order), 0) FROM `{$table}` WHERE object = 'lead'"
        );

        $fields = [
            ['dialoghsm_status',        'WhatsApp Status',        'text',     $maxOrder + 1],
            ['dialoghsm_last_response', 'WhatsApp Last Response', 'text',     $maxOrder + 2],
            ['dialoghsm_last_sent',     'WhatsApp Last Sent',     'datetime', $maxOrder + 3],
        ];

        foreach ($fields as [$alias, $label, $type, $order]) {
            $this->addSql("
                INSERT INTO `{$table}`
                    (is_published, date_added, label, alias, type, field_group, object,
                     is_required, is_fixed, is_visible, is_short_visible, is_listable,
                     is_publicly_updatable, is_unique_identifer, is_index,
                     field_order, column_is_not_created, original_is_published_value)
                SELECT 1, '{$now}', '{$label}', '{$alias}', '{$type}', 'core', 'lead',
                       0, 0, 1, 0, 1, 0, 0, 0, {$order}, 0, 1
                FROM DUAL
                WHERE NOT EXISTS (
                    SELECT 1 FROM `{$table}` WHERE alias = '{$alias}'
                )
            ");
        }
    }
}
