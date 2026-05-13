<?php

declare(strict_types=1);

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\DialogHSMBundle\Controller\MessageLogController;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class MessageLogControllerTest extends TestCase
{
    /** @var MessageLogRepository&MockObject */
    private MessageLogRepository $repo;

    protected function setUp(): void
    {
        $this->repo = $this->getMockBuilder(MessageLogRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'countAll',
                'getLogs',
                'getDistinctSenderNames',
                'getDistinctTemplateNames',
                'deleteQueued',
                'getStatsByPeriod',
                'getChartData',
                'getGroupedDispatches',
            ])
            ->getMock();
    }

    /**
     * Builds a controller mock, stubbing infrastructure methods and optionally
     * setting coreParametersHelper via reflection (needed for dashboardAction).
     *
     * @param string[] $extraMethods
     */
    private function makeController(array $extraMethods = [], bool $withParams = false): MessageLogController&MockObject
    {
        $methods = array_unique(array_merge(
            ['isCsrfTokenValid', 'postActionRedirect', 'delegateView', 'generateUrl'],
            $extraMethods
        ));

        $controller = $this->getMockBuilder(MessageLogController::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();

        if ($withParams) {
            $params = $this->createMock(CoreParametersHelper::class);
            $params->method('get')->willReturn('UTC');

            $prop = new \ReflectionProperty(\Mautic\CoreBundle\Controller\CommonController::class, 'coreParametersHelper');
            $prop->setValue($controller, $params);
        }

        // Cache pass-through: executa o callback diretamente, sem armazenar.
        // Isso garante que os testes continuem verificando as chamadas ao repositório.
        $item  = $this->createMock(ItemInterface::class);
        $item->method('expiresAfter')->willReturnSelf();
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            static fn (string $key, callable $callback) => $callback($item)
        );
        $controller->setCache($cache);

        return $controller;
    }

    // =========================================================================
    // purgeQueuedAction — GET (renderiza formulário)
    // =========================================================================

    public function testPurgeQueuedGetDelegatesView(): void
    {
        $controller = $this->makeController();

        $this->repo->method('countAll')->willReturn(3);
        $this->repo->method('getDistinctSenderNames')->willReturn(['Número 1']);
        $this->repo->method('getDistinctTemplateNames')->willReturn(['hello_world']);

        $controller->expects($this->once())->method('delegateView')->willReturn(new Response());
        $controller->method('generateUrl')->willReturn('/dialoghsm/logs/purge');

        $request = Request::create('/dialoghsm/logs/purge', 'GET');
        $controller->purgeQueuedAction($request, $this->repo);
    }

    public function testPurgeQueuedGetPassesQueuedCountToView(): void
    {
        $controller = $this->makeController();

        $this->repo->method('countAll')
            ->with(['status' => MessageLog::STATUS_QUEUED])
            ->willReturn(42);
        $this->repo->method('getDistinctSenderNames')->willReturn([]);
        $this->repo->method('getDistinctTemplateNames')->willReturn([]);

        $capturedArgs = null;
        $controller->method('delegateView')
            ->willReturnCallback(static function (array $args) use (&$capturedArgs): Response {
                $capturedArgs = $args;
                return new Response();
            });
        $controller->method('generateUrl')->willReturn('/');

        $controller->purgeQueuedAction(Request::create('/purge', 'GET'), $this->repo);

        $this->assertSame(42, $capturedArgs['viewParameters']['totalQueued']);
    }

    // =========================================================================
    // purgeQueuedAction — POST: CSRF
    // =========================================================================

    public function testPurgeQueuedPostWithInvalidCsrfThrowsAccessDenied(): void
    {
        $controller = $this->makeController();
        $controller->method('isCsrfTokenValid')->willReturn(false);

        $request = Request::create('/purge', 'POST', ['_token' => 'bad']);

        $this->expectException(AccessDeniedException::class);
        $controller->purgeQueuedAction($request, $this->repo);
    }

    // =========================================================================
    // purgeQueuedAction — POST: parâmetros passados a deleteQueued
    // =========================================================================

    public function testPurgeQueuedPostPassesCampaignIdWhenPositive(): void
    {
        $controller = $this->makeController();
        $controller->method('isCsrfTokenValid')->willReturn(true);
        $controller->method('postActionRedirect')->willReturn(new Response());
        $controller->method('generateUrl')->willReturn('/');

        $this->repo
            ->expects($this->once())
            ->method('deleteQueued')
            ->with(null, null, 42)
            ->willReturn(5);

        $request = Request::create('/purge', 'POST', [
            '_token'     => 'tok',
            'campaignId' => '42',
        ]);
        $controller->purgeQueuedAction($request, $this->repo);
    }

    public function testPurgeQueuedPostPassesNullWhenCampaignIdIsZero(): void
    {
        $controller = $this->makeController();
        $controller->method('isCsrfTokenValid')->willReturn(true);
        $controller->method('postActionRedirect')->willReturn(new Response());
        $controller->method('generateUrl')->willReturn('/');

        $this->repo
            ->expects($this->once())
            ->method('deleteQueued')
            ->with(null, null, null)
            ->willReturn(0);

        $request = Request::create('/purge', 'POST', [
            '_token'     => 'tok',
            'campaignId' => '0',
        ]);
        $controller->purgeQueuedAction($request, $this->repo);
    }

    public function testPurgeQueuedPostPassesNullWhenTemplateNameIsEmpty(): void
    {
        $controller = $this->makeController();
        $controller->method('isCsrfTokenValid')->willReturn(true);
        $controller->method('postActionRedirect')->willReturn(new Response());
        $controller->method('generateUrl')->willReturn('/');

        $this->repo
            ->expects($this->once())
            ->method('deleteQueued')
            ->with(null, null, null)
            ->willReturn(0);

        $request = Request::create('/purge', 'POST', [
            '_token'       => 'tok',
            'templateName' => '',
            'senderName'   => '',
            'campaignId'   => '0',
        ]);
        $controller->purgeQueuedAction($request, $this->repo);
    }

    public function testPurgeQueuedPostPassesAllFiltersWhenProvided(): void
    {
        $controller = $this->makeController();
        $controller->method('isCsrfTokenValid')->willReturn(true);
        $controller->method('postActionRedirect')->willReturn(new Response());
        $controller->method('generateUrl')->willReturn('/');

        $this->repo
            ->expects($this->once())
            ->method('deleteQueued')
            ->with('hello_world', 'Número 1', 7)
            ->willReturn(10);

        $request = Request::create('/purge', 'POST', [
            '_token'       => 'tok',
            'templateName' => 'hello_world',
            'senderName'   => 'Número 1',
            'campaignId'   => '7',
        ]);
        $controller->purgeQueuedAction($request, $this->repo);
    }

    public function testPurgeQueuedPostRedirectsWithDeletedCount(): void
    {
        $controller = $this->makeController();
        $controller->method('isCsrfTokenValid')->willReturn(true);
        $controller->method('generateUrl')->willReturn('/');

        $this->repo->method('deleteQueued')->willReturn(17);

        $capturedArgs = null;
        $controller->method('postActionRedirect')
            ->willReturnCallback(static function (array $args) use (&$capturedArgs): Response {
                $capturedArgs = $args;
                return new Response();
            });

        $controller->purgeQueuedAction(
            Request::create('/purge', 'POST', ['_token' => 'tok']),
            $this->repo
        );

        $flash = $capturedArgs['flashes'][0];
        $this->assertSame('dialoghsm.log.purge.success', $flash['msg']);
        $this->assertSame(17, $flash['msgVars']['%count%']);
    }

    // =========================================================================
    // dashboardAction — novo dado: dispatches
    // =========================================================================

    public function testDashboardActionPassesDispatchesToView(): void
    {
        $controller = $this->makeController([], withParams: true);

        $dispatches = [
            ['template_name' => 'foo', 'campaign_id' => 1, 'date' => '2026-05-13', 'total' => 10, 'sent' => 9, 'delivered' => 8, 'read' => 7, 'failed' => 1, 'dlq' => 0],
        ];

        $this->repo->method('getStatsByPeriod')->willReturn(['total' => 0, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0]);
        $this->repo->method('getChartData')->willReturn([]);
        $this->repo->method('getGroupedDispatches')->willReturn($dispatches);

        $capturedArgs = null;
        $controller->method('delegateView')
            ->willReturnCallback(static function (array $args) use (&$capturedArgs): Response {
                $capturedArgs = $args;
                return new Response();
            });
        $controller->method('generateUrl')->willReturn('/');

        $controller->dashboardAction(Request::create('/dashboard'), $this->repo);

        $this->assertSame($dispatches, $capturedArgs['viewParameters']['dispatches']);
    }

    public function testDashboardActionCallsGetGroupedDispatches(): void
    {
        $controller = $this->makeController([], withParams: true);

        $this->repo->method('getStatsByPeriod')->willReturn(['total' => 0, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0]);
        $this->repo->method('getChartData')->willReturn([]);
        $this->repo
            ->expects($this->once())
            ->method('getGroupedDispatches')
            ->willReturn([]);

        $controller->method('delegateView')->willReturn(new Response());
        $controller->method('generateUrl')->willReturn('/');

        $controller->dashboardAction(Request::create('/dashboard'), $this->repo);
    }

    public function testDashboardActionDefaultChartDaysIsSeven(): void
    {
        $controller = $this->makeController([], withParams: true);

        $this->repo->method('getStatsByPeriod')->willReturn(['total' => 0, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0]);
        $this->repo->method('getGroupedDispatches')->willReturn([]);
        $this->repo
            ->expects($this->once())
            ->method('getChartData')
            ->with(7)
            ->willReturn([]);

        $capturedArgs = null;
        $controller->method('delegateView')
            ->willReturnCallback(static function (array $args) use (&$capturedArgs): Response {
                $capturedArgs = $args;
                return new Response();
            });
        $controller->method('generateUrl')->willReturn('/');

        $controller->dashboardAction(Request::create('/dashboard'), $this->repo);

        $this->assertSame(7, $capturedArgs['viewParameters']['chartDays']);
    }

    public function testDashboardActionInvalidDaysParamFallsBackToSeven(): void
    {
        $controller = $this->makeController([], withParams: true);

        $this->repo->method('getStatsByPeriod')->willReturn(['total' => 0, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0]);
        $this->repo->method('getGroupedDispatches')->willReturn([]);
        $this->repo
            ->expects($this->once())
            ->method('getChartData')
            ->with(7)
            ->willReturn([]);

        $capturedArgs = null;
        $controller->method('delegateView')
            ->willReturnCallback(static function (array $args) use (&$capturedArgs): Response {
                $capturedArgs = $args;
                return new Response();
            });
        $controller->method('generateUrl')->willReturn('/');

        // 99 is not in [7, 14, 30] — must fall back to 7
        $controller->dashboardAction(Request::create('/dashboard', 'GET', ['days' => '99']), $this->repo);

        $this->assertSame(7, $capturedArgs['viewParameters']['chartDays']);
    }

    // =========================================================================
    // Cache — verifica que o cache protege as queries do repositório
    // =========================================================================

    /**
     * Cache quente: cache->get() retorna valor direto sem invocar callback.
     * Repositório NÃO deve ser chamado.
     */
    public function testDashboardCacheHitSkipsRepositoryQueries(): void
    {
        $methods = ['isCsrfTokenValid', 'postActionRedirect', 'delegateView', 'generateUrl'];
        $controller = $this->getMockBuilder(MessageLogController::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();

        $params = $this->createMock(CoreParametersHelper::class);
        $params->method('get')->willReturn('UTC');
        $prop = new \ReflectionProperty(\Mautic\CoreBundle\Controller\CommonController::class, 'coreParametersHelper');
        $prop->setValue($controller, $params);

        // Cache quente: retorna dado fixo sem chamar o callback
        $cachedStats      = ['total' => 5, 'queued' => 0, 'sent' => 5, 'delivered' => 3, 'read' => 1, 'failed' => 0, 'dlq' => 0];
        $cachedDispatches = [['template_name' => 'cached_tpl', 'campaign_id' => null, 'date' => '2026-01-01', 'total' => 5, 'sent_plus' => 5, 'delivered_plus' => 3, 'read_count' => 1, 'failed' => 0, 'dlq' => 0]];

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            static function (string $key) use ($cachedStats, $cachedDispatches): mixed {
                if (str_contains($key, 'dispatches')) {
                    return $cachedDispatches;
                }
                if (str_contains($key, 'chart')) {
                    return [];
                }
                return $cachedStats;
            }
        );
        $controller->setCache($cache);

        // Repositório NÃO deve ser chamado nenhuma vez
        $this->repo->expects($this->never())->method('getStatsByPeriod');
        $this->repo->expects($this->never())->method('getChartData');
        $this->repo->expects($this->never())->method('getGroupedDispatches');

        $controller->method('delegateView')->willReturn(new Response());
        $controller->method('generateUrl')->willReturn('/');

        $controller->dashboardAction(Request::create('/dashboard'), $this->repo);
    }

    /**
     * Cache frio: cache->get() invoca o callback.
     * Repositório DEVE ser chamado exatamente uma vez por query.
     */
    public function testDashboardCacheMissCallsRepositoryOnce(): void
    {
        $controller = $this->makeController([], withParams: true);

        // makeController já injeta cache pass-through (chama o callback)
        $this->repo->expects($this->exactly(2))->method('getStatsByPeriod')->willReturn(
            ['total' => 0, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0]
        );
        $this->repo->expects($this->once())->method('getChartData')->willReturn([]);
        $this->repo->expects($this->once())->method('getGroupedDispatches')->willReturn([]);

        $controller->method('delegateView')->willReturn(new Response());
        $controller->method('generateUrl')->willReturn('/');

        $controller->dashboardAction(Request::create('/dashboard'), $this->repo);
    }

    /**
     * Verifica que as chaves de cache contêm o slot horário para evitar stale cross-hour.
     */
    public function testDashboardCacheKeysContainHourSlot(): void
    {
        $methods = ['isCsrfTokenValid', 'postActionRedirect', 'delegateView', 'generateUrl'];
        $controller = $this->getMockBuilder(MessageLogController::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();

        $params = $this->createMock(CoreParametersHelper::class);
        $params->method('get')->willReturn('UTC');
        $prop = new \ReflectionProperty(\Mautic\CoreBundle\Controller\CommonController::class, 'coreParametersHelper');
        $prop->setValue($controller, $params);

        $capturedKeys = [];
        $item         = $this->createMock(ItemInterface::class);
        $item->method('expiresAfter')->willReturnSelf();
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            static function (string $key, callable $callback) use (&$capturedKeys, $item): mixed {
                $capturedKeys[] = $key;
                return $callback($item);
            }
        );
        $controller->setCache($cache);

        $this->repo->method('getStatsByPeriod')->willReturn(
            ['total' => 0, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0]
        );
        $this->repo->method('getChartData')->willReturn([]);
        $this->repo->method('getGroupedDispatches')->willReturn([]);

        $controller->method('delegateView')->willReturn(new Response());
        $controller->method('generateUrl')->willReturn('/');

        $controller->dashboardAction(Request::create('/dashboard'), $this->repo);

        $expectedSlot = (new \DateTime())->format('YmdH');
        foreach ($capturedKeys as $key) {
            $this->assertStringContainsString($expectedSlot, $key, "Chave '{$key}' não contém o slot horário");
        }
        $this->assertCount(4, $capturedKeys, 'Devem haver exatamente 4 chaves de cache');
    }

    public function testPurgeQueuedPostTrimsWhitespaceFromFilters(): void
    {
        $controller = $this->makeController();
        $controller->method('isCsrfTokenValid')->willReturn(true);
        $controller->method('postActionRedirect')->willReturn(new Response());
        $controller->method('generateUrl')->willReturn('/');

        $this->repo
            ->expects($this->once())
            ->method('deleteQueued')
            ->with('hello_world', 'Número 1', null)
            ->willReturn(2);

        $request = Request::create('/purge', 'POST', [
            '_token'       => 'tok',
            'templateName' => '  hello_world  ',
            'senderName'   => '  Número 1  ',
            'campaignId'   => '0',
        ]);
        $controller->purgeQueuedAction($request, $this->repo);
    }
}
