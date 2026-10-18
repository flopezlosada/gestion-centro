<?php

declare(strict_types=1);

namespace App\Family;

use App\Entity\FamilyActivity;
use App\Entity\FamilySignup;
use App\Entity\FamilyVolunteer;
use App\Enum\Weekday;
use App\Service\AppSettings;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tells the families coming to an activity, by e-mail, when its organiser changes or removes it.
 *
 * E-mail because a family has no other way to hear it: the notices and the phone are staff's. Only the
 * sessions a family is signed up for, from today on, count, and only a change a family has to act on —
 * the day, the hours or the place of one of them —, not a new title or description.
 *
 * What changed is found by comparing the session of each signup before and after the edit
 * ({@see snapshot()}), not by asking each form what it touched: the session is what the family sees, and
 * it comes out of the activity of either kind the same way ({@see FamilySessions::sessionOn()}). A
 * one-off moved to another day takes its signups along, so they show as moved; a weekly one no longer
 * held on a day leaves the signup there without a session, which is said as such.
 *
 * Each family gets its own message, addressed to it alone: the families never learn of one another.
 * Delivery is best-effort: the change is already saved, and a failure — of the transport, or an address
 * the mail library refuses — is logged and the next family still told. The log carries the family's id
 * and the kind of failure, not the exception's message: a refused address or an SMTP rejection quotes
 * the address, and a family's is never written down ({@see \App\Entity\FamilyVolunteer}).
 */
final class FamilyActivityMailer
{
    public function __construct(
        private readonly FamilySessions $sessions,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AppSettings $settings,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
    ) {
    }

    /**
     * The sessions the families are coming to, from today on, as they are now: taken before an edit or a
     * removal, and handed back to {@see mailChanges()} or {@see mailCancellation()} after it.
     *
     * @param FamilyActivity $activity the activity about to change
     *
     * @return list<array{signup: FamilySignup, was: FamilySession|null}> each signup with its session
     */
    public function snapshot(FamilyActivity $activity): array
    {
        $today = $this->clock->now()->setTime(0, 0);

        return array_values(array_map(
            fn (FamilySignup $s): array => ['signup' => $s, 'was' => $this->sessions->sessionOn($activity, $s->getSessionDate())],
            array_filter($activity->getSignups(), static fn (FamilySignup $s): bool => $s->getSessionDate() >= $today),
        ));
    }

    /**
     * Mails each family whose session changed day, hours or place, with how it is now.
     *
     * @param FamilyActivity                                             $activity the activity, already saved
     * @param list<array{signup: FamilySignup, was: FamilySession|null}> $snapshot what {@see snapshot()} gave before the edit
     */
    public function mailChanges(FamilyActivity $activity, array $snapshot): void
    {
        $this->mailEach($snapshot, 'Cambio en «'.$activity->getTitle().'»', 'Ha cambiado «'.$activity->getTitle().'», a la que vas a venir:', function (FamilySignup $signup, FamilySession $was) use ($activity): ?string {
            $now = $this->sessions->sessionOn($activity, $signup->getSessionDate());
            if (null !== $now && [$was->startsAt, $was->endsAt, $was->place] == [$now->startsAt, $now->endsAt, $now->place]) {
                return null;
            }

            return self::describe($was).' → '.(null === $now ? 'ya no hay sesión ese día.' : 'ahora '.self::describe($now).'.');
        });
    }

    /**
     * Mails each family coming that the activity will not take place.
     *
     * @param FamilyActivity                                             $activity the activity, about to be or just removed
     * @param list<array{signup: FamilySignup, was: FamilySession|null}> $snapshot what {@see snapshot()} gave before removing it
     */
    public function mailCancellation(FamilyActivity $activity, array $snapshot): void
    {
        $this->mailEach($snapshot, 'Se cancela «'.$activity->getTitle().'»', 'Se ha cancelado «'.$activity->getTitle().'». Ya no hace falta que vengas:', static fn (FamilySignup $signup, FamilySession $was): string => self::describe($was));
    }

    /**
     * Sends one message to each family with something to be told, its sessions listed under the opening.
     * A signup that had no session already before (a weekly day dropped by an earlier edit, when the
     * family was told) is not about this change, and is left out.
     *
     * @param list<array{signup: FamilySignup, was: FamilySession|null}> $snapshot the signups and their sessions before
     * @param string                                                     $subject  the subject line
     * @param string                                                     $opening  the first line of the body
     * @param callable(FamilySignup, FamilySession): ?string             $line     what to say of one session, null for nothing
     */
    private function mailEach(array $snapshot, string $subject, string $opening, callable $line): void
    {
        $families = [];
        $lines = [];
        foreach ($snapshot as ['signup' => $signup, 'was' => $was]) {
            $said = null !== $was ? $line($signup, $was) : null;
            if (null !== $said) {
                $families[$signup->getFamily()->getId()] = $signup->getFamily();
                $lines[$signup->getFamily()->getId()][] = '- '.$said;
            }
        }
        if ([] === $lines) {
            return;
        }

        $link = $this->urlGenerator->generate('family_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        foreach ($lines as $id => $familyLines) {
            $this->send($families[$id], $subject, $opening."\n\n".implode("\n", $familyLines)."\n\nPuedes verlo, y apuntarte a otra sesión, en:\n".$link);
        }
    }

    /**
     * @param FamilyVolunteer $family  who to tell
     * @param string          $subject the subject line
     * @param string          $body    the text
     */
    private function send(FamilyVolunteer $family, string $subject, string $body): void
    {
        try {
            $this->mailer->send((new Email())
                ->from($this->mailerFrom)
                ->to($family->getEmail())
                ->subject($subject)
                ->text($body."\n\n".$this->settings->getOrganizationName()));
        } catch (TransportExceptionInterface|RfcComplianceException $e) {
            $this->logger->error('No se pudo avisar por correo a una familia de un cambio de actividad', [
                'family' => $family->getId(),
                'failure' => $e::class,
            ]);
        }
    }

    /**
     * @return string the session as the family reads it, e.g. "martes 13/01, de 10:00 a 11:00, en Aula 2.3"
     */
    private static function describe(FamilySession $session): string
    {
        return mb_strtolower(Weekday::from((int) $session->startsAt->format('N'))->label()).' '.$session->startsAt->format('d/m')
            .', '.(null === $session->endsAt ? 'a las '.$session->startsAt->format('H:i') : 'de '.$session->startsAt->format('H:i').' a '.$session->endsAt->format('H:i'))
            .(null === $session->place ? '' : ', en '.$session->place);
    }
}
