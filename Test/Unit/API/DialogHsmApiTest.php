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


}
?>