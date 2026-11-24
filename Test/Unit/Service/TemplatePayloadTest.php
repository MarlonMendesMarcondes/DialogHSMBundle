<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Service\TemplatePayload;
use PHPUnit\Framework\TestCase;

/**
 * Conversão entre o formulário guiado de template e os pares do Payload Data.
 */
class TemplatePayloadTest extends TestCase
{
    /**
     * @param list<array{label: string, value: string}> $list
     *
     * @return array<string, string>
     */
    private static function pairs(array $list): array
    {
        return array_column($list, 'value', 'label');
    }

    public function testGuidedFieldsBecomeThePairsBuildPayloadReads(): void
    {
        $pairs = self::pairs(TemplatePayload::toList('boas_vindas', 'es', json_encode([
            'header'  => 'https://cdn.x.com/a.jpg',
            'body'    => ['{contactfield=firstname}', 'Direito'],
            'buttons' => ['1' => ['type' => 'URL', 'value' => 'abc']],
        ])));

        $this->assertSame([
            'content'      => 'boas_vindas',
            'language'     => 'es',
            '{{1}}'        => '{contactfield=firstname}',
            '{{2}}'        => 'Direito',
            'vars'         => '{{1}},{{2}}',
            'url_arquivo'  => 'https://cdn.x.com/a.jpg',
            'buttons'      => ',url',
            'buttons_vars' => ',abc',
        ], $pairs);
    }

    public function testWithoutTemplateThereIsNothingToSend(): void
    {
        $this->assertSame([], TemplatePayload::toList('  ', 'pt_BR', '{"body":["x"]}'));
    }

    public function testInvalidParamsJsonStillSendsTheTemplate(): void
    {
        $this->assertSame(['content' => 'aviso', 'language' => 'pt_BR', 'vars' => ''], self::pairs(TemplatePayload::toList('aviso', '', '{quebrado')));
    }

    public function testGuidedPairsWinOverRepeatedAdvancedLabels(): void
    {
        $merged = TemplatePayload::merge(
            [['label' => 'content', 'value' => 'novo']],
            [['label' => 'content', 'value' => 'velho'], ['label' => 'limited_time_offer', 'value' => '2026-12-31']]
        );

        $this->assertSame(['content' => 'novo', 'limited_time_offer' => '2026-12-31'], self::pairs($merged));
    }

    public function testStoredMessageWithoutGuidedFieldsIsSentAsBefore(): void
    {
        $legacy = ['list' => [['label' => 'nome', 'value' => '{contactfield=firstname}']]];

        $this->assertSame($legacy, TemplatePayload::expandStored('promo', $legacy));
    }

    public function testStoredGuidedFieldsAreExpandedWithTheAdvancedPairs(): void
    {
        $expanded = TemplatePayload::expandStored('promo', [
            'list' => [['label' => 'limited_time_offer', 'value' => '2026-12-31']],
            'hsm'  => ['language' => 'pt_BR', 'params' => ['body' => ['Ana']]],
        ]);

        $this->assertSame([
            'content'            => 'promo',
            'language'           => 'pt_BR',
            '{{1}}'              => 'Ana',
            'vars'               => '{{1}}',
            'limited_time_offer' => '2026-12-31',
        ], self::pairs($expanded['list']));
    }

    public function testLegacyPairsWithVarsBecomeGuidedFieldsKeepingTheRest(): void
    {
        $converted = TemplatePayload::fromLegacyList([
            ['label' => 'content', 'value' => 'promo'],
            ['label' => 'language', 'value' => 'es'],
            ['label' => 'vars', 'value' => 'nome, curso'],
            ['label' => 'curso', 'value' => 'Direito'],
            ['label' => 'nome', 'value' => '{contactfield=firstname}'],
            ['label' => 'url_arquivo', 'value' => 'https://x.com/a.pdf'],
            ['label' => 'buttons', 'value' => ',url'],
            ['label' => 'buttons_vars', 'value' => ',abc'],
            ['label' => 'limited_time_offer', 'value' => '2026-12-31'],
        ]);

        $this->assertSame('es', $converted['language']);
        $this->assertSame([
            'header'  => 'https://x.com/a.pdf',
            'body'    => ['{contactfield=firstname}', 'Direito'],
            'buttons' => [1 => ['type' => 'url', 'value' => 'abc']],
        ], $converted['params']);
        $this->assertSame([['label' => 'limited_time_offer', 'value' => '2026-12-31']], $converted['advanced']);
    }

    /**
     * Sem "vars", a Mensagem WhatsApp usava como variáveis todos os rótulos que não são de
     * controle, na ordem — a conversão mantém exatamente o mesmo envio.
     */
    public function testLegacyPairsWithoutVarsUseTheNonControlLabelsInOrder(): void
    {
        $list = [
            ['label' => 'nome', 'value' => '{contactfield=firstname}'],
            ['label' => 'limited_time_offer', 'value' => '2026-12-31'],
            ['label' => 'curso', 'value' => 'Direito'],
        ];

        $converted = TemplatePayload::fromLegacyList($list);
        $this->assertSame(['{contactfield=firstname}', 'Direito'], $converted['params']['body']);
        $this->assertSame('pt_BR', $converted['language']);

        // Ida e volta: o que o formulário guiado grava gera as mesmas variáveis do body.
        $expanded = self::pairs(TemplatePayload::expandStored('promo', [
            'list' => $converted['advanced'],
            'hsm'  => ['language' => $converted['language'], 'params' => $converted['params']],
        ])['list']);
        $this->assertSame(['{contactfield=firstname}', 'Direito'], [$expanded['{{1}}'], $expanded['{{2}}']]);
        $this->assertSame('2026-12-31', $expanded['limited_time_offer']);
    }

    // ── variáveis com origem ─────────────────────────────────────────────────

    public function testVariablesWithSourceAreResolvedWithTheTemplateLanguage(): void
    {
        $seen  = [];
        $pairs = self::pairs(TemplatePayload::toList('promo', 'en', json_encode(['body' => [
            ['source' => 'field', 'field' => 'firstname'],
            ['source' => 'static', 'value' => 'Direito'],
            'texto antigo',
        ]]), static function (mixed $variable, string $locale) use (&$seen): string {
            $seen[] = $locale;

            return is_array($variable) && 'field' === $variable['source'] ? 'Maria' : (is_array($variable) ? $variable['value'] : $variable);
        }));

        $this->assertSame(['Maria', 'Direito', 'texto antigo'], [$pairs['{{1}}'], $pairs['{{2}}'], $pairs['{{3}}']]);
        $this->assertSame(['en', 'en', 'en'], $seen);
    }

    public function testWithoutResolverOnlyStaticValuesAreKept(): void
    {
        $pairs = self::pairs(TemplatePayload::toList('promo', '', ['body' => [
            ['source' => 'field', 'field' => 'firstname'],
            ['source' => 'static', 'value' => 'Direito'],
        ]]));

        $this->assertSame(['', 'Direito'], [$pairs['{{1}}'], $pairs['{{2}}']]);
    }

    public function testHintsDescribeTheSourceOfEachVariable(): void
    {
        $this->assertSame([
            '{{1}}' => '{contactfield=firstname}',
            '{{3}}' => '{customobject=curso.inicio}',
        ], TemplatePayload::variableHints(json_encode(['body' => [
            ['source' => 'field', 'field' => 'firstname'],
            'fixo',
            ['source' => 'custom_object', 'customObject' => 'curso', 'customObjectField' => 'inicio'],
        ]])));
    }

    public function testStoredMessagePassesTheResolverAlong(): void
    {
        $expanded = TemplatePayload::expandStored('promo', [
            'list' => [],
            'hsm'  => ['language' => 'pt_BR', 'params' => ['body' => [['source' => 'field', 'field' => 'firstname']]]],
        ], static fn (mixed $variable, string $locale): string => 'Ana/'.$locale);

        $this->assertSame('Ana/pt_BR', self::pairs($expanded['list'])['{{1}}']);
    }
}
