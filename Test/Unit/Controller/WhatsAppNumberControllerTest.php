<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use MauticPlugin\DialogHSMBundle\Service\MultiWebhookService;
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

    protected function setUp(): void
    {
        $this->model   = $this->createMock(WhatsAppNumberModel::class);
        $this->service = $this->createMock(MultiWebhookService::class);

        $this->controller = $this->getMockBuilder(WhatsAppNumberController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getModel', 'generateUrl'])
            ->getMock();

        $this->controller->method('getModel')->willReturn($this->model);
        $this->controller->setMultiWebhookService($this->service);
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
}
