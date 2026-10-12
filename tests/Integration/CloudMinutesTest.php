<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Meeting;
use App\Entity\MeetingType;
use App\Entity\User;
use App\Repository\MeetingRepository;
use App\Service\AppSettings;
use App\Service\Cloud\CloudAccount;
use App\Service\Cloud\CloudCredentials;
use App\Service\Cloud\CloudException;
use App\Service\Cloud\CloudMinutes;
use App\Service\Cloud\EducamadridCloud;
use App\Service\Cloud\MinutesOdtRenderer;
use Psr\Log\NullLogger;
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
 * to READ, and nobody at all unless CLOUD_SHARE_WITH says so), and that a cloud failing halfway never
 * leaves a document the meeting does not point at.
 */
final class CloudMinutesTest extends KernelTestCase
{
    private AppSettings $settings;

    /** @var list<array{method: string, url: string, body: string}> */
    private array $sent = [];

    /** @var list<string> logins the fake cloud does not know, so sharing with them fails */
    private array $unknownLogins = [];

    /** Whether the fake cloud drops the connection on any share. */
    private bool $sharingUnreachable = false;

    /** Where the fake cloud says the draft is now, or null for a draft that was deleted. */
    private ?string $draftNowAt = 'Actas/2026-2027/Otras reuniones/2026-09-15 14.00 Reunión de seguimiento.odt';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(AppSettings::class);
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

        return new CloudMinutes($account, $cloud, $renderer, $this->settings, self::getContainer()->get(MeetingRepository::class), new NullLogger(), $shareWith);
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

        return match ($method) {
            'MKCOL', 'PUT' => new MockResponse('', ['http_code' => 201]),
            'COPY' => new MockResponse('', ['http_code' => 201]),
            'PROPFIND' => new MockResponse('<oc:fileid>'.(str_contains($url, 'publicada') ? '900' : '776060721').'</oc:fileid>', ['http_code' => 207]),
            'SEARCH' => new MockResponse(null === $this->draftNowAt ? '<d:multistatus xmlns:d="DAV:"/>'
                : '<d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/ies.lacabrera/'.implode('/', array_map(rawurlencode(...), explode('/', $this->draftNowAt))).'</d:href></d:response></d:multistatus>', ['http_code' => 207]),
            'POST' => $this->fakeShare($body),
            'DELETE' => new MockResponse('', ['http_code' => 200]),
            default => throw new \LogicException('La nube falsa no sabe responder a '.$method),
        };
    }

    private function fakeShare(string $body): MockResponse
    {
        if ($this->sharingUnreachable) {
            return new MockResponse('', ['error' => 'Connection reset']);
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

    private function user(string $name, string $email): User
    {
        return (new User())->setFullName($name)->setEmail($email);
    }

    private function heldMeeting(User $convener, User ...$attendees): Meeting
    {
        $meeting = new Meeting($convener, 'Reunión de seguimiento', new \DateTimeImmutable('2026-09-15 14:00'));
        foreach ($attendees as $attendee) {
            $meeting->addAttendee($attendee);
        }

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
        $meeting->setType((new MeetingType())->setName('Departamento: Lengua'));
        $this->settings->setCloudMinutesFolder('Documentación/Actas');

        $problems = $this->minutes('all')->create($meeting, $secretary, self::at());

        self::assertSame([], $problems);
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

        $problems = $this->minutes(null)->create($meeting, $secretary, self::at());

        self::assertSame([], $this->shares(), 'una BD de desarrollo tiene el claustro real: sin configurar, a nadie');
        self::assertSame(['Natalia: fuera de CLOUD_SHARE_WITH (entorno de pruebas)'], $problems);
        self::assertTrue($meeting->hasCloudMinutes(), 'el acta se crea igual');
    }

    public function testACloudThatDropsTheConnectionWhileSharingStillLeavesTheMeetingPointingAtTheDocument(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary);
        $this->sharingUnreachable = true;

        $problems = $this->minutes('all')->create($meeting, $secretary, self::at());

        // Si esto lanzara, la reunión no se guardaría y el reintento chocaría con el documento ya creado
        // (la nube nunca sobrescribe): un acta huérfana y un botón que ya no funciona.
        self::assertSame('776060721', $meeting->getMinutesCloudFileId());
        self::assertCount(1, $problems);
        self::assertStringStartsWith('Natalia: No se pudo contactar', $problems[0]);
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

        $problems = $this->minutes('all')->publish($meeting, $secretary);

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
        self::assertSame([
            'Sin cuenta: no tiene correo de EducaMadrid',
            'Ya no está: No se ha encontrado el usuario',
        ], $problems, 'quien no se alcanza se dice, y no impide que llegue al resto');
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
        self::assertSame('natalia.x', CloudMinutes::cloudLoginOf($this->user('N', 'Natalia.X@Educa.Madrid.org')));
        self::assertNull(CloudMinutes::cloudLoginOf($this->user('N', 'natalia@gmail.com')));
        self::assertNull(CloudMinutes::cloudLoginOf($this->user('N', 'natalia@educa.madrid.org.evil.test')));
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

        $problems = $this->minutes('all')->upload($meeting, $secretary, self::at(), 'bytes del docx', 'DOCX');

        self::assertSame([], $problems);
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
}
