<?php

declare(strict_types=1);

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Mautic\ChannelBundle\Event\ChannelBroadcastEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessageRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppMessageModel;
use MauticPlugin\DialogHSMBundle\Service\BulkRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class WhatsAppMessageModelTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a fully-wired WhatsAppMessageModel with the supplied mocks.
     */
    private function makeModel(
        LeadModel $leadModel,
        MessageBusInterface $bus,
        EntityManagerInterface $em,
        ?BulkRateLimiter $rateLimiter = null,
    ): WhatsAppMessageModel {
        $ref   = new \ReflectionClass(WhatsAppMessageModel::class);
        $model = $ref->newInstanceWithoutConstructor();

        $emProp = new \ReflectionProperty(\Mautic\CoreBundle\Model\AbstractCommonModel::class, 'em');
        $emProp->setAccessible(true);
        $emProp->setValue($model, $em);

        $model->setLeadModel($leadModel);
        $model->setBus($bus);

        if ($rateLimiter === null) {
            $rateLimiter = $this->createMock(BulkRateLimiter::class);
            $rateLimiter->method('getBulkSendDelay')->willReturn(0.0);
        }
        $model->setRateLimiter($rateLimiter);

        return $model;
    }

    /**
     * Returns a fully-stubbed EntityManagerInterface + WhatsAppMessageRepository.
     *
     * @return array{EntityManagerInterface, WhatsAppMessageRepository}
     */
    private function makeEmWithRepo(): array
    {
        $mockQuery = $this->createMock(AbstractQuery::class);
        $mockQuery->method('execute')->willReturn(null);

        $mockQb = $this->createMock(QueryBuilder::class);
        $mockQb->method('update')->willReturnSelf();
        $mockQb->method('set')->willReturnSelf();
        $mockQb->method('where')->willReturnSelf();
        $mockQb->method('setParameter')->willReturnSelf();
        $mockQb->method('getQuery')->willReturn($mockQuery);

        $mockRepo = $this->createMock(WhatsAppMessageRepository::class);

        $mockEm = $this->createMock(EntityManagerInterface::class);
        $mockEm->method('createQueryBuilder')->willReturn($mockQb);
        $mockEm->method('getRepository')
            ->with(WhatsAppMessage::class)
            ->willReturn($mockRepo);
        $mockEm->method('persist')->with($this->anything());
        $mockEm->method('flush');
        $mockEm->method('clear');

        return [$mockEm, $mockRepo];
    }

    private function makeNumberMock(): WhatsAppNumber
    {
        $number = $this->createMock(WhatsAppNumber::class);
        $number->method('getApiKey')->willReturn('test-api-key');
        $number->method('getBaseUrl')->willReturn('https://waba.360dialog.io');
        $number->method('getName')->willReturn('Test Number');

        return $number;
    }

    /**
     * @param array<mixed> $payloadData
     */
    private function makeMessageMock(array $payloadData, ?WhatsAppNumber $number = null): WhatsAppMessage
    {
        $message = $this->createMock(WhatsAppMessage::class);
        $message->method('getId')->willReturn(42);
        $message->method('getWhatsAppNumber')->willReturn($number ?? $this->makeNumberMock());
        $message->method('getTemplateName')->willReturn('template_x');
        $message->method('getPayloadData')->willReturn($payloadData);

        return $message;
    }

    // -------------------------------------------------------------------------
    // getLookupResults() — filtro isPublished
    // -------------------------------------------------------------------------

    public function testGetLookupResultsAlwaysFiltersPublishedOnly(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();

        $capturedParams = null;
        $repo->method('getEntities')
            ->willReturnCallback(function (array $params) use (&$capturedParams): array {
                $capturedParams = $params;

                return [];
            });

        $model = $this->makeModel(
            $this->createMock(LeadModel::class),
            $this->createMock(MessageBusInterface::class),
            $em,
        );

        $model->getLookupResults('dialoghsm.whatsappmessage');

        $this->assertNotNull($capturedParams);
        $forceFilters = $capturedParams['filter']['force'] ?? [];
        $publishedFilter = array_filter(
            $forceFilters,
            fn (array $f) => ($f['column'] ?? '') === 'wm.isPublished'
                && ($f['expr'] ?? '') === 'eq'
                && ($f['value'] ?? null) === true
        );

        $this->assertNotEmpty(
            $publishedFilter,
            'getLookupResults deve sempre incluir filtro isPublished=true'
        );
    }

    public function testGetLookupResultsWithIdArrayFilterKeepsPublishedConstraint(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();

        $capturedParams = null;
        $repo->method('getEntities')
            ->willReturnCallback(function (array $params) use (&$capturedParams): array {
                $capturedParams = $params;

                return [];
            });

        $model = $this->makeModel(
            $this->createMock(LeadModel::class),
            $this->createMock(MessageBusInterface::class),
            $em,
        );

        $model->getLookupResults('dialoghsm.whatsappmessage', [1, 2, 3]);

        $forceFilters    = $capturedParams['filter']['force'] ?? [];
        $publishedFilter = array_filter($forceFilters, fn ($f) => ($f['column'] ?? '') === 'wm.isPublished');
        $idFilter        = array_filter($forceFilters, fn ($f) => ($f['column'] ?? '') === 'wm.id');

        $this->assertNotEmpty($publishedFilter, 'Filtro isPublished deve estar presente junto com filtro de IDs');
        $this->assertNotEmpty($idFilter, 'Filtro de IDs deve estar presente');
    }

    public function testGetLookupResultsWithStringFilterKeepsPublishedConstraint(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();

        $capturedParams = null;
        $repo->method('getEntities')
            ->willReturnCallback(function (array $params) use (&$capturedParams): array {
                $capturedParams = $params;

                return [];
            });

        $model = $this->makeModel(
            $this->createMock(LeadModel::class),
            $this->createMock(MessageBusInterface::class),
            $em,
        );

        $model->getLookupResults('dialoghsm.whatsappmessage', 'template');

        $forceFilters    = $capturedParams['filter']['force'] ?? [];
        $publishedFilter = array_filter($forceFilters, fn ($f) => ($f['column'] ?? '') === 'wm.isPublished');
        $stringFilter    = $capturedParams['filter']['string'] ?? null;

        $this->assertNotEmpty($publishedFilter, 'Filtro isPublished deve estar presente junto com filtro de string');
        $this->assertSame('template', $stringFilter, 'Filtro de string deve ser passado corretamente');
    }

    public function testGetLookupResultsReturnsIdToNameMap(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();

        $msg1 = $this->createMock(WhatsAppMessage::class);
        $msg1->method('getId')->willReturn(10);
        $msg1->method('getName')->willReturn('Template A');

        $msg2 = $this->createMock(WhatsAppMessage::class);
        $msg2->method('getId')->willReturn(20);
        $msg2->method('getName')->willReturn('Template B');

        $repo->method('getEntities')->willReturn([$msg1, $msg2]);

        $model = $this->makeModel(
            $this->createMock(LeadModel::class),
            $this->createMock(MessageBusInterface::class),
            $em,
        );

        $results = $model->getLookupResults('dialoghsm.whatsappmessage');

        $this->assertSame([10 => 'Template A', 20 => 'Template B'], $results);
    }

    // -------------------------------------------------------------------------
    // Basic model tests
    // -------------------------------------------------------------------------

    public function testGetPermissionBaseReturnsCorrectString(): void
    {
        [$em] = $this->makeEmWithRepo();
        $model = $this->makeModel(
            $this->createMock(LeadModel::class),
            $this->createMock(MessageBusInterface::class),
            $em,
        );

        $this->assertSame('dialoghsm:whatsappmessages', $model->getPermissionBase());
    }

    public function testGetEntityWithNullIdReturnsNewInstance(): void
    {
        [$em] = $this->makeEmWithRepo();
        $model = $this->makeModel(
            $this->createMock(LeadModel::class),
            $this->createMock(MessageBusInterface::class),
            $em,
        );

        $entity = $model->getEntity(null);

        $this->assertInstanceOf(WhatsAppMessage::class, $entity);
    }

    // -------------------------------------------------------------------------
    // sendToLists() — guard cases
    // -------------------------------------------------------------------------

    public function testSendToListsWhatsAppNumberNullReturnsZeroZero(): void
    {
        [$em] = $this->makeEmWithRepo();

        $message = $this->createMock(WhatsAppMessage::class);
        $message->method('getWhatsAppNumber')->willReturn(null);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $model  = $this->makeModel($this->createMock(LeadModel::class), $bus, $em);
        $event  = $this->createMock(ChannelBroadcastEvent::class);
        $result = $model->sendToLists($message, $event);

        $this->assertSame([0, 0], $result);
    }

    public function testSendToListsNoContactsReturnsZeroZero(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();
        $repo->method('getPendingContacts')->willReturn([]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $model  = $this->makeModel($this->createMock(LeadModel::class), $bus, $em);
        $event  = $this->createMock(ChannelBroadcastEvent::class);
        $result = $model->sendToLists($this->makeMessageMock([]), $event);

        $this->assertSame([0, 0], $result);
    }

    public function testSendToListsContactWithEmptyPhoneIncreasesFailed(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();
        $repo->method('getPendingContacts')
            ->willReturnOnConsecutiveCalls(
                [['id' => 1, 'phone' => '']],
                [],
            );

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $model  = $this->makeModel($this->createMock(LeadModel::class), $bus, $em);
        $event  = $this->createMock(ChannelBroadcastEvent::class);
        $result = $model->sendToLists($this->makeMessageMock([]), $event);

        $this->assertSame([0, 1], $result);
    }

    public function testSendToListsContactWithNullLeadIncreasesFailed(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();
        $repo->method('getPendingContacts')
            ->willReturnOnConsecutiveCalls(
                [['id' => 99, 'phone' => '5511999999999']],
                [],
            );

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->with(99)->willReturn(null);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $model  = $this->makeModel($leadModel, $bus, $em);
        $event  = $this->createMock(ChannelBroadcastEvent::class);
        $result = $model->sendToLists($this->makeMessageMock([]), $event);

        $this->assertSame([0, 1], $result);
    }

    // -------------------------------------------------------------------------
    // sendToLists() — Redis dispatch via SendWhatsAppDirectBatchMessage
    // -------------------------------------------------------------------------

    public function testSendToListsDispatchesBatchMessageToRedis(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();
        $payload = ['list' => [['label' => 'content', 'value' => 'template_x']]];

        $repo->method('getPendingContacts')
            ->willReturnOnConsecutiveCalls(
                [['id' => 7, 'phone' => '5511988887777']],
                [],
            );

        $lead = $this->createMock(Lead::class);
        $lead->method('getProfileFields')->willReturn([]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->with(7)->willReturn($lead);

        $capturedMessage = null;
        $bus             = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (object $msg) use (&$capturedMessage): Envelope {
                $capturedMessage = $msg;

                return new Envelope($msg);
            });

        $model  = $this->makeModel($leadModel, $bus, $em);
        $event  = $this->createMock(ChannelBroadcastEvent::class);
        $result = $model->sendToLists($this->makeMessageMock($payload), $event);

        $this->assertSame([1, 0], $result);
        $this->assertInstanceOf(SendWhatsAppDirectBatchMessage::class, $capturedMessage);
        $this->assertCount(1, $capturedMessage->items);
        $this->assertInstanceOf(SendWhatsAppMessage::class, $capturedMessage->items[0]);
        $this->assertSame('template_x', $capturedMessage->items[0]->templateName);
        $this->assertTrue($capturedMessage->items[0]->isBatch);
    }

    public function testSendToListsDispatchCarriesSendDelayFromRateLimiter(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();

        $repo->method('getPendingContacts')
            ->willReturnOnConsecutiveCalls(
                [['id' => 7, 'phone' => '5511988887777']],
                [],
            );

        $lead = $this->createMock(Lead::class);
        $lead->method('getProfileFields')->willReturn([]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturn($lead);

        $rateLimiter = $this->createMock(BulkRateLimiter::class);
        $rateLimiter->method('getBulkSendDelay')->willReturn(1.5);

        $capturedMessage = null;
        $bus             = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willReturnCallback(function (object $msg) use (&$capturedMessage): Envelope {
                $capturedMessage = $msg;

                return new Envelope($msg);
            });

        $model = $this->makeModel($leadModel, $bus, $em, $rateLimiter);
        $model->sendToLists($this->makeMessageMock([]), $this->createMock(ChannelBroadcastEvent::class));

        $this->assertInstanceOf(SendWhatsAppDirectBatchMessage::class, $capturedMessage);
        $this->assertSame(1.5, $capturedMessage->sendDelay);
        $this->assertSame(1, $capturedMessage->batchLimit);
    }

    public function testSendToListsBatchContainsQueueLogIdAsIntegerString(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();

        $repo->method('getPendingContacts')
            ->willReturnOnConsecutiveCalls(
                [['id' => 5, 'phone' => '5511988887777']],
                [],
            );

        $lead = $this->createMock(Lead::class);
        $lead->method('getProfileFields')->willReturn([]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturn($lead);

        // Simula auto-increment do DB: define id=99 no MessageLog ao persistir
        $em->method('persist')->willReturnCallback(function (object $obj): void {
            if ($obj instanceof MessageLog) {
                $ref = new \ReflectionProperty(MessageLog::class, 'id');
                $ref->setAccessible(true);
                $ref->setValue($obj, 99);
            }
        });

        $capturedMessage = null;
        $bus             = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willReturnCallback(function (object $msg) use (&$capturedMessage): Envelope {
                $capturedMessage = $msg;

                return new Envelope($msg);
            });

        $model = $this->makeModel($leadModel, $bus, $em);
        $model->sendToLists($this->makeMessageMock([]), $this->createMock(ChannelBroadcastEvent::class));

        $this->assertInstanceOf(SendWhatsAppDirectBatchMessage::class, $capturedMessage);
        $item = $capturedMessage->items[0];
        // queueLogId deve ser string numérica (ID inteiro do log) — detectável por ctype_digit()
        $this->assertSame('99', $item->queueLogId);
        $this->assertTrue(ctype_digit($item->queueLogId));
    }

    public function testSendToListsBusExceptionIncreasesFailed(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();
        $payload = ['list' => [['label' => 'content', 'value' => 'template_x']]];

        $repo->method('getPendingContacts')
            ->willReturnOnConsecutiveCalls(
                [['id' => 3, 'phone' => '5511977776666']],
                [],
            );

        $lead = $this->createMock(Lead::class);
        $lead->method('getProfileFields')->willReturn([]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->with(3)->willReturn($lead);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('bus failure'));

        $model  = $this->makeModel($leadModel, $bus, $em);
        $event  = $this->createMock(ChannelBroadcastEvent::class);
        $result = $model->sendToLists($this->makeMessageMock($payload), $event);

        $this->assertSame([0, 1], $result);
    }

    public function testSendToListsMultipleContactsInOneBatchMessage(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();

        $repo->method('getPendingContacts')
            ->willReturnOnConsecutiveCalls(
                [
                    ['id' => 1, 'phone' => '5511111111111'],
                    ['id' => 2, 'phone' => '5511222222222'],
                    ['id' => 3, 'phone' => '5511333333333'],
                ],
                [],
            );

        $lead = $this->createMock(Lead::class);
        $lead->method('getProfileFields')->willReturn([]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturn($lead);

        $capturedMessage = null;
        $bus             = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (object $msg) use (&$capturedMessage): Envelope {
                $capturedMessage = $msg;

                return new Envelope($msg);
            });

        $model  = $this->makeModel($leadModel, $bus, $em);
        $result = $model->sendToLists($this->makeMessageMock([]), $this->createMock(ChannelBroadcastEvent::class));

        $this->assertSame([3, 0], $result);
        $this->assertInstanceOf(SendWhatsAppDirectBatchMessage::class, $capturedMessage);
        $this->assertCount(3, $capturedMessage->items);
    }

    // -------------------------------------------------------------------------
    // sendToLists() — token resolution
    // -------------------------------------------------------------------------

    public function testSendToListsTokensResolvedPerContact(): void
    {
        $payload = ['list' => [['label' => 'body', 'value' => '{contactfield=firstname}']]];

        [$em, $repo] = $this->makeEmWithRepo();
        $repo->method('getPendingContacts')
            ->willReturnOnConsecutiveCalls(
                [['id' => 5, 'phone' => '5511966665555']],
                [],
            );

        $lead = $this->createMock(Lead::class);
        $lead->method('getProfileFields')->willReturn(['firstname' => 'João']);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->with(5)->willReturn($lead);

        $capturedMessage = null;
        $bus             = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willReturnCallback(function (object $msg) use (&$capturedMessage): Envelope {
                $capturedMessage = $msg;

                return new Envelope($msg);
            });

        $model = $this->makeModel($leadModel, $bus, $em);
        $model->sendToLists($this->makeMessageMock($payload), $this->createMock(ChannelBroadcastEvent::class));

        $this->assertInstanceOf(SendWhatsAppDirectBatchMessage::class, $capturedMessage);
        $item = $capturedMessage->items[0];
        // resolveTokens converte lista → key-value; token deve estar resolvido
        $this->assertSame('João', $item->payloadData['body']);
    }
}
