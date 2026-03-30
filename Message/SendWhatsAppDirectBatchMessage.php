<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Message;

/**
 * Mensagem de lote para o transporte whatsapp_direct (Redis Streams).
 *
 * Publicada uma única vez por execução de campanha. O worker consome o lote
 * inteiro em sequência, aplicando batch/delay da mesma forma que o modo inline.
 *
 * Nomenclatura:
 *   "Direct" → vai pelo transporte whatsapp_direct (Redis), não pelo RabbitMQ
 *   "Batch"  → carrega múltiplos itens com controle de velocidade (batchLimit + sendDelay)
 *
 * Não confundir com o "batch" do consumer --mode=batch (RabbitMQ, horário comercial).
 *
 * Semântica de throttle:
 *   batchLimit=10, sendDelay=2 → envia 10, aguarda 2s, envia 10, aguarda 2s...
 *   batchLimit=0,  sendDelay=2 → aguarda 2s entre cada mensagem individualmente
 *   batchLimit=0,  sendDelay=0 → envia sem pausa
 */
class SendWhatsAppDirectBatchMessage
{
    /**
     * @param SendWhatsAppMessage[] $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $batchLimit,
        public readonly int $sendDelay,
    ) {
    }
}
