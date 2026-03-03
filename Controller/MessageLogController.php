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

    public function indexAction(Request $request, MessageLogRepository $messageLogRepository, int $page = 1): Response
    {
        $session = $request->getSession();

        $limit = $session->get('mautic.dialoghsm.log.limit', self::PAGE_LIMIT);
        $start = (1 === $page) ? 0 : (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        $total = $messageLogRepository->countAll();
        $items = $messageLogRepository->getLogs($start, $limit);

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
}
