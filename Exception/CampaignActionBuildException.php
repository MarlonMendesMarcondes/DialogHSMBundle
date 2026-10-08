<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Exception;

/**
 * Sinaliza que a ação de campanha não pôde ser montada (integração, número, fila ou
 * payload inválidos) — um problema de configuração, não do contato.
 *
 * O CampaignSubscriber converte esta exceção em fail() com intervalo de nova tentativa
 * para todos os contatos ainda não decididos do lote: nenhuma mensagem é enviada e
 * nenhum contato é consumido. A mensagem da exceção vira o motivo exibido na timeline
 * do contato e na notificação do Mautic, por isso deve ser legível para o usuário.
 */
class CampaignActionBuildException extends \RuntimeException
{
}
