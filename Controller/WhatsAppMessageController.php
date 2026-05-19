<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppMessageModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppMessageController extends FormController
{
    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse|Response
     */
    public function indexAction(Request $request, int $page = 1)
    {
        $model = $this->getModel('dialoghsm.whatsappmessage');
        \assert($model instanceof WhatsAppMessageModel);

        $session = $request->getSession();

        $limit      = $session->get('mautic.dialoghsm.message.limit', $this->coreParametersHelper->get('default_pagelimit'));
        $start      = (1 === $page) ? 0 : (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        $search     = $request->get('search', $session->get('mautic.dialoghsm.message.filter', ''));
        $session->set('mautic.dialoghsm.message.filter', $search);

        $orderBy    = $session->get('mautic.dialoghsm.message.orderby', 'wm.name');
        $orderByDir = $session->get('mautic.dialoghsm.message.orderbydir', 'ASC');

        $items = $model->getEntities([
            'start'      => $start,
            'limit'      => $limit,
            'filter'     => ['string' => $search],
            'orderBy'    => $orderBy,
            'orderByDir' => $orderByDir,
        ]);

        $count = count($items);
        if ($count && $count < ($start + 1)) {
            $lastPage = (floor($count / $limit)) ?: 1;
            $session->set('mautic.dialoghsm.message.page', $lastPage);

            return $this->postActionRedirect([
                'returnUrl'       => $this->generateUrl('mautic_dialoghsm_message_index', ['page' => $lastPage]),
                'viewParameters'  => ['page' => $lastPage],
                'contentTemplate' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppMessageController::indexAction',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_dialoghsm_message_index',
                    'mauticContent' => 'dialoghsm_message',
                ],
            ]);
        }

        $session->set('mautic.dialoghsm.message.page', $page);

        return $this->delegateView([
            'viewParameters' => [
                'searchValue' => $search,
                'items'       => $items,
                'totalItems'  => $count,
                'page'        => $page,
                'limit'       => $limit,
                'tmpl'        => $request->get('tmpl', 'index'),
            ],
            'contentTemplate' => '@DialogHSM/WhatsAppMessage/list.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_dialoghsm_message_index',
                'mauticContent' => 'dialoghsm_message',
                'route'         => $this->generateUrl('mautic_dialoghsm_message_index', ['page' => $page]),
            ],
        ]);
    }

    /**
     * @return Response
     */
    public function newAction(Request $request)
    {
        $model  = $this->getModel('dialoghsm.whatsappmessage');
        \assert($model instanceof WhatsAppMessageModel);
        $entity = $model->getEntity();

        $action = $this->generateUrl('mautic_dialoghsm_message_action', ['objectAction' => 'new']);
        $form   = $model->createForm($entity, $this->formFactory, $action);

        if ('POST' === $request->getMethod()) {
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $model->saveEntity($entity);

                    $this->addFlashMessage('mautic.core.notice.created', [
                        '%name%'      => $entity->getName(),
                        '%menu_link%' => 'mautic_dialoghsm_message_index',
                        '%url%'       => $this->generateUrl(
                            'mautic_dialoghsm_message_action',
                            ['objectAction' => 'edit', 'objectId' => $entity->getId()]
                        ),
                    ]);

                    if ($this->getFormButton($form, ['buttons', 'save'])->isClicked()) {
                        return $this->postActionRedirect([
                            'returnUrl'       => $this->generateUrl('mautic_dialoghsm_message_index'),
                            'contentTemplate' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppMessageController::indexAction',
                            'passthroughVars' => [
                                'activeLink'    => '#mautic_dialoghsm_message_index',
                                'mauticContent' => 'dialoghsm_message',
                            ],
                        ]);
                    }

                    return $this->editAction($request, $entity->getId(), true);
                }
            } else {
                return $this->postActionRedirect([
                    'returnUrl'       => $this->generateUrl('mautic_dialoghsm_message_index'),
                    'contentTemplate' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppMessageController::indexAction',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_dialoghsm_message_index',
                        'mauticContent' => 'dialoghsm_message',
                    ],
                ]);
            }
        }

        return $this->delegateView([
            'viewParameters' => [
                'form'   => $form->createView(),
                'entity' => $entity,
            ],
            'contentTemplate' => '@DialogHSM/WhatsAppMessage/form.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_dialoghsm_message_index',
                'mauticContent' => 'dialoghsm_message',
                'route'         => $this->generateUrl('mautic_dialoghsm_message_action', ['objectAction' => 'new']),
            ],
        ]);
    }

    /**
     * @return Response
     */
    public function editAction(Request $request, int $objectId, bool $ignorePost = false)
    {
        $model  = $this->getModel('dialoghsm.whatsappmessage');
        \assert($model instanceof WhatsAppMessageModel);
        $entity = $model->getEntity($objectId);

        $postActionVars = [
            'returnUrl'       => $this->generateUrl('mautic_dialoghsm_message_index'),
            'contentTemplate' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppMessageController::indexAction',
            'passthroughVars' => [
                'activeLink'    => '#mautic_dialoghsm_message_index',
                'mauticContent' => 'dialoghsm_message',
            ],
        ];

        if (null === $entity) {
            return $this->postActionRedirect(array_merge($postActionVars, [
                'flashes' => [[
                    'type'    => 'error',
                    'msg'     => 'dialoghsm.whatsapp_message.error.notfound',
                    'msgVars' => ['%id%' => $objectId],
                ]],
            ]));
        }

        if ($model->isLocked($entity)) {
            return $this->isLocked($postActionVars, $entity, 'dialoghsm.whatsappmessage');
        }

        $action = $this->generateUrl('mautic_dialoghsm_message_action', ['objectAction' => 'edit', 'objectId' => $objectId]);
        $form   = $model->createForm($entity, $this->formFactory, $action);

        if (!$ignorePost && 'POST' === $request->getMethod()) {
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $model->saveEntity($entity, $this->getFormButton($form, ['buttons', 'save'])->isClicked());

                    $this->addFlashMessage('mautic.core.notice.updated', [
                        '%name%'      => $entity->getName(),
                        '%menu_link%' => 'mautic_dialoghsm_message_index',
                        '%url%'       => $this->generateUrl(
                            'mautic_dialoghsm_message_action',
                            ['objectAction' => 'edit', 'objectId' => $entity->getId()]
                        ),
                    ], 'warning');
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
            'contentTemplate' => '@DialogHSM/WhatsAppMessage/form.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_dialoghsm_message_index',
                'mauticContent' => 'dialoghsm_message',
                'route'         => $this->generateUrl(
                    'mautic_dialoghsm_message_action',
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
        $postActionVars = [
            'returnUrl'       => $this->generateUrl('mautic_dialoghsm_message_index'),
            'contentTemplate' => 'MauticPlugin\DialogHSMBundle\Controller\WhatsAppMessageController::indexAction',
            'passthroughVars' => [
                'activeLink'    => '#mautic_dialoghsm_message_index',
                'mauticContent' => 'dialoghsm_message',
            ],
        ];

        $flashes = [];

        if (Request::METHOD_POST === $request->getMethod()) {
            $model  = $this->getModel('dialoghsm.whatsappmessage');
            \assert($model instanceof WhatsAppMessageModel);
            $entity = $model->getEntity($objectId);

            if (null === $entity) {
                $flashes[] = [
                    'type'    => 'error',
                    'msg'     => 'dialoghsm.whatsapp_message.error.notfound',
                    'msgVars' => ['%id%' => $objectId],
                ];
            } elseif ($model->isLocked($entity)) {
                return $this->isLocked($postActionVars, $entity, 'dialoghsm.whatsappmessage');
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
        return 'dialoghsm.whatsappmessage';
    }

    protected function getDefaultOrderDirection(): string
    {
        return 'ASC';
    }
}
