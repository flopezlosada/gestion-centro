<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\Meeting;
use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Convening a meeting and keeping its acta: a project coordinator convenes with the project's teachers
 * already ticked, the convened see it (in their list and in their agenda) and can read the acta, and
 * nobody else gets near it. A plain docente convenes nothing.
 */
final class MeetingCrudTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Department $maths;
    private Role $teacher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->maths = (new Department())->setCode('maths-meet')->setName('Matemáticas');
        $this->teacher = (new Role())->setCode('teacher-meet')->setName('Docente')->setPerDepartment(true);
        $this->em->persist($this->maths);
        $this->em->persist($this->teacher);
    }

    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email)->setUnit($this->maths)->addAssignedRole($this->teacher);
        $this->em->persist($user);

        return $user;
    }

    /**
     * A project with a coordinator and its members, all plain docentes (coordinating grants no rank).
     *
     * @param list<User> $members the project's teachers
     */
    private function project(User $coordinator, array $members): Project
    {
        $project = (new Project())->setName('Erasmus+ '.uniqid())->setCoordinator($coordinator);
        foreach ($members as $member) {
            $project->addMember($member);
        }
        $this->em->persist($project);

        return $project;
    }

    private function meeting(User $convener, User ...$attendees): Meeting
    {
        $meeting = new Meeting($convener, 'Reunión de seguimiento', (new \DateTimeImmutable('+2 days'))->setTime(14, 0));
        foreach ($attendees as $attendee) {
            $meeting->addAttendee($attendee);
        }
        $this->em->persist($meeting);

        return $meeting;
    }

    /**
     * @param string $kind the notice kind to look for (e.g. "meeting.convened")
     *
     * @return int how many notices of that kind the person has
     */
    private function noticesOf(User $recipient, string $kind): int
    {
        return $this->em->getRepository(Notification::class)->count(['recipient' => $recipient, 'kind' => $kind]);
    }

    public function testAPlainTeacherCannotConvene(): void
    {
        $docente = $this->user('Pedro Docente', 'pedro.meet@centro.test');
        $this->em->flush();
        $this->client->loginUser($docente);

        $this->client->request('GET', '/reuniones/nueva');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheMeetingsPageOffersNoConveningButtonToAPlainTeacher(): void
    {
        $docente = $this->user('Pedro Docente', 'pedro2.meet@centro.test');
        $this->em->flush();
        $this->client->loginUser($docente);

        $this->client->request('GET', '/reuniones');

        // La pantalla no ofrece lo que el controlador va a negar.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Convocar reunión');
    }

    public function testCoordinatorConvenesWithTheProjectTeachersAlreadyTicked(): void
    {
        $coordinator = $this->user('Lucía Coordina', 'lucia.meet@centro.test');
        $member = $this->user('Pedro Miembro', 'pedro3.meet@centro.test');
        $other = $this->user('Ana Miembro', 'ana.meet@centro.test');
        $project = $this->project($coordinator, [$member, $other]);
        $this->em->flush();
        $this->client->loginUser($coordinator);

        $crawler = $this->client->request('GET', '/reuniones/nueva?proyecto='.$project->getId());
        self::assertResponseIsSuccessful();

        // El "por defecto" del centro: los profes del proyecto llegan ya marcados, resuelto en el
        // servidor (sin JS).
        $checked = $crawler->filter('input[type="checkbox"][name^="meeting_form[attendees]"]:checked')->extract(['value']);
        self::assertEqualsCanonicalizing([(string) $member->getId(), (string) $other->getId()], $checked);

        $form = $crawler->selectButton('Convocar')->form();
        $values = $form->getPhpValues();
        $values['meeting_form']['title'] = 'Seguimiento de movilidades';
        $values['meeting_form']['day'] = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');
        $values['meeting_form']['startTime'] = '14:00';
        $values['meeting_form']['place'] = 'Sala de profesores';
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects();
        $this->em->clear();
        $meeting = $this->em->getRepository(Meeting::class)->findOneBy(['title' => 'Seguimiento de movilidades']);
        self::assertInstanceOf(Meeting::class, $meeting);
        self::assertSame('14:00', $meeting->getStartAt()->format('H:i'), 'el día y la hora se componen en un instante');
        self::assertCount(2, $meeting->getAttendees());
        self::assertSame($project->getId(), $meeting->getProject()?->getId());

        // Convocar ES avisar.
        self::assertSame(1, $this->noticesOf($this->em->getRepository(User::class)->find($member->getId()), 'meeting.convened'));
        self::assertSame(0, $this->noticesOf($this->em->getRepository(User::class)->find($coordinator->getId()), 'meeting.convened'), 'quien convoca no se avisa a sí misma');
    }

    public function testAMeetingIsOnlyVisibleToThePeopleItConcerns(): void
    {
        $coordinator = $this->user('Lucía Coordina', 'lucia2.meet@centro.test');
        $attendee = $this->user('Pedro Convocado', 'pedro4.meet@centro.test');
        $stranger = $this->user('Ajena Nada', 'ajena.meet@centro.test');
        $meeting = $this->meeting($coordinator, $attendee);
        $this->em->flush();
        $url = '/reuniones/'.$meeting->getId();

        $this->client->loginUser($attendee);
        $this->client->request('GET', '/reuniones');
        self::assertSelectorTextContains('body', 'Reunión de seguimiento');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($stranger);
        $this->client->request('GET', '/reuniones');
        self::assertSelectorTextNotContains('body', 'Reunión de seguimiento');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTodaysMeetingShowsUpInTheHomeAgenda(): void
    {
        $coordinator = $this->user('Lucía Coordina', 'lucia3.meet@centro.test');
        $attendee = $this->user('Pedro Convocado', 'pedro5.meet@centro.test');
        // Hoy a mediodía: la agenda de Inicio la reparte por día, así que una hora central evita que un
        // desfase de zona la empuje al día siguiente.
        $meeting = new Meeting($coordinator, 'Claustro de septiembre', (new \DateTimeImmutable('today'))->setTime(12, 0));
        $meeting->addAttendee($attendee);
        $this->em->persist($meeting);
        $this->em->flush();

        $this->client->loginUser($attendee);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Claustro de septiembre');
    }

    public function testOnlyTheConvenerUploadsTheActaAndTheConvenedReadIt(): void
    {
        $coordinator = $this->user('Lucía Coordina', 'lucia4.meet@centro.test');
        $attendee = $this->user('Pedro Convocado', 'pedro6.meet@centro.test');
        $stranger = $this->user('Ajena Nada', 'ajena2.meet@centro.test');
        $meeting = $this->meeting($coordinator, $attendee);
        $this->em->flush();
        $id = (int) $meeting->getId();
        $uploadUrl = '/reuniones/'.$id.'/acta';
        $downloadUrl = $uploadUrl.'/descargar';

        // Un convocado NO sube el acta: la firma quien dirigió la reunión. La ficha no le ofrece el
        // formulario y el POST a pelo se rechaza (sin token válido no hay ni siquiera por dónde empezar).
        $this->client->loginUser($attendee);
        $this->client->request('GET', '/reuniones/'.$id);
        self::assertSelectorNotExists('input[type="file"][name="acta"]', 'a un convocado no se le ofrece subirla');
        $this->client->request('POST', $uploadUrl, ['_token' => 'irrelevante'], ['acta' => $this->actaFile()]);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertFalse($this->em->getRepository(Meeting::class)->find($id)?->hasMinutes(), 'no se guardó nada');

        // Quien convoca sí, con el token del formulario que la propia ficha le enseña.
        $this->client->loginUser($coordinator);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $token = (string) $crawler->filter('form[action="'.$uploadUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $uploadUrl, ['_token' => $token], ['acta' => $this->actaFile()]);
        self::assertResponseRedirects();

        $this->em->clear();
        $stored = $this->em->getRepository(Meeting::class)->find($id);
        self::assertInstanceOf(Meeting::class, $stored);
        self::assertTrue($stored->hasMinutes());
        self::assertSame('acta.pdf', $stored->getMinutesName());
        self::assertSame(1, $this->noticesOf($this->em->getRepository(User::class)->find($attendee->getId()), 'meeting.minutes'));

        // El acta se lee dentro del grupo convocado y en ningún otro sitio.
        $this->client->loginUser($attendee);
        $this->client->request('GET', $downloadUrl);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($stranger);
        $this->client->request('GET', $downloadUrl);
        self::assertResponseStatusCodeSame(403);

        // La transacción del test se deshace, pero el fichero es real y vive fuera de la base de datos:
        // sin esto cada ejecución deja un acta huérfana en el almacén privado.
        self::getContainer()->get(FileUploader::class)->remove((string) $stored->getMinutesPath());
    }

    public function testMovingTheMeetingWarnsThePeopleAlreadyConvened(): void
    {
        $coordinator = $this->user('Lucía Coordina', 'lucia5.meet@centro.test');
        $attendee = $this->user('Pedro Convocado', 'pedro7.meet@centro.test');
        $meeting = $this->meeting($coordinator, $attendee);
        $this->em->flush();
        $id = (int) $meeting->getId();

        $this->client->loginUser($coordinator);
        $crawler = $this->client->request('GET', '/reuniones/'.$id.'/editar');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Guardar')->form();
        $values = $form->getPhpValues();
        $values['meeting_form']['startTime'] = '17:45';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        $this->em->clear();
        $stored = $this->em->getRepository(Meeting::class)->find($id);
        self::assertInstanceOf(Meeting::class, $stored);
        self::assertSame('17:45', $stored->getStartAt()->format('H:i'));
        self::assertSame(1, $this->noticesOf($this->em->getRepository(User::class)->find($attendee->getId()), 'meeting.rescheduled'));
        self::assertSame(0, $this->noticesOf($this->em->getRepository(User::class)->find($attendee->getId()), 'meeting.convened'), 'a quien ya estaba convocado no se le vuelve a convocar');
    }

    public function testCancellingWarnsThePeopleConvened(): void
    {
        $coordinator = $this->user('Lucía Coordina', 'lucia6.meet@centro.test');
        $attendee = $this->user('Pedro Convocado', 'pedro8.meet@centro.test');
        $meeting = $this->meeting($coordinator, $attendee);
        $this->em->flush();
        $id = (int) $meeting->getId();
        $action = '/reuniones/'.$id.'/borrar';

        $this->client->loginUser($coordinator);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $token = (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $action, ['_token' => $token]);

        self::assertResponseRedirects('/reuniones');
        $this->em->clear();
        self::assertNull($this->em->getRepository(Meeting::class)->find($id));
        self::assertSame(1, $this->noticesOf($this->em->getRepository(User::class)->find($attendee->getId()), 'meeting.cancelled'));
    }

    /**
     * A small real file to upload as the acta. Written to the system temp dir and handed over as a test
     * upload (no is_uploaded_file check), which is what lets the controller store it for real.
     *
     * @return UploadedFile the upload
     */
    private function actaFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'acta').'.pdf';
        file_put_contents($path, '%PDF-1.4 acta de prueba');

        return new UploadedFile($path, 'acta.pdf', 'application/pdf', null, true);
    }
}
