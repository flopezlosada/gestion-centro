<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\Meeting;
use App\Entity\MeetingRemark;
use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\MeetingScope;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

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
        // Subirla la deja como BORRADOR: todavía no se le ha dicho nada a nadie. Publicar es otro paso, y
        // es el que reparte (ver testPublishingTheActaSendsItByEmail).
        self::assertFalse($stored->isMinutesPublished());
        self::assertSame(0, $this->noticesOf($this->em->getRepository(User::class)->find($attendee->getId()), 'meeting.minutes'));

        // Y mientras es borrador no la lee ni quien está convocado: es de quien la levanta hasta que se
        // publica, que es justo lo que la ficha promete.
        $this->client->loginUser($attendee);
        $this->client->request('GET', $downloadUrl);
        self::assertResponseStatusCodeSame(403, 'el borrador todavía no es del grupo');

        $this->client->loginUser($coordinator);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $publishUrl = '/reuniones/'.$id.'/acta/publicar';
        $token = (string) $crawler->filter('form[action="'.$publishUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $publishUrl, ['_token' => $token]);
        self::assertResponseRedirects();

        // Publicada, el acta se lee dentro del grupo convocado y en ningún otro sitio. La persona ajena se
        // prueba DESPUÉS de publicar a propósito: con el acta en borrador su 403 sería el mismo, pero por
        // el motivo equivocado, y el test pasaría sin demostrar nada.
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

    public function testTheNamedMinutesKeeperUploadsTheActaAndTheConvenerDoesNot(): void
    {
        // Caso real del centro: en la CCP convoca la dirección y el acta la levanta la secretaría.
        $convener = $this->user('Ana Directora', 'ana.meet@centro.test');
        $secretary = $this->user('Sara Secretaría', 'sara.meet@centro.test');
        $meeting = $this->meeting($convener, $secretary);
        $meeting->setMinutesTakenBy($secretary);
        $this->em->flush();
        $id = (int) $meeting->getId();
        $uploadUrl = '/reuniones/'.$id.'/acta';

        // A quien convoca ya no se le ofrece el acta: la ha delegado.
        $this->client->loginUser($convener);
        $this->client->request('GET', '/reuniones/'.$id);
        self::assertSelectorNotExists('input[type="file"][name="acta"]');

        $this->client->loginUser($secretary);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        self::assertSelectorTextContains('body', 'Levanta el acta');
        $token = (string) $crawler->filter('form[action="'.$uploadUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $uploadUrl, ['_token' => $token], ['acta' => $this->actaFile()]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(Meeting::class)->find($id);
        self::assertInstanceOf(Meeting::class, $stored);
        self::assertTrue($stored->hasMinutes());
        self::assertSame('sara.meet@centro.test', $stored->getMinutesUploadedBy()?->getEmail());

        self::getContainer()->get(FileUploader::class)->remove((string) $stored->getMinutesPath());
    }

    public function testTheRollIsTakenAfterTheMeetingAndOnlyAmongThePeopleExpected(): void
    {
        $convener = $this->user('Lucía Coordina', 'lucia7.meet@centro.test');
        $came = $this->user('Pedro Vino', 'pedro9.meet@centro.test');
        $missed = $this->user('Ana Faltó', 'ana2.meet@centro.test');
        $stranger = $this->user('Ajena Nada', 'ajena3.meet@centro.test');
        // Ya celebrada: antes de empezar no hay nada que contar y la acción se niega.
        $meeting = new Meeting($convener, 'Reunión de departamento', (new \DateTimeImmutable('-2 hours')));
        $meeting->addAttendee($came)->addAttendee($missed);
        $this->em->persist($meeting);
        $this->em->flush();
        $id = (int) $meeting->getId();
        $action = '/reuniones/'.$id.'/acta/registro';

        $this->client->loginUser($convener);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $token = (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
        // Se cuela alguien que no estaba convocado: la entidad lo descarta.
        $this->client->request('POST', $action, ['_token' => $token, 'asistentes' => [(string) $came->getId(), (string) $stranger->getId()]]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(Meeting::class)->find($id);
        self::assertInstanceOf(Meeting::class, $stored);
        self::assertTrue($stored->isAttendanceTaken());
        self::assertSame(['Pedro Vino'], array_map(static fn (User $u): string => $u->getFullName(), $stored->getAttended()->toArray()));
        self::assertSame(['Ana Faltó', 'Lucía Coordina'], array_map(static fn (User $u): string => $u->getFullName(), $stored->absentees()));
    }

    /**
     * EL FALLO que traía la pantalla: el desarrollo y la asistencia eran dos formularios en el mismo bloque,
     * así que «Guardar asistencia» mandaba solo las casillas y el texto recién escrito se quedaba sin
     * enviar. Ahora es UN formulario y un POST guarda las dos cosas.
     */
    public function testSavingTheActaKeepsTheTextAndTheRollTogether(): void
    {
        $convener = $this->user('Lucía Coordina', 'lucia30.meet@centro.test');
        $came = $this->user('Pedro Vino', 'pedro30.meet@centro.test');
        $meeting = new Meeting($convener, 'Reunión de departamento', new \DateTimeImmutable('-2 hours'));
        $meeting->addAttendee($came);
        $this->em->persist($meeting);
        $this->em->flush();
        $id = (int) $meeting->getId();

        $this->client->loginUser($convener);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);

        // Un solo formulario para todo el bloque: el textarea del desarrollo y las casillas de asistencia
        // están DENTRO del mismo, que es lo que hace que se envíen juntos.
        $form = $crawler->selectButton('Guardar el acta')->form();
        self::assertStringEndsWith('/acta/registro', $form->getUri());
        self::assertCount(1, $crawler->filter('form[action$="/acta/registro"] textarea[name="tratado"]'));
        self::assertCount(1, $crawler->filter('form[action$="/acta/registro"] textarea[name="acuerdos"]'));
        self::assertGreaterThan(0, $crawler->filter('form[action$="/acta/registro"] input[name="asistentes[]"]')->count());

        // Se envía como lo haría el navegador con el formulario entero (getPhpValues: las casillas
        // expandidas no se pueden asignar por índice, ver domcrawler-expanded-checkboxes).
        $values = $form->getPhpValues();
        $values['tratado'] = 'Se abre la sesión a las 12:00.';
        $values['acuerdos'] = '1. Se aprueba la programación.';
        $values['asistentes'] = [(string) $came->getId()];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        $this->em->clear();
        $stored = $this->em->getRepository(Meeting::class)->find($id);
        self::assertInstanceOf(Meeting::class, $stored);
        self::assertSame('Se abre la sesión a las 12:00.', $stored->getDiscussion(), 'el desarrollo ya no se pierde al guardar la lista');
        self::assertSame('1. Se aprueba la programación.', $stored->getAgreements());
        self::assertTrue($stored->isAttendanceTaken());
        self::assertCount(1, $stored->getAttended());
    }

    /**
     * El candado del acta publicada y su desahogo: quien asistió no la reescribe, pero puede puntualizarla,
     * y el aviso llega a quien puede corregirla.
     */
    public function testAnAttendeeCannotRewriteAPublishedActaButMayRemarkOnIt(): void
    {
        $convener = $this->user('Lucía Coordina', 'lucia31.meet@centro.test');
        $attendee = $this->user('Pedro Convocado', 'pedro31.meet@centro.test');
        $stranger = $this->user('Ajena Nada', 'ajena31.meet@centro.test');
        $meeting = new Meeting($convener, 'CCP de noviembre', new \DateTimeImmutable('-2 hours'));
        $meeting->addAttendee($attendee);
        $this->em->persist($meeting);
        $this->em->flush();
        $id = (int) $meeting->getId();

        // Se escribe, se genera y se publica.
        $this->client->loginUser($convener);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $recordUrl = '/reuniones/'.$id.'/acta/registro';
        $token = (string) $crawler->filter('form[action="'.$recordUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $recordUrl, ['_token' => $token, 'tratado' => 'Lo tratado.', 'asistentes' => [(string) $attendee->getId()]]);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $generateUrl = '/reuniones/'.$id.'/acta/generar';
        $token = (string) $crawler->filter('form[action="'.$generateUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $generateUrl, ['_token' => $token]);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $publishUrl = '/reuniones/'.$id.'/acta/publicar';
        $token = (string) $crawler->filter('form[action="'.$publishUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $publishUrl, ['_token' => $token]);
        self::assertResponseRedirects();

        // Quien asistió: no se le ofrece escribir el acta, y el POST a pelo se rechaza.
        $this->client->loginUser($attendee);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        self::assertSelectorNotExists('textarea[name="tratado"]', 'quien asistió no reescribe el acta publicada');
        $this->client->request('POST', $recordUrl, ['_token' => 'irrelevante', 'tratado' => 'Lo que me apetezca.']);
        self::assertResponseStatusCodeSame(403);

        // Pero sí puede puntualizarla.
        $remarkUrl = '/reuniones/'.$id.'/acta/observacion';
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $token = (string) $crawler->filter('form[action="'.$remarkUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $remarkUrl, ['_token' => $token, 'observacion' => 'En el punto 3 se acordó aplazarlo, no aprobarlo.']);
        self::assertResponseRedirects();

        $this->em->clear();
        $stored = $this->em->getRepository(Meeting::class)->find($id);
        self::assertInstanceOf(Meeting::class, $stored);
        self::assertCount(1, $this->em->getRepository(MeetingRemark::class)->findBy(['meeting' => $stored]));
        // El aviso va a quien puede corregirla, no a todo el grupo.
        self::assertSame(1, $this->noticesOf($this->em->getRepository(User::class)->find($convener->getId()), 'meeting.remark'));
        self::assertSame(0, $this->noticesOf($this->em->getRepository(User::class)->find($attendee->getId()), 'meeting.remark'), 'a quien la escribe no se le avisa de lo suyo');

        // Y la observación se lee en la ficha, dentro del grupo y en ningún otro sitio.
        $this->client->request('GET', '/reuniones/'.$id);
        self::assertSelectorTextContains('body', 'se acordó aplazarlo');
        $this->client->loginUser($stranger);
        $this->client->request('POST', $remarkUrl, ['_token' => 'irrelevante', 'observacion' => 'Yo opino.']);
        self::assertResponseStatusCodeSame(403);

        self::getContainer()->get(FileUploader::class)->remove((string) $stored->getMinutesPath());
    }

    /** Corregir un acta ya publicada deja el PDF que la gente recibió diciendo otra cosa: la ficha lo dice. */
    public function testCorrectingAPublishedActaWarnsThatTheFileIsNowOutOfDate(): void
    {
        $convener = $this->user('Lucía Coordina', 'lucia32.meet@centro.test');
        $attendee = $this->user('Pedro Convocado', 'pedro32.meet@centro.test');
        $meeting = new Meeting($convener, 'Claustro de noviembre', new \DateTimeImmutable('-2 hours'));
        $meeting->addAttendee($attendee);
        $this->em->persist($meeting);
        $this->em->flush();
        $id = (int) $meeting->getId();
        $recordUrl = '/reuniones/'.$id.'/acta/registro';

        $this->client->loginUser($convener);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $token = (string) $crawler->filter('form[action="'.$recordUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $recordUrl, ['_token' => $token, 'tratado' => 'Primera versión.', 'asistentes' => [(string) $attendee->getId()]]);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $generateUrl = '/reuniones/'.$id.'/acta/generar';
        $token = (string) $crawler->filter('form[action="'.$generateUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $generateUrl, ['_token' => $token]);

        // Recién generada, el archivo coincide con el texto: no hay nada que avisar.
        $this->client->request('GET', '/reuniones/'.$id);
        self::assertSelectorTextNotContains('body', 'se ha modificado después de generar el PDF');

        // Se corrige el texto sin regenerar: ahí sí.
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $token = (string) $crawler->filter('form[action="'.$recordUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $recordUrl, ['_token' => $token, 'tratado' => 'Versión corregida.', 'asistentes' => [(string) $attendee->getId()]]);
        $this->client->request('GET', '/reuniones/'.$id);

        self::assertSelectorTextContains('body', 'se ha modificado después de generar el PDF');

        $this->em->clear();
        $stored = $this->em->getRepository(Meeting::class)->find($id);
        self::assertInstanceOf(Meeting::class, $stored);
        self::assertTrue($stored->minutesOutdated());
        self::getContainer()->get(FileUploader::class)->remove((string) $stored->getMinutesPath());
    }

    /**
     * Los dos pasos que pidió el centro: guardar deja un borrador que no ve nadie, y PUBLICAR es el gesto
     * que la reparte — aviso a los convocados y el PDF por correo, adjunto.
     */
    public function testPublishingTheActaSendsItByEmailWithThePdfAttached(): void
    {
        $convener = $this->user('Lucía Coordina', 'lucia20.meet@centro.test');
        $attendee = $this->user('Pedro Convocado', 'pedro20.meet@centro.test');
        $meeting = new Meeting($convener, 'CCP de octubre', new \DateTimeImmutable('-2 hours'));
        $meeting->addAttendee($attendee);
        $this->em->persist($meeting);
        $this->em->flush();
        $id = (int) $meeting->getId();

        // 1. Se sube el acta: borrador, nadie se entera.
        $this->client->loginUser($convener);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $uploadUrl = '/reuniones/'.$id.'/acta';
        $token = (string) $crawler->filter('form[action="'.$uploadUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $uploadUrl, ['_token' => $token], ['acta' => $this->actaFile()]);
        self::assertResponseRedirects();
        self::assertEmailCount(0, null, 'un borrador no se manda a nadie');

        // 2. Se publica: ahí sí.
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $publishUrl = '/reuniones/'.$id.'/acta/publicar';
        $token = (string) $crawler->filter('form[action="'.$publishUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $publishUrl, ['_token' => $token]);
        self::assertResponseRedirects();

        $this->em->clear();
        $stored = $this->em->getRepository(Meeting::class)->find($id);
        self::assertInstanceOf(Meeting::class, $stored);
        self::assertTrue($stored->isMinutesPublished());
        self::assertSame(1, $this->noticesOf($this->em->getRepository(User::class)->find($attendee->getId()), 'meeting.minutes'));

        // Un correo por persona (no uno con todos en copia) y con el PDF adjunto: es lo que el centro
        // pidió — "envía por mail a todas las personas participantes".
        $emails = self::getMailerMessages();
        // DOS y no tres: uno por convocado, con el acta adjunta, y ninguno más. El aviso de "ya hay acta"
        // es push-only justamente por esto — si también fuera por correo, cada persona recibiría dos
        // mensajes de la misma acta, y el segundo peor que el primero.
        self::assertCount(2, $emails, 'un correo a cada convocado, incluido quien levanta el acta');
        // getMailerMessages() promete RawMessage; los adjuntos son de Email, así que se estrecha primero.
        $first = $emails[0];
        self::assertInstanceOf(Email::class, $first);
        self::assertNotEmpty($first->getAttachments(), 'el acta viaja adjunta, no solo enlazada');

        // Y ya está en el archivo de actas.
        $this->client->loginUser($attendee);
        $this->client->request('GET', '/reuniones/actas');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'CCP de octubre');

        self::getContainer()->get(FileUploader::class)->remove((string) $stored->getMinutesPath());
    }

    /**
     * Con familias o con alumnado no hay acta aquí: eso va a RAICES, y duplicarlo son dos medios registros.
     * Pero sí queda quién vino, que es un dato de la cita — así que la pantalla ofrece la lista y solo la
     * lista, y un POST con texto no puede colar media acta.
     */
    public function testAMeetingWithFamiliesKeepsTheRollButNoMinutes(): void
    {
        $convener = $this->user('Lucía Coordina', 'lucia21.meet@centro.test');
        $attendee = $this->user('Pedro Convocado', 'pedro21.meet@centro.test');
        $meeting = new Meeting($convener, 'Entrevista con la familia', new \DateTimeImmutable('-2 hours'));
        $meeting->addAttendee($attendee);
        $meeting->setScope(MeetingScope::FAMILIES);
        $this->em->persist($meeting);
        $this->em->flush();
        $id = (int) $meeting->getId();
        $recordUrl = '/reuniones/'.$id.'/acta/registro';

        $this->client->loginUser($convener);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'se registran en RAICES');
        self::assertSelectorNotExists('form[action$="/acta/generar"]', 'no se ofrece generar un acta que no existe');
        self::assertSelectorNotExists('textarea[name="tratado"]', 'ni escribir un desarrollo que no va aquí');

        // La lista sí se ofrece, y guardarla no arrastra el texto que se cuele en el POST.
        $token = (string) $crawler->filter('form[action="'.$recordUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $recordUrl, ['_token' => $token, 'tratado' => 'algo', 'asistentes' => [(string) $attendee->getId()]]);
        self::assertResponseRedirects();

        $this->em->clear();
        $stored = $this->em->getRepository(Meeting::class)->find($id);
        self::assertInstanceOf(Meeting::class, $stored);
        self::assertNull($stored->getDiscussion(), 'el desarrollo no se guarda en una reunión sin acta');
        self::assertTrue($stored->isAttendanceTaken());
        self::assertCount(1, $stored->getAttended());
    }

    public function testWhatWasDiscussedBecomesTheGeneratedPdfActa(): void
    {
        $convener = $this->user('Lucía Coordina', 'lucia9.meet@centro.test');
        $attendee = $this->user('Pedro Convocado', 'pedro11.meet@centro.test');
        $meeting = new Meeting($convener, 'Reunión de departamento', new \DateTimeImmutable('-2 hours'));
        $meeting->addAttendee($attendee);
        $this->em->persist($meeting);
        $this->em->flush();
        $id = (int) $meeting->getId();

        // 1. Se escribe el acta.
        $this->client->loginUser($convener);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $recordAction = '/reuniones/'.$id.'/acta/registro';
        $token = (string) $crawler->filter('form[action="'.$recordAction.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $recordAction, ['_token' => $token, 'tratado' => "1. Se aprueba la programación.\n2. Se acuerda repetir en mayo."]);
        self::assertResponseRedirects();

        // 2. Se pide el acta en PDF: no es automática, sale de lo recogido.
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $generateAction = '/reuniones/'.$id.'/acta/generar';
        $token = (string) $crawler->filter('form[action="'.$generateAction.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $generateAction, ['_token' => $token]);
        self::assertResponseRedirects();

        $this->em->clear();
        $stored = $this->em->getRepository(Meeting::class)->find($id);
        self::assertInstanceOf(Meeting::class, $stored);
        self::assertStringContainsString('Se aprueba la programación', (string) $stored->getDiscussion());
        self::assertTrue($stored->hasMinutes(), 'el acta generada ES el acta de la reunión');
        self::assertStringEndsWith('.pdf', (string) $stored->getMinutesName());

        // 3. Recién generada es un BORRADOR: quien está convocado todavía no la ve, aunque adivine la URL.
        $this->client->loginUser($attendee);
        $this->client->request('GET', '/reuniones/'.$id.'/acta/descargar');
        self::assertResponseStatusCodeSame(403, 'el borrador es de quien levanta el acta hasta que se publica');

        // 4. Se publica, y entonces sí se la puede descargar.
        $this->client->loginUser($convener);
        $crawler = $this->client->request('GET', '/reuniones/'.$id);
        $publishAction = '/reuniones/'.$id.'/acta/publicar';
        $token = (string) $crawler->filter('form[action="'.$publishAction.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $publishAction, ['_token' => $token]);
        self::assertResponseRedirects();

        $this->client->loginUser($attendee);
        $this->client->request('GET', '/reuniones/'.$id.'/acta/descargar');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('.pdf', (string) $this->client->getResponse()->headers->get('content-disposition'));

        self::getContainer()->get(FileUploader::class)->remove((string) $stored->getMinutesPath());
    }

    public function testOnlyTheMinutesKeeperRecordsWhatWasDiscussedOrGeneratesTheActa(): void
    {
        $convener = $this->user('Lucía Coordina', 'lucia10.meet@centro.test');
        $attendee = $this->user('Pedro Convocado', 'pedro12.meet@centro.test');
        $meeting = new Meeting($convener, 'Reunión de departamento', new \DateTimeImmutable('-2 hours'));
        $meeting->addAttendee($attendee);
        $this->em->persist($meeting);
        $this->em->flush();
        $id = (int) $meeting->getId();

        $this->client->loginUser($attendee);
        $this->client->request('GET', '/reuniones/'.$id);
        self::assertSelectorNotExists('textarea[name="tratado"]', 'a quien solo asiste no se le ofrece escribir el acta');
        self::assertSelectorNotExists('input[name="asistentes[]"]', 'ni pasar lista, que ahora es parte del mismo acta');
        $this->client->request('POST', '/reuniones/'.$id.'/acta/registro', ['_token' => 'irrelevante', 'tratado' => 'Lo que me apetezca.']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/reuniones/'.$id.'/acta/generar', ['_token' => 'irrelevante']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheActaCannotBeGeneratedBeforeTheMeetingHappens(): void
    {
        $convener = $this->user('Lucía Coordina', 'lucia11.meet@centro.test');
        $meeting = $this->meeting($convener, $this->user('Pedro Convocado', 'pedro13.meet@centro.test'));
        $this->em->flush();
        $id = (int) $meeting->getId();

        $this->client->loginUser($convener);
        $this->client->request('GET', '/reuniones/'.$id);
        self::assertSelectorNotExists('form[action="/reuniones/'.$id.'/acta/generar"]');
        $this->client->request('POST', '/reuniones/'.$id.'/acta/generar', ['_token' => 'irrelevante']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAttendanceCannotBeTakenBeforeTheMeetingHappens(): void
    {
        $convener = $this->user('Lucía Coordina', 'lucia8.meet@centro.test');
        $attendee = $this->user('Pedro Convocado', 'pedro10.meet@centro.test');
        $meeting = $this->meeting($convener, $attendee);
        $this->em->flush();
        $id = (int) $meeting->getId();

        $this->client->loginUser($convener);
        // La ficha no ofrece el formulario…
        $this->client->request('GET', '/reuniones/'.$id);
        self::assertSelectorNotExists('form[action="/reuniones/'.$id.'/acta/registro"]');
        // …y el POST a pelo tampoco vale.
        $this->client->request('POST', '/reuniones/'.$id.'/acta/registro', ['_token' => 'irrelevante', 'asistentes' => []]);
        self::assertResponseStatusCodeSame(403);
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
