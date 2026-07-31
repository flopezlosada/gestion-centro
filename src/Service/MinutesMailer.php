<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Meeting;
use App\Entity\User;
use App\Security\AccessGate;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends the published acta to everyone the meeting concerns, WITH THE PDF ATTACHED.
 *
 * A separate service and not part of {@see NotificationDispatcher} because it is a different thing: the
 * dispatcher delivers NOTICES (a line of text, the same one on the phone and in the inbox), and this
 * delivers a DOCUMENT. The centre asked for it in those words — "el programa genera un acta en PDF que
 * envía por mail a todas las personas participantes" — and an acta you have to sign into an application
 * to read is not the same as one already sitting in your inbox.
 *
 * Everyone gets their own message rather than one with everybody in copy: a meeting's roll is not
 * something to publish to the rest of the staff, and a reply should reach whoever sent it, not all
 * fifteen people.
 *
 * Best-effort per recipient: a transport failure is logged and skipped, so one bad address never costs
 * the other fourteen their acta — the meeting is published either way, and the acta is in the app.
 */
final class MinutesMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly FileUploader $uploader,
        private readonly AccessGate $accessGate,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
        #[Autowire('%app.organization_name%')]
        private readonly string $organizationName,
    ) {
    }

    /**
     * Mails the meeting's acta to the given people. Does nothing when there is no file to send.
     *
     * @param Meeting    $meeting the meeting, with its acta already attached and published
     * @param list<User> $people  who to send it to
     *
     * @return int how many messages went out
     */
    public function send(Meeting $meeting, array $people): int
    {
        $path = $meeting->getMinutesPath();
        if (null === $path) {
            return 0;
        }

        $subject = sprintf('Acta: %s (%s)', $meeting->getTitle(), $meeting->getStartAt()->format('d/m/Y'));
        $body = sprintf(
            "Adjunto va el acta de «%s», del %s.\n\nTambién la tienes en la aplicación, en tu apartado de actas.\n\n%s",
            $meeting->getTitle(),
            $meeting->getStartAt()->format('d/m/Y'),
            $this->organizationName,
        );

        $sent = 0;
        foreach ($people as $person) {
            // La misma puerta que el resto de envíos: a quien no puede entrar en la aplicación no se le
            // manda nada (ver AccessGate); el acta le espera dentro el día que se le abra el acceso.
            if (!$this->accessGate->allows($person)) {
                continue;
            }

            try {
                $this->mailer->send((new Email())
                    ->from($this->mailerFrom)
                    ->to($person->getEmail())
                    ->subject($subject)
                    ->text($body)
                    ->attachFromPath($this->uploader->absolutePath($path), $meeting->getMinutesName() ?? 'acta.pdf'));
                ++$sent;
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('No se pudo enviar el acta por correo', [
                    'meeting' => $meeting->getId(),
                    'recipient' => $person->getEmail(),
                    'exception' => $e,
                ]);
            }
        }

        return $sent;
    }
}
