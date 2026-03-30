<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MessageLogController extends FormController
{
    private const MAX_LOGS   = 10000;
    private const PAGE_LIMIT = 50;

    private const FILTER_KEYS = ['status', 'dateFrom', 'dateTo', 'senderName', 'contact'];

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
        $limit   = $session->get('mautic.dialoghsm.log.limit', self::PAGE_LIMIT);
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
                'items'      => $items,
                'totalItems' => $total,
                'maxLogs'    => self::MAX_LOGS,
                'page'       => $page,
                'limit'      => $limit,
                'filters'    => $filters,
                'tmpl'       => $request->get('tmpl', 'index'),
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
