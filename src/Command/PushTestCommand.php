<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\WebPushSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends a single test Web Push to a user's subscribed devices, to verify that the server can actually
 * sign and deliver a push (VAPID keys configured, crypto working, endpoint reachable). Only the push
 * leg is exercised — it does NOT create an in-app notice nor send e-mail — so a failure points squarely
 * at the push pipeline. Read-only side effects (one notification); no prod guard needed.
 */
#[AsCommand(name: 'app:push:test', description: 'Envía un push de prueba a un usuario (para verificar el envío de notificaciones push)')]
final class PushTestCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly WebPushSender $webPush,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email del usuario al que enviar el push de prueba');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->users->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $io->error(sprintf('No hay ningún usuario con el email "%s".', $email));

            return Command::FAILURE;
        }

        $subscriptions = $this->subscriptions->findByUser($user);
        if ([] === $subscriptions) {
            $io->warning(sprintf('%s no tiene ninguna suscripción push. Que active los avisos en /avisos (navegador normal, permiso permanente) y reintenta.', $email));

            return Command::FAILURE;
        }

        $io->text(sprintf('Enviando push de prueba a %s (%d dispositivo(s))…', $email, \count($subscriptions)));
        $this->webPush->sendToUser($user, 'Aviso de prueba', 'Si ves esto, las notificaciones push funcionan.', '/avisos');

        $io->success('Push enviado. Si no llega, revisa el log (var/log) por si el servicio de push o la firma fallaron.');

        return Command::SUCCESS;
    }
}
