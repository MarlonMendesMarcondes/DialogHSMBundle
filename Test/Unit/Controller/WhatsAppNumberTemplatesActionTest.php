<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController;
use MauticPlugin\DialogHSMBundle\DTO\TemplateDefinition;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use MauticPlugin\DialogHSMBundle\Service\PartnerConfigProvider;
use MauticPlugin\DialogHSMBundle\Service\TemplateCategoryResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Endpoint que alimenta o seletor de template do formulário guiado das ações novas.
 */
class WhatsAppNumberTemplatesActionTest extends TestCase
{
    private WhatsAppNumberController&MockObject $controller;
    private WhatsAppNumberModel&MockObject $model;
    private TemplateCategoryResolver&MockObject $resolver;
    private PartnerConfigProvider&MockObject $config;
    private bool $allowed = true;

    protected function setUp(): void
    {
        $this->model    = $this->createMock(WhatsAppNumberModel::class);
        $this->resolver = $this->createMock(TemplateCategoryResolver::class);
        $this->config   = $this->createMock(PartnerConfigProvider::class);
        $this->config->method('getDefaultBaseUrl')->willReturn('https://waba-v2.360dialog.io/messages');

        $this->controller = $this->getMockBuilder(WhatsAppNumberController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getModel', 'canListTemplates'])
            ->getMock();
        $this->controller->method('getModel')->willReturn($this->model);
        $this->controller->method('canListTemplates')->willReturnCallback(fn (): bool => $this->allowed);
        $this->controller->setTemplateResolver($this->resolver);
        $this->controller->setPartnerConfigProvider($this->config);
    }

    private function number(?string $baseUrl = null): WhatsAppNumber
    {
        $number = $this->createMock(WhatsAppNumber::class);
        $number->method('getId')->willReturn(7);
        $number->method('getApiKey')->willReturn('KEY');
        $number->method('getBaseUrl')->willReturn($baseUrl);

        return $number;
    }

    /**
     * @return array<string, mixed>
     */
    private function call(Request $request = new Request(), int $id = 7): array
    {
        $response = $this->controller->templatesAction($request, $id);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }

    public function testListsTemplatesWithoutExposingTheApiKey(): void
    {
        $this->model->method('getEntity')->willReturn($this->number());
        $this->resolver->expects($this->once())->method('listTemplates')
            ->with('KEY', 'https://waba-v2.360dialog.io/messages', false)
            ->willReturn([
                new TemplateDefinition('promo', 'pt_BR', 'APPROVED', 'MARKETING', 'IMAGE', 0, 2,
                    [['type' => 'QUICK_REPLY', 'vars' => 0, 'text' => 'Quero'], ['type' => 'URL', 'vars' => 1, 'text' => 'Ver']],
                    null, null, 'Olá {{1}}, curso {{2}}', 'Equipe'),
                new TemplateDefinition('aviso', 'pt_BR', 'PAUSED', 'UTILITY', null, 0, 0, [], null),
            ]);

        ['status' => $status, 'body' => $body] = $this->call();

        $this->assertSame(200, $status);
        $this->assertTrue($body['available']);
        $this->assertSame('promo', $body['templates'][0]['name']);
        $this->assertTrue($body['templates'][0]['approved']);
        $this->assertSame(['format' => 'IMAGE', 'vars' => 0, 'text' => null], $body['templates'][0]['header']);
        $this->assertSame(['vars' => 2, 'text' => 'Olá {{1}}, curso {{2}}'], $body['templates'][0]['body']);
        $this->assertSame('Equipe', $body['templates'][0]['footer']);
        $this->assertSame(['index' => 1, 'type' => 'URL', 'vars' => 1, 'text' => 'Ver'], $body['templates'][0]['buttons'][1]);
        $this->assertFalse($body['templates'][1]['approved']);
        $this->assertStringNotContainsString('KEY', json_encode($body));
    }

    public function testRefreshParameterAsksTheResolverToReload(): void
    {
        $this->model->method('getEntity')->willReturn($this->number('https://waba-v2.360dialog.io/marketing_messages'));
        $this->resolver->expects($this->once())->method('listTemplates')
            ->with('KEY', 'https://waba-v2.360dialog.io/marketing_messages', true)
            ->willReturn([]);

        $this->call(new Request(['refresh' => '1']));
    }

    public function testUnavailableListTellsTheFormToUseManualMode(): void
    {
        $this->model->method('getEntity')->willReturn($this->number());
        $this->resolver->method('listTemplates')->willReturn(null);

        $this->assertSame(['available' => false, 'templates' => []], $this->call()['body']);
    }

    public function testUnknownNumberIs404(): void
    {
        $this->model->method('getEntity')->willReturn(null);

        $this->assertSame(404, $this->call()['status']);
    }

    public function testUserWithoutCampaignOrMessagePermissionIsDenied(): void
    {
        $this->allowed = false;
        $this->model->expects($this->never())->method('getEntity');

        $this->assertSame(403, $this->call()['status']);
    }
}
