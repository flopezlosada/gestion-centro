<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sends a test Web Push to a user's devices and PRINTS the push service's response per device
 * (success / HTTP status / reason). Unlike {@see \App\Service\WebPushSender} (best-effort, swallows
 * failures), this surfaces exactly what FCM/APNs answered, so a "delivered but never arrived" can be
 * diagnosed: 201 = accepted by the push service, 4xx = rejected (bad VAPID, expired, payload…). Builds
 * its own WebPush with the same options as the real sender (high urgency).
 */
#[AsCommand(name: 'app:push:test', description: 'Envía un push de prueba a un usuario y muestra la respuesta del servicio de push')]
final class PushTestCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PushSubscriptionRepository $subscriptions,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $vapidPublicKey,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')]
        private readonly string $vapidPrivateKey,
        #[Autowire('%env(VAPID_SUBJECT)%')]
        private readonly string $vapidSubject,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email del usuario al que enviar el push de prueba');
        $this->addArgument('mensaje', InputArgument::OPTIONAL, 'Texto del aviso (por defecto "Aviso de prueba")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('' === $this->vapidPublicKey || '' === $this->vapidPrivateKey) {
            $io->error('VAPID no configurado (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY vacíos). Genera y pon las claves primero.');

            return Command::FAILURE;
        }

        $email = (string) $input->getArgument('email');
        $user = $this->users->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $io->error(sprintf('No hay ningún usuario con el email "%s".', $email));

            return Command::FAILURE;
        }

        $subscriptions = $this->subscriptions->findByUser($user);
        if ([] === $subscriptions) {
            $io->warning(sprintf('%s no tiene ninguna suscripción push.', $email));

            return Command::FAILURE;
        }

        $title = trim((string) $input->getArgument('mensaje')) ?: 'Aviso de prueba';
        $payload = json_encode([
            'title' => $title,
            'body' => 'Notificación de prueba del sistema de guardias.',
            'url' => '/guardias/mias',
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        $webPush = new WebPush(
            ['VAPID' => ['subject' => $this->vapidSubject, 'publicKey' => $this->vapidPublicKey, 'privateKey' => $this->vapidPrivateKey]],
            ['urgency' => 'high', 'TTL' => 43200],
            10,
            ['allow_redirects' => false],
        );
        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                new Subscription($subscription->getEndpoint(), $subscription->getP256dh(), $subscription->getAuth(), 'aes128gcm'),
                $payload,
            );
        }

        $io->text(sprintf('Enviando "%s" a %s (%d dispositivo(s))…', $title, $email, \count($subscriptions)));
        $rows = [];
        foreach ($webPush->flush() as $report) {
            $response = $report->getResponse();
            $rows[] = [
                $report->isSuccess() ? 'OK' : 'FALLO',
                null !== $response ? (string) $response->getStatusCode() : '—',
                mb_substr($report->getReason(), 0, 60),
                mb_substr($report->getEndpoint(), 0, 40),
            ];
        }
        $io->table(['Resultado', 'HTTP', 'Motivo', 'Endpoint'], $rows);

        return Command::SUCCESS;
    }
}
