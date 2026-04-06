<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\EventListener\ApiKeyEncryptionSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Os event args do Doctrine são "final" — não podem ser mockados com createMock().
 * Criamos instâncias reais passando um EntityManagerInterface mockado como dependência.
 */
class ApiKeyEncryptionSubscriberTest extends TestCase
{
    private EncryptionHelper&MockObject $encryption;
    private ApiKeyEncryptionSubscriber  $subscriber;

    protected function setUp(): void
    {
        $this->encryption = $this->createMock(EncryptionHelper::class);
        $this->subscriber = new ApiKeyEncryptionSubscriber($this->encryption);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeNumber(?string $apiKey = null): WhatsAppNumber
    {
        $n = new WhatsAppNumber();
        if (null !== $apiKey) {
            $n->setApiKey($apiKey);
        }

        return $n;
    }

    private function makeEm(?UnitOfWork $uow = null): EntityManagerInterface&MockObject
    {
        $uow ??= $this->createMock(UnitOfWork::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getClassMetadata')->willReturn($this->createMock(ClassMetadata::class));

        return $em;
    }

    /**
     * Cria um UoW mock que já tem o origData populado com a chave corrente da entidade.
     */
    private function makeUowWithOrigData(WhatsAppNumber $entity): UnitOfWork&MockObject
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getOriginalEntityData')->willReturn(['apiKey' => $entity->getApiKey()]);

        return $uow;
    }

    // =========================================================================
    // postLoad — descriptografia
    // =========================================================================

    public function testPostLoadIgnoresNonWhatsAppNumberEntity(): void
    {
        $this->encryption->expects($this->never())->method('decrypt');

        $other = new \stdClass();
        $args  = new PostLoadEventArgs($other, $this->makeEm());
        $this->subscriber->postLoad($args);
    }

    public function testPostLoadIgnoresEntityWithEmptyApiKey(): void
    {
        $number = $this->makeNumber(null);
        $this->encryption->expects($this->never())->method('decrypt');

        $args = new PostLoadEventArgs($number, $this->makeEm($this->makeUowWithOrigData($number)));
        $this->subscriber->postLoad($args);

        $this->assertNull($number->getApiKey());
    }

    public function testPostLoadIgnoresPlaintextKeyWithoutPrefix(): void
    {
        $plain  = str_repeat('k', 40);
        $number = $this->makeNumber($plain);
        $this->encryption->expects($this->never())->method('decrypt');

        $args = new PostLoadEventArgs($number, $this->makeEm($this->makeUowWithOrigData($number)));
        $this->subscriber->postLoad($args);

        $this->assertSame($plain, $number->getApiKey());
    }

    public function testPostLoadDecryptsKeyWithEncPrefix(): void
    {
        $plain     = 'minha-chave-secreta-12345678';
        $encrypted = ApiKeyEncryptionSubscriber::ENC_PREFIX.'base64abc|ivxyz';
        $number    = $this->makeNumber($encrypted);

        $this->encryption->method('decrypt')->willReturn($plain);

        $args = new PostLoadEventArgs($number, $this->makeEm($this->makeUowWithOrigData($number)));
        $this->subscriber->postLoad($args);

        $this->assertSame($plain, $number->getApiKey());
    }

    public function testPostLoadPassesPayloadWithoutPrefixToDecrypt(): void
    {
        $raw       = 'base64abc|ivxyz';
        $encrypted = ApiKeyEncryptionSubscriber::ENC_PREFIX.$raw;
        $number    = $this->makeNumber($encrypted);

        $this->encryption->expects($this->once())
            ->method('decrypt')
            ->with($raw)
            ->willReturn('plaintext');

        $args = new PostLoadEventArgs($number, $this->makeEm($this->makeUowWithOrigData($number)));
        $this->subscriber->postLoad($args);
    }

    public function testPostLoadLeavesValueIntactWhenDecryptionFails(): void
    {
        $encrypted = ApiKeyEncryptionSubscriber::ENC_PREFIX.'invalid|data';
        $number    = $this->makeNumber($encrypted);

        $this->encryption->method('decrypt')->willReturn(false);

        $args = new PostLoadEventArgs($number, $this->makeEm($this->makeUowWithOrigData($number)));
        $this->subscriber->postLoad($args);

        $this->assertSame($encrypted, $number->getApiKey());
    }

    public function testPostLoadUpdatesUowSnapshotAfterDecryption(): void
    {
        $plain     = 'plain-key-1234567890';
        $encrypted = ApiKeyEncryptionSubscriber::ENC_PREFIX.'enc|iv';
        $number    = $this->makeNumber($encrypted);

        $this->encryption->method('decrypt')->willReturn($plain);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getOriginalEntityData')->willReturn(['apiKey' => $encrypted]);
        $uow->expects($this->once())
            ->method('setOriginalEntityData')
            ->with($number, $this->callback(fn ($d) => $d['apiKey'] === $plain));

        $args = new PostLoadEventArgs($number, $this->makeEm($uow));
        $this->subscriber->postLoad($args);
    }

    public function testPostLoadDoesNotUpdateSnapshotWhenApiKeyNotInOrigData(): void
    {
        $encrypted = ApiKeyEncryptionSubscriber::ENC_PREFIX.'enc|iv';
        $number    = $this->makeNumber($encrypted);

        $this->encryption->method('decrypt')->willReturn('plain');

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getOriginalEntityData')->willReturn([]); // sem 'apiKey'
        $uow->expects($this->never())->method('setOriginalEntityData');

        $args = new PostLoadEventArgs($number, $this->makeEm($uow));
        $this->subscriber->postLoad($args);
    }

    // =========================================================================
    // prePersist — criptografia antes de inserir
    // =========================================================================

    public function testPrePersistIgnoresNonWhatsAppNumberEntity(): void
    {
        $this->encryption->expects($this->never())->method('encrypt');

        $args = new PrePersistEventArgs(new \stdClass(), $this->makeEm());
        $this->subscriber->prePersist($args);
    }

    public function testPrePersistIgnoresEntityWithEmptyApiKey(): void
    {
        $number = $this->makeNumber(null);
        $this->encryption->expects($this->never())->method('encrypt');

        $args = new PrePersistEventArgs($number, $this->makeEm());
        $this->subscriber->prePersist($args);
    }

    public function testPrePersistEncryptsPlaintextApiKey(): void
    {
        $plain  = 'minha-chave-secreta-12345678';
        $number = $this->makeNumber($plain);

        $this->encryption->method('encrypt')->willReturn('enc|iv');

        $args = new PrePersistEventArgs($number, $this->makeEm());
        $this->subscriber->prePersist($args);

        $this->assertSame(ApiKeyEncryptionSubscriber::ENC_PREFIX.'enc|iv', $number->getApiKey());
    }

    public function testPrePersistSkipsAlreadyEncryptedKey(): void
    {
        $encrypted = ApiKeyEncryptionSubscriber::ENC_PREFIX.'enc|iv';
        $number    = $this->makeNumber($encrypted);

        $this->encryption->expects($this->never())->method('encrypt');

        $args = new PrePersistEventArgs($number, $this->makeEm());
        $this->subscriber->prePersist($args);

        $this->assertSame($encrypted, $number->getApiKey());
    }

    // =========================================================================
    // preUpdate — criptografia antes de atualizar
    // =========================================================================

    public function testPreUpdateIgnoresNonWhatsAppNumberEntity(): void
    {
        $this->encryption->expects($this->never())->method('encrypt');

        $changeSet = [];
        $args      = new PreUpdateEventArgs(new \stdClass(), $this->makeEm(), $changeSet);
        $this->subscriber->preUpdate($args);
    }

    public function testPreUpdateEncryptsPlaintextApiKey(): void
    {
        $plain  = 'minha-chave-secreta-12345678';
        $number = $this->makeNumber($plain);

        $this->encryption->method('encrypt')->willReturn('enc|iv');

        $changeSet = ['apiKey' => [$plain, $plain]];
        $args      = new PreUpdateEventArgs($number, $this->makeEm(), $changeSet);
        $this->subscriber->preUpdate($args);

        $this->assertSame(ApiKeyEncryptionSubscriber::ENC_PREFIX.'enc|iv', $number->getApiKey());
    }

    public function testPreUpdateCallsRecomputeSingleEntityChangeSet(): void
    {
        $plain  = 'minha-chave-secreta-12345678';
        $number = $this->makeNumber($plain);

        $this->encryption->method('encrypt')->willReturn('enc|iv');

        $uow = $this->createMock(UnitOfWork::class);
        $uow->expects($this->once())->method('recomputeSingleEntityChangeSet');

        $changeSet = [];
        $args      = new PreUpdateEventArgs($number, $this->makeEm($uow), $changeSet);
        $this->subscriber->preUpdate($args);
    }

    public function testPreUpdateSkipsAlreadyEncryptedKey(): void
    {
        $encrypted = ApiKeyEncryptionSubscriber::ENC_PREFIX.'enc|iv';
        $number    = $this->makeNumber($encrypted);

        $this->encryption->expects($this->never())->method('encrypt');

        $uow = $this->createMock(UnitOfWork::class);
        $uow->expects($this->never())->method('recomputeSingleEntityChangeSet');

        $changeSet = [];
        $args      = new PreUpdateEventArgs($number, $this->makeEm($uow), $changeSet);
        $this->subscriber->preUpdate($args);
    }

    // =========================================================================
    // postPersist — restauração do texto plano
    // =========================================================================

    public function testPostPersistIgnoresNonWhatsAppNumberEntity(): void
    {
        $args = new PostPersistEventArgs(new \stdClass(), $this->makeEm());
        $this->subscriber->postPersist($args); // não deve lançar exceções
        $this->addToAssertionCount(1);
    }

    public function testPostPersistRestoresPlaintextAfterEncrypt(): void
    {
        $plain  = 'minha-chave-secreta-12345678';
        $number = $this->makeNumber($plain);

        $this->encryption->method('encrypt')->willReturn('enc|iv');

        // 1. Criptografa (prePersist)
        $prePersistArgs = new PrePersistEventArgs($number, $this->makeEm());
        $this->subscriber->prePersist($prePersistArgs);
        $this->assertStringStartsWith(ApiKeyEncryptionSubscriber::ENC_PREFIX, $number->getApiKey() ?? '');

        // 2. Restaura (postPersist)
        $uow = $this->makeUowWithOrigData($number);
        $postPersistArgs = new PostPersistEventArgs($number, $this->makeEm($uow));
        $this->subscriber->postPersist($postPersistArgs);

        $this->assertSame($plain, $number->getApiKey());
    }

    public function testPostPersistUpdatesUowSnapshotToPlaintext(): void
    {
        $plain     = 'minha-chave-secreta-12345678';
        $encrypted = ApiKeyEncryptionSubscriber::ENC_PREFIX.'enc|iv';
        $number    = $this->makeNumber($plain);

        $this->encryption->method('encrypt')->willReturn('enc|iv');

        $prePersistArgs = new PrePersistEventArgs($number, $this->makeEm());
        $this->subscriber->prePersist($prePersistArgs);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getOriginalEntityData')->willReturn(['apiKey' => $encrypted]);
        $uow->expects($this->once())
            ->method('setOriginalEntityData')
            ->with($number, $this->callback(fn ($d) => $d['apiKey'] === $plain));

        $postPersistArgs = new PostPersistEventArgs($number, $this->makeEm($uow));
        $this->subscriber->postPersist($postPersistArgs);
    }

    public function testPostPersistDoesNothingIfNoPrePersistHappened(): void
    {
        $number = $this->makeNumber('some-key');

        $uow = $this->createMock(UnitOfWork::class);
        $uow->expects($this->never())->method('setOriginalEntityData');

        $args = new PostPersistEventArgs($number, $this->makeEm($uow));
        $this->subscriber->postPersist($args);
    }

    // =========================================================================
    // postUpdate — restauração do texto plano
    // =========================================================================

    public function testPostUpdateRestoresPlaintextAfterEncrypt(): void
    {
        $plain  = 'minha-chave-secreta-12345678';
        $number = $this->makeNumber($plain);

        $this->encryption->method('encrypt')->willReturn('enc|iv');

        // Usa prePersist para popular o cache (mesmo comportamento de preUpdate)
        $prePersistArgs = new PrePersistEventArgs($number, $this->makeEm());
        $this->subscriber->prePersist($prePersistArgs);

        $uow             = $this->makeUowWithOrigData($number);
        $postUpdateArgs  = new PostUpdateEventArgs($number, $this->makeEm($uow));
        $this->subscriber->postUpdate($postUpdateArgs);

        $this->assertSame($plain, $number->getApiKey());
    }

    public function testPostUpdateIgnoresNonWhatsAppNumberEntity(): void
    {
        $args = new PostUpdateEventArgs(new \stdClass(), $this->makeEm());
        $this->subscriber->postUpdate($args);
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Round-trip: encrypt → persist → postPersist → reload → decrypt
    // =========================================================================

    public function testRoundTripEncryptDecryptYieldsOriginalValue(): void
    {
        $plain = 'chave-api-original-1234567890';

        // Simula EncryptionHelper (encrypt/decrypt simétrico)
        $this->encryption->method('encrypt')
            ->willReturnCallback(fn (string $v) => base64_encode($v).'|fakiv');
        $this->encryption->method('decrypt')
            ->willReturnCallback(fn (string $v) => base64_decode(explode('|', $v)[0]));

        $number = $this->makeNumber($plain);

        // prePersist: criptografa
        $this->subscriber->prePersist(new PrePersistEventArgs($number, $this->makeEm()));
        $encryptedInDb = $number->getApiKey();
        $this->assertStringStartsWith(ApiKeyEncryptionSubscriber::ENC_PREFIX, $encryptedInDb ?? '');
        $this->assertNotSame($plain, $encryptedInDb);

        // postPersist: restaura texto plano
        $uow = $this->makeUowWithOrigData($number);
        $this->subscriber->postPersist(new PostPersistEventArgs($number, $this->makeEm($uow)));
        $this->assertSame($plain, $number->getApiKey());

        // postLoad: simula reload — a entidade vem do banco com valor criptografado
        $reloaded = $this->makeNumber($encryptedInDb);
        $this->subscriber->postLoad(
            new PostLoadEventArgs($reloaded, $this->makeEm($this->makeUowWithOrigData($reloaded)))
        );
        $this->assertSame($plain, $reloaded->getApiKey());
    }

    // =========================================================================
    // Constante ENC_PREFIX
    // =========================================================================

    public function testEncPrefixConstantValue(): void
    {
        $this->assertSame('ENC:', ApiKeyEncryptionSubscriber::ENC_PREFIX);
    }
}
