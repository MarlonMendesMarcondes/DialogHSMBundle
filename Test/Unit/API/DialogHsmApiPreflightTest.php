<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMApi;
use MauticPlugin\DialogHSMBundle\Service\PartnerConfigProvider;
use MauticPlugin\DialogHSMBundle\DTO\PreflightResult;
use MauticPlugin\DialogHSMBundle\Service\TemplatePreflightValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integração da pré-validação na DialogHSMApi: modo off/log/enforce, payload normalizado e
 * nenhuma chamada à API quando o envio é barrado.
 */
class DialogHsmApiPreflightTest extends TestCase
{
    private const URL = 'https://waba-v2.360dialog.io/messages';

    private Client&MockObject $client;
    private TemplatePreflightValidator&MockObject $validator;
    private DialogHSMApi $api;

    /** @var array<int, array<string, mixed>> */
    private array $posted = [];

    protected function setUp(): void
    {
        putenv('DIALOGHSM_DISABLE_SSRF_CHECK=0');

        $this->client = $this->createMock(Client::class);
        $this->client->method('request')->willReturnCallback(function (string $method, string $url, array $options): Response {
            $this->posted[] = $options['json'];

            return new Response(200, [], json_encode(['messages' => [['id' => 'wamid.ok']]]));
        });

        $this->validator = $this->createMock(TemplatePreflightValidator::class);
        $this->api       = new DialogHSMApi($this->client, new NullLogger(), static fn (string $h): string => '1.2.3.5', null, $this->validator);
    }

    protected function tearDown(): void
    {
        putenv('DIALOGHSM_DISABLE_SSRF_CHECK');
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadData(): array
    {
        return [
            'content'  => 'promo',
            'language' => 'pt_BR',
            'vars'     => 'nome,curso',
            'nome'     => '',
            'curso'    => "Direito\nNoturno",
            '_tokens'  => ['nome' => '{contactfield=firstname}'],
        ];
    }

    public function testEnforceModeBlocksWithoutCallingTheApi(): void
    {
        $this->validator->method('getMode')->willReturn(PartnerConfigProvider::PREFLIGHT_ENFORCE);
        $this->validator->expects($this->once())->method('validate')
            ->with('KEY', self::URL, $this->anything(), [
                'body_labels' => ['nome', 'curso'],
                'tokens'      => ['nome' => '{contactfield=firstname}'],
            ])
            ->willReturn(PreflightResult::invalid('parâmetro 1 do BODY vazio (variável "nome", token {contactfield=firstname})', []));

        $result = $this->api->sendMessage('KEY', self::URL, '+5511999990000', self::payloadData());

        $this->assertFalse($result['success']);
        $this->assertTrue($result['invalid']);
        $this->assertFalse($result['retryable']);
        $this->assertNull($result['http_status']);
        $this->assertSame('Pré-validação: parâmetro 1 do BODY vazio (variável "nome", token {contactfield=firstname})', $result['error']);
        $this->assertSame([], $this->posted, 'A API não pode ser chamada para um envio barrado.');
    }

    public function testEnforceModeSendsTheNormalizedTemplate(): void
    {
        $this->validator->method('getMode')->willReturn(PartnerConfigProvider::PREFLIGHT_ENFORCE);
        $this->validator->method('validate')->willReturnCallback(static function (string $k, string $u, array $template): PreflightResult {
            $template['components'][0]['parameters'][1]['text'] = 'Direito Noturno';

            return PreflightResult::valid($template);
        });

        $result = $this->api->sendMessage('KEY', self::URL, '+5511999990000', self::payloadData());

        $this->assertTrue($result['success']);
        $this->assertSame('Direito Noturno', $this->posted[0]['template']['components'][0]['parameters'][1]['text']);
        $this->assertArrayNotHasKey('_tokens', $this->posted[0], 'Campo interno não vai para a API.');
    }

    public function testLogModeSendsTheOriginalPayloadEvenWhenInvalid(): void
    {
        $this->validator->method('getMode')->willReturn(PartnerConfigProvider::PREFLIGHT_LOG);
        $this->validator->method('validate')->willReturn(PreflightResult::invalid('motivo', []));

        $result = $this->api->sendMessage('KEY', self::URL, '+5511999990000', self::payloadData());

        $this->assertTrue($result['success']);
        $this->assertSame("Direito\nNoturno", $this->posted[0]['template']['components'][0]['parameters'][1]['text']);
    }

    public function testOffModeDoesNotValidate(): void
    {
        $this->validator->method('getMode')->willReturn(PartnerConfigProvider::PREFLIGHT_OFF);
        $this->validator->expects($this->never())->method('validate');

        $this->assertTrue($this->api->sendMessage('KEY', self::URL, '+5511999990000', self::payloadData())['success']);
    }

    public function testValidatorErrorNeverBlocksTheSend(): void
    {
        $this->validator->method('getMode')->willReturn(PartnerConfigProvider::PREFLIGHT_ENFORCE);
        $this->validator->method('validate')->willThrowException(new \RuntimeException('bug'));

        $this->assertTrue($this->api->sendMessage('KEY', self::URL, '+5511999990000', self::payloadData())['success']);
    }

}
