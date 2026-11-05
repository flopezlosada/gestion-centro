<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Meeting;
use App\Entity\MeetingType;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\CloudShareRepository;
use App\Repository\MeetingRepository;
use App\Service\AppSettings;
use App\Service\Cloud\CloudAccount;
use App\Service\Cloud\CloudCredentials;
use App\Service\Cloud\CloudException;
use App\Service\Cloud\CloudMinutes;
use App\Service\Cloud\CloudShareQueue;
use App\Service\Cloud\EducamadridCloud;
use App\Service\Cloud\MinutesOdtRenderer;
use App\Service\Cron\TaskLock;
use App\Service\MeetingNotifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Twig\Environment;

/**
 * The acta as a document in the cloud, from creating it to publishing it, against a fake cloud that
 * answers like Nextcloud and records what it was asked. The account is sealed for real in the settings
 * table; only the cloud is fake.
 *
 * What it guards: where the document goes, who it is shared with and how (the writer to EDIT, the rest
 * to READ, and nobody at all unless CLOUD_SHARE_WITH says so), that a cloud failing halfway never
 * leaves a document the meeting does not point at, and that what the cloud's limit on shares leaves out
 * waits in the queue and is made later — telling each person only once theirs is made.
 */
final class CloudMinutesTest extends KernelTestCase
{
    private AppSettings $settings;

    private EntityManagerInterface $em;

    private CloudShareQueue $queue;

    /** @var array<int|string, string> where the fake cloud keeps each file the app put or copied in it, by the id it gave it */
    private array $paths = [];

    /** @var list<User> the people this test made, so only their notices are counted */
    private array $people = [];

    /** @var list<string> logins the fake cloud says a file is already shared with */
    private array $alreadyShared = [];

    /** How many more shares the fake cloud allows before answering 429, or null for no limit. */
    private ?int $shareBudget = null;

    /** @var list<array{method: string, url: string, body: string}> */
    private array $sent = [];

    /** @var list<string> logins the fake cloud does not know, so sharing with them fails */
    private array $unknownLogins = [];

    /** Whether the fake cloud drops the connection on any share. */
    private bool $sharingUnreachable = false;

    /** @var array<string, string> the bytes of each file the app PUT, by its WebDAV URL, as the fake cloud keeps them */
    private array $files = [];

    /** The version the fake cloud says its files are; it moves on every write. */
    private int $version = 1;

    /** What the fake cloud answers a PUT with If-Match instead of writing, or null to write (412 saved, 423 open). */
    private ?int $conditionalPutRefusedWith = null;

    /** Where the fake cloud says the draft is now, or null for a draft that was deleted. */
    private ?string $draftNowAt = 'Actas/2026-2027/Otras reuniones/2026-09-15 14.00 Reunión de seguimiento.odt';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(AppSettings::class);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function minutes(?string $shareWith, bool $connected = true): CloudMinutes
    {
        $cloud = new EducamadridCloud(new MockHttpClient($this->fakeCloud(...)));
        // La clave se hace al vuelo: ninguna, ni de mentira, vive en el repositorio.
        $account = new CloudAccount($this->settings, $cloud, base64_encode(sodium_crypto_secretbox_keygen()));
        if ($connected) {
            $account->connect(new CloudCredentials('ies.lacabrera', 'app-password'));
        }
        $renderer = new MinutesOdtRenderer(self::getContainer()->get(Environment::class), self::getContainer()->getParameter('kernel.project_dir').'/public/img/logo-ies.png');

        $container = self::getContainer();
        $this->queue = new CloudShareQueue($account, $cloud, $container->get(CloudShareRepository::class), $this->em, $container->get(MeetingNotifier::class), $container->get(TaskLock::class), $shareWith);

        return new CloudMinutes($account, $cloud, $renderer, $this->settings, $container->get(MeetingRepository::class), $this->queue);
    }

    /**
     * Answers like the EducaMadrid cloud would.
     *
     * @param array<string, mixed> $options
     */
    private function fakeCloud(string $method, string $url, array $options): MockResponse
    {
        $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
        $this->sent[] = ['method' => $method, 'url' => $url, 'body' => $body];

        if ('PUT' === $method) {
            $ifMatch = $options['normalized_headers']['if-match'][0] ?? null;
            if (null !== $ifMatch && null !== $this->conditionalPutRefusedWith) {
                return new MockResponse('', ['http_code' => $this->conditionalPutRefusedWith]);
            }
            if (null !== $ifMatch && 'If-Match: '.$this->etag() !== $ifMatch) {
                return new MockResponse('', ['http_code' => 412]);
            }
            $this->remember($url);
            $this->files[$url] = $body;
            ++$this->version;
        }
        if ('GET' === $method && str_contains($url, '/remote.php/dav/files/')) {
            return isset($this->files[$url])
                ? new MockResponse($this->files[$url], ['http_code' => 200, 'response_headers' => ['ETag' => $this->etag()]])
                : new MockResponse('', ['http_code' => 404]);
        }
        if ('COPY' === $method) {
            $this->remember((string) preg_replace('#^Destination: #i', '', (string) ($options['normalized_headers']['destination'][0] ?? '')));
        }

        return match ($method) {
            'MKCOL', 'PUT' => new MockResponse('', ['http_code' => 201]),
            'COPY' => new MockResponse('', ['http_code' => 201]),
            'PROPFIND' => new MockResponse('<oc:fileid>'.self::idFor($url).'</oc:fileid>', ['http_code' => 207]),
            'SEARCH' => $this->fakeSearch($body),
            'GET' => new MockResponse((string) json_encode(['ocs' => ['data' => array_map(static fn (string $l): array => ['share_type' => 0, 'share_with' => $l], $this->alreadyShared)]]), ['http_code' => 200]),
            'POST' => $this->fakeShare($body),
            'DELETE' => new MockResponse('', ['http_code' => 200]),
            default => throw new \LogicException('La nube falsa no sabe responder a '.$method),
        };
    }

    /** The version of the fake cloud's files, quoted as Nextcloud sends it. */
    private function etag(): string
    {
        return '"v'.$this->version.'"';
    }

    /**
     * The PUT requests sent, in order.
     *
     * @return list<array{method: string, url: string, body: string}>
     */
    private function puts(): array
    {
        return array_values(array_filter($this->sent, static fn (array $r): bool => 'PUT' === $r['method']));
    }

    /** The content.xml of an .odt's bytes: where the agenda is written. */
    private static function contentOf(string $odt): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'odt');
        file_put_contents($path, $odt);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $content = (string) $zip->getFromName('content.xml');
        $zip->close();
        unlink($path);

        return $content;
    }

    /** The id the fake cloud gives a file: the published copy one, the draft (and anything else) another. */
    private static function idFor(string $url): string
    {
        return str_contains($url, 'publicada') ? '900' : '776060721';
    }

    /** Notes where a file put or copied in the fake cloud is; the first file with an id keeps it. */
    private function remember(string $url): void
    {
        $prefix = EducamadridCloud::BASE_URL.'/remote.php/dav/files/ies.lacabrera/';
        $this->paths[self::idFor($url)] ??= rawurldecode(substr($url, \strlen($prefix)));
    }

    /** Finds a file by its id: where the app put it, or else where the test says the draft is now. */
    private function fakeSearch(string $body): MockResponse
    {
        preg_match('#<d:literal>(\d+)</d:literal>#', $body, $id);
        $path = $this->paths[$id[1] ?? ''] ?? ('776060721' === ($id[1] ?? null) ? $this->draftNowAt : null);

        return new MockResponse(null === $path ? '<d:multistatus xmlns:d="DAV:"/>'
            : '<d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/ies.lacabrera/'.implode('/', array_map(rawurlencode(...), explode('/', $path))).'</d:href></d:response></d:multistatus>', ['http_code' => 207]);
    }

    private function fakeShare(string $body): MockResponse
    {
        if ($this->sharingUnreachable) {
            return new MockResponse('', ['error' => 'Connection reset']);
        }
        if (0 === $this->shareBudget) {
            return new MockResponse('', ['http_code' => 429]);
        }
        if (null !== $this->shareBudget) {
            --$this->shareBudget;
        }
        parse_str($body, $share);

        return \in_array($share['shareWith'] ?? null, $this->unknownLogins, true)
            ? new MockResponse('{"ocs":{"meta":{"message":"No se ha encontrado el usuario"}}}', ['http_code' => 404])
            : new MockResponse('{"ocs":{"meta":{"status":"ok"}}}', ['http_code' => 200]);
    }

    /**
     * @return list<array{path: string, shareWith: string, permissions: string}>
     */
    private function shares(): array
    {
        $shares = [];
        foreach ($this->sent as $request) {
            if ('POST' === $request['method'] && str_contains($request['url'], '/shares')) {
                parse_str($request['body'], $share);
                $shares[] = ['path' => $share['path'], 'shareWith' => $share['shareWith'], 'permissions' => $share['permissions']];
            }
        }

        return $shares;
    }

    /**
     * The "ya puedes leer el acta" notices recorded, by who got them.
     *
     * @return list<string>
     */
    private function toldToRead(): array
    {
        return array_map(
            static fn (Notification $n): string => $n->getRecipient()->getFullName(),
            $this->em->getRepository(Notification::class)->findBy(['kind' => 'meeting.minutes', 'recipient' => $this->people], ['id' => 'ASC']),
        );
    }

    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);
        $this->people[] = $user;

        return $user;
    }

    private function heldMeeting(User $convener, User ...$attendees): Meeting
    {
        $meeting = new Meeting($convener, 'Reunión de seguimiento', new \DateTimeImmutable('2026-09-15 14:00'));
        foreach ($attendees as $attendee) {
            $meeting->addAttendee($attendee);
        }
        $this->em->persist($meeting);
        $this->em->flush();

        return $meeting;
    }

    private static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-15 16:00');
    }

    public function testCreatingPutsTheActaInTheFolderOfItsYearAndKindAndSharesItToEditWithItsWriter(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $this->user('Luis', 'luis.y@educa.madrid.org'));
        $type = (new MeetingType())->setName('Departamento: Lengua');
        $this->em->persist($type);
        $meeting->setType($type);
        $this->settings->setCloudMinutesFolder('Documentación/Actas');

        $sharing = $this->minutes('all')->create($meeting, $secretary, self::at());

        self::assertSame(['pending' => 0, 'problems' => []], $sharing);
        self::assertSame('776060721', $meeting->getMinutesCloudFileId());
        $put = array_values(array_filter($this->sent, static fn (array $r): bool => 'PUT' === $r['method']));
        self::assertCount(1, $put);
        // Los dos puntos del tipo no pueden ir en una ruta: se cambian por un guion.
        self::assertStringEndsWith('/Documentaci%C3%B3n/Actas/2026-2027/Departamento-%20Lengua/2026-09-15%2014.00%20Reuni%C3%B3n%20de%20seguimiento.odt', $put[0]['url']);
        self::assertSame(
            [['path' => '/Documentación/Actas/2026-2027/Departamento- Lengua/2026-09-15 14.00 Reunión de seguimiento.odt', 'shareWith' => 'natalia.x', 'permissions' => (string) EducamadridCloud::PERMISSION_EDIT]],
            $this->shares(),
            'el borrador solo lo comparte con quien lo escribe; el resto lee lo publicado',
        );
    }

    public function testWithCloudShareWithUnsetTheActaIsSharedWithNobody(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary);

        $sharing = $this->minutes(null)->create($meeting, $secretary, self::at());

        self::assertSame([], $this->shares(), 'una BD de desarrollo tiene el claustro real: sin configurar, a nadie');
        self::assertSame(['pending' => 0, 'problems' => ['Natalia: fuera de CLOUD_SHARE_WITH (entorno de pruebas)']], $sharing);
        self::assertTrue($meeting->hasCloudMinutes(), 'el acta se crea igual');
    }

    public function testACloudThatDropsTheConnectionWhileSharingStillLeavesTheMeetingPointingAtTheDocument(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary);
        $this->sharingUnreachable = true;

        $sharing = $this->minutes('all')->create($meeting, $secretary, self::at());

        // Si esto lanzara, la reunión no se guardaría y el reintento chocaría con el documento ya creado
        // (la nube nunca sobrescribe): un acta huérfana y un botón que ya no funciona.
        self::assertSame('776060721', $meeting->getMinutesCloudFileId());
        self::assertSame(['pending' => 1, 'problems' => []], $sharing, 'no es culpa de Natalia: queda en cola para la próxima vuelta');
    }

    public function testPublishingCopiesTheDraftWhereItIsNowAndSharesTheCopyToReadWithEverybody(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $owner = $this->user('Dirección', 'ies.lacabrera@educa.madrid.org');
        $outsider = $this->user('Sin cuenta', 'sin.cuenta@gmail.com');
        $gone = $this->user('Ya no está', 'baja@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary, $this->user('Luis', 'luis.y@educa.madrid.org'), $owner, $outsider, $gone);
        $meeting->attachCloudMinutes('776060721', $secretary, self::at());
        // Dirección lo movió a su carpeta antes de publicar: se encuentra por el id, no por donde se creó.
        $this->draftNowAt = 'Documentación/Actas/acta movida.odt';
        $this->unknownLogins = ['baja'];

        $sharing = $this->minutes('all')->publish($meeting, $secretary);

        self::assertTrue($meeting->isMinutesPublished());
        self::assertSame('900', $meeting->getMinutesCloudPublishedFileId());
        $copy = array_values(array_filter($this->sent, static fn (array $r): bool => 'COPY' === $r['method']));
        self::assertStringEndsWith('/Documentaci%C3%B3n/Actas/acta%20movida.odt', $copy[0]['url']);
        $read = (string) EducamadridCloud::PERMISSION_READ;
        self::assertSame([
            ['path' => '/Documentación/Actas/acta movida (publicada).odt', 'shareWith' => 'natalia.x', 'permissions' => $read],
            ['path' => '/Documentación/Actas/acta movida (publicada).odt', 'shareWith' => 'luis.y', 'permissions' => $read],
            ['path' => '/Documentación/Actas/acta movida (publicada).odt', 'shareWith' => 'baja', 'permissions' => $read],
        ], $this->shares(), 'la copia, en lectura; a la cuenta dueña no se le comparte lo suyo');
        self::assertSame(['pending' => 0, 'problems' => [
            'Sin cuenta: no tiene correo de EducaMadrid',
            'Ya no está: No se ha encontrado el usuario',
        ]], $sharing, 'quien no se alcanza se dice, y no impide que llegue al resto');
        self::assertSame(['Luis'], $this->toldToRead(), 'el aviso llega a quien ya puede abrirla; quien convoca no se avisa a sí misma');
    }

    public function testADeletedDraftCannotBePublished(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary);
        $meeting->attachCloudMinutes('776060721', $secretary, self::at());
        $this->draftNowAt = null;

        try {
            $this->minutes('all')->publish($meeting, $secretary);
            self::fail('Publicar un acta que ya no existe tiene que fallar.');
        } catch (CloudException $e) {
            self::assertStringContainsString('papelera', $e->getMessage());
        }
        self::assertFalse($meeting->isMinutesPublished(), 'sin copia que leer, no se da por publicada');
    }

    public function testWithoutAConnectedAccountNothingIsCreated(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary);

        try {
            $this->minutes('all', connected: false)->create($meeting, $secretary, self::at());
            self::fail('Sin cuenta conectada no hay dónde crearla.');
        } catch (CloudException $e) {
            self::assertStringContainsString('no está conectada', $e->getMessage());
        }
        self::assertSame([], $this->sent);
        self::assertFalse($meeting->hasCloudMinutes());
    }

    public function testThePublishedLinkOpensTheCopyAndFallsBackToTheDraft(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary);
        $minutes = $this->minutes('all', connected: false);

        self::assertNull($minutes->urlFor($meeting));

        $meeting->attachCloudMinutes('776060721', $secretary, self::at());
        self::assertSame('https://cloud.educa.madrid.org/index.php/f/776060721', $minutes->publishedUrlFor($meeting));

        $meeting->publishCloudMinutes('900', $secretary);
        self::assertSame('https://cloud.educa.madrid.org/index.php/f/900', $minutes->publishedUrlFor($meeting));
        self::assertSame('https://cloud.educa.madrid.org/index.php/f/776060721', $minutes->urlFor($meeting), 'quien escribe sigue abriendo el borrador');
    }

    public function testTheCloudLoginIsTheEducamadridEmailWithoutItsDomain(): void
    {
        self::assertSame('natalia.x', CloudShareQueue::cloudLoginOf($this->user('N', 'Natalia.X@Educa.Madrid.org')));
        self::assertNull(CloudShareQueue::cloudLoginOf($this->user('N', 'natalia@gmail.com')));
        self::assertNull(CloudShareQueue::cloudLoginOf($this->user('N', 'natalia@educa.madrid.org.evil.test')));
    }

    public function testTheActasFolderIsAFolderInsideTheAccount(): void
    {
        self::assertSame('Actas', $this->settings->getCloudMinutesFolder(), 'sin elegir, una carpeta propia en la raíz');

        $this->settings->setCloudMinutesFolder(' /Documentación\\ Actas // 2026/ ');
        self::assertSame('Documentación/Actas/2026', $this->settings->getCloudMinutesFolder());

        foreach (['', ' / ', 'Actas/../../otro', './Actas'] as $bad) {
            try {
                $this->settings->setCloudMinutesFolder($bad);
                self::fail(\sprintf('«%s» no es una carpeta dentro de la cuenta.', $bad));
            } catch (\InvalidArgumentException) {
                self::assertSame('Documentación/Actas/2026', $this->settings->getCloudMinutesFolder(), 'lo inválido no se guarda');
            }
        }
    }

    public function testAnActaWrittenOutsideTheAppIsCreatedFromItsFileAndSharedLikeAnyOther(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary);

        $sharing = $this->minutes('all')->upload($meeting, $secretary, self::at(), 'bytes del docx', 'DOCX');

        self::assertSame(['pending' => 0, 'problems' => []], $sharing);
        $puts = array_values(array_filter($this->sent, static fn (array $r): bool => 'PUT' === $r['method']));
        self::assertCount(1, $puts);
        self::assertStringEndsWith('/2026-09-15%2014.00%20Reuni%C3%B3n%20de%20seguimiento.docx', $puts[0]['url'], 'con su extensión: es la que dice a la nube cómo abrirlo');
        self::assertSame('bytes del docx', $puts[0]['body']);
        self::assertSame('776060721', $meeting->getMinutesCloudFileId());
        self::assertSame([['path' => '/Actas/2026-2027/Otras reuniones/2026-09-15 14.00 Reunión de seguimiento.docx', 'shareWith' => 'natalia.x', 'permissions' => (string) EducamadridCloud::PERMISSION_EDIT]], $this->shares());
    }

    public function testPublishingAnUploadedActaKeepsItsExtension(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary);
        $meeting->attachCloudMinutes('776060721', $secretary, self::at());
        $this->draftNowAt = 'Actas/acta.docx';

        $this->minutes('all')->publish($meeting, $secretary);

        $copy = array_values(array_filter($this->sent, static fn (array $r): bool => 'COPY' === $r['method']));
        self::assertStringEndsWith('/Actas/acta.docx', $copy[0]['url']);
        self::assertSame(['/Actas/acta (publicada).docx'], array_column($this->shares(), 'path'));
    }

    public function testMovingAnActaWrittenHereKeepsTheTextToEditAndTheVeryFileThatWasSent(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $luis = $this->user('Luis', 'luis.y@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary, $luis);
        $meeting->recordSession('<div>Lo <strong>tratado</strong></div>', '<div>Lo acordado</div>', [$secretary], self::at());
        $meeting->attachMinutes('meeting-minutes/uuid-1.pdf', 'acta-ccp.PDF', $secretary, self::at());
        $meeting->publishMinutes($secretary);

        $sharing = $this->minutes('all')->moveToCloud($meeting, '%PDF-1.4 el que se envió');

        self::assertSame(['pending' => 0, 'problems' => []], $sharing);
        $puts = array_values(array_filter($this->sent, static fn (array $r): bool => 'PUT' === $r['method']));
        self::assertCount(2, $puts);
        self::assertStringEndsWith('/2026-09-15%2014.00%20Reuni%C3%B3n%20de%20seguimiento.odt', $puts[0]['url'], 'el borrador, con el texto, para poder corregirlo');
        self::assertStringEndsWith('/2026-09-15%2014.00%20Reuni%C3%B3n%20de%20seguimiento%20%28publicada%29.pdf', $puts[1]['url'], 'lo publicado es el PDF que se envió');
        self::assertSame('%PDF-1.4 el que se envió', $puts[1]['body']);
        self::assertSame('900', $meeting->getMinutesCloudPublishedFileId());
        self::assertNull($meeting->getDiscussion(), 'el texto deja de estar aquí');
        self::assertTrue($meeting->isMinutesPublished(), 'moverla no la despublica');
        self::assertSame([
            ['path' => '/Actas/2026-2027/Otras reuniones/2026-09-15 14.00 Reunión de seguimiento.odt', 'shareWith' => 'natalia.x', 'permissions' => (string) EducamadridCloud::PERMISSION_EDIT],
            ['path' => '/Actas/2026-2027/Otras reuniones/2026-09-15 14.00 Reunión de seguimiento (publicada).pdf', 'shareWith' => 'natalia.x', 'permissions' => (string) EducamadridCloud::PERMISSION_READ],
            ['path' => '/Actas/2026-2027/Otras reuniones/2026-09-15 14.00 Reunión de seguimiento (publicada).pdf', 'shareWith' => 'luis.y', 'permissions' => (string) EducamadridCloud::PERMISSION_READ],
        ], $this->shares());
    }

    public function testAnUploadedActaWithoutTextMovesAsTheFileItIs(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary);
        $meeting->attachMinutes('meeting-minutes/uuid-1.docx', 'acta.docx', $secretary, self::at());

        $this->minutes('all')->moveToCloud($meeting, 'bytes del docx');

        $puts = array_values(array_filter($this->sent, static fn (array $r): bool => 'PUT' === $r['method']));
        self::assertCount(1, $puts, 'sin publicar, no hay copia publicada');
        self::assertStringEndsWith('seguimiento.docx', $puts[0]['url']);
        self::assertSame('bytes del docx', $puts[0]['body']);
        self::assertFalse($meeting->isMinutesPublished());
    }

    public function testAnUnpublishedFileBesideTheTextIsKeptAndNotThrownAway(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary);
        $meeting->recordSession('<div>Lo tratado</div>', null, [], self::at());
        $meeting->attachMinutes('meeting-minutes/uuid-1.pdf', 'acta.pdf', $secretary, self::at());

        $this->minutes('all')->moveToCloud($meeting, 'el pdf subido de fuera');

        $puts = array_values(array_filter($this->sent, static fn (array $r): bool => 'PUT' === $r['method']));
        self::assertCount(2, $puts);
        self::assertStringEndsWith('seguimiento%20%28documento%20anterior%29.pdf', $puts[1]['url']);
        self::assertSame('el pdf subido de fuera', $puts[1]['body']);
        self::assertCount(1, $this->shares(), 'el documento anterior no se comparte con nadie');
    }

    public function testWhatTheCloudsLimitLeavesOutWaitsAndIsSharedLaterTellingEachOnlyWhenTheirsIsMade(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $luis = $this->user('Luis', 'luis.y@educa.madrid.org');
        $ana = $this->user('Ana', 'ana.z@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $luis, $ana);
        $meeting->attachCloudMinutes('776060721', $secretary, self::at());
        $this->shareBudget = 1;

        $sharing = $this->minutes('all')->publish($meeting, $secretary);

        self::assertSame(['pending' => 1, 'problems' => []], $sharing, 'la nube no deja más: Ana espera, no falla');
        self::assertSame(['Luis'], $this->toldToRead(), 'a Ana no se le dice que ya puede leerla antes de poder abrirla');

        $this->shareBudget = null;
        $run = $this->queue->process();

        self::assertSame(1, $run->shared);
        self::assertNull($run->stoppedBy);
        self::assertSame(['pending' => 0, 'problems' => []], $this->queue->leftFor($meeting));
        self::assertSame(['Luis', 'Ana'], $this->toldToRead());
    }

    public function testTheCloudsLimitStopsTheRunAsAWaitAndNotAFailure(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $this->user('Luis', 'luis.y@educa.madrid.org'), $this->user('Ana', 'ana.z@educa.madrid.org'));
        $meeting->attachCloudMinutes('776060721', $secretary, self::at());
        $this->shareBudget = 0;
        $minutes = $this->minutes('all');
        $minutes->publish($meeting, $secretary);
        $sent = \count($this->sent);

        $run = $this->queue->process();

        self::assertTrue($run->throttled);
        self::assertSame(0, $run->shared + $run->failed);
        self::assertCount($sent + 3, $this->sent, 'buscar, leer lo compartido y UN intento: tras el 429 no se insiste con el resto');
    }

    public function testResharingSkipsWhoAlreadyHasItAndTellsNobody(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary, $this->user('Luis', 'luis.y@educa.madrid.org'), $this->user('Ana', 'ana.z@educa.madrid.org'));
        $meeting->attachCloudMinutes('776060721', $secretary, self::at());
        $meeting->publishCloudMinutes('900', $secretary);
        $this->paths['900'] = 'Actas/acta (publicada).odt';
        $this->alreadyShared = ['natalia.x', 'luis.y'];

        $sharing = $this->minutes('all')->reshare($meeting);

        self::assertSame(['pending' => 0, 'problems' => []], $sharing);
        self::assertSame([
            ['path' => '/Actas/acta (publicada).odt', 'shareWith' => 'ana.z', 'permissions' => (string) EducamadridCloud::PERMISSION_READ],
        ], $this->shares(), 'solo a quien le faltaba');
        self::assertSame([], $this->toldToRead(), 'rellenar huecos no es publicar: la app no avisa a nadie');
    }

    public function testPublishingACorrectionReplacesWhatWasLeftOfTheOldCopyInsteadOfPilingItUp(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $this->user('Luis', 'luis.y@educa.madrid.org'), $this->user('Ana', 'ana.z@educa.madrid.org'));
        $meeting->attachCloudMinutes('776060721', $secretary, self::at());
        $this->shareBudget = 0;
        $minutes = $this->minutes('all');

        $minutes->publish($meeting, $secretary);
        $sharing = $minutes->publish($meeting, $secretary);

        self::assertSame(['pending' => 2, 'problems' => []], $sharing, 'dos personas pendientes, no cuatro');
    }

    public function testAQueueWithoutAConnectedAccountSaysSoInsteadOfLookingDone(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $this->user('Luis', 'luis.y@educa.madrid.org'));
        $meeting->attachCloudMinutes('776060721', $secretary, self::at());
        $this->shareBudget = 0;
        $this->minutes('all')->publish($meeting, $secretary);
        $this->settings->setCloudAccount(null);

        $run = $this->queue->process();

        self::assertFalse($run->throttled);
        self::assertStringContainsString('no está conectada', (string) $run->stoppedBy);
    }

    public function testAnActaCreatedFromTheTemplateKeepsTheFingerprintOfWhatWasPut(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary);

        $this->minutes('all')->create($meeting, $secretary, self::at());

        self::assertSame(hash('sha256', $this->puts()[0]['body']), $meeting->getMinutesCloudTemplateHash());
    }

    public function testAnActaUploadedFromOutsideHasNoFingerprintSoTheTemplateNeverReplacesIt(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary);

        $this->minutes('all')->upload($meeting, $secretary, self::at(), 'bytes del docx', 'docx');

        self::assertNull($meeting->getMinutesCloudTemplateHash());
    }

    public function testAnAgendaWrittenAfterTheActaWasCreatedIsWrittenIntoTheUntouchedTemplate(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary);
        $minutes = $this->minutes('all');
        $minutes->create($meeting, $secretary, self::at());
        self::assertStringNotContainsString('Horarios de guardia', self::contentOf($this->puts()[0]['body']));

        $meeting->setAgenda('<ol><li>Aprobación del acta anterior</li><li>Horarios de guardia</li></ol>');
        $etagRead = $this->etag();

        self::assertTrue($minutes->rewriteTemplate($meeting));
        $puts = $this->puts();
        self::assertCount(2, $puts);
        self::assertSame($puts[0]['url'], $puts[1]['url'], 'el mismo documento, el que ya está compartido: no uno nuevo');
        $content = self::contentOf($puts[1]['body']);
        self::assertStringContainsString('Aprobación del acta anterior', $content);
        self::assertStringContainsString('Horarios de guardia', $content);
        self::assertSame(hash('sha256', $puts[1]['body']), $meeting->getMinutesCloudTemplateHash(), 'la próxima vez se compara con lo recién escrito');
        self::assertNotSame($etagRead, $this->etag());
    }

    public function testATemplateSomebodyHasWrittenInIsNeverRewritten(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary);
        $minutes = $this->minutes('all');
        $minutes->create($meeting, $secretary, self::at());
        $hash = $meeting->getMinutesCloudTemplateHash();
        // Natalia ya escribió el desarrollo en el editor de EducaMadrid.
        $url = $this->puts()[0]['url'];
        $this->files[$url] = 'el acta con lo que escribió Natalia';

        $meeting->setAgenda('<p>Horarios de guardia</p>');

        self::assertFalse($minutes->rewriteTemplate($meeting));
        self::assertCount(1, $this->puts(), 'ni se intenta escribir');
        self::assertSame('el acta con lo que escribió Natalia', $this->files[$url]);
        self::assertSame($hash, $meeting->getMinutesCloudTemplateHash());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function writesThatLoseTheRace(): iterable
    {
        yield 'alguien guardó entre la lectura y la escritura' => [412];
        yield 'el documento está abierto y bloqueado en el editor' => [423];
    }

    #[DataProvider('writesThatLoseTheRace')]
    public function testWhenTheCloudRefusesTheConditionalWriteTheDraftIsLeftAsItWas(int $status): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary);
        $minutes = $this->minutes('all');
        $minutes->create($meeting, $secretary, self::at());
        $hash = $meeting->getMinutesCloudTemplateHash();
        $this->conditionalPutRefusedWith = $status;

        $meeting->setAgenda('<p>Horarios de guardia</p>');

        self::assertFalse($minutes->rewriteTemplate($meeting));
        self::assertSame($hash, $meeting->getMinutesCloudTemplateHash(), 'no se ha escrito nada: la huella sigue siendo la de lo que hay allí');
    }

    public function testAnActaWithoutFingerprintIsNotEvenReadFromTheCloud(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary);
        $meeting->attachCloudMinutes('776060721', $secretary, self::at());
        $meeting->setAgenda('<p>Horarios de guardia</p>');

        self::assertFalse($this->minutes('all')->rewriteTemplate($meeting));
        self::assertSame([], array_filter($this->sent, static fn (array $r): bool => \in_array($r['method'], ['SEARCH', 'GET', 'PUT'], true)), 'sin huella no hay nada que comparar: ni se pregunta a la nube');
    }

    public function testADraftThatIsGoneFromTheCloudIsNotRewritten(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $secretary);
        $meeting->attachCloudMinutes('776060721', $secretary, self::at(), hash('sha256', 'plantilla'));
        $this->draftNowAt = null;

        self::assertFalse($this->minutes('all')->rewriteTemplate($meeting));
        self::assertSame([], $this->puts());
    }
}
