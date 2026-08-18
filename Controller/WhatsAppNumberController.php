<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMPartnerApi;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberBalanceHistoryRepository;
use MauticPlugin\DialogHSMBundle\Service\BalanceAlertService;
use MauticPlugin\DialogHSMBundle\Service\BalanceHistoryRecorder;
use MauticPlugin\DialogHSMBundle\Service\MultiWebhookService;
use MauticPlugin\DialogHSMBundle\Service\PartnerConfigProvider;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class WhatsAppNumberController extends FormController
{
    private MultiWebhookService $multiWebhookService;
    private DialogHSMPartnerApi $partnerApi;
    private PartnerConfigProvider $partnerConfigProvider;
    private BalanceAlertService $balanceAlertService;
    private BalanceHistoryRecorder $balanceHistoryRecorder;
    private WhatsAppNumberBalanceHistoryRepository $balanceHistoryRepository;

    #[Required]
    public function setMultiWebhookService(MultiWebhookService $service): void
    {
        $this->multiWebhookService = $service;
    }

    #[Required]
    public function setPartnerApi(DialogHSMPartnerApi $partnerApi): void
    {
        $this->partnerApi = $partnerApi;
    }

    #[Required]
    public function setPartnerConfigProvider(PartnerConfigProvider $partnerConfigProvider): void
    {
        $this->partnerConfigProvider = $partnerConfigProvider;
    }

    #[Required]
    public function setBalanceAlertService(BalanceAlertService $balanceAlertService): void
    {
        $this->balanceAlertService = $balanceAlertService;
    }

    #[Required]
    public function setBalanceHistoryRecorder(BalanceHistoryRecorder $balanceHistoryRecorder): void
    {
        $this->balanceHistoryRecorder = $balanceHistoryRecorder;
    }

    #[Required]
    public function setBalanceHistoryRepository(WhatsAppNumberBalanceHistoryRepository $repository): void
    {
        $this->balanceHistoryRepository = $repository;
    }

    /**
     * @return JsonResponse|Response
     */
    public function indexAction(Request $request, int $page = 1)
    {
        $model = $this->getModel('dialoghsm.whatsappnumber');
        \assert($model instanceof WhatsAppNumberModel);

        $session = $request->getSession();

        $limit = $session->get('mautic.dialoghsm.number.limit', $this->coreParametersHelper->get('default_pagelimit'));
        $start = (1 === $page) ? 0 : (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        $search = $request->get('search', $session->get('mautic.dialoghsm.number.filter', ''));
        $session->set('mautic.dialoghsm.number.filter', $search);

        $filter  = ['string' => $search];
        $orderBy    = $session->get('mautic.dialoghsm.number.orderby', 'wn.name');
        $orderByDir = $session->get('mautic.dialoghsm.number.orderbydir', 'ASC');

        $items = $model->getEntities([
            'start'      => $start,
            'limit'      => $limit,
            'filter'     => $filter,
            'orderBy'    => $orderBy,
            'orderByDir' => $orderByDir,
        ]);

        $count = count($items);
        if ($count && $count < ($start + 1)) {
            $lastPage = (floor($count / $limit)) ?: 1;
            $session->set('mautic.dialoghsm.number.page', $lastPage);
            $returnUrl = $this->generateUrl('mautic_dialoghsm_number_index', ['page' => $lastPage]);

            return $this->postActionRedirect([
                'returnUrl'       => $returnUrl,
                'viewParameters'  => ['page' => $lastPage],
                'contentTemplate' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController::indexAction',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_dialoghsm_number_index',
                    'mauticContent' => 'dialoghsm_number',
                ],
            ]);
        }

        $session->set('mautic.dialoghsm.number.page', $page);

        return $this->delegateView([
            'viewParameters' => [
                'searchValue' => $search,
                'items'       => $items,
                'totalItems'  => $count,
                'page'        => $page,
                'limit'       => $limit,
                'tmpl'        => $request->get('tmpl', 'index'),
            ],
            'contentTemplate' => '@DialogHSM/WhatsAppNumber/list.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_dialoghsm_number_index',
                'mauticContent' => 'dialoghsm_number',
                'route'         => $this->generateUrl('mautic_dialoghsm_number_index', ['page' => $page]),
            ],
        ]);
    }

    /**
     * @return Response
     */
    public function newAction(Request $request)
    {
        $model  = $this->getModel('dialoghsm.whatsappnumber');
        \assert($model instanceof WhatsAppNumberModel);
        $entity = $model->getEntity();

        $action = $this->generateUrl('mautic_dialoghsm_number_action', ['objectAction' => 'new']);
        $form   = $model->createForm($entity, $this->formFactory, $action);

        if ('POST' === $request->getMethod()) {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $model->saveEntity($entity);

                    $this->addFlashMessage(
                        'mautic.core.notice.created',
                        [
                            '%name%'      => $entity->getName(),
                            '%menu_link%' => 'mautic_dialoghsm_number_index',
                            '%url%'       => $this->generateUrl(
                                'mautic_dialoghsm_number_action',
                                ['objectAction' => 'edit', 'objectId' => $entity->getId()]
                            ),
                        ]
                    );

                    if ($this->getFormButton($form, ['buttons', 'save'])->isClicked()) {
                        return $this->postActionRedirect([
                            'returnUrl'       => $this->generateUrl('mautic_dialoghsm_number_index'),
                            'contentTemplate' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController::indexAction',
                            'passthroughVars' => [
                                'activeLink'    => '#mautic_dialoghsm_number_index',
                                'mauticContent' => 'dialoghsm_number',
                            ],
                        ]);
                    }

                    return $this->editAction($request, $entity->getId(), true);
                }
            } else {
                return $this->postActionRedirect([
                    'returnUrl'       => $this->generateUrl('mautic_dialoghsm_number_index'),
                    'contentTemplate' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController::indexAction',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_dialoghsm_number_index',
                        'mauticContent' => 'dialoghsm_number',
                    ],
                ]);
            }
        }

        return $this->delegateView([
            'viewParameters' => [
                'form'   => $form->createView(),
                'entity' => $entity,
            ],
            'contentTemplate' => '@DialogHSM/WhatsAppNumber/form.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_dialoghsm_number_index',
                'mauticContent' => 'dialoghsm_number',
                'route'         => $this->generateUrl('mautic_dialoghsm_number_action', ['objectAction' => 'new']),
            ],
        ]);
    }

    /**
     * @return Response
     */
    public function editAction(Request $request, int $objectId, bool $ignorePost = false)
    {
        $model  = $this->getModel('dialoghsm.whatsappnumber');
        \assert($model instanceof WhatsAppNumberModel);
        $entity = $model->getEntity($objectId);

        $returnUrl      = $this->generateUrl('mautic_dialoghsm_number_index');
        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'contentTemplate' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController::indexAction',
            'passthroughVars' => [
                'activeLink'    => '#mautic_dialoghsm_number_index',
                'mauticContent' => 'dialoghsm_number',
            ],
        ];

        if (null === $entity) {
            return $this->postActionRedirect(array_merge($postActionVars, [
                'flashes' => [[
                    'type'    => 'error',
                    'msg'     => 'dialoghsm.number.error.notfound',
                    'msgVars' => ['%id%' => $objectId],
                ]],
            ]));
        }

        if ($model->isLocked($entity)) {
            return $this->isLocked($postActionVars, $entity, 'dialoghsm.whatsappnumber');
        }

        $action = $this->generateUrl('mautic_dialoghsm_number_action', ['objectAction' => 'edit', 'objectId' => $objectId]);
        $form   = $model->createForm($entity, $this->formFactory, $action);

        if (!$ignorePost && 'POST' === $request->getMethod()) {
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $model->saveEntity($entity, $this->getFormButton($form, ['buttons', 'save'])->isClicked());

                    $this->addFlashMessage(
                        'mautic.core.notice.updated',
                        [
                            '%name%'      => $entity->getName(),
                            '%menu_link%' => 'mautic_dialoghsm_number_index',
                            '%url%'       => $this->generateUrl(
                                'mautic_dialoghsm_number_action',
                                ['objectAction' => 'edit', 'objectId' => $entity->getId()]
                            ),
                        ],
                        'warning'
                    );
                }
            } else {
                $model->unlockEntity($entity);
            }

            if ($cancelled || ($valid && $this->getFormButton($form, ['buttons', 'save'])->isClicked())) {
                return $this->postActionRedirect($postActionVars);
            }
        } else {
            $model->lockEntity($entity);
        }

        return $this->delegateView([
            'viewParameters' => [
                'form'           => $form->createView(),
                'entity'         => $entity,
                'balanceHistory' => $this->balanceHistoryRepository->findAllForNumber($entity->getId()),
            ],
            'contentTemplate' => '@DialogHSM/WhatsAppNumber/form.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_dialoghsm_number_index',
                'mauticContent' => 'dialoghsm_number',
                'route'         => $this->generateUrl(
                    'mautic_dialoghsm_number_action',
                    ['objectAction' => 'edit', 'objectId' => $entity->getId()]
                ),
            ],
        ]);
    }

    /**
     * @return Response
     */
    public function deleteAction(Request $request, int $objectId)
    {
        $returnUrl      = $this->generateUrl('mautic_dialoghsm_number_index');
        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'contentTemplate' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppNumberController::indexAction',
            'passthroughVars' => [
                'activeLink'    => '#mautic_dialoghsm_number_index',
                'mauticContent' => 'dialoghsm_number',
            ],
        ];

        $flashes = [];

        if (Request::METHOD_POST === $request->getMethod()) {
            $model  = $this->getModel('dialoghsm.whatsappnumber');
            \assert($model instanceof WhatsAppNumberModel);
            $entity = $model->getEntity($objectId);

            if (null === $entity) {
                $flashes[] = [
                    'type'    => 'error',
                    'msg'     => 'dialoghsm.number.error.notfound',
                    'msgVars' => ['%id%' => $objectId],
                ];
            } elseif ($model->isLocked($entity)) {
                return $this->isLocked($postActionVars, $entity, 'dialoghsm.whatsappnumber');
            } else {
                $model->deleteEntity($entity);
                $flashes[] = [
                    'type'    => 'notice',
                    'msg'     => 'mautic.core.notice.deleted',
                    'msgVars' => ['%name%' => $entity->getName(), '%id%' => $objectId],
                ];
            }
        }

        return $this->postActionRedirect(array_merge($postActionVars, ['flashes' => $flashes]));
    }

    public function webhookCheckAction(int $objectId): JsonResponse
    {
        $model = $this->getModel('dialoghsm.whatsappnumber');
        \assert($model instanceof WhatsAppNumberModel);
        $entity = $model->getEntity($objectId);

        if (null === $entity) {
            return new JsonResponse(['error' => 'Number not found'], 404);
        }

        $result = $this->multiWebhookService->check($entity->getApiKey() ?? '');

        return new JsonResponse($result);
    }

    public function webhookRegisterAction(Request $request, int $objectId): JsonResponse
    {
        if ('POST' !== $request->getMethod()) {
            return new JsonResponse(['error' => 'Method not allowed'], 405);
        }

        $model = $this->getModel('dialoghsm.whatsappnumber');
        \assert($model instanceof WhatsAppNumberModel);
        $entity = $model->getEntity($objectId);

        if (null === $entity) {
            return new JsonResponse(['error' => 'Number not found'], 404);
        }

        $webhookUrl = $this->generateUrl(
            'mautic_dialoghsm_webhook',
            ['phoneNumber' => $entity->getPhoneNumber()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $result = $this->multiWebhookService->register($entity->getApiKey() ?? '', $webhookUrl);

        return new JsonResponse(array_merge($result, ['url' => $webhookUrl]));
    }

    public function balanceCheckAction(Request $request, int $objectId): JsonResponse
    {
        if ('POST' !== $request->getMethod()) {
            return new JsonResponse(['error' => 'Method not allowed'], 405);
        }

        $model = $this->getModel('dialoghsm.whatsappnumber');
        \assert($model instanceof WhatsAppNumberModel);
        $entity = $model->getEntity($objectId);

        if (null === $entity) {
            return new JsonResponse(['error' => 'Number not found'], 404);
        }

        if (empty($entity->getClientId()) || empty($entity->getChannelId())) {
            return new JsonResponse([
                'success' => false,
                'error'   => $this->translator->trans('dialoghsm.number.balance.missing_ids'),
            ], 400);
        }

        $partnerId     = $this->partnerConfigProvider->getPartnerId();
        $partnerApiKey = $this->partnerConfigProvider->getPartnerApiKey();

        if (empty($partnerId) || empty($partnerApiKey)) {
            return new JsonResponse([
                'success' => false,
                'error'   => $this->translator->trans('dialoghsm.number.balance.missing_partner_config'),
            ], 400);
        }

        $result = $this->partnerApi->getChannelBalance(
            $partnerId,
            $partnerApiKey,
            $entity->getClientId(),
            $entity->getChannelId()
        );

        if ($result['success']) {
            $entity->setBalanceInfo($result['balance'], $result['currency'], new \DateTime());
            $entity->setBalanceUsageSnapshot($result['usage']);
            $model->getRepository()->saveEntity($entity);

            $this->balanceAlertService->checkAndNotify($entity, $result['balance'], $result['currency']);

            $this->balanceHistoryRecorder->recordIfNewRecharge(
                $entity,
                $result['last_renewal_date'],
                $result['last_renewal_amount'],
                $result['balance'],
                $result['currency']
            );
        }

        return new JsonResponse([
            'success'          => $result['success'],
            'error'            => $result['error'],
            'balance'          => $entity->getBalance(),
            'currency'         => $entity->getBalanceCurrency(),
            'balanceUpdatedAt' => $entity->getBalanceUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    protected function getModelName(): string
    {
        return 'dialoghsm.whatsappnumber';
    }

    protected function getDefaultOrderDirection(): string
    {
        return 'ASC';
    }
}
