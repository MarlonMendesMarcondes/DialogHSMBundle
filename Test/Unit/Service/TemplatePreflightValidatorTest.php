<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\DialogHSMBundle\Service\PartnerConfigProvider;
use MauticPlugin\DialogHSMBundle\Service\TemplateCategoryResolver;
use MauticPlugin\DialogHSMBundle\Service\TemplatePreflightValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Um caso por erro de envio previsível visto em produção (#132001, #132000, #131008, #132012,
 * #100) e os fallbacks: sem a definição do template, o envio sempre segue.
 *
 * Usa o TemplateCategoryResolver real, com o HTTP simulado: a validação é exercitada sobre a
 * mesma definição que viria da listagem /message_templates da 360dialog.
 */
class TemplatePreflightValidatorTest extends TestCase
{
    private const URL = 'https://waba-v2.360dialog.io/messages';

    private Client&MockObject $client;
    private int $now = 1_700_000_000;
    private int $requests = 0;

    /** @var list<array<int, array<string, mixed>>> páginas devolvidas a cada listagem, em ordem */
    private array $listings = [];

    private TemplatePreflightValidator $validator;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->client->method('request')->willReturnCallback(function (): Response {
            $templates = $this->listings[min($this->requests, count($this->listings) - 1)] ?? null;
            ++$this->requests;

            if (null === $templates) {
                return new Response(500, [], '{}');
            }

            return new Response(200, [], json_encode(['data' => $templates]));
        });

        $resolver = new TemplateCategoryResolver($this->client, new ArrayAdapter(), new NullLogger(), fn (): int => $this->now);

        $config = $this->createMock(PartnerConfigProvider::class);
        $config->method('getPreflightMode')->willReturn(PartnerConfigProvider::PREFLIGHT_ENFORCE);

        $this->validator = new TemplatePreflightValidator($resolver, $config);
    }

    /**
     * @param array<int, array<string, mixed>> $templates
     */
    private function givenTemplates(array $templates): void
    {
        $this->listings[] = $templates;
    }

    /**
     * @param array<int, array<string, mixed>> $components
     *
     * @return array<string, mixed>
     */
    private static function tpl(string $name, array $components, string $language = 'pt_BR', string $status = 'APPROVED'): array
    {
        return ['name' => $name, 'language' => $language, 'status' => $status, 'category' => 'MARKETING', 'components' => $components];
    }

    /**
     * @param array<int, array<string, mixed>> $components
     *
     * @return array<string, mixed>
     */
    private static function send(string $name, array $components, string $language = 'pt_BR'): array
    {
        return ['name' => $name, 'language' => ['code' => $language], 'components' => $components];
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(string ...$values): array
    {
        return ['type' => 'body', 'parameters' => array_map(static fn (string $v): array => ['type' => 'text', 'text' => $v], $values)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function header(string $type, string $link = 'https://cdn.example.com/a.jpg'): array
    {
        return ['type' => 'header', 'parameters' => [['type' => $type, $type => ['link' => $link]]]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function button(int $index, string $subType, string $text): array
    {
        return ['type' => 'button', 'sub_type' => $subType, 'index' => $index, 'parameters' => [['type' => 'text', 'text' => $text]]];
    }

    // ── válido ───────────────────────────────────────────────────────────────

    public function testValidMessageWithMediaHeaderBodyAndButtonVariablePasses(): void
    {
        $this->givenTemplates([self::tpl('promo', [
            ['type' => 'HEADER', 'format' => 'IMAGE'],
            ['type' => 'BODY', 'text' => 'Olá {{1}}, seu curso {{2}} começa amanhã.'],
            ['type' => 'FOOTER', 'text' => 'Equipe'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Quero'],
                ['type' => 'URL', 'text' => 'Ver', 'url' => 'https://site.com/c/{{1}}'],
            ]],
        ])]);

        $result = $this->validator->validate('KEY', self::URL, self::send('promo', [
            self::header('image'),
            self::body('Maria', 'Direito'),
            self::button(1, 'url', 'abc123'),
        ]));

        $this->assertTrue($result->valid, (string) $result->reason);
        $this->assertNull($result->reason);
    }

    // ── #132001 ──────────────────────────────────────────────────────────────

    public function testTemplateThatDoesNotExistIsRejected(): void
    {
        $this->givenTemplates([self::tpl('outro', [['type' => 'BODY', 'text' => 'oi']])]);

        $result = $this->validator->validate('KEY', self::URL, self::send('apagado', []));

        $this->assertFalse($result->valid);
        $this->assertSame('template "apagado" não existe neste número', $result->reason);
    }

    public function testTemplateInAnotherLanguageIsRejectedNamingTheLanguagesItHas(): void
    {
        $this->givenTemplates([self::tpl('boas_vindas', [['type' => 'BODY', 'text' => 'hi']], 'en_US')]);

        $result = $this->validator->validate('KEY', self::URL, self::send('boas_vindas', [], 'pt_BR'));

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('não existe no idioma pt_BR', (string) $result->reason);
        $this->assertStringContainsString('en_US', (string) $result->reason);
    }

    public function testTemplateNotApprovedIsRejected(): void
    {
        $this->givenTemplates([self::tpl('pausado', [['type' => 'BODY', 'text' => 'oi']], 'pt_BR', 'PAUSED')]);

        $result = $this->validator->validate('KEY', self::URL, self::send('pausado', []));

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('status PAUSED', (string) $result->reason);
    }

    // ── #132000 ──────────────────────────────────────────────────────────────

    public function testBodyParameterCountDifferentFromTemplateIsRejected(): void
    {
        $this->givenTemplates([self::tpl('t', [['type' => 'BODY', 'text' => '{{1}} e {{2}} e {{3}}']])]);

        $result = $this->validator->validate('KEY', self::URL, self::send('t', [self::body('a', 'b')]));

        $this->assertFalse($result->valid);
        $this->assertSame('BODY do template espera 3 parâmetro(s), a mensagem envia 2', $result->reason);
    }

    public function testRepeatedVariableCountsOnce(): void
    {
        $this->givenTemplates([self::tpl('t', [['type' => 'BODY', 'text' => '{{1}}, oi {{1}}! Curso {{2}}']])]);

        $this->assertTrue($this->validator->validate('KEY', self::URL, self::send('t', [self::body('a', 'b')]))->valid);
    }

    // ── #131008 ──────────────────────────────────────────────────────────────

    public function testEmptyBodyParameterIsRejectedNamingTheToken(): void
    {
        $this->givenTemplates([self::tpl('t', [['type' => 'BODY', 'text' => '{{1}} {{2}}']])]);

        $result = $this->validator->validate('KEY', self::URL, self::send('t', [self::body('Curso', '')]), [
            'body_labels' => ['curso', 'nome'],
            'tokens'      => ['nome' => '{contactfield=firstname}'],
        ]);

        $this->assertFalse($result->valid);
        $this->assertSame('parâmetro 2 do BODY vazio (variável "nome", token {contactfield=firstname})', $result->reason);
    }

    public function testEmptyBodyParameterIsRejectedEvenWithoutTemplateDefinition(): void
    {
        // Lista indisponível (HTTP 500): parâmetro vazio é recusado pela Meta em qualquer template.
        $result = $this->validator->validate('KEY', self::URL, self::send('t', [self::body('  ')]));

        $this->assertFalse($result->valid);
        $this->assertSame('parâmetro 1 do BODY vazio', $result->reason);
    }

    public function testMediaHeaderWithoutMediaIsRejected(): void
    {
        $this->givenTemplates([self::tpl('t', [['type' => 'HEADER', 'format' => 'IMAGE'], ['type' => 'BODY', 'text' => 'oi']])]);

        $result = $this->validator->validate('KEY', self::URL, self::send('t', []));

        $this->assertFalse($result->valid);
        $this->assertStringStartsWith('HEADER do template é IMAGE, mas a mensagem não envia mídia', (string) $result->reason);
    }

    public function testButtonVariableWithoutValueIsRejected(): void
    {
        $this->givenTemplates([self::tpl('t', [
            ['type' => 'BODY', 'text' => 'oi'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'url' => 'https://x.com/{{1}}']]],
        ])]);

        $missing = $this->validator->validate('KEY', self::URL, self::send('t', []));
        $empty   = $this->validator->validate('KEY', self::URL, self::send('t', [self::button(0, 'url', ' ')]));

        $this->assertStringContainsString('o botão 0 (URL) do template tem variável', (string) $missing->reason);
        $this->assertSame('parâmetro do botão 0 (URL) vazio', $empty->reason);
    }

    // ── #132012 ──────────────────────────────────────────────────────────────

    public function testHeaderMediaTypeDifferentFromTemplateIsRejected(): void
    {
        $this->givenTemplates([self::tpl('t', [['type' => 'HEADER', 'format' => 'VIDEO'], ['type' => 'BODY', 'text' => 'oi']])]);

        $result = $this->validator->validate('KEY', self::URL, self::send('t', [self::header('image')]));

        $this->assertFalse($result->valid);
        $this->assertSame('HEADER do template é VIDEO, mas a mensagem envia image', $result->reason);
    }

    public function testMediaSentToTemplateWithoutHeaderIsRejected(): void
    {
        $this->givenTemplates([self::tpl('t', [['type' => 'BODY', 'text' => 'oi']])]);

        $result = $this->validator->validate('KEY', self::URL, self::send('t', [self::header('document', 'https://x.com/a.pdf')]));

        $this->assertSame('o template não tem HEADER, mas a mensagem envia um (document)', $result->reason);
    }

    // ── #100 ─────────────────────────────────────────────────────────────────

    public function testLineBreaksTabsAndRepeatedSpacesAreNormalized(): void
    {
        $this->givenTemplates([self::tpl('t', [['type' => 'BODY', 'text' => '{{1}}']])]);

        $result = $this->validator->validate('KEY', self::URL, self::send('t', [self::body("  Rua A,\n 123\t\tCentro      SP ")]));

        $this->assertTrue($result->valid);
        $this->assertSame('Rua A, 123 Centro SP', $result->template['components'][0]['parameters'][0]['text']);
    }

    public function testParameterWithOnlyLineBreaksIsRejected(): void
    {
        $result = $this->validator->validate('KEY', self::URL, self::send('t', [self::body("\n\t ")]));

        $this->assertFalse($result->valid);
        $this->assertSame('parâmetro 1 do BODY vazio', $result->reason);
    }

    // ── carrossel ────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function carouselTemplate(int $cards): array
    {
        $card = ['components' => [
            ['type' => 'HEADER', 'format' => 'IMAGE'],
            ['type' => 'BODY', 'text' => 'Card fixo'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Quero']]],
        ]];

        return self::tpl('carrossel', [
            ['type' => 'BODY', 'text' => 'Oi {{1}}'],
            ['type' => 'CAROUSEL', 'cards' => array_fill(0, $cards, $card)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function carouselComponent(int $cards): array
    {
        $built = [];
        for ($i = 0; $i < $cards; ++$i) {
            $built[] = ['card_index' => $i, 'components' => [['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => "https://x.com/{$i}.png"]]]]]];
        }

        return ['type' => 'carousel', 'cards' => $built];
    }

    public function testValidCarouselPasses(): void
    {
        $this->givenTemplates([self::carouselTemplate(3)]);

        $result = $this->validator->validate('KEY', self::URL, self::send('carrossel', [self::body('Ana'), self::carouselComponent(3)]));

        $this->assertTrue($result->valid, (string) $result->reason);
    }

    public function testCarouselWithDifferentCardCountIsRejected(): void
    {
        $this->givenTemplates([self::carouselTemplate(3)]);

        $result = $this->validator->validate('KEY', self::URL, self::send('carrossel', [self::body('Ana'), self::carouselComponent(2)]));

        $this->assertSame('o carrossel do template tem 3 card(s), a mensagem envia 2', $result->reason);
    }

    public function testCarouselTemplateWithoutCardsIsRejected(): void
    {
        $this->givenTemplates([self::carouselTemplate(2)]);

        $result = $this->validator->validate('KEY', self::URL, self::send('carrossel', [self::body('Ana')]));

        $this->assertSame('o template é carrossel com 2 card(s), mas a mensagem não envia cards', $result->reason);
    }

    // ── fallback: sem dado, o envio segue ────────────────────────────────────

    public function testWithoutTemplateListTheMessageIsAccepted(): void
    {
        // Sem listagem configurada, o cliente devolve HTTP 500.
        $result = $this->validator->validate('KEY', self::URL, self::send('qualquer', [self::body('a', 'b', 'c')]));

        $this->assertTrue($result->valid);
    }

    public function testNon360dialogHostIsNotQueriedAndTheMessageIsAccepted(): void
    {
        $result = $this->validator->validate('TOKEN', 'https://graph.facebook.com/v21.0/123/messages', self::send('qualquer', [self::body('a')]));

        $this->assertTrue($result->valid);
        $this->assertSame(0, $this->requests);
    }

    public function testNamedVariablesHaveUnknownCountAndAreAccepted(): void
    {
        $this->givenTemplates([self::tpl('nomeado', [['type' => 'BODY', 'text' => 'Oi {{primeiro_nome}}']])]);

        $this->assertTrue($this->validator->validate('KEY', self::URL, self::send('nomeado', [self::body('Ana', 'extra')]))->valid);
    }

    public function testUnknownButtonTypeIsNotChecked(): void
    {
        $this->givenTemplates([self::tpl('otp', [
            ['type' => 'BODY', 'text' => '{{1}} é seu código'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE']]],
        ])]);

        $this->assertTrue($this->validator->validate('KEY', self::URL, self::send('otp', [self::body('1234')]))->valid);
    }

    public function testTemplateItemWithoutLanguageIsUnknownNotMissing(): void
    {
        $this->givenTemplates([['name' => 'sem_idioma', 'status' => 'APPROVED', 'category' => 'UTILITY']]);

        $this->assertTrue($this->validator->validate('KEY', self::URL, self::send('sem_idioma', []))->valid);
    }

    // ── cache desatualizado ──────────────────────────────────────────────────

    public function testStaleCacheIsReloadedBeforeRejectingATemplateCreatedAfterIt(): void
    {
        $this->givenTemplates([self::tpl('antigo', [['type' => 'BODY', 'text' => 'oi']])]);
        $this->givenTemplates([
            self::tpl('antigo', [['type' => 'BODY', 'text' => 'oi']]),
            self::tpl('novo', [['type' => 'BODY', 'text' => 'oi {{1}}']]),
        ]);

        $this->assertTrue($this->validator->validate('KEY', self::URL, self::send('antigo', []))->valid);

        $this->now += TemplateCategoryResolver::MIN_REFRESH_AGE;

        $result = $this->validator->validate('KEY', self::URL, self::send('novo', [self::body('Ana')]));

        $this->assertTrue($result->valid, (string) $result->reason);
        $this->assertSame(2, $this->requests);
    }

    public function testRecentCacheIsNotReloadedOnEveryRejection(): void
    {
        $this->givenTemplates([self::tpl('t', [['type' => 'BODY', 'text' => 'oi']])]);

        for ($i = 0; $i < 5; ++$i) {
            $this->assertFalse($this->validator->validate('KEY', self::URL, self::send('nao_existe', []))->valid);
        }

        $this->assertSame(1, $this->requests, 'Com o cache recente, a reprovação não pode gerar chamadas à 360dialog.');
    }
}
