<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use Mautic\CoreBundle\Controller\AjaxLookupControllerTrait;

class AjaxController extends CommonAjaxController
{
    use AjaxLookupControllerTrait;
}
