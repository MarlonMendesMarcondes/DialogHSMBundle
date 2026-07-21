<?php

declare(strict_types=1);

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\LeadMergeEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\EventListener\LeadMergeSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LeadMergeSubscriberTest extends TestCase
{
    private MessageLogRepository&MockObject $mockRepo;
    private LeadMergeSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->mockRepo   = $this->createMock(MessageLogRepository::class);
        $this->subscriber = new LeadMergeSubscriber($this->mockRepo);
    }

    public function testSubscribesToLeadPostMerge(): void
    {
        $events = LeadMergeSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(LeadEvents::LEAD_POST_MERGE, $events);
    }

    public function testOnLeadMergeReassignsLogsFromLoserToVictor(): void
    {
        $victor = new Lead();
        $victor->setId(970008);

        $loser = new Lead();
        $loser->setId(970007);

        $this->mockRepo->expects($this->once())
            ->method('updateLead')
            ->with(970007, 970008);

        $this->subscriber->onLeadMerge(new LeadMergeEvent($victor, $loser));
    }

    /**
     * O ID mais alto não é necessariamente o vencedor (depende de --newer-into-older),
     * então o teste usa IDs em ordem inversa para garantir que loser/victor não fiquem trocados.
     */
    public function testOnLeadMergeKeepsLoserAndVictorOrderWhenLoserIdIsHigher(): void
    {
        $victor = new Lead();
        $victor->setId(100);

        $loser = new Lead();
        $loser->setId(200);

        $this->mockRepo->expects($this->once())
            ->method('updateLead')
            ->with(200, 100);

        $this->subscriber->onLeadMerge(new LeadMergeEvent($victor, $loser));
    }

    public function testOnLeadMergeCallsUpdateLeadEvenWhenLoserHasNoLogs(): void
    {
        $victor = new Lead();
        $victor->setId(2);

        $loser = new Lead();
        $loser->setId(1);

        // updateLead() é um UPDATE em massa (WHERE lead_id = X); não checar
        // existência antes é intencional, então deve ser chamado incondicionalmente.
        $this->mockRepo->expects($this->once())
            ->method('updateLead')
            ->with(1, 2);

        $this->subscriber->onLeadMerge(new LeadMergeEvent($victor, $loser));
    }

    public function testOnLeadMergePropagatesRepositoryException(): void
    {
        $victor = new Lead();
        $victor->setId(2);

        $loser = new Lead();
        $loser->setId(1);

        // ContactMerger::merge() despacha o evento de forma síncrona; uma exceção aqui
        // deve subir e abortar o merge, não ser engolida silenciosamente pelo listener.
        $this->mockRepo->method('updateLead')
            ->willThrowException(new \RuntimeException('falha de conexão com o banco'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('falha de conexão com o banco');

        $this->subscriber->onLeadMerge(new LeadMergeEvent($victor, $loser));
    }
}
