<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

/**
 * Leitura e gravação da coluna campaign_lead_event_log.metadata fora do ORM.
 *
 * A coluna é do tipo Doctrine 'array' (serialize do PHP) no Mautic 5 e no 7. Versões antigas
 * do plugin gravavam JSON nela, que o Mautic lê como [] — timeline, API e tela da campanha não
 * mostravam nada. Por isso a leitura aceita os dois formatos e a gravação é sempre serialize.
 */
final class CampaignLogMetadata
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(?string $raw): array
    {
        if (null === $raw || '' === $raw) {
            return [];
        }

        $value = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($value)) {
            return $value;
        }

        $value = json_decode($raw, true);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function encode(array $metadata): string
    {
        return serialize($metadata);
    }
}
