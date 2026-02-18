<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Model;

use Mautic\CoreBundle\Model\AjaxLookupModelInterface;
use Mautic\CoreBundle\Model\FormModel;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\Form\Type\WhatsAppNumberType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * @extends FormModel<WhatsAppNumber>
 *
 * @implements AjaxLookupModelInterface<WhatsAppNumber>
 */
class WhatsAppNumberModel extends FormModel implements AjaxLookupModelInterface
{
    public function getRepository(): WhatsAppNumberRepository
    {
        return $this->em->getRepository(WhatsAppNumber::class);
    }

    public function getPermissionBase(): string
    {
        return 'dialoghsm:numbers';
    }

    public function createForm($entity, FormFactoryInterface $formFactory, $action = null, $options = []): FormInterface
    {
        if (!$entity instanceof WhatsAppNumber) {
            throw new MethodNotAllowedHttpException(['WhatsAppNumber']);
        }

        if (!empty($action)) {
            $options['action'] = $action;
        }

        return $formFactory->create(WhatsAppNumberType::class, $entity, $options);
    }

    public function getEntity($id = null): ?WhatsAppNumber
    {
        if (null === $id) {
            return new WhatsAppNumber();
        }

        return parent::getEntity($id);
    }

    /**
     * @param string $filter
     * @param int    $limit
     * @param int    $start
     * @param array  $options
     */
    public function getLookupResults($type, $filter = '', $limit = 10, $start = 0, $options = []): array
    {
        $results = [];

        $entities = $this->getRepository()->getNumberList($filter, $limit, $start);

        foreach ($entities as $entity) {
            $results[$entity['id']] = $entity['name'].' ('.$entity['phoneNumber'].')';
        }

        return $results;
    }
}
