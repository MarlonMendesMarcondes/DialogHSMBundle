<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;

/**
 * Transparentemente criptografa/descriptografa o campo api_key de WhatsAppNumber.
 *
 * Fluxo de leitura (postLoad):
 *   DB contém "ENC:<base64>|<base64>" → descriptografa em memória → snapshot UoW atualizado.
 *
 * Fluxo de escrita (prePersist / preUpdate):
 *   Entidade tem texto plano → criptografa → salva no DB.
 *   postPersist / postUpdate → restaura texto plano em memória → snapshot UoW atualizado.
 *
 * Chaves antigas (sem prefixo ENC:) continuam funcionando até a próxima gravação,
 * quando serão automaticamente criptografadas. Use o comando
 * `dialoghsm:encrypt-api-keys` para forçar a migração de todas as chaves existentes.
 */
class ApiKeyEncryptionSubscriber
{
    public const ENC_PREFIX = 'ENC:';

    /** @var \WeakMap<WhatsAppNumber, string> Guarda o texto plano durante o ciclo persist/update */
    private \WeakMap $plaintextCache;

    public function __construct(private readonly EncryptionHelper $encryptionHelper)
    {
        $this->plaintextCache = new \WeakMap();
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof WhatsAppNumber) {
            return;
        }

        $encrypted = $entity->getApiKey();
        if (!$encrypted || !str_starts_with($encrypted, self::ENC_PREFIX)) {
            return; // texto plano pré-migração ou vazio — deixa como está
        }

        $decrypted = $this->encryptionHelper->decrypt(substr($encrypted, strlen(self::ENC_PREFIX)));
        if (false === $decrypted) {
            return; // falha de descriptografia — deixa o valor original intacto
        }

        $entity->setApiKeyRaw($decrypted);

        // Atualiza o snapshot do Doctrine para que a entidade não seja considerada "suja"
        $uow      = $args->getObjectManager()->getUnitOfWork();
        $origData = $uow->getOriginalEntityData($entity);
        if (array_key_exists('apiKey', $origData)) {
            $origData['apiKey'] = $decrypted;
            $uow->setOriginalEntityData($entity, $origData);
        }
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof WhatsAppNumber) {
            return;
        }

        $this->encryptAndCache($entity);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof WhatsAppNumber) {
            return;
        }

        $this->encryptAndCache($entity);

        // Reconstrói o changeset após modificar o campo
        $em = $args->getObjectManager();
        $em->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $em->getClassMetadata(WhatsAppNumber::class),
            $entity
        );
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof WhatsAppNumber) {
            $this->restorePlaintext($entity, $args->getObjectManager());
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof WhatsAppNumber) {
            $this->restorePlaintext($entity, $args->getObjectManager());
        }
    }

    /**
     * Criptografa a API key se ainda estiver em texto plano, guardando o original no cache.
     */
    private function encryptAndCache(WhatsAppNumber $entity): void
    {
        $plain = $entity->getApiKey();
        if (!$plain || str_starts_with($plain, self::ENC_PREFIX)) {
            return; // vazia ou já criptografada
        }

        $this->plaintextCache[$entity] = $plain;
        $entity->setApiKeyRaw(self::ENC_PREFIX.$this->encryptionHelper->encrypt($plain));
    }

    /**
     * Após o flush, restaura o texto plano em memória e sincroniza o snapshot do UoW.
     */
    private function restorePlaintext(WhatsAppNumber $entity, object $em): void
    {
        if (!isset($this->plaintextCache[$entity])) {
            return;
        }

        $plain = $this->plaintextCache[$entity];
        unset($this->plaintextCache[$entity]);

        $entity->setApiKeyRaw($plain);

        // Snapshot deve corresponder ao texto plano para a entidade não ficar "suja"
        $uow      = $em->getUnitOfWork();
        $origData = $uow->getOriginalEntityData($entity);
        if (array_key_exists('apiKey', $origData)) {
            $origData['apiKey'] = $plain;
            $uow->setOriginalEntityData($entity, $origData);
        }
    }
}
