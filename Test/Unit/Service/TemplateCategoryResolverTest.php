<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\DialogHSMBundle\Service\TemplateCategoryResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class TemplateCategoryResolverTest extends TestCase
{
    private const MM_URL = 'https://waba-v2.360dialog.io/marketing_messages';

    private Client&MockObject $client;
    private ArrayAdapter $cache;
    private TemplateCategoryResolver $resolver;

    protected function setUp(): void
    {
        $this->client   = $this->createMock(Client::class);
        $this->cache    = new ArrayAdapter();
        $this->resolver = new TemplateCategoryResolver($this->client, $this->cache, new NullLogger());
    }

    /**
     * @param array<int, array<string, string>> $templates
     * @param array<string, mixed>              $paging
     */
    private static function page(array $templates, array $paging = []): Response
    {
        return new Response(200, [], json_encode(['data' => $templates] + ([] === $paging ? [] : ['paging' => $paging])));
    }

    public function testReturnsTheTemplateCategory(): void
    {
        $this->client->expects($this->once())->method('request')
            ->with('GET', 'https://waba-v2.360dialog.io/message_templates', $this->callback(
                fn (array $o): bool => 'KEY' === $o['headers']['D360-API-KEY'] && 'name,category,language,status,components' === $o['query']['fields']
            ))
            ->willReturn(self::page([
                ['name' => 'cs_ativacao_disc1', 'category' => 'UTILITY'],
                ['name' => 'promo', 'category' => 'MARKETING'],
            ]));

        $this->assertSame('UTILITY', $this->resolver->getCategory('KEY', self::MM_URL, 'cs_ativacao_disc1'));
    }

    public function testListIsFetchedOnceAndServedFromCache(): void
    {
        $this->client->expects($this->once())->method('request')
            ->willReturn(self::page([
                ['name' => 'a', 'category' => 'UTILITY'],
                ['name' => 'b', 'category' => 'marketing'],
            ]));

        $this->assertSame('UTILITY', $this->resolver->getCategory('KEY', self::MM_URL, 'a'));
        $this->assertSame('MARKETING', $this->resolver->getCategory('KEY', self::MM_URL, 'b'));
    }

    public function testEachApiKeyHasItsOwnList(): void
    {
        $this->client->expects($this->exactly(2))->method('request')
            ->willReturnOnConsecutiveCalls(
                self::page([['name' => 'x', 'category' => 'UTILITY']]),
                self::page([['name' => 'x', 'category' => 'MARKETING']]),
            );

        $this->assertSame('UTILITY', $this->resolver->getCategory('KEY_A', self::MM_URL, 'x'));
        $this->assertSame('MARKETING', $this->resolver->getCategory('KEY_B', self::MM_URL, 'x'));
    }

    public function testFollowsPagination(): void
    {
        $queries = [];
        $this->client->expects($this->exactly(2))->method('request')
            ->willReturnCallback(function (string $m, string $u, array $o) use (&$queries) {
                $queries[] = $o['query'];

                return 1 === count($queries)
                    ? self::page([['name' => 'p1', 'category' => 'MARKETING']], ['cursors' => ['after' => 'CUR1'], 'next' => 'https://waba-v2.360dialog.io/message_templates?after=CUR1'])
                    : self::page([['name' => 'p2', 'category' => 'UTILITY']], ['cursors' => ['after' => 'CUR2']]);
            });

        $this->assertSame('UTILITY', $this->resolver->getCategory('KEY', self::MM_URL, 'p2'));
        $this->assertArrayNotHasKey('after', $queries[0]);
        $this->assertSame('CUR1', $queries[1]['after']);
    }

    public function testLegacyResponseShapeIsAccepted(): void
    {
        $this->client->method('request')
            ->willReturn(new Response(200, [], json_encode(['waba_templates' => [['name' => 'old', 'category' => 'AUTHENTICATION']]])));

        $this->assertSame('AUTHENTICATION', $this->resolver->getCategory('KEY', self::MM_URL, 'old'));
    }

    public function testTemplateWithDifferentCategoriesPerLanguageIsUnknown(): void
    {
        $this->client->method('request')
            ->willReturn(self::page([
                ['name' => 'multi', 'category' => 'UTILITY'],
                ['name' => 'multi', 'category' => 'MARKETING'],
                ['name' => 'multi', 'category' => 'UTILITY'],
            ]));

        $this->assertNull($this->resolver->getCategory('KEY', self::MM_URL, 'multi'));
    }

    public function testUnknownTemplateReturnsNull(): void
    {
        $this->client->method('request')->willReturn(self::page([['name' => 'a', 'category' => 'UTILITY']]));

        $this->assertNull($this->resolver->getCategory('KEY', self::MM_URL, 'nao_existe'));
    }

    public function testOtherHostsAreNotQueried(): void
    {
        $this->client->expects($this->never())->method('request');

        $this->assertNull($this->resolver->getCategory('TOKEN', 'https://graph.facebook.com/v18.0/123/marketing_messages', 'a'));
        $this->assertNull($this->resolver->getCategory('KEY', 'https://evil.example/marketing_messages', 'a'));
    }

    public function testFailedLookupIsNotCachedButNotRetriedInTheSameProcess(): void
    {
        $this->client->expects($this->once())->method('request')
            ->willReturn(new Response(401, [], json_encode(['error' => 'Invalid api token.'])));

        $this->assertNull($this->resolver->getCategory('KEY', self::MM_URL, 'a'));
        $this->assertNull($this->resolver->getCategory('KEY', self::MM_URL, 'a'));
        $this->assertFalse($this->cache->hasItem('dialoghsm_templates_v2_'.hash('sha256', 'KEY')));
    }

    public function testNetworkErrorReturnsNull(): void
    {
        $this->client->method('request')->willThrowException(new \RuntimeException('timeout'));

        $this->assertNull($this->resolver->getCategory('KEY', self::MM_URL, 'a'));
    }

    public function testResponseWithoutListReturnsNull(): void
    {
        $this->client->method('request')->willReturn(new Response(200, [], '{"foo":1}'));

        $this->assertNull($this->resolver->getCategory('KEY', self::MM_URL, 'a'));
    }

}
