<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppNumberController extends FormController
{
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
                'form'   => $form->createView(),
                'entity' => $entity,
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

    protected function getModelName(): string
    {
        return 'dialoghsm.whatsappnumber';
    }

    protected function getDefaultOrderDirection(): string
    {
        return 'ASC';
    }
}
