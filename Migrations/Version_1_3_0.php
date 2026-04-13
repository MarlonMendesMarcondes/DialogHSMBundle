<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Marcador de versão para a remoção da infraestrutura de webhook (v1.3.1).
 *
 * A partir desta versão o Mautic rastreia apenas o resultado do envio (sent/failed/dlq).
 * Confirmações de entrega (delivered/read) ficam no Chatwoot, que recebe os callbacks
 * do 360dialog diretamente.
 *
 * Removidos em v1.3.1:
 *   - WebhookController e rota mautic_dialoghsm_webhook
 *   - Coluna webhook_token em dialog_hsm_numbers
 *   - Métodos findByWebhookToken() e findByWamid() de webhook
 *
 * Não há alteração de schema nesta migração — a coluna webhook_token já foi
 * removida pela Version_1_1_1. Esta migration serve apenas como marco de auditoria.
 */
class Version_1_3_0 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        // Sem alteração de schema — aplica sempre como marcador de versão
        return true;
    }

    protected function up(): void
    {
        // Nenhuma alteração de schema necessária.
        // Esta migration é um marco de auditoria documentando a remoção do webhook em v1.3.1.
    }
}
