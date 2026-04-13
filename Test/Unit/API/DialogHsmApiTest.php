<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMApi;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DialogHsmApiTest extends TestCase
{
    private Client&MockObject $mockClient;
    private LoggerInterface&MockObject $mockLogger;
    private DialogHSMApi $api;

    /**
     * DNS resolver stub: retorna IPs fixos e determinísticos para os testes.
     * Evita resolução DNS real e permite simular rebinding retornando IPs privados.
     *
     * Domínios "bons" → IPs públicos.
     * Domínios "ruins" → IPs privados (simulam alvo de SSRF).
     * Desconhecidos → retorna o próprio hostname (comportamento do gethostbyname ao falhar).
     */
    private static function createDnsResolver(): callable
    {
        return static function (string $host): string {
            return match ($host) {
                // Endpoints legítimos de API
                'api.360dialog.com'        => '1.2.3.4',
                'waba-v2.360dialog.io'     => '1.2.3.5',
                'graph.facebook.com'       => '1.2.3.6',
                // CDNs de mídia usados nos testes de payload
                'example.com'              => '1.2.3.7',
                'cdn.example.com'          => '1.2.3.8',
                // Alvos internos usados nos testes de SSRF
                'internal.corp'            => '10.0.0.1',     // RFC1918
                'evil-rebind.com'          => '172.16.0.1',   // RFC1918
                // hostname desconhecido → gethostbyname retorna o próprio host (falha)
                default                    => $host,
            };
        };
    }

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(Client::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->api        = new DialogHSMApi($this->mockClient, $this->mockLogger, self::createDnsResolver());
    }

    // =========================================================================
    // Envio básico — sucesso e erros HTTP
    // =========================================================================

    public function testSendMessageSuccess(): void
    {
        $mockResponse = new Response(200, [], json_encode(['messages' => [['id' => 'wamid.abc']]]));
        $this->mockClient->method('request')->willReturn($mockResponse);

        $result = $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
            'vars'     => 'nome',
            'nome'     => 'João',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('wamid.abc', $result['wamid']);
        $this->assertNull($result['error']);
        $this->assertEquals(200, $result['http_status']);
    }

    public function testSendMessageHttpError404(): void
    {
        $mockResponse = new Response(404, [], json_encode(['error' => ['message' => 'Not Found']]));
        $this->mockClient->method('request')->willReturn($mockResponse);

        $result = $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('404', $result['error']);
        $this->assertEquals(404, $result['http_status']);
    }

    public function testSendMessageHttpError500(): void
    {
        $mockResponse = new Response(500, [], json_encode(['error' => ['message' => 'Internal Server Error']]));
        $this->mockClient->method('request')->willReturn($mockResponse);

        $result = $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('500', $result['error']);
        $this->assertEquals(500, $result['http_status']);
    }

    public function testSendMessageRequestException(): void
    {
        $guzzleRequest = new GuzzleRequest('POST', 'https://api.360dialog.com/v1/messages');
        $this->mockClient->method('request')->willThrowException(
            new RequestException('Network error', $guzzleRequest)
        );

        $result = $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('Network error', $result['error']);
        $this->assertNull($result['http_status']);
    }

    public function testSendMessageGenericException(): void
    {
        $this->mockClient->method('request')->willThrowException(new \Exception('Unexpected error'));

        $result = $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('Unexpected error', $result['error']);
        $this->assertNull($result['http_status']);
    }

    public function testSendMessageInvalidJsonResponse(): void
    {
        $mockResponse = new Response(400, [], 'Invalid JSON');
        $this->mockClient->method('request')->willReturn($mockResponse);

        $result = $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('400', $result['error']);
        $this->assertEquals(400, $result['http_status']);
    }

    // =========================================================================
    // Componentes de payload — header de mídia
    // =========================================================================

    public function testHeaderWithJpgImage(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'nome_template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://example.com/image.jpg',
        ]);

        $header = $capturedPayload['template']['components'][0];
        $this->assertEquals('header', $header['type']);
        $this->assertEquals('image', $header['parameters'][0]['type']);
        // O hostname é substituído pelo IP resolvido (anti-rebinding)
        $this->assertEquals('https://1.2.3.7/image.jpg', $header['parameters'][0]['image']['link']);
    }

    public function testHeaderWithPngImage(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'nome_template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://example.com/banner.png',
        ]);

        $header = $capturedPayload['template']['components'][0];
        $this->assertEquals('image', $header['parameters'][0]['type']);
        $this->assertEquals('https://1.2.3.7/banner.png', $header['parameters'][0]['image']['link']);
    }

    public function testHeaderWithMp4Video(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'nome_template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://example.com/video.mp4',
        ]);

        $header = $capturedPayload['template']['components'][0];
        $this->assertEquals('video', $header['parameters'][0]['type']);
        $this->assertEquals('https://1.2.3.7/video.mp4', $header['parameters'][0]['video']['link']);
    }

    public function testHeaderWithPdfDocument(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'nome_template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://example.com/contrato.pdf',
        ]);

        $header = $capturedPayload['template']['components'][0];
        $this->assertEquals('document', $header['parameters'][0]['type']);
        $this->assertEquals('https://1.2.3.7/contrato.pdf', $header['parameters'][0]['document']['link']);
    }

    public function testHeaderWithUnknownExtensionProducesNoHeaderComponent(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'nome_template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://example.com/arquivo.txt',
        ]);

        $this->assertEmpty($capturedPayload['template']['components']);
    }

    // =========================================================================
    // Componentes de payload — body, buttons, limited_time_offer
    // =========================================================================

    public function testBodyVariablesAreIncludedAsTextParameters(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
            'vars'     => 'nome, cidade',
            'nome'     => 'João',
            'cidade'   => 'São Paulo',
        ]);

        $components = $capturedPayload['template']['components'];
        $body       = array_values(array_filter($components, fn ($c) => $c['type'] === 'body'))[0] ?? null;

        $this->assertNotNull($body);
        $this->assertCount(2, $body['parameters']);
        $this->assertEquals('João', $body['parameters'][0]['text']);
        $this->assertEquals('São Paulo', $body['parameters'][1]['text']);
    }

    public function testButtonComponentsAreIncludedInPayload(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'      => 'nome_template',
            'language'     => 'pt_BR',
            'buttons'      => 'url, quick_reply',
            'buttons_vars' => 'pagina, sim',
        ]);

        $buttons = array_values(array_filter($capturedPayload['template']['components'], fn ($c) => $c['type'] === 'button'));

        $this->assertCount(2, $buttons);
        $this->assertEquals('url', $buttons[0]['sub_type']);
        $this->assertEquals('pagina', $buttons[0]['parameters'][0]['text']);
        $this->assertEquals('quick_reply', $buttons[1]['sub_type']);
        $this->assertEquals('sim', $buttons[1]['parameters'][0]['text']);
    }

    public function testLimitedTimeOfferComponentIsIncludedInPayload(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'            => 'nome_template',
            'language'           => 'pt_BR',
            'limited_time_offer' => '2024-12-31T23:59:59',
        ]);

        $lto = array_values(array_filter($capturedPayload['template']['components'], fn ($c) => $c['type'] === 'limited_time_offer'))[0] ?? null;

        $this->assertNotNull($lto);
        $expectedMs = (new \DateTime('2024-12-31T23:59:59'))->getTimestamp() * 1000;
        $this->assertEquals($expectedMs, $lto['parameters'][0]['limited_time_offer']['expiration_time_ms']);
    }

    public function testLimitedTimeOfferComponentIsAbsentWhenNotProvided(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
        ]);

        $lto = array_values(array_filter($capturedPayload['template']['components'], fn ($c) => $c['type'] === 'limited_time_offer'));
        $this->assertEmpty($lto);
    }

    public function testLimitedTimeOfferComponentIsPositionedBeforeButtons(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'            => 'nome_template',
            'language'           => 'pt_BR',
            'limited_time_offer' => '2025-06-01T00:00:00',
            'buttons'            => 'url',
            'buttons_vars'       => 'pagina',
        ]);

        $types       = array_column($capturedPayload['template']['components'], 'type');
        $ltoIndex    = array_search('limited_time_offer', $types);
        $buttonIndex = array_search('button', $types);

        $this->assertNotFalse($ltoIndex);
        $this->assertNotFalse($buttonIndex);
        $this->assertLessThan($buttonIndex, $ltoIndex);
    }

    public function testLimitedTimeOfferWithAllComponents(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'            => 'nome_template',
            'language'           => 'pt_BR',
            'url_arquivo'        => 'https://example.com/banner.jpg',
            'vars'               => 'nome',
            'nome'               => 'Maria',
            'limited_time_offer' => '2025-06-01T12:00:00',
            'buttons'            => 'url',
            'buttons_vars'       => 'link',
        ]);

        $types = array_column($capturedPayload['template']['components'], 'type');
        $this->assertContains('header', $types);
        $this->assertContains('body', $types);
        $this->assertContains('limited_time_offer', $types);
        $this->assertContains('button', $types);
        $this->assertLessThan(array_search('limited_time_offer', $types), array_search('body', $types));
        $this->assertLessThan(array_search('button', $types), array_search('limited_time_offer', $types));
    }

    // =========================================================================
    // Formatação de telefone
    // =========================================================================

    public function testPhoneWithPlusPrefixIsPreservedWithoutDoubleDdi(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '+5511999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertEquals('+5511999999999', $capturedPayload['to']);
    }

    public function testPhoneWith55PrefixGetsLeadingPlus(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '5511999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertEquals('+5511999999999', $capturedPayload['to']);
    }

    public function testPhoneWithoutCountryCodeGetsBrazilPrefix(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertEquals('+5511999999999', $capturedPayload['to']);
    }

    public function testBaseUrlTrailingSlashIsStripped(): void
    {
        $capturedUrl  = null;
        $mockResponse = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $url, array $options) use (&$capturedUrl, $mockResponse) {
                $capturedUrl = $url;
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages/', '11999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertEquals('https://api.360dialog.com/v1/messages', $capturedUrl);
    }

    // =========================================================================
    // Meta Cloud API (Facebook Graph)
    // =========================================================================

    public function testMetaCloudUrlUsesAuthorizationBearerHeader(): void
    {
        $capturedHeaders = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'wamid.abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedHeaders, $mockResponse) {
                $capturedHeaders = $options['headers'];
                return $mockResponse;
            });

        $this->api->sendMessage(
            'EAABsToken123',
            'https://graph.facebook.com/v18.0/123456789/messages',
            '11999999999',
            ['content' => 'template', 'language' => 'pt_BR']
        );

        $this->assertEquals('Bearer EAABsToken123', $capturedHeaders['Authorization']);
        $this->assertArrayNotHasKey('D360-API-KEY', $capturedHeaders);
    }

    public function testDialog360UrlUsesD360ApiKeyHeader(): void
    {
        $capturedHeaders = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedHeaders, $mockResponse) {
                $capturedHeaders = $options['headers'];
                return $mockResponse;
            });

        $this->api->sendMessage(
            'MY_360_KEY',
            'https://waba-v2.360dialog.io/messages',
            '11999999999',
            ['content' => 'template', 'language' => 'pt_BR']
        );

        $this->assertEquals('MY_360_KEY', $capturedHeaders['D360-API-KEY']);
        $this->assertArrayNotHasKey('Authorization', $capturedHeaders);
    }

    public function testMetaCloudSuccessResponse(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'messaging_product' => 'whatsapp',
            'messages'          => [['id' => 'wamid.HBgN']],
        ]));

        $this->mockClient->method('request')->willReturn($mockResponse);

        $result = $this->api->sendMessage(
            'EAABsToken123',
            'https://graph.facebook.com/v18.0/123456789/messages',
            '11999999999',
            ['content' => 'template', 'language' => 'pt_BR']
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('wamid.HBgN', $result['wamid']);
    }

    public function testMetaCloudHttpError401(): void
    {
        $mockResponse = new Response(401, [], json_encode([
            'error' => ['message' => 'Invalid OAuth access token', 'code' => 190],
        ]));
        $this->mockClient->method('request')->willReturn($mockResponse);

        $result = $this->api->sendMessage(
            'token_invalido',
            'https://graph.facebook.com/v18.0/123456789/messages',
            '11999999999',
            ['content' => 'template', 'language' => 'pt_BR']
        );

        $this->assertFalse($result['success']);
        $this->assertEquals(401, $result['http_status']);
        $this->assertStringContainsString('Invalid OAuth access token', $result['error']);
    }

    public function testMetaCloudPayloadFormatIsIdenticalTo360dialog(): void
    {
        $payloadDialog = null;
        $payloadMeta   = null;
        $mockResponse  = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->expects($this->exactly(2))->method('request')
            ->willReturnCallback(function (string $m, string $url, array $options) use (&$payloadDialog, &$payloadMeta, $mockResponse) {
                if (str_contains($url, 'graph.facebook.com')) {
                    $payloadMeta = $options['json'];
                } else {
                    $payloadDialog = $options['json'];
                }
                return $mockResponse;
            });

        $data = [
            'content'     => 'template_promo',
            'language'    => 'pt_BR',
            'vars'        => 'nome',
            'nome'        => 'Carlos',
            'url_arquivo' => 'https://example.com/img.jpg',
        ];

        $this->api->sendMessage('KEY_360', 'https://waba-v2.360dialog.io/messages', '11999999999', $data);
        $this->api->sendMessage('TOKEN_META', 'https://graph.facebook.com/v18.0/999/messages', '11999999999', $data);

        $this->assertEquals($payloadDialog, $payloadMeta, 'O payload deve ser idêntico para ambos os provedores');
    }

    // =========================================================================
    // SSRF — rejeição de URLs de mídia (url_arquivo)
    // =========================================================================

    public function testMediaHttpUrlIsRejected(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'http://example.com/image.jpg', // HTTP → rejeitado
        ]);

        $this->assertEmpty($capturedPayload['template']['components']);
    }

    public function testMediaLocalhostUrlIsRejected(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://localhost/image.jpg',
        ]);

        $this->assertEmpty($capturedPayload['template']['components']);
    }

    public function testMediaPrivateIpUrlIsRejected(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://192.168.1.100/image.jpg', // RFC1918
        ]);

        $this->assertEmpty($capturedPayload['template']['components']);
    }

    public function testMediaLoopbackIpUrlIsRejected(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://127.0.0.1/image.jpg',
        ]);

        $this->assertEmpty($capturedPayload['template']['components']);
    }

    public function testMediaAwsMetadataIpUrlIsRejected(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://169.254.169.254/latest/meta-data/image.jpg', // AWS IMDS
        ]);

        $this->assertEmpty($capturedPayload['template']['components']);
    }

    public function testMediaMalformedUrlIsRejected(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'not-a-url',
        ]);

        $this->assertEmpty($capturedPayload['template']['components']);
    }

    public function testMediaHostnameResolvingToPrivateIpIsRejected(): void
    {
        // "internal.corp" resolve para 10.0.0.1 (RFC1918) via stub de DNS
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://internal.corp/image.jpg',
        ]);

        $this->assertEmpty($capturedPayload['template']['components']);
    }

    // =========================================================================
    // SSRF — rejeição de baseUrl (novo: o endpoint da API também é validado)
    // =========================================================================

    public function testPrivateIpBaseUrlIsBlockedBeforeRequest(): void
    {
        // Garante que o Guzzle NÃO é chamado quando a baseUrl for privada
        $this->mockClient->expects($this->never())->method('request');

        $result = $this->api->sendMessage('API_KEY', 'https://10.0.0.1/api', '11999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('URL da API inválida', $result['error']);
        $this->assertNull($result['http_status']);
        $this->assertNull($result['wamid']);
    }

    public function testLocalhostBaseUrlIsBlocked(): void
    {
        $this->mockClient->expects($this->never())->method('request');

        $result = $this->api->sendMessage('API_KEY', 'https://localhost/api', '11999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('URL da API inválida', $result['error']);
    }

    public function testHttpSchemeBaseUrlIsBlocked(): void
    {
        $this->mockClient->expects($this->never())->method('request');

        $result = $this->api->sendMessage('API_KEY', 'http://api.360dialog.com/messages', '11999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('URL da API inválida', $result['error']);
    }

    public function testLoopbackBaseUrlIsBlocked(): void
    {
        $this->mockClient->expects($this->never())->method('request');

        $result = $this->api->sendMessage('API_KEY', 'https://127.0.0.1/api', '11999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertFalse($result['success']);
    }

    public function testAwsMetadataBaseUrlIsBlocked(): void
    {
        $this->mockClient->expects($this->never())->method('request');

        $result = $this->api->sendMessage('API_KEY', 'https://169.254.169.254/api', '11999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertFalse($result['success']);
    }

    public function testBaseUrlWithHostnameThatResolvesToPrivateIpIsBlocked(): void
    {
        // "evil-rebind.com" → 172.16.0.1 via stub
        $this->mockClient->expects($this->never())->method('request');

        $result = $this->api->sendMessage('API_KEY', 'https://evil-rebind.com/api', '11999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('URL da API inválida', $result['error']);
    }

    public function testBaseUrlWithUnresolvableHostnameIsBlocked(): void
    {
        // hostname não mapeado no stub → gethostbyname retorna o próprio hostname → falha de resolução
        $this->mockClient->expects($this->never())->method('request');

        $result = $this->api->sendMessage('API_KEY', 'https://does-not-exist.invalid/api', '11999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // DNS Pinning — CURLOPT_RESOLVE fixa o IP no socket (anti-rebinding)
    // =========================================================================

    public function testSendMessagePinsBaseUrlIpViaCurlResolve(): void
    {
        // Verifica que o CURLOPT_RESOLVE é enviado com o IP resolvido pelo stub (1.2.3.4)
        $capturedCurl = null;
        $mockResponse = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedCurl, $mockResponse) {
                $capturedCurl = $options['curl'] ?? [];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertArrayHasKey(\CURLOPT_RESOLVE, $capturedCurl, 'CURLOPT_RESOLVE deve estar presente nas opções curl');
        $resolveEntries = $capturedCurl[\CURLOPT_RESOLVE];
        $this->assertNotEmpty($resolveEntries);
        // Formato esperado: "hostname:porta:ip"
        $this->assertEquals('api.360dialog.com:443:1.2.3.4', $resolveEntries[0]);
    }

    public function testCurlResolveUsesCorrectPortForDefaultHttps(): void
    {
        // Porta 443 deve ser usada quando a URL não especifica porta explicitamente
        $capturedCurl = null;
        $mockResponse = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedCurl, $mockResponse) {
                $capturedCurl = $options['curl'] ?? [];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://waba-v2.360dialog.io/messages', '11999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $this->assertStringContainsString(':443:', $capturedCurl[\CURLOPT_RESOLVE][0]);
    }

    public function testCurlResolveContainsResolvedIpNotHostname(): void
    {
        // O IP no CURLOPT_RESOLVE deve ser o IP numérico (stub retorna 1.2.3.6), não o hostname
        $capturedCurl = null;
        $mockResponse = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedCurl, $mockResponse) {
                $capturedCurl = $options['curl'] ?? [];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://graph.facebook.com/v18.0/123/messages', '11999999999', [
            'content' => 'template', 'language' => 'pt_BR',
        ]);

        $entry = $capturedCurl[\CURLOPT_RESOLVE][0] ?? '';
        $this->assertStringEndsWith(':1.2.3.6', $entry, 'O valor deve terminar com o IP resolvido');
        $this->assertStringNotContainsString('graph.facebook.com:graph.facebook.com', $entry);
    }

    // =========================================================================
    // IP Pinning nas URLs de mídia (anti-rebinding para fetches do 360dialog)
    // =========================================================================

    public function testMediaUrlHostnameIsReplacedWithResolvedIp(): void
    {
        // O payload enviado ao 360dialog deve conter o IP no lugar do hostname,
        // para que o 360dialog não faça nova resolução DNS (DNS rebinding)
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://cdn.example.com/banner.jpg',
        ]);

        $link = $capturedPayload['template']['components'][0]['parameters'][0]['image']['link'];

        // Deve usar o IP (1.2.3.8), não o hostname original
        $this->assertStringStartsWith('https://1.2.3.8/', $link);
        $this->assertStringNotContainsString('cdn.example.com', $link);
    }

    public function testMediaUrlPathAndQueryArePreservedAfterIpSubstitution(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://example.com/pasta/arquivo.pdf?v=2&token=abc',
        ]);

        $link = $capturedPayload['template']['components'][0]['parameters'][0]['document']['link'];

        $this->assertEquals('https://1.2.3.7/pasta/arquivo.pdf?v=2&token=abc', $link);
    }

    public function testMediaUrlWithQueryStringIsAccepted(): void
    {
        // A regex de extensão deve aceitar URLs com query string (ex: imagem.jpg?v=3)
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://example.com/imagem.jpg?v=3&cache=bust',
        ]);

        $components = $capturedPayload['template']['components'];
        $this->assertNotEmpty($components);
        $this->assertEquals('image', $components[0]['parameters'][0]['type']);
    }

    public function testMediaDnsRebindingHostnameIsRejected(): void
    {
        // "evil-rebind.com" → 172.16.0.1 via stub: simula DNS rebinding para IP privado
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['messages' => [['id' => 'abc']]]));

        $this->mockClient->method('request')
            ->willReturnCallback(function (string $m, string $u, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];
                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://evil-rebind.com/image.jpg',
        ]);

        // Hostname resolve para IP privado → header component rejeitado
        $this->assertEmpty($capturedPayload['template']['components']);
    }
}
