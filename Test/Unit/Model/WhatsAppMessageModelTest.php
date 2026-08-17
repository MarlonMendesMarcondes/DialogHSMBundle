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
    /**
     * Records every createQueryBuilder() chain executed during the test, as
     * {entity, alias, sets: [field => param], params: [name => value]}.
     *
     * @var array<int, array{entity: ?string, alias: ?string, sets: array<string, string>, params: array<string, mixed>}>
     */
    private array $qbCalls = [];

    private int $nextLogId = 1;

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
        ?\Psr\Log\LoggerInterface $logger = null,
    ): WhatsAppMessageModel {
        $ref   = new \ReflectionClass(WhatsAppMessageModel::class);
        $model = $ref->newInstanceWithoutConstructor();

        $emProp = new \ReflectionProperty(\Mautic\CoreBundle\Model\AbstractCommonModel::class, 'em');
        $emProp->setAccessible(true);
        $emProp->setValue($model, $em);

        $loggerProp = new \ReflectionProperty(\Mautic\CoreBundle\Model\AbstractCommonModel::class, 'logger');
        $loggerProp->setAccessible(true);
        if ($logger !== null) {
            $loggerProp->setValue($model, $logger);
        } else {
            $loggerProp->setValue($model, $this->createMock(\Psr\Log\LoggerInterface::class));
        }

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
        $mockEm = $this->createMock(EntityManagerInterface::class);
        $mockEm->method('createQueryBuilder')->willReturnCallback(function () {
            $call = ['entity' => null, 'alias' => null, 'sets' => [], 'params' => []];

            $mockQuery = $this->createMock(AbstractQuery::class);
            $mockQuery->method('execute')->willReturn(null);

            $mockQb = $this->createMock(QueryBuilder::class);
            $mockQb->method('update')->willReturnCallback(function (string $entity, string $alias) use ($mockQb, &$call) {
                $call['entity'] = $entity;
                $call['alias']  = $alias;

                return $mockQb;
            });
            $mockQb->method('set')->willReturnCallback(function (string $field, string $paramName) use ($mockQb, &$call) {
                $call['sets'][$field] = $paramName;

                return $mockQb;
            });
            $mockQb->method('where')->willReturnSelf();
            $mockQb->method('setParameter')->willReturnCallback(function (string $key, $value) use ($mockQb, &$call) {
                $call['params'][$key] = $value;

                return $mockQb;
            });
            $mockQb->method('getQuery')->willReturnCallback(function () use ($mockQuery, &$call) {
                $this->qbCalls[] = $call;

                return $mockQuery;
            });

            return $mockQb;
        });

        $mockRepo = $this->createMock(WhatsAppMessageRepository::class);

        $mockEm->method('getRepository')
            ->with(WhatsAppMessage::class)
            ->willReturn($mockRepo);
        $mockEm->method('persist')->willReturnCallback(function ($entity): void {
            if ($entity instanceof MessageLog) {
                $idProp = new \ReflectionProperty(MessageLog::class, 'id');
                $idProp->setAccessible(true);
                $idProp->setValue($entity, $this->nextLogId++);
            }
        });
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

    public function testSendToListsBusExceptionMarksMessageLogAsFailedWithErrorMessage(): void
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

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('SendWhatsAppDirectBatchMessage'),
                $this->callback(function (array $context) {
                    return 'bus failure' === $context['exception']
                        && \RuntimeException::class === $context['class'];
                }),
            );

        $model  = $this->makeModel($leadModel, $bus, $em, null, $logger);
        $event  = $this->createMock(ChannelBroadcastEvent::class);
        $model->sendToLists($this->makeMessageMock($payload), $event);

        $failureUpdates = array_values(array_filter(
            $this->qbCalls,
            static fn (array $call) => MessageLog::class === $call['entity'],
        ));

        $this->assertCount(1, $failureUpdates);
        $update = $failureUpdates[0];
        $this->assertSame(MessageLog::STATUS_FAILED, $update['params']['status']);
        $this->assertStringContainsString('bus failure', $update['params']['errorMessage']);
        $this->assertSame([1], $update['params']['ids']);
    }

    public function testSendToListsBusExceptionWithMultipleContactsMarksAllLogsInBatchFailed(): void
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

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('bus failure'));

        $model  = $this->makeModel($leadModel, $bus, $em);
        $result = $model->sendToLists($this->makeMessageMock([]), $this->createMock(ChannelBroadcastEvent::class));

        $this->assertSame([0, 3], $result);

        $failureUpdates = array_values(array_filter(
            $this->qbCalls,
            static fn (array $call) => MessageLog::class === $call['entity'],
        ));

        $this->assertCount(1, $failureUpdates);
        $this->assertSame([1, 2, 3], $failureUpdates[0]['params']['ids']);
    }

    public function testSendToListsPartialBatchFailureOnlyMarksFailedBatchLogsAsFailed(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();

        $firstBatch = [];
        for ($i = 1; $i <= 100; ++$i) {
            $firstBatch[] = ['id' => $i, 'phone' => '55119999900'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)];
        }
        $secondBatch = [['id' => 101, 'phone' => '5511988887777']];

        $repo->method('getPendingContacts')
            ->willReturnOnConsecutiveCalls(
                $firstBatch,
                $secondBatch,
                [],
            );

        $lead = $this->createMock(Lead::class);
        $lead->method('getProfileFields')->willReturn([]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturn($lead);

        // 1º batch (100 contatos) despacha com sucesso; 2º batch (1 contato) falha.
        $bus       = $this->createMock(MessageBusInterface::class);
        $callCount = 0;
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$callCount): Envelope {
            ++$callCount;
            if (2 === $callCount) {
                throw new \RuntimeException('second batch failure');
            }

            return new Envelope($msg);
        });

        $model  = $this->makeModel($leadModel, $bus, $em);
        $result = $model->sendToLists($this->makeMessageMock([]), $this->createMock(ChannelBroadcastEvent::class));

        $this->assertSame([100, 1], $result);

        $failureUpdates = array_values(array_filter(
            $this->qbCalls,
            static fn (array $call) => MessageLog::class === $call['entity'],
        ));

        // Só o log do 2º batch (id 101) deve ter sido marcado como failed.
        $this->assertCount(1, $failureUpdates);
        $this->assertSame([101], $failureUpdates[0]['params']['ids']);
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
