<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Exception;

/**
 * Sinaliza um erro transitório na API 360dialog (rede, rate limit, servidor indisponível).
 *
 * Quando lançada pelo handler, o Symfony Messenger aplica a retry strategy configurada
 * (até 3 tentativas com backoff exponencial). Após esgotar os retries, a mensagem vai
 * para a fila DLQ e o MessengerFailedEventSubscriber registra o log com status 'dlq'.
 *
 * Erros permanentes (validação, autenticação) NÃO devem lançar esta exceção — devem ser
 * registrados diretamente como 'failed' sem gerar retry.
 */
class TransientApiException extends \RuntimeException
{
}
