<?php

declare(strict_types=1);

use Mautic\CoreBundle\Translation\Translator;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMPartnerApi;
use MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberBalanceHistoryRepository;
use MauticPlugin\DialogHSMBundle\Service\BalanceAlertService;
use MauticPlugin\DialogHSMBundle\Service\BalanceHistoryRecorder;
use MauticPlugin\DialogHSMBundle\Service\MultiWebhookService;
use MauticPlugin\DialogHSMBundle\Service\PartnerConfigProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class WhatsAppNumberControllerTest extends TestCase
{
    /** @var WhatsAppNumberController&MockObject */
    private WhatsAppNumberController $controller;

    /** @var WhatsAppNumberModel&MockObject */
    private WhatsAppNumberModel $model;

    /** @var MultiWebhookService&MockObject */
    private MultiWebhookService $service;

    /** @var DialogHSMPartnerApi&MockObject */
    private DialogHSMPartnerApi $partnerApi;

    /** @var PartnerConfigProvider&MockObject */
    private PartnerConfigProvider $partnerConfigProvider;

    /** @var BalanceAlertService&MockObject */
    private BalanceAlertService $balanceAlertService;

    /** @var BalanceHistoryRecorder&MockObject */
    private BalanceHistoryRecorder $balanceHistoryRecorder;

    /** @var WhatsAppNumberBalanceHistoryRepository&MockObject */
    private WhatsAppNumberBalanceHistoryRepository $balanceHistoryRepository;

    protected function setUp(): void
    {
        $this->model                    = $this->createMock(WhatsAppNumberModel::class);
        $this->service                   = $this->createMock(MultiWebhookService::class);
        $this->partnerApi                = $this->createMock(DialogHSMPartnerApi::class);
        $this->partnerConfigProvider      = $this->createMock(PartnerConfigProvider::class);
        $this->balanceAlertService        = $this->createMock(BalanceAlertService::class);
        $this->balanceHistoryRecorder      = $this->createMock(BalanceHistoryRecorder::class);
        $this->balanceHistoryRepository   = $this->createMock(WhatsAppNumberBalanceHistoryRepository::class);

        $this->controller = $this->getMockBuilder(WhatsAppNumberController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getModel', 'generateUrl'])
            ->getMock();

        $this->controller->method('getModel')->willReturn($this->model);
        $this->controller->setMultiWebhookService($this->service);
        $this->controller->setPartnerApi($this->partnerApi);
        $this->controller->setPartnerConfigProvider($this->partnerConfigProvider);
        $this->controller->setBalanceAlertService($this->balanceAlertService);
        $this->controller->setBalanceHistoryRecorder($this->balanceHistoryRecorder);
        $this->controller->setBalanceHistoryRepository($this->balanceHistoryRepository);

        $translator = $this->createMock(Translator::class);
        $translator->method('trans')->willReturnArgument(0);
        $prop = new \ReflectionProperty(\Mautic\CoreBundle\Controller\CommonController::class, 'translator');
        $prop->setValue($this->controller, $translator);
    }

    private function makeEligibleEntity(): WhatsAppNumber
    {
        $entity = $this->makeEntity();
        $entity->setClientId('client1');
        $entity->setChannelId('channel1');

        return $entity;
    }

    private function makeEntity(string $apiKey = 'key123', string $phone = '5511999990000'): WhatsAppNumber
    {
        $entity = new WhatsAppNumber();
        $entity->setApiKeyRaw($apiKey);
        $entity->setPhoneNumber($phone);

        return $entity;
    }

    // =========================================================================
    // webhookCheckAction
    // =========================================================================

    public function testWebhookCheckReturns404WhenEntityNotFound(): void
    {
        $this->model->method('getEntity')->with(99)->willReturn(null);

        $response = $this->controller->webhookCheckAction(99);

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $body);
    }

    public function testWebhookCheckCallsServiceWithEntityApiKey(): void
    {
        $this->model->method('getEntity')->willReturn($this->makeEntity('mykey'));

        $this->service
            ->expects($this->once())
            ->method('check')
            ->with('mykey')
            ->willReturn(['enabled' => true, 'destinations' => []]);

        $response = $this->controller->webhookCheckAction(1);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testWebhookCheckReturnsServiceResultAsJson(): void
    {
        $this->model->method('getEntity')->willReturn($this->makeEntity());

        $this->service->method('check')->willReturn(['enabled' => true, 'destinations' => [['name' => 'mautic']]]);

        $body = json_decode((string) $this->controller->webhookCheckAction(1)->getContent(), true);

        $this->assertTrue($body['enabled']);
        $this->assertCount(1, $body['destinations']);
    }

    public function testWebhookCheckWithNullApiKeyPassesEmptyString(): void
    {
        $entity = new WhatsAppNumber();
        $this->model->method('getEntity')->willReturn($entity);

        $this->service
            ->expects($this->once())
            ->method('check')
            ->with('')
            ->willReturn([]);

        $this->controller->webhookCheckAction(1);
    }

    // =========================================================================
    // webhookRegisterAction
    // =========================================================================

    public function testWebhookRegisterReturns405WhenNotPost(): void
    {
        $request  = Request::create('/dialoghsm/numbers/1/webhook/register', 'GET');
        $response = $this->controller->webhookRegisterAction($request, 1);

        $this->assertSame(405, $response->getStatusCode());
    }

    public function testWebhookRegisterReturns404WhenEntityNotFound(): void
    {
        $this->model->method('getEntity')->with(99)->willReturn(null);

        $request  = Request::create('/dialoghsm/numbers/99/webhook/register', 'POST');
        $response = $this->controller->webhookRegisterAction($request, 99);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testWebhookRegisterCallsServiceWithApiKeyAndUrl(): void
    {
        $this->model->method('getEntity')->willReturn($this->makeEntity('apikey', '5511999990000'));

        $webhookUrl = 'https://example.com/dialoghsm/webhook/5511999990000';
        $this->controller->method('generateUrl')->willReturn($webhookUrl);

        $this->service
            ->expects($this->once())
            ->method('register')
            ->with('apikey', $webhookUrl)
            ->willReturn(['success' => true, 'action' => 'created', 'message' => 'OK']);

        $request = Request::create('/dialoghsm/numbers/1/webhook/register', 'POST');
        $this->controller->webhookRegisterAction($request, 1);
    }

    public function testWebhookRegisterResponseContainsUrlField(): void
    {
        $webhookUrl = 'https://example.com/dialoghsm/webhook/5511999990000';

        $this->model->method('getEntity')->willReturn($this->makeEntity());
        $this->controller->method('generateUrl')->willReturn($webhookUrl);
        $this->service->method('register')->willReturn(['success' => true, 'action' => 'created', 'message' => 'OK']);

        $request = Request::create('/dialoghsm/numbers/1/webhook/register', 'POST');
        $body    = json_decode((string) $this->controller->webhookRegisterAction($request, 1)->getContent(), true);

        $this->assertArrayHasKey('url', $body);
        $this->assertSame($webhookUrl, $body['url']);
        $this->assertTrue($body['success']);
    }

    public function testWebhookRegisterMergesServiceResultIntoResponse(): void
    {
        $this->model->method('getEntity')->willReturn($this->makeEntity());
        $this->controller->method('generateUrl')->willReturn('https://example.com/webhook');

        $serviceResult = ['success' => false, 'action' => 'create', 'message' => 'server error'];
        $this->service->method('register')->willReturn($serviceResult);

        $request = Request::create('/dialoghsm/numbers/1/webhook/register', 'POST');
        $body    = json_decode((string) $this->controller->webhookRegisterAction($request, 1)->getContent(), true);

        $this->assertFalse($body['success']);
        $this->assertSame('create', $body['action']);
        $this->assertSame('server error', $body['message']);
    }

    // =========================================================================
    // balanceCheckAction
    // =========================================================================

    public function testBalanceCheckReturns405WhenNotPost(): void
    {
        $request  = Request::create('/dialoghsm/numbers/1/balance/check', 'GET');
        $response = $this->controller->balanceCheckAction($request, 1);

        $this->assertSame(405, $response->getStatusCode());
    }

    public function testBalanceCheckReturns404WhenEntityNotFound(): void
    {
        $this->model->method('getEntity')->with(99)->willReturn(null);

        $request  = Request::create('/dialoghsm/numbers/99/balance/check', 'POST');
        $response = $this->controller->balanceCheckAction($request, 99);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testBalanceCheckReturns400WhenClientIdMissing(): void
    {
        $entity = $this->makeEntity();
        $entity->setChannelId('channel1');
        $this->model->method('getEntity')->willReturn($entity);

        $this->partnerApi->expects($this->never())->method('getChannelBalance');

        $request  = Request::create('/dialoghsm/numbers/1/balance/check', 'POST');
        $response = $this->controller->balanceCheckAction($request, 1);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testBalanceCheckReturns400WhenChannelIdMissing(): void
    {
        $entity = $this->makeEntity();
        $entity->setClientId('client1');
        $this->model->method('getEntity')->willReturn($entity);

        $request  = Request::create('/dialoghsm/numbers/1/balance/check', 'POST');
        $response = $this->controller->balanceCheckAction($request, 1);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testBalanceCheckReturns400WhenPartnerConfigMissing(): void
    {
        $this->model->method('getEntity')->willReturn($this->makeEligibleEntity());
        $this->partnerConfigProvider->method('getPartnerId')->willReturn(null);
        $this->partnerConfigProvider->method('getPartnerApiKey')->willReturn('key');

        $this->partnerApi->expects($this->never())->method('getChannelBalance');

        $request  = Request::create('/dialoghsm/numbers/1/balance/check', 'POST');
        $response = $this->controller->balanceCheckAction($request, 1);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testBalanceCheckSuccessPersistsAndCallsAlertService(): void
    {
        $entity = $this->makeEligibleEntity();
        $this->model->method('getEntity')->willReturn($entity);
        $this->partnerConfigProvider->method('getPartnerId')->willReturn('partner1');
        $this->partnerConfigProvider->method('getPartnerApiKey')->willReturn('apikey');

        $repo = $this->createMock(\MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository::class);
        $repo->expects($this->once())->method('saveEntity')->with($entity);
        $this->model->method('getRepository')->willReturn($repo);

        $this->partnerApi->expects($this->once())
            ->method('getChannelBalance')
            ->with('partner1', 'apikey', 'client1', 'channel1')
            ->willReturn([
                'success'             => true,
                'balance'             => 48.15,
                'currency'            => 'usd',
                'error'               => null,
                'last_renewal_amount' => 52.0,
                'last_renewal_date'   => '2026-05-13T13:51:11.821Z',
                'usage'               => [],
            ]);

        $this->balanceAlertService->expects($this->once())
            ->method('checkAndNotify')
            ->with($entity, 48.15, 'usd');

        $this->balanceHistoryRecorder->expects($this->once())
            ->method('recordIfNewRecharge')
            ->with($entity, '2026-05-13T13:51:11.821Z', 52.0, 48.15, 'usd');

        $request  = Request::create('/dialoghsm/numbers/1/balance/check', 'POST');
        $body     = json_decode((string) $this->controller->balanceCheckAction($request, 1)->getContent(), true);

        $this->assertTrue($body['success']);
        $this->assertSame(48.15, $body['balance']);
        $this->assertSame('usd', $body['currency']);
    }

    public function testBalanceCheckFailureDoesNotPersistOrAlert(): void
    {
        $entity = $this->makeEligibleEntity();
        $this->model->method('getEntity')->willReturn($entity);
        $this->partnerConfigProvider->method('getPartnerId')->willReturn('partner1');
        $this->partnerConfigProvider->method('getPartnerApiKey')->willReturn('apikey');

        $repo = $this->createMock(\MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository::class);
        $repo->expects($this->never())->method('saveEntity');
        $this->model->method('getRepository')->willReturn($repo);

        $this->partnerApi->method('getChannelBalance')
            ->willReturn(['success' => false, 'balance' => null, 'currency' => null, 'error' => 'HTTP 500']);

        $this->balanceAlertService->expects($this->never())->method('checkAndNotify');
        $this->balanceHistoryRecorder->expects($this->never())->method('recordIfNewRecharge');

        $request = Request::create('/dialoghsm/numbers/1/balance/check', 'POST');
        $body    = json_decode((string) $this->controller->balanceCheckAction($request, 1)->getContent(), true);

        $this->assertFalse($body['success']);
        $this->assertSame('HTTP 500', $body['error']);
    }
}
