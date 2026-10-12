<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\Meeting;
use App\Entity\Role;
use App\Entity\User;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The acta written in the EducaMadrid cloud, seen from the app's routes — none of these calls reach the
 * cloud. What it guards: an acta goes to the cloud only when nothing of it was written here; once it is
 * there, the in-app ways (writing its text, uploading or removing a file) are closed, so its text can
 * never land in this database; and everything that asks "is there an acta" (approving it, downloading
 * it from a list) reads the cloud one as an acta.
 */
final class MeetingCloudMinutesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Department $dept;
    private Role $teacher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->dept = (new Department())->setCode('lengua-cloud')->setName('Lengua');
        $this->teacher = (new Role())->setCode('teacher-cloud')->setName('Docente')->setPerDepartment(true);
        $this->em->persist($this->dept);
        $this->em->persist($this->teacher);
    }

    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email)->setUnit($this->dept)->addAssignedRole($this->teacher);
        $this->em->persist($user);

        return $user;
    }

    /** A meeting that started yesterday, so its acta can be written. */
    private function heldMeeting(User $convener, User ...$attendees): Meeting
    {
        $meeting = new Meeting($convener, 'Reunión de departamento', (new \DateTimeImmutable('yesterday'))->setTime(10, 0));
        foreach ([$convener, ...$attendees] as $attendee) {
            $meeting->addAttendee($attendee);
        }
        $this->em->persist($meeting);

        return $meeting;
    }

    private function connectCloud(): void
    {
        // La pantalla solo pregunta SI hay cuenta; ninguna de estas rutas abre la clave.
        self::getContainer()->get(AppSettings::class)->setCloudAccount('cuenta-sellada-de-prueba');
    }

    private function show(Meeting $meeting): Crawler
    {
        $crawler = $this->client->request('GET', '/reuniones/'.$meeting->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private static function token(Crawler $crawler, string $form): string
    {
        return (string) $crawler->filter($form.' input[name="_token"]')->first()->attr('value');
    }

    private function reload(Meeting $meeting): Meeting
    {
        $this->em->clear();

        return $this->em->find(Meeting::class, $meeting->getId()) ?? throw new \LogicException('La reunión ha desaparecido.');
    }

    public function testWithTheCloudConnectedTheWriterTakesTheRollHereAndCreatesTheActaThere(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $luis = $this->user('Luis', 'luis.y@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $luis);
        $this->connectCloud();
        $this->em->flush();
        $this->client->loginUser($secretary);

        $crawler = $this->show($meeting);
        self::assertCount(0, $crawler->filter('#acta-form'), 'nada de escribir el texto en la app');
        self::assertCount(1, $crawler->filter('#cloud-create-form'));

        $this->client->request('POST', '/reuniones/'.$meeting->getId().'/acta/asistencia', [
            '_token' => self::token($crawler, '#cloud-roll-form'),
            'asistentes' => [(string) $luis->getId()],
        ]);
        self::assertResponseRedirects();

        $meeting = $this->reload($meeting);
        self::assertTrue($meeting->isAttendanceTaken());
        self::assertSame(['Luis'], array_map(static fn (User $u): string => $u->getFullName(), $meeting->getAttended()->toArray()));
        self::assertNull($meeting->getDiscussion(), 'pasar lista no guarda texto');
    }

    public function testOnceInTheCloudTheActaCannotBeWrittenUploadedOrRemovedHere(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary);
        $meeting->attachCloudMinutes('776060721', $secretary, new \DateTimeImmutable());
        $this->em->flush();
        $this->client->loginUser($secretary);
        // Sin la nube conectada: el acta que YA está allí sigue allí.
        $crawler = $this->show($meeting);
        $record = self::token($crawler, '#cloud-roll-form');
        $minutes = self::token($crawler, 'form[action$="/acta/publicar"]');
        $base = '/reuniones/'.$meeting->getId().'/acta';

        $this->client->request('POST', $base.'/registro', ['_token' => $record, 'tratado' => '<p>El texto que no debe llegar a la BD.</p>']);
        self::assertResponseStatusCodeSame(403);

        $pdf = tempnam(sys_get_temp_dir(), 'acta');
        file_put_contents((string) $pdf, '%PDF-1.4 acta');
        $this->client->request('POST', $base, ['_token' => $minutes], ['acta' => new UploadedFile((string) $pdf, 'acta.pdf', 'application/pdf', null, true)]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $base.'/borrar', ['_token' => $minutes]);
        self::assertResponseStatusCodeSame(403);

        $meeting = $this->reload($meeting);
        self::assertNull($meeting->getDiscussion());
        self::assertNull($meeting->getMinutesPath());
        self::assertSame('776060721', $meeting->getMinutesCloudFileId());
    }

    public function testAnActaStartedHereStaysHereWhenTheCloudIsConnected(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary);
        $meeting->recordSession('<p>Lo tratado, escrito antes de conectar la nube.</p>', null, [], new \DateTimeImmutable());
        $this->connectCloud();
        $this->em->flush();
        $this->client->loginUser($secretary);

        $crawler = $this->show($meeting);
        self::assertCount(1, $crawler->filter('#acta-form'), 'sigue escribiéndose en la app');
        self::assertCount(0, $crawler->filter('#cloud-create-form'));

        $this->client->request('POST', '/reuniones/'.$meeting->getId().'/acta/nube', ['_token' => self::token($crawler, 'form[action$="/acta"]')]);
        self::assertResponseStatusCodeSame(403, 'una segunda acta en la nube dejaría escondida la escrita aquí');

        $this->client->request('POST', '/reuniones/'.$meeting->getId().'/acta/asistencia', ['_token' => self::token($crawler, '#acta-form')]);
        self::assertResponseStatusCodeSame(403, 'aquí la lista se guarda con el texto, de una vez');

        self::assertFalse($this->reload($meeting)->hasCloudMinutes());
    }

    public function testTheActaLinkOfAListOpensThePublishedCopyAndADraftOnlyForItsWriter(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $luis = $this->user('Luis', 'luis.y@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary, $luis);
        $meeting->attachCloudMinutes('776060721', $secretary, new \DateTimeImmutable());
        $this->em->flush();
        $download = '/reuniones/'.$meeting->getId().'/acta/descargar';

        $this->client->loginUser($luis);
        $this->client->request('GET', $download);
        self::assertResponseStatusCodeSame(403, 'el borrador es de quien lo escribe');

        $this->client->loginUser($secretary);
        $this->client->request('GET', $download);
        self::assertResponseRedirects('https://cloud.educa.madrid.org/index.php/f/776060721');

        // Recargadas las dos: tras el clear(), la persona de antes ya no es la que el ORM conoce.
        $this->reload($meeting)->publishCloudMinutes('900', $this->em->find(User::class, $secretary->getId()) ?? throw new \LogicException());
        $this->em->flush();

        $this->client->loginUser($luis);
        $this->client->request('GET', $download);
        self::assertResponseRedirects('https://cloud.educa.madrid.org/index.php/f/900', null, 'lo publicado, no el borrador');
    }

    public function testAPublishedCloudActaCanBeApproved(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary)->setMinutesApprovalRequired(true);
        $meeting->attachCloudMinutes('776060721', $secretary, new \DateTimeImmutable());
        $meeting->publishCloudMinutes('900', $secretary);
        $this->em->flush();
        $this->client->loginUser($secretary);

        $crawler = $this->show($meeting);
        $this->client->request('POST', '/reuniones/'.$meeting->getId().'/acta/aprobar', ['_token' => self::token($crawler, 'form[action$="/acta/aprobar"]')]);
        self::assertResponseRedirects();

        self::assertTrue($this->reload($meeting)->areMinutesApproved(), 'un acta en la nube es un acta: se puede aprobar');
    }

    public function testOnlyASuperuserReachesTheCloudConnection(): void
    {
        $teacher = $this->user('Docente', 'docente@educa.madrid.org');
        $this->em->flush();
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/admin/nube');
        self::assertResponseStatusCodeSame(403);

        $direction = (new Role())->setCode('direction-cloud')->setName('Dirección')->setAdmin(true);
        $admin = (new User())->setFullName('Dirección')->setEmail('direccion@educa.madrid.org')->addAssignedRole($direction);
        $this->em->persist($direction);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/nube');
        self::assertResponseIsSuccessful();
    }

    public function testAnActaWrittenOutsideIsCheckedBeforeItGoesAnywhere(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary);
        $this->connectCloud();
        $this->em->flush();
        $this->client->loginUser($secretary);
        $crawler = $this->show($meeting);
        self::assertCount(1, $crawler->filter('form[action$="/acta/nube/subir"]'), 'quien escribe el acta puede subirla ya escrita');
        $token = self::token($crawler, 'form[action$="/acta/nube/subir"]');

        $image = tempnam(sys_get_temp_dir(), 'acta');
        file_put_contents((string) $image, 'no es un acta');
        $this->client->request('POST', '/reuniones/'.$meeting->getId().'/acta/nube/subir', ['_token' => $token], ['acta' => new UploadedFile((string) $image, 'foto.png', 'image/png', null, true)]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'no es un documento de texto');

        $this->client->request('POST', '/reuniones/'.$meeting->getId().'/acta/nube/subir', ['_token' => $token]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'Elige el documento del acta');

        self::assertFalse($this->reload($meeting)->hasCloudMinutes(), 'nada ha llegado a la nube');
    }

    public function testAnActaAlreadyInTheCloudIsNotReplacedByAnUpload(): void
    {
        $secretary = $this->user('Natalia', 'natalia.x@educa.madrid.org');
        $meeting = $this->heldMeeting($secretary);
        $meeting->attachCloudMinutes('776060721', $secretary, new \DateTimeImmutable());
        $this->em->flush();
        $this->client->loginUser($secretary);
        $crawler = $this->show($meeting);
        self::assertCount(0, $crawler->filter('form[action$="/acta/nube/subir"]'));

        $doc = tempnam(sys_get_temp_dir(), 'acta');
        file_put_contents((string) $doc, 'acta');
        $this->client->request('POST', '/reuniones/'.$meeting->getId().'/acta/nube/subir', ['_token' => self::token($crawler, 'form[action$="/acta/publicar"]')], ['acta' => new UploadedFile((string) $doc, 'acta.docx', null, null, true)]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash.error', 'ya tiene acta en la nube');
        self::assertSame('776060721', $this->reload($meeting)->getMinutesCloudFileId());
    }
}
