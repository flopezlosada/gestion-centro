<?php

declare(strict_types=1);

namespace App\Service\Cloud;

use App\Entity\Meeting;
use App\Entity\User;
use App\Repository\MeetingRepository;
use App\Service\AppSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The acta as a document in the centre's EducaMadrid cloud: created from a template in the centre's
 * account and shared to EDIT with whoever keeps the minutes. Publishing makes the cloud copy it — a frozen
 * copy, beside the draft — and shares THAT copy to READ with everybody convened, so they read what was
 * published while the draft can still be corrected; correcting is publishing again. The app keeps only
 * the documents' ids; the text never comes back here, not even to make the copy.
 *
 * Who it is shared with goes through CLOUD_SHARE_WITH, and the default is NOBODY: "all" in production, a
 * comma-separated list of cloud logins anywhere else. A development database holds the real staff list,
 * and sharing a document makes the cloud notify a real person — so forgetting to configure it must fail
 * silent, not loud.
 */
final class CloudMinutes
{
    private const string EDUCAMADRID_DOMAIN = '@educa.madrid.org';

    public function __construct(
        private readonly CloudAccount $account,
        private readonly EducamadridCloud $cloud,
        private readonly MinutesOdtRenderer $renderer,
        private readonly AppSettings $settings,
        private readonly MeetingRepository $meetings,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'default::CLOUD_SHARE_WITH')]
        private readonly ?string $shareWith,
    ) {
    }

    /**
     * Creates the acta's document from the template and shares it, to edit, with whoever keeps the
     * minutes.
     *
     * @param Meeting            $meeting the meeting, already started
     * @param User               $by      who asked for it
     * @param \DateTimeImmutable $now     the current instant
     *
     * @return list<string> the people it could NOT be shared with, and why, for the screen to say
     *
     * @throws CloudException when no account is connected or the cloud fails
     */
    public function create(Meeting $meeting, User $by, \DateTimeImmutable $now): array
    {
        return $this->createFrom($meeting, $by, $now, $this->renderer->render($meeting), 'odt');
    }

    /**
     * Creates the acta from a document written outside the app — in Word, LibreOffice, or downloaded from
     * somebody's own cloud — instead of the template, so whoever keeps the minutes their own way is not left
     * out. The file lands in the centre's account like any acta, and from there it is the same: shared to
     * edit with whoever keeps the minutes, published by copying it. Its bytes pass through here on their
     * way and are not kept.
     *
     * @param Meeting            $meeting   the meeting, already started
     * @param User               $by        who uploads it
     * @param \DateTimeImmutable $now       the current instant
     * @param string             $content   the document's bytes
     * @param string             $extension its extension ("docx"), which the cloud uses to open it
     *
     * @return list<string> the people it could NOT be shared with, and why
     *
     * @throws CloudException when no account is connected or the cloud fails
     */
    public function upload(Meeting $meeting, User $by, \DateTimeImmutable $now, string $content, string $extension): array
    {
        return $this->createFrom($meeting, $by, $now, $content, strtolower($extension));
    }

    /**
     * @return list<string> the people it could NOT be shared with, and why
     *
     * @throws CloudException when no account is connected or the cloud fails
     */
    private function createFrom(Meeting $meeting, User $by, \DateTimeImmutable $now, string $content, string $extension): array
    {
        $credentials = $this->requireCredentials();
        $folder = $this->folderFor($meeting);
        $path = $folder.'/'.substr(self::fileNameFor($meeting), 0, -\strlen('.odt')).'.'.$extension;

        $this->cloud->ensureFolder($credentials, $folder);
        $fileId = $this->uploadOrAdopt($credentials, $path, $content, $meeting);
        $meeting->attachCloudMinutes($fileId, $by, $now);

        $keeper = $meeting->minutesKeeper();

        return null === $keeper ? [] : $this->shareWithPeople($credentials, $path, [$keeper], EducamadridCloud::PERMISSION_EDIT);
    }

    /**
     * Publishes the acta: the cloud copies the draft as it is now, beside it, and the copy is shared to
     * READ with everybody convened. Publishing again (a correction) replaces the copy, which the cloud then
     * gives a new id, so it is shared again. The draft is found by its id, not by where it was created:
     * whoever runs the account may have moved or renamed it.
     *
     * @param Meeting $meeting a meeting whose acta is in the cloud
     * @param User    $by      who publishes it
     *
     * @return list<string> the people it could NOT be shared with, and why
     *
     * @throws CloudException when no account is connected, the draft is gone or the cloud fails
     */
    public function publish(Meeting $meeting, User $by): array
    {
        $credentials = $this->requireCredentials();
        $draft = $this->cloud->pathOf($credentials, (string) $meeting->getMinutesCloudFileId())
            ?? throw new CloudException('El documento del acta ya no está en la nube: puede que lo hayan borrado. Recupéralo de la papelera de la cuenta del centro.');

        $copyId = $this->cloud->copy($credentials, $draft, self::publishedPathFor($draft));
        $meeting->publishCloudMinutes($copyId, $by);

        return $this->shareWithPeople($credentials, self::publishedPathFor($draft), $meeting->people(), EducamadridCloud::PERMISSION_READ);
    }

    /**
     * Moves an acta written in the app to the cloud, keeping its life as it was: the text, when there is
     * one, becomes the draft (.odt from the template, with the text in it) so it can still be corrected
     * there; a published acta gets as its published copy the very FILE the people convened received, not a
     * new rendering of the text. An unpublished file beside a text goes too, unshared, as the "documento
     * anterior": it may say something else, and nothing is thrown away. Shared like any cloud acta. Nobody
     * is told: this is a move, not a publication, and they already had it.
     *
     * Safe to run again after a failure halfway: what is already in the cloud is taken, not duplicated.
     *
     * @param Meeting     $meeting a meeting whose acta was written in the app
     * @param string|null $file    the bytes of its file here, or null when it has none
     *
     * @return list<string> the people it could NOT be shared with, and why
     *
     * @throws CloudException  when no account is connected or the cloud fails
     * @throws \LogicException when there is nothing to move, or a published acta has no file
     */
    public function moveToCloud(Meeting $meeting, ?string $file): array
    {
        $hasText = null !== $meeting->getDiscussion() || null !== $meeting->getAgreements();
        if (!$hasText && null === $file) {
            throw new \LogicException('Esta reunión no tiene acta escrita en la aplicación.');
        }
        if ($meeting->isMinutesPublished() && null === $file) {
            throw new \LogicException('Un acta publicada sin su fichero no se puede pasar a la nube tal como se envió.');
        }

        $credentials = $this->requireCredentials();
        $folder = $this->folderFor($meeting);
        $base = $folder.'/'.substr(self::fileNameFor($meeting), 0, -\strlen('.odt'));
        $fileExtension = self::extensionOf($meeting->getMinutesName());
        $this->cloud->ensureFolder($credentials, $folder);

        // Lo que se escribe: el texto en la plantilla, o el fichero tal cual si no hay texto.
        $draftPath = $base.($hasText ? '.odt' : '.'.$fileExtension);
        $draftId = $this->uploadOrAdopt($credentials, $draftPath, $hasText ? $this->renderer->render($meeting) : (string) $file, $meeting);

        // Lo que se leyó: el fichero que recibieron las personas convocadas.
        $publishedPath = null;
        $publishedId = null;
        if ($meeting->isMinutesPublished()) {
            $publishedPath = self::publishedPathFor($base.'.'.$fileExtension);
            $publishedId = $this->uploadOrAdopt($credentials, $publishedPath, (string) $file, $meeting);
        } elseif ($hasText && null !== $file) {
            // Un fichero sin publicar junto a un texto: puede ser un acta subida de fuera que no dice lo
            // mismo. No se tira: va al lado, sin compartir, para quien lleva la cuenta del centro.
            $this->uploadOrAdopt($credentials, $base.' (documento anterior).'.$fileExtension, $file, $meeting);
        }

        $meeting->moveMinutesToCloud($draftId, $publishedId);

        $keeper = $meeting->minutesKeeper();
        $problems = null === $keeper ? [] : $this->shareWithPeople($credentials, $draftPath, [$keeper], EducamadridCloud::PERMISSION_EDIT);

        return null === $publishedPath ? $problems : [...$problems, ...$this->shareWithPeople($credentials, $publishedPath, $meeting->people(), EducamadridCloud::PERMISSION_READ)];
    }

    /**
     * The link that opens the DRAFT, the document whoever keeps the minutes writes in.
     */
    public function urlFor(Meeting $meeting): ?string
    {
        $fileId = $meeting->getMinutesCloudFileId();

        return null === $fileId ? null : $this->cloud->fileUrl($fileId);
    }

    /**
     * The link that opens what was PUBLISHED, the frozen copy everybody convened reads. An acta published
     * before copies existed has none, and falls back to its draft.
     */
    public function publishedUrlFor(Meeting $meeting): ?string
    {
        $fileId = $meeting->getMinutesCloudPublishedFileId();

        return null === $fileId ? $this->urlFor($meeting) : $this->cloud->fileUrl($fileId);
    }

    /**
     * A person's login in the EducaMadrid cloud: their e-mail's local part, when the e-mail is an
     * EducaMadrid one (checked against a real account: natalia.x@educa.madrid.org logs in as natalia.x).
     *
     * @return string|null the login, or null for somebody without an EducaMadrid e-mail
     */
    public static function cloudLoginOf(User $user): ?string
    {
        $email = strtolower($user->getEmail());

        return str_ends_with($email, self::EDUCAMADRID_DOMAIN) ? substr($email, 0, -\strlen(self::EDUCAMADRID_DOMAIN)) : null;
    }

    /**
     * @param list<User> $people
     *
     * @return list<string> who it could not be shared with, and why
     */
    private function shareWithPeople(CloudCredentials $credentials, string $path, array $people, int $permissions): array
    {
        $allowed = $this->allowedLogins();
        $problems = [];
        $toShare = [];
        foreach ($people as $person) {
            $login = self::cloudLoginOf($person);
            $problem = match (true) {
                null === $login => 'no tiene correo de EducaMadrid',
                $login === strtolower($credentials->login) => null,
                null !== $allowed && !\in_array($login, $allowed, true) => 'fuera de CLOUD_SHARE_WITH (entorno de pruebas)',
                default => false,
            };
            if (false === $problem) {
                $toShare[$login] = $person;
            } elseif (null !== $problem) {
                $problems[] = $this->noteUnshared($person, $problem);
            }
        }

        foreach ($this->cloud->shareWithEach($credentials, $path, array_map(strval(...), array_keys($toShare)), $permissions) as $login => $problem) {
            if (null !== $problem) {
                $problems[] = $this->noteUnshared($toShare[$login], $problem);
            }
        }

        return $problems;
    }

    /**
     * @return string the line the screen shows for somebody it could not be shared with
     */
    private function noteUnshared(User $person, string $problem): string
    {
        $this->logger->info('Acta en la nube no compartida', ['user' => $person->getId(), 'reason' => $problem]);

        return $person->getFullName().': '.$problem;
    }

    /**
     * Uploads the template, or takes the document already at that path when it belongs to no other
     * meeting: a first attempt that created it and then failed (the id lookup, the save, a double click)
     * must not leave the button refusing forever. The cloud never overwrites, so what was written in it is
     * kept either way.
     *
     * @throws CloudException when the path holds another meeting's acta, or the cloud fails
     */
    private function uploadOrAdopt(CloudCredentials $credentials, string $path, string $content, Meeting $meeting): string
    {
        try {
            return $this->cloud->upload($credentials, $path, $content);
        } catch (CloudFileExistsException $e) {
            $fileId = $this->cloud->fileIdAt($credentials, $path);
            $owner = $this->meetings->findOneBy(['minutesCloudFileId' => $fileId]);
            if (null !== $owner && $owner !== $meeting) {
                throw new CloudException('En la nube ya hay un acta con ese nombre, de otra reunión: cambia el título de esta y vuelve a crearla.', previous: $e);
            }

            return $fileId;
        }
    }

    /**
     * @return list<string>|null the logins it may be shared with, or null for everybody
     */
    private function allowedLogins(): ?array
    {
        $setting = trim((string) $this->shareWith);

        return 'all' === $setting ? null : array_values(array_filter(array_map(
            static fn (string $l): string => strtolower(trim($l)),
            explode(',', $setting),
        )));
    }

    /**
     * @throws CloudException when no account is connected
     */
    private function requireCredentials(): CloudCredentials
    {
        return $this->account->credentials() ?? throw new CloudException('La nube de EducaMadrid no está conectada. Conéctala desde Administración.');
    }

    /**
     * "Actas/2026-2027/Departamento de Matemáticas", under the folder chosen in Administración: one folder
     * per school year and kind of meeting, so the cloud is browsable on its own, without the app.
     */
    private function folderFor(Meeting $meeting): string
    {
        $start = $meeting->getStartAt();
        $year = (int) $start->format('Y');
        $first = (int) $start->format('n') >= 9 ? $year : $year - 1;

        return \sprintf('%s/%d-%d/%s', $this->settings->getCloudMinutesFolder(), $first, $first + 1, self::safeName($meeting->getType()?->getName() ?? 'Otras reuniones'));
    }

    /**
     * "…/2026-10-13 Reunión de departamento (publicada).odt": the frozen copy goes beside its draft, with
     * its extension, so whoever browses the folder sees both and which is which.
     */
    private static function publishedPathFor(string $draft): string
    {
        return 1 === preg_match('#^(.*?)(\.[A-Za-z0-9]{1,5})?$#', $draft, $parts) ? $parts[1].' (publicada)'.($parts[2] ?? '') : $draft.' (publicada)';
    }

    /**
     * "2026-10-13 Reunión de departamento.odt"; the date first so a folder lists in order.
     */
    private static function fileNameFor(Meeting $meeting): string
    {
        return $meeting->getStartAt()->format('Y-m-d H.i').' '.self::safeName($meeting->getTitle()).'.odt';
    }

    /** "pdf" from "acta.PDF"; a file kept here with no usable extension is taken as a PDF. */
    private static function extensionOf(?string $name): string
    {
        return 1 === preg_match('#\.([A-Za-z0-9]{1,5})$#', (string) $name, $m) ? strtolower($m[1]) : 'pdf';
    }

    /** A title with nothing in it that a path would read as a separator or a cloud would refuse. */
    private static function safeName(string $name): string
    {
        return trim(preg_replace('#[/\\\\:*?"<>|]+#', '-', $name) ?? '', " .-") ?: 'Sin título';
    }
}
