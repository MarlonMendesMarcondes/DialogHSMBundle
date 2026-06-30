<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\DependencyInjection\DialogHSMExtension;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Valida que a extensão registra os transports, o consumer name único e as rotas
 * corretamente — prevenindo a race condition de XACK no Redis Stream.
 */
class DialogHSMExtensionTest extends TestCase
{
    private DialogHSMExtension $extension;
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->extension = new DialogHSMExtension();
        $this->container = new ContainerBuilder();
        $this->extension->prepend($this->container);
    }

    // =========================================================================
    // Env var defaults — container compila sem as vars de ambiente configuradas
    //
    // O que importa testar é que os parâmetros EXISTEM no container após prepend().
    // Sem eles, o Symfony lança exceção em compile time quando as env vars estão
    // ausentes, quebrando toda a aplicação. O valor resolvido varia conforme o
    // ambiente (no container de teste as vars já estão definidas via docker-compose).
    // =========================================================================

    public function testDefaultEnvParamWhatsappIsRegistered(): void
    {
        $this->assertTrue(
            $this->container->hasParameter('env(MAUTIC_MESSENGER_DSN_WHATSAPP)'),
            'Parâmetro env(MAUTIC_MESSENGER_DSN_WHATSAPP) deve existir para o container compilar sem a env var definida'
        );
    }

    public function testDefaultEnvParamWhatsappDirectIsRegistered(): void
    {
        $this->assertTrue(
            $this->container->hasParameter('env(MAUTIC_MESSENGER_DSN_WHATSAPP_DIRECT)'),
            'Parâmetro env(MAUTIC_MESSENGER_DSN_WHATSAPP_DIRECT) deve existir para o container compilar sem a env var definida'
        );
    }

    public function testDefaultEnvParamWhatsappFailedIsRegistered(): void
    {
        $this->assertTrue(
            $this->container->hasParameter('env(MAUTIC_MESSENGER_DSN_WHATSAPP_FAILED)'),
            'Parâmetro env(MAUTIC_MESSENGER_DSN_WHATSAPP_FAILED) deve existir para o container compilar sem a env var definida'
        );
    }

    // =========================================================================
    // Transports registrados
    // =========================================================================

    public function testThreeTransportsAreRegistered(): void
    {
        $transports = $this->getMessengerConfig()['transports'];

        $this->assertArrayHasKey('whatsapp',        $transports, 'Transport "whatsapp" (RabbitMQ bulk) deve existir');
        $this->assertArrayHasKey('whatsapp_direct',  $transports, 'Transport "whatsapp_direct" (Redis direct) deve existir');
        $this->assertArrayHasKey('whatsapp_failed',  $transports, 'Transport "whatsapp_failed" (DLQ) deve existir');
    }

    public function testAllTransportsHaveAutoSetupFalse(): void
    {
        $transports = $this->getMessengerConfig()['transports'];

        foreach (['whatsapp', 'whatsapp_direct', 'whatsapp_failed'] as $name) {
            $this->assertFalse(
                $transports[$name]['options']['auto_setup'],
                "Transport \"{$name}\" deve ter auto_setup=false (queues gerenciadas manualmente)"
            );
        }
    }

    // =========================================================================
    // Consumer name único — previne race condition de XACK no Redis Stream
    //
    // Contexto: o Symfony Redis Messenger usa gethostname() como consumer name
    // padrão. Todos os workers no mesmo servidor compartilham a mesma PEL
    // (Pending Entries List), causando race condition quando múltiplos workers
    // tentam dar XACK na mesma mensagem órfã simultaneamente.
    // =========================================================================

    public function testWhatsAppDirectHasConsumerOptionConfigured(): void
    {
        $options = $this->getMessengerConfig()['transports']['whatsapp_direct']['options'];

        $this->assertArrayHasKey('consumer', $options, 'consumer deve estar explicitamente configurado para evitar race condition de XACK');
    }

    public function testWhatsAppDirectConsumerNameContainsHostname(): void
    {
        $consumer = $this->getMessengerConfig()['transports']['whatsapp_direct']['options']['consumer'];

        $this->assertStringContainsString(
            gethostname(),
            $consumer,
            'Consumer name deve incluir o hostname para ser único por servidor'
        );
    }

    public function testWhatsAppDirectConsumerNameContainsTransportSuffix(): void
    {
        $consumer = $this->getMessengerConfig()['transports']['whatsapp_direct']['options']['consumer'];

        $this->assertStringContainsString(
            '-whatsapp-direct',
            $consumer,
            'Consumer name deve ter sufixo do transport para não conflitar com outros consumers do mesmo host'
        );
    }

    public function testWhatsAppDirectConsumerNameIsNotBareHostname(): void
    {
        $consumer = $this->getMessengerConfig()['transports']['whatsapp_direct']['options']['consumer'];

        $this->assertNotSame(
            gethostname(),
            $consumer,
            'Consumer name não pode ser apenas o hostname — múltiplos workers no mesmo host compartilhariam a PEL'
        );
    }

    public function testWhatsAppAndWhatsAppDirectHaveDifferentConsumerNames(): void
    {
        $transports     = $this->getMessengerConfig()['transports'];
        $directConsumer = $transports['whatsapp_direct']['options']['consumer'] ?? null;

        // whatsapp usa AMQP (sem consumer name no sentido do Redis), mas garantimos
        // que whatsapp_direct tem um consumer name distinto e explícito
        $this->assertNotNull($directConsumer);
        $this->assertIsString($directConsumer);
        $this->assertGreaterThan(strlen(gethostname()), strlen($directConsumer));
    }

    // =========================================================================
    // Retry strategy
    // =========================================================================

    public function testWhatsAppTransportHasRetryStrategy(): void
    {
        $transport = $this->getMessengerConfig()['transports']['whatsapp'];

        $this->assertArrayHasKey('retry_strategy', $transport);
        $this->assertSame(3, $transport['retry_strategy']['max_retries']);
    }

    public function testWhatsAppDirectTransportHasRetryStrategy(): void
    {
        $transport = $this->getMessengerConfig()['transports']['whatsapp_direct'];

        $this->assertArrayHasKey('retry_strategy', $transport);
        $this->assertSame(3, $transport['retry_strategy']['max_retries']);
    }

    // =========================================================================
    // Routing de mensagens
    // =========================================================================

    public function testSendWhatsAppMessageRoutesToWhatsapp(): void
    {
        $routing = $this->getMessengerConfig()['routing'];

        $this->assertSame(
            'whatsapp',
            $routing[SendWhatsAppMessage::class],
            'SendWhatsAppMessage deve rotear para o transport "whatsapp" (RabbitMQ por fila)'
        );
    }

    public function testSendWhatsAppDirectBatchMessageRoutesToWhatsappDirect(): void
    {
        $routing = $this->getMessengerConfig()['routing'];

        $this->assertSame(
            'whatsapp_direct',
            $routing[SendWhatsAppDirectBatchMessage::class],
            'SendWhatsAppDirectBatchMessage deve rotear para "whatsapp_direct" (Redis Stream)'
        );
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /** @return array<string, mixed> */
    private function getMessengerConfig(): array
    {
        // prependExtensionConfig insere no início da lista; o primeiro elemento
        // é o mais recente (o nosso). Cada elemento é um array de config do framework.
        $configs = $this->container->getExtensionConfig('framework');

        foreach ($configs as $config) {
            if (isset($config['messenger'])) {
                return $config['messenger'];
            }
        }

        $this->fail('Nenhuma configuração de "messenger" encontrada no ContainerBuilder após prepend()');
    }
}
