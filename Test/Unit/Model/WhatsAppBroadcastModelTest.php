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
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppBroadcastModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class WhatsAppBroadcastModelTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a fully-wired WhatsAppBroadcastModel with the supplied mocks.
     * Because WhatsAppBroadcastModel overrides __construct without calling
     * parent::__construct(), the protected $em property defined on
     * AbstractCommonModel is never set — we inject it via reflection.
     */
    private function makeModel(
        LeadModel $leadModel,
        MessageBusInterface $bus,
        EntityManagerInterface $em,
    ): WhatsAppBroadcastModel {
        $model = new WhatsAppBroadcastModel($leadModel, $bus);

        $ref = new \ReflectionProperty(\Mautic\CoreBundle\Model\AbstractCommonModel::class, 'em');
        $ref->setAccessible(true);
        $ref->setValue($model, $em);

        return $model;
    }

    /**
     * Returns a fully-stubbed EntityManagerInterface whose createQueryBuilder()
     * yields a fluent QueryBuilder stub — required for the DQL UPDATE at the
     * end of sendToLists().
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

    /**
     * Builds a WhatsAppNumber mock with the standard test values.
     */
    private function makeNumberMock(): WhatsAppNumber
    {
        $number = $this->createMock(WhatsAppNumber::class);
        $number->method('getApiKey')->willReturn('test-api-key');
        $number->method('getBaseUrl')->willReturn('https://waba.360dialog.io');
        $number->method('getName')->willReturn('Test Number');

        return $number;
    }

    /**
     * Builds a WhatsAppMessage mock with the given payload and number.
     *
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
    // Tests
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

    public function testSendToListsContactWithEmptyPhoneIncreasesFailed(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();

        $repo->method('getPendingContacts')
            ->willReturnOnConsecutiveCalls(
                [['id' => 1, 'phone' => '']],
                [],
            );

        $bus      = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $model = $this->makeModel($this->createMock(LeadModel::class), $bus, $em);

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

        $model = $this->makeModel($leadModel, $bus, $em);

        $event  = $this->createMock(ChannelBroadcastEvent::class);
        $result = $model->sendToLists($this->makeMessageMock([]), $event);

        $this->assertSame([0, 1], $result);
    }

    public function testSendToListsSuccessfulDispatchIncreasesSent(): void
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
        $this->assertInstanceOf(SendWhatsAppMessage::class, $capturedMessage);
        $this->assertSame('template_x', $capturedMessage->templateName);
        $this->assertTrue($capturedMessage->isBatch);
    }

    public function testSendToListsBusExceptionIncreasesFailed(): void
    {
        $payload = ['list' => [['label' => 'content', 'value' => 'template_x']]];

        [$em, $repo] = $this->makeEmWithRepo();

        $repo->method('getPendingContacts')
            ->willReturnOnConsecutiveCalls(
                [['id' => 3, 'phone' => '5511977776666']],
                [],
            );

        $lead = $this->createMock(Lead::class);
        $lead->method('getProfileFields')->willReturn([]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->with(3)->willReturn($lead);

        // Track every call to persist() so we can inspect the log's final status
        $persistedObjects = [];
        $em->method('persist')
            ->willReturnCallback(function (object $obj) use (&$persistedObjects): void {
                $persistedObjects[] = $obj;
            });

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('bus failure'));

        $model  = $this->makeModel($leadModel, $bus, $em);
        $event  = $this->createMock(ChannelBroadcastEvent::class);
        $result = $model->sendToLists($this->makeMessageMock($payload), $event);

        $this->assertSame([0, 1], $result);

        // The last persisted MessageLog must have STATUS_FAILED
        $logs = array_values(array_filter($persistedObjects, fn ($o) => $o instanceof MessageLog));
        $this->assertNotEmpty($logs);
        $lastLog = end($logs);
        $this->assertSame(MessageLog::STATUS_FAILED, $lastLog->getStatus());
    }

    public function testSendToListsTokensResolvedPerContact(): void
    {
        // TokenHelper::findLeadTokens resolves {contactfield=<alias>} tokens.
        // The model pipes each payload item's 'value' through that helper, so a
        // value of '{contactfield=firstname}' with profileFields ['firstname'=>'João']
        // must come out as 'João' in the dispatched message.
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

        $model  = $this->makeModel($leadModel, $bus, $em);
        $event  = $this->createMock(ChannelBroadcastEvent::class);
        $model->sendToLists($this->makeMessageMock($payload), $event);

        $this->assertInstanceOf(SendWhatsAppMessage::class, $capturedMessage);
        $resolvedList = $capturedMessage->payloadData['list'];
        $this->assertSame('João', $resolvedList[0]['value']);
    }

    public function testSendToListsNoContactsReturnsZeroZero(): void
    {
        [$em, $repo] = $this->makeEmWithRepo();

        // Returns empty on first call → loop exits immediately
        $repo->method('getPendingContacts')->willReturn([]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $model  = $this->makeModel($this->createMock(LeadModel::class), $bus, $em);
        $event  = $this->createMock(ChannelBroadcastEvent::class);
        $result = $model->sendToLists($this->makeMessageMock([]), $event);

        $this->assertSame([0, 0], $result);
    }

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
}
