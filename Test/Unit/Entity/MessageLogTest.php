<?php

declare(strict_types=1);

use Doctrine\ORM\Mapping\ClassMetadata as ORMClassMetadata;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use PHPUnit\Framework\TestCase;

class MessageLogTest extends TestCase
{
    private function makeLog(): MessageLog
    {
        return new MessageLog();
    }

    // =========================================================================
    // Getters e Setters
    // =========================================================================

    public function testGetIdReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getId());
    }

    public function testGetLeadIdReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getLeadId());
    }

    public function testSetLeadIdStoresValue(): void
    {
        $log = $this->makeLog()->setLeadId(42);
        $this->assertSame(42, $log->getLeadId());
    }

    public function testSetLeadIdReturnsSelf(): void
    {
        $log = $this->makeLog();
        $this->assertSame($log, $log->setLeadId(1));
    }

    public function testGetCampaignIdReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getCampaignId());
    }

    public function testSetCampaignIdStoresValue(): void
    {
        $log = $this->makeLog()->setCampaignId(7);
        $this->assertSame(7, $log->getCampaignId());
    }

    public function testSetCampaignIdAcceptsNull(): void
    {
        $log = $this->makeLog()->setCampaignId(7)->setCampaignId(null);
        $this->assertNull($log->getCampaignId());
    }

    public function testSetCampaignIdReturnsSelf(): void
    {
        $log = $this->makeLog();
        $this->assertSame($log, $log->setCampaignId(1));
    }

    public function testGetCampaignEventIdReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getCampaignEventId());
    }

    public function testSetCampaignEventIdStoresValue(): void
    {
        $log = $this->makeLog()->setCampaignEventId(99);
        $this->assertSame(99, $log->getCampaignEventId());
    }

    public function testSetCampaignEventIdAcceptsNull(): void
    {
        $log = $this->makeLog()->setCampaignEventId(99)->setCampaignEventId(null);
        $this->assertNull($log->getCampaignEventId());
    }

    public function testSetCampaignEventIdReturnsSelf(): void
    {
        $log = $this->makeLog();
        $this->assertSame($log, $log->setCampaignEventId(1));
    }

    public function testGetSenderNameReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getSenderName());
    }

    public function testSetSenderNameStoresValue(): void
    {
        $log = $this->makeLog()->setSenderName('Vendas BR');
        $this->assertSame('Vendas BR', $log->getSenderName());
    }

    public function testSetSenderNameAcceptsNull(): void
    {
        $log = $this->makeLog()->setSenderName(null);
        $this->assertNull($log->getSenderName());
    }

    public function testSetSenderNameReturnsSelf(): void
    {
        $log = $this->makeLog();
        $this->assertSame($log, $log->setSenderName('x'));
    }

    public function testGetTemplateNameReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getTemplateName());
    }

    public function testSetTemplateNameStoresValue(): void
    {
        $log = $this->makeLog()->setTemplateName('promo_hsm');
        $this->assertSame('promo_hsm', $log->getTemplateName());
    }

    public function testSetTemplateNameReturnsSelf(): void
    {
        $log = $this->makeLog();
        $this->assertSame($log, $log->setTemplateName('tpl'));
    }

    public function testGetPhoneNumberReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getPhoneNumber());
    }

    public function testSetPhoneNumberStoresValue(): void
    {
        $log = $this->makeLog()->setPhoneNumber('+5511999999999');
        $this->assertSame('+5511999999999', $log->getPhoneNumber());
    }

    public function testSetPhoneNumberReturnsSelf(): void
    {
        $log = $this->makeLog();
        $this->assertSame($log, $log->setPhoneNumber('+5511999999999'));
    }

    public function testGetStatusReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getStatus());
    }

    public function testSetStatusStoresValue(): void
    {
        $log = $this->makeLog()->setStatus('sent');
        $this->assertSame('sent', $log->getStatus());
    }

    public function testSetStatusReturnsSelf(): void
    {
        $log = $this->makeLog();
        $this->assertSame($log, $log->setStatus('sent'));
    }

    public function testGetHttpStatusCodeReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getHttpStatusCode());
    }

    public function testSetHttpStatusCodeStoresValue(): void
    {
        $log = $this->makeLog()->setHttpStatusCode(200);
        $this->assertSame(200, $log->getHttpStatusCode());
    }

    public function testSetHttpStatusCodeAcceptsNull(): void
    {
        $log = $this->makeLog()->setHttpStatusCode(null);
        $this->assertNull($log->getHttpStatusCode());
    }

    public function testSetHttpStatusCodeReturnsSelf(): void
    {
        $log = $this->makeLog();
        $this->assertSame($log, $log->setHttpStatusCode(200));
    }

    public function testGetApiResponseReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getApiResponse());
    }

    public function testSetApiResponseStoresValue(): void
    {
        $log = $this->makeLog()->setApiResponse('{"messages":[{"id":"wamid.xxx"}]}');
        $this->assertSame('{"messages":[{"id":"wamid.xxx"}]}', $log->getApiResponse());
    }

    public function testSetApiResponseAcceptsNull(): void
    {
        $log = $this->makeLog()->setApiResponse(null);
        $this->assertNull($log->getApiResponse());
    }

    public function testSetApiResponseReturnsSelf(): void
    {
        $log = $this->makeLog();
        $this->assertSame($log, $log->setApiResponse(null));
    }

    public function testGetErrorMessageReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getErrorMessage());
    }

    public function testSetErrorMessageStoresValue(): void
    {
        $log = $this->makeLog()->setErrorMessage('Too many requests');
        $this->assertSame('Too many requests', $log->getErrorMessage());
    }

    public function testSetErrorMessageAcceptsNull(): void
    {
        $log = $this->makeLog()->setErrorMessage(null);
        $this->assertNull($log->getErrorMessage());
    }

    public function testSetErrorMessageReturnsSelf(): void
    {
        $log = $this->makeLog();
        $this->assertSame($log, $log->setErrorMessage(null));
    }

    public function testGetDateSentReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getDateSent());
    }

    public function testSetDateSentStoresValue(): void
    {
        $date = new \DateTime('2024-01-15 10:00:00');
        $log  = $this->makeLog()->setDateSent($date);
        $this->assertSame($date, $log->getDateSent());
    }

    public function testSetDateSentReturnsSelf(): void
    {
        $log = $this->makeLog();
        $this->assertSame($log, $log->setDateSent(new \DateTime()));
    }

    // =========================================================================
    // wamid
    // =========================================================================

    public function testGetWamidReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeLog()->getWamid());
    }

    public function testSetWamidStoresValue(): void
    {
        $log = $this->makeLog()->setWamid('wamid.HBgLNTUxMTk');
        $this->assertSame('wamid.HBgLNTUxMTk', $log->getWamid());
    }

    public function testSetWamidAcceptsNull(): void
    {
        $log = $this->makeLog()->setWamid('wamid.abc')->setWamid(null);
        $this->assertNull($log->getWamid());
    }

    public function testSetWamidReturnsSelf(): void
    {
        $log = $this->makeLog();
        $this->assertSame($log, $log->setWamid('wamid.xyz'));
    }

    // =========================================================================
    // Constantes de status
    // =========================================================================

    public function testStatusConstantsExist(): void
    {
        $this->assertSame('sent', MessageLog::STATUS_SENT);
        $this->assertSame('delivered', MessageLog::STATUS_DELIVERED);
        $this->assertSame('read', MessageLog::STATUS_READ);
        $this->assertSame('failed', MessageLog::STATUS_FAILED);
        $this->assertSame('dlq', MessageLog::STATUS_DLQ);
    }

    // =========================================================================
    // loadMetadata — verifica que é chamado sem exceções
    // =========================================================================

    public function testLoadMetadataRunsWithoutException(): void
    {
        $classMetadata = new ORMClassMetadata(MessageLog::class);

        $this->expectNotToPerformAssertions();
        MessageLog::loadMetadata($classMetadata);
    }
}
