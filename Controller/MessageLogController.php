<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

class MessageLogController extends FormController
{
    private const DEFAULT_MAX_LOGS = 100_000;
    private const PAGE_LIMIT       = 50;

    private const FILTER_KEYS = ['status', 'dateFrom', 'dateTo', 'senderName', 'contact', 'templateName'];

    private IntegrationsHelper $integrationsHelper;

    #[Required]
    public function setIntegrationsHelper(IntegrationsHelper $integrationsHelper): void
    {
        $this->integrationsHelper = $integrationsHelper;
    }

    private function getMaxLogs(): int
    {
        try {
            $integration = $this->integrationsHelper->getIntegration(DialogHSMIntegration::NAME);
            $apiKeys     = $integration->getIntegrationConfiguration()->getApiKeys() ?? [];

            return max(1_000, min(1_000_000, (int) ($apiKeys['log_max_records'] ?? self::DEFAULT_MAX_LOGS)));
        } catch (\Throwable) {
            return self::DEFAULT_MAX_LOGS;
        }
    }

    public function dashboardAction(Request $request, MessageLogRepository $messageLogRepository): Response
    {
        $allowedDays = [7, 14, 30];
        $chartDays   = in_array((int) $request->query->get('days', 7), $allowedDays, true)
            ? (int) $request->query->get('days', 7)
            : 7;

        $now     = new \DateTime();
        $from24h = (clone $now)->modify('-24 hours');
        $from7d  = (clone $now)->modify('-7 days')->setTime(0, 0, 0);

        $stats24h  = $messageLogRepository->getStatsByPeriod($from24h);
        $stats7d   = $messageLogRepository->getStatsByPeriod($from7d);
        $chartRaw  = $messageLogRepository->getChartData($chartDays);

        // Prepara dados para Chart.js
        $labels   = array_keys($chartRaw);
        $statuses = ['queued', 'sent', 'failed', 'dlq'];
        $datasets = [];
        $colors   = [
            'queued' => 'rgba(150, 150, 150, 0.85)',
            'sent'   => 'rgba(92, 184, 92, 0.85)',
            'failed' => 'rgba(217, 83, 79, 0.85)',
            'dlq'    => 'rgba(240, 173, 78, 0.85)',
        ];

        foreach ($statuses as $status) {
            $datasets[] = [
                'label'           => $status,
                'backgroundColor' => $colors[$status],
                'data'            => array_values(array_map(fn ($day) => $day[$status], $chartRaw)),
            ];
        }

        return $this->delegateView([
            'viewParameters'  => [
                'stats24h'  => $stats24h,
                'stats7d'   => $stats7d,
                'chartJson' => json_encode(['labels' => $labels, 'datasets' => $datasets]),
                'chartDays' => $chartDays,
                'timezone'  => $this->coreParametersHelper->get('default_timezone', 'UTC'),
                'tmpl'      => 'index',
            ],
            'contentTemplate' => '@DialogHSM/MessageLog/dashboard.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_dialoghsm_dashboard',
                'mauticContent' => 'dialoghsm_dashboard',
                'route'         => $this->generateUrl('mautic_dialoghsm_dashboard'),
            ],
        ]);
    }

    public function indexAction(Request $request, MessageLogRepository $messageLogRepository, int $page = 1): Response
    {
        $session = $request->getSession();

        // --- Filtros ---
        if ($request->isMethod('POST') && $request->request->has('dialoghsm_log_filter')) {
            $filters = $this->extractFilters($request->request->all('dialoghsm_log_filter'));
            $session->set('mautic.dialoghsm.log.filters', $filters);
            // Volta para a página 1 ao aplicar filtro
            $page = 1;
        } elseif ($request->query->has('clearFilters')) {
            $session->remove('mautic.dialoghsm.log.filters');
            $page = 1;

            return $this->redirect($this->generateUrl('mautic_dialoghsm_log_index', ['page' => 1]));
        }

        $filters = $session->get('mautic.dialoghsm.log.filters', []);

        if ($request->query->has('limit')) {
            $limit = max(1, min(100, (int) $request->query->get('limit')));
            $session->set('mautic.dialoghsm.log.limit', $limit);
            $page = 1;
        } else {
            $limit = $session->get('mautic.dialoghsm.log.limit', self::PAGE_LIMIT);
        }
        $start   = (1 === $page) ? 0 : (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        $total = $messageLogRepository->countAll($filters);
        $items = $messageLogRepository->getLogs($start, $limit, $filters);

        if ($total && $total < ($start + 1)) {
            $lastPage = (int) (ceil($total / $limit)) ?: 1;
            $session->set('mautic.dialoghsm.log.page', $lastPage);

            return $this->postActionRedirect([
                'returnUrl'       => $this->generateUrl('mautic_dialoghsm_log_index', ['page' => $lastPage]),
                'viewParameters'  => ['page' => $lastPage],
                'contentTemplate' => 'MauticPlugin\DialogHSMBundle\Controller\MessageLogController::indexAction',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_dialoghsm_log_index',
                    'mauticContent' => 'dialoghsm_log',
                ],
            ]);
        }

        $session->set('mautic.dialoghsm.log.page', $page);

        return $this->delegateView([
            'viewParameters' => [
                'items'        => $items,
                'totalItems'   => $total,
                'maxLogs'      => $this->getMaxLogs(),
                'page'         => $page,
                'limit'        => $limit,
                'filters'      => $filters,
                'senderNames'   => $messageLogRepository->getDistinctSenderNames(),
                'templateNames' => $messageLogRepository->getDistinctTemplateNames(),
                'timezone'     => $this->coreParametersHelper->get('default_timezone', 'UTC'),
                'tmpl'         => $request->get('tmpl', 'index'),
            ],
            'contentTemplate' => '@DialogHSM/MessageLog/list.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_dialoghsm_log_index',
                'mauticContent' => 'dialoghsm_log',
                'route'         => $this->generateUrl('mautic_dialoghsm_log_index', ['page' => $page]),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, string>
     */
    private function extractFilters(array $raw): array
    {
        $filters = [];
        foreach (self::FILTER_KEYS as $key) {
            $value = trim((string) ($raw[$key] ?? ''));
            if ('' !== $value) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }
}
