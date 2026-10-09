<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Exception;

/**
 * O webhook chegou antes de o Mautic estar pronto para processá-lo: o WebhookController
 * responde 503 e a 360dialog reentrega mais tarde. Nada pode ter sido gravado antes.
 */
class WebhookRetryLaterException extends \RuntimeException
{
}
