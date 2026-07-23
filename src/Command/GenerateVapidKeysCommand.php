<?php

declare(strict_types=1);

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-shot helper: generates a VAPID key pair for Web Push and prints the .env.local lines to paste.
 * Run ONCE per environment and keep the keys stable — regenerating them invalidates every existing
 * browser subscription (they are tied to the public key), so everyone would have to re-enable push.
 */
#[AsCommand(name: 'app:push:generate-vapid-keys', description: 'Genera un par de claves VAPID para las notificaciones push (una sola vez)')]
final class GenerateVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keys = VAPID::createVapidKeys();

        $io->title('Claves VAPID generadas');
        $io->warning('Genera las claves UNA sola vez por entorno. Si las regeneras, se invalidan todas las suscripciones existentes y el profesorado tendrá que volver a activar los avisos.');
        $io->text('Pega estas líneas en tu .env.local (VAPID_SUBJECT: un mailto: o URL del centro):');
        $io->newLine();
        $io->writeln(sprintf('VAPID_PUBLIC_KEY=%s', $keys['publicKey']));
        $io->writeln(sprintf('VAPID_PRIVATE_KEY=%s', $keys['privateKey']));
        $io->writeln('VAPID_SUBJECT=mailto:avisos@tucentro.example');

        return Command::SUCCESS;
    }
}
