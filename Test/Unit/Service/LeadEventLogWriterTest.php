<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\LeadEventLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LeadEventLogWriterTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private LeadEventLogRepository&MockObject $eventLogRepo;
    private Connection&MockObject             $connection;
    private CoreParametersHelper&MockObject   $coreParameters;
    private LeadEventLogWriter                $writer;

    protected function setUp(): void
    {
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->eventLogRepo   = $this->createMock(LeadEventLogRepository::class);
        $this->connection     = $this->createMock(Connection::class);
        $this->coreParameters = $this->createMock(CoreParametersHelper::class);
        $this->coreParameters->method('get')->with('default_timezone')->willReturn('America/Sao_Paulo');

        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('getTableName')->willReturn('lead_event_log');

        $this->em->method('getRepository')->willReturn($this->eventLogRepo);
        $this->em->method('getConnection')->willReturn($this->connection);
        $this->em->method('getClassMetadata')->willReturn($meta);
        $this->em->method('getReference')->willReturnCallback(
            fn (string $class, int $id) => $this->createMock(Lead::class)
        );

        $this->writer = new LeadEventLogWriter($this->em, $this->coreParameters);
    }

    private function makeLog(int $id = 1, int $leadId = 99): MessageLog
    {
        $log = new MessageLog();
        $log->setLeadId($leadId);
        $log->setTemplateName('template_test');
        $log->setPhoneNumber('+5511999999999');
        $log->setWamid('wamid.abc');
        $log->setDateSent(new \DateTime());

        $ref = new \ReflectionProperty(MessageLog::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($log, $id);

        return $log;
    }

    // =========================================================================
    // Escrita quando não existe
    // =========================================================================

    public function testWriteCallsSaveEntityWhenEventDoesNotExist(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        $this->eventLogRepo->expects($this->once())->method('saveEntity')
            ->with($this->isInstanceOf(LeadEventLog::class));

        $this->writer->write($this->makeLog(), MessageLog::STATUS_SENT, new \DateTime());
    }

    public function testWriteCallsDetachAfterSave(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        $this->eventLogRepo->expects($this->once())->method('detachEntity');

        $this->writer->write($this->makeLog(), MessageLog::STATUS_SENT, new \DateTime());
    }

    // =========================================================================
    // Idempotência — já existe
    // =========================================================================

    public function testWriteSkipsWhenEventAlreadyExists(): void
    {
        $this->connection->method('fetchOne')->willReturn('123');

        $this->eventLogRepo->expects($this->never())->method('saveEntity');

        $this->writer->write($this->makeLog(), MessageLog::STATUS_SENT, new \DateTime());
    }

    // =========================================================================
    // Campos gravados
    // =========================================================================

    public function testWriteSetsCorrectBundle(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        $captured = null;
        $this->eventLogRepo->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $entry) use (&$captured): void {
                $captured = $entry;
            });

        $this->writer->write($this->makeLog(), MessageLog::STATUS_SENT, new \DateTime());

        $this->assertSame(LeadEventLogWriter::BUNDLE, $captured->getBundle());
    }

    public function testWriteSetsCorrectObject(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        $captured = null;
        $this->eventLogRepo->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $entry) use (&$captured): void {
                $captured = $entry;
            });

        $this->writer->write($this->makeLog(), MessageLog::STATUS_DELIVERED, new \DateTime());

        $this->assertSame(LeadEventLogWriter::OBJECT, $captured->getObject());
    }

    public function testWriteSetsActionFromParameter(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        $captured = null;
        $this->eventLogRepo->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $entry) use (&$captured): void {
                $captured = $entry;
            });

        $this->writer->write($this->makeLog(), MessageLog::STATUS_READ, new \DateTime());

        $this->assertSame(MessageLog::STATUS_READ, $captured->getAction());
    }

    public function testWriteSetsObjectIdFromLog(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        $captured = null;
        $this->eventLogRepo->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $entry) use (&$captured): void {
                $captured = $entry;
            });

        $this->writer->write($this->makeLog(42), MessageLog::STATUS_SENT, new \DateTime());

        $this->assertSame(42, $captured->getObjectId());
    }

    public function testWriteSetsDateAddedConvertedToMauticTimezone(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        // UTC 13:00 → America/Sao_Paulo (UTC-3) = 10:00
        $utcDate  = new \DateTime('2025-01-15 13:00:00', new \DateTimeZone('UTC'));
        $captured = null;
        $this->eventLogRepo->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $entry) use (&$captured): void {
                $captured = $entry;
            });

        $this->writer->write($this->makeLog(), MessageLog::STATUS_SENT, $utcDate);

        $stored = $captured->getDateAdded();
        $this->assertSame('America/Sao_Paulo', $stored->getTimezone()->getName());
        $this->assertSame('2025-01-15 10:00:00', $stored->format('Y-m-d H:i:s'));
    }

    public function testWriteSetsPropertiesWithTemplateAndPhone(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        $captured = null;
        $this->eventLogRepo->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $entry) use (&$captured): void {
                $captured = $entry;
            });

        $this->writer->write($this->makeLog(), MessageLog::STATUS_SENT, new \DateTime());

        $props = $captured->getProperties();
        $this->assertSame('template_test', $props['template_name']);
        $this->assertSame('+5511999999999', $props['phone_number']);
        $this->assertSame('wamid.abc', $props['wamid']);
    }

    // =========================================================================
    // Idempotência — checa com os parâmetros corretos
    // =========================================================================

    public function testExistsCheckUsesCorrectObjectId(): void
    {
        $capturedParams = null;
        $this->connection->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
                $capturedParams = $params;

                return false;
            });

        $this->eventLogRepo->method('saveEntity');

        $this->writer->write($this->makeLog(77), MessageLog::STATUS_SENT, new \DateTime());

        $this->assertSame(77, $capturedParams['object_id']);
        $this->assertSame(MessageLog::STATUS_SENT, $capturedParams['action']);
        $this->assertSame(LeadEventLogWriter::BUNDLE, $capturedParams['bundle']);
        $this->assertSame(LeadEventLogWriter::OBJECT, $capturedParams['object']);
    }
}
