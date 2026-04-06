<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\EventListener\ApiKeyEncryptionSubscriber;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'dialoghsm:encrypt-api-keys',
    description: 'Criptografa todas as API keys de WhatsApp ainda armazenadas em texto plano'
)]
class EncryptApiKeysCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionHelper $encryptionHelper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Mostra quais registros seriam atualizados sem efetuar alterações'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('DialogHSM — Criptografia de API Keys');

        if ($dryRun) {
            $io->note('Modo dry-run: nenhuma alteração será gravada.');
        }

        $numbers = $this->entityManager
            ->getRepository(WhatsAppNumber::class)
            ->findAll();

        $total     = count($numbers);
        $encrypted = 0;
        $skipped   = 0;

        foreach ($numbers as $number) {
            $apiKey = $number->getApiKey();

            if (empty($apiKey)) {
                ++$skipped;
                continue;
            }

            if (str_starts_with($apiKey, ApiKeyEncryptionSubscriber::ENC_PREFIX)) {
                ++$skipped;
                $io->text(sprintf('  <fg=yellow>SKIP</> %s — já criptografada', $number->getName() ?? $number->getId()));
                continue;
            }

            $io->text(sprintf('  <fg=green>ENCRYPT</> %s', $number->getName() ?? $number->getId()));

            if (!$dryRun) {
                $encryptedValue = ApiKeyEncryptionSubscriber::ENC_PREFIX
                    .$this->encryptionHelper->encrypt($apiKey);

                // Grava diretamente via DBAL para contornar o subscriber (que já atuaria no flush)
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE '.
                    $this->entityManager->getClassMetadata(WhatsAppNumber::class)->getTableName().
                    ' SET api_key = :encrypted WHERE id = :id',
                    ['encrypted' => $encryptedValue, 'id' => $number->getId()]
                );
            }

            ++$encrypted;
        }

        $io->newLine();
        $io->success(sprintf(
            '%d de %d chaves processadas (%d ignoradas).%s',
            $encrypted,
            $total,
            $skipped,
            $dryRun ? ' (dry-run — sem alterações reais)' : ''
        ));

        return Command::SUCCESS;
    }
}
