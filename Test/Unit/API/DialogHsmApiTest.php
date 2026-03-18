<?php
declare(strict_types=1);
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMApi;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class DialogHsmApiTest extends TestCase
{
    private Client&MockObject $mockClient;
    private LoggerInterface&MockObject $mocklogger;
    private DialogHSMApi $api;

    protected function setUp(): void
        {
            $this->mockClient = $this->createMock(Client::class);
            $this->mocklogger = $this->createMock(LoggerInterface::class);
            $this->api = new DialogHSMApi($this->mockClient, $this->mocklogger);
        }
    
    
    public function testSendMessageSuccess(): void
        {
            $mockResponse = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));
            $this->mockClient->method('request')->willReturn($mockResponse);

            $result = $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
                'content' => 'nome_template',
                'language' => 'pt_BR',
                'vars' => 'nome',
                'nome' => 'João',
            ]);

            $this->assertTrue($result['success']);
            $this->assertEquals('abc', $result['response']['message'][0]['id']);
            $this->assertNull($result['error']);
            $this->assertEquals(200, $result['http_status']);
        }
    
    
    public function testSendMessageHttpError404(): void
    {
        $mockResponse = new Response(404, [] , json_encode(['error' => [['Not Found']]]));
        $this->mockClient->method('request')->willReturn($mockResponse);

        $result = $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content' => 'nome_template',
            'language' => 'pt_BR',
            'vars' => 'nome',
            'nome' => 'João',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('HTTP 404: {"error":[["Not Found"]]}', $result['error']);
        $this->assertEquals(404, $result['http_status']);
    }

    public function testSendMessageHttpError500(): void
    {
        $mockResponse = new Response(500, [] , json_encode(['error' => [['Internal Server Error']]]));
        $this->mockClient->method('request')->willReturn($mockResponse);

        $result = $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content' => 'nome_template',
            'language' => 'pt_BR',
            'vars' => 'nome',
            'nome' => 'João',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('HTTP 500: {"error":[["Internal Server Error"]]}', $result['error']);
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
            'vars'     => 'nome',
            'nome'     => 'João',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('Network error', $result['error']);
        $this->assertNull($result['http_status']);
    }

    public function testSendMessageGenericException(): void
    {
        $this->mockClient->method('request')->willThrowException(new \Exception('Unexpected error'));

        $result = $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content' => 'nome_template',
            'language' => 'pt_BR',
            'vars' => 'nome',
            'nome' => 'João',
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
            'content' => 'nome_template',
            'language' => 'pt_BR',
            'vars' => 'nome',
            'nome' => 'João',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('HTTP 400: null', $result['error']);
        $this->assertEquals(400, $result['http_status']);
    }

    public function testHeaderWithJpgImage(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];

                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'nome_template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://example.com/image.jpg',
        ]);

        $this->assertNotEmpty($capturedPayload['template']['components']);
        $header = $capturedPayload['template']['components'][0];
        $this->assertEquals('header', $header['type']);
        $this->assertEquals('image', $header['parameters'][0]['type']);
        $this->assertEquals('https://example.com/image.jpg', $header['parameters'][0]['image']['link']);
    }

    public function testHeaderWithPngImage(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];

                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'nome_template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://example.com/banner.png',
        ]);

        $header = $capturedPayload['template']['components'][0];
        $this->assertEquals('header', $header['type']);
        $this->assertEquals('image', $header['parameters'][0]['type']);
        $this->assertEquals('https://example.com/banner.png', $header['parameters'][0]['image']['link']);
    }

    public function testHeaderWithMp4Video(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];

                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'nome_template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://example.com/video.mp4',
        ]);

        $header = $capturedPayload['template']['components'][0];
        $this->assertEquals('header', $header['type']);
        $this->assertEquals('video', $header['parameters'][0]['type']);
        $this->assertEquals('https://example.com/video.mp4', $header['parameters'][0]['video']['link']);
    }

    public function testHeaderWithPdfDocument(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];

                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'nome_template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://example.com/contrato.pdf',
        ]);

        $header = $capturedPayload['template']['components'][0];
        $this->assertEquals('header', $header['type']);
        $this->assertEquals('document', $header['parameters'][0]['type']);
        $this->assertEquals('https://example.com/contrato.pdf', $header['parameters'][0]['document']['link']);
    }

    public function testHeaderWithUnknownExtensionProducesNoHeaderComponent(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];

                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'     => 'nome_template',
            'language'    => 'pt_BR',
            'url_arquivo' => 'https://example.com/arquivo.txt',
        ]);

        // Extensão desconhecida → buildHeaderParameter retorna null → sem componente de header
        $this->assertEmpty($capturedPayload['template']['components']);
    }

    public function testBodyVariablesAreIncludedAsTextParameters(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
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
        $body       = array_values(array_filter($components, fn($c) => $c['type'] === 'body'))[0] ?? null;

        $this->assertNotNull($body);
        $this->assertCount(2, $body['parameters']);
        $this->assertEquals('text', $body['parameters'][0]['type']);
        $this->assertEquals('João', $body['parameters'][0]['text']);
        $this->assertEquals('text', $body['parameters'][1]['type']);
        $this->assertEquals('São Paulo', $body['parameters'][1]['text']);
    }

    public function testButtonComponentsAreIncludedInPayload(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];

                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'      => 'nome_template',
            'language'     => 'pt_BR',
            'buttons'      => 'url, quick_reply',
            'buttons_vars' => 'pagina, sim',
        ]);

        $components = $capturedPayload['template']['components'];
        $buttons    = array_values(array_filter($components, fn($c) => $c['type'] === 'button'));

        $this->assertCount(2, $buttons);
        $this->assertEquals('url', $buttons[0]['sub_type']);
        $this->assertEquals(0, $buttons[0]['index']);
        $this->assertEquals('pagina', $buttons[0]['parameters'][0]['text']);
        $this->assertEquals('quick_reply', $buttons[1]['sub_type']);
        $this->assertEquals(1, $buttons[1]['index']);
        $this->assertEquals('sim', $buttons[1]['parameters'][0]['text']);
    }

    public function testPhoneWithPlusPrefixIsPreservedWithoutDoubleDdi(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];

                return $mockResponse;
            });

        // Número já vem com "+" → deve preservar o DDI sem duplicar
        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '+5511999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
        ]);

        $this->assertEquals('+5511999999999', $capturedPayload['to']);
    }

    public function testPhoneWith55PrefixGetsLeadingPlus(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];

                return $mockResponse;
            });

        // Número com DDI 55 mas sem "+" → adiciona "+"
        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '5511999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
        ]);

        $this->assertEquals('+5511999999999', $capturedPayload['to']);
    }

    public function testPhoneWithoutCountryCodeGetsBrazilPrefix(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];

                return $mockResponse;
            });

        // Número local sem DDI → prefixa com "+55"
        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
        ]);

        $this->assertEquals('+5511999999999', $capturedPayload['to']);
    }

    public function testBaseUrlTrailingSlashIsStripped(): void
    {
        $capturedUrl  = null;
        $mockResponse = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedUrl, $mockResponse) {
                $capturedUrl = $url;

                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages/', '11999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
        ]);

        $this->assertEquals('https://api.360dialog.com/v1/messages', $capturedUrl);
    }

    public function testLimitedTimeOfferComponentIsIncludedInPayload(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];

                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'            => 'nome_template',
            'language'           => 'pt_BR',
            'limited_time_offer' => '2024-12-31T23:59:59',
        ]);

        $components = $capturedPayload['template']['components'];
        $lto        = array_values(array_filter($components, fn ($c) => $c['type'] === 'limited_time_offer'))[0] ?? null;

        $this->assertNotNull($lto);
        $this->assertEquals('limited_time_offer', $lto['parameters'][0]['type']);
        $expectedMs = (new \DateTime('2024-12-31T23:59:59'))->getTimestamp() * 1000;
        $this->assertEquals($expectedMs, $lto['parameters'][0]['limited_time_offer']['expiration_time_ms']);
    }

    public function testLimitedTimeOfferComponentIsAbsentWhenNotProvided(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
                $capturedPayload = $options['json'];

                return $mockResponse;
            });

        $this->api->sendMessage('API_KEY', 'https://api.360dialog.com/v1/messages', '11999999999', [
            'content'  => 'nome_template',
            'language' => 'pt_BR',
        ]);

        $components = $capturedPayload['template']['components'];
        $lto        = array_values(array_filter($components, fn ($c) => $c['type'] === 'limited_time_offer'));

        $this->assertEmpty($lto);
    }

    public function testLimitedTimeOfferComponentIsPositionedBeforeButtons(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
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

        $components = $capturedPayload['template']['components'];
        $types      = array_column($components, 'type');

        $ltoIndex    = array_search('limited_time_offer', $types);
        $buttonIndex = array_search('button', $types);

        $this->assertNotFalse($ltoIndex);
        $this->assertNotFalse($buttonIndex);
        $this->assertLessThan($buttonIndex, $ltoIndex, 'limited_time_offer deve aparecer antes dos buttons');
    }

    public function testLimitedTimeOfferWithAllComponents(): void
    {
        $capturedPayload = null;
        $mockResponse    = new Response(200, [], json_encode(['message' => [['id' => 'abc']]]));

        $this->mockClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayload, $mockResponse) {
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

        $components = $capturedPayload['template']['components'];
        $types      = array_column($components, 'type');

        $this->assertContains('header', $types);
        $this->assertContains('body', $types);
        $this->assertContains('limited_time_offer', $types);
        $this->assertContains('button', $types);

        // Ordem: header → body → limited_time_offer → button
        $this->assertLessThan(
            array_search('limited_time_offer', $types),
            array_search('body', $types),
            'body deve aparecer antes de limited_time_offer'
        );
        $this->assertLessThan(
            array_search('button', $types),
            array_search('limited_time_offer', $types),
            'limited_time_offer deve aparecer antes de button'
        );
    }
}
?>