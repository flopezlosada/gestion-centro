<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agenda\MyClasses;
use App\Entity\FamilyActivity;
use App\Entity\FamilyVolunteer;
use App\Entity\NonLectiveDay;
use App\Entity\OneOffFamilyActivity;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\WeeklyFamilyActivity;
use App\Enum\Weekday;
use App\Family\FamilySessions;
use App\Service\AppSettings;
use App\Tests\Support\BuildsTheCentresTimetable;
use App\Tests\Support\MintsSessionCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Las actividades con familias, por el lado del profesorado: cualquiera publica —en una de sus clases cada
 * semana, o un día suelto—, solo quien organiza (o un administrador) cambia o borra. Y la puerta que hace posible abrir la app a familias más adelante: el área
 * del personal exige ROLE_STAFF, que solo da {@see User}, y no ROLE_USER, que se le supone a cualquier cuenta.
 */
final class FamilyActivityTest extends WebTestCase
{
    use BuildsTheCentresTimetable;
    use ClockSensitiveTrait;
    use MintsSessionCsrfTokens;

    private const string TODAY = '2026-01-13';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Viene apagado de serie; lo que se prueba aquí es el módulo encendido (el interruptor, en
        // AdminCentreSettingsTest).
        self::getContainer()->get(AppSettings::class)->setFamilyActivitiesEnabled(true);
        // A Tuesday: the dates below, relative to it, fall Monday to Friday whatever day CI runs on.
        self::mockTime(self::TODAY.' 08:00');
    }

    /**
     * Si alguien vuelve a poner ROLE_USER en la regla general, cualquier cuenta que no sea del personal
     * vería la app entera. Se comprueba la regla misma, no una pantalla, para que valga para todas.
     */
    public function testTheStaffAreaAsksForTheStaffRole(): void
    {
        $accessMap = self::getContainer()->get('security.access_map');
        \assert($accessMap instanceof AccessMapInterface);

        foreach (['/', '/guardias', '/reuniones', '/admin/usuarios', '/actividades-familias'] as $path) {
            [$roles] = $accessMap->getPatterns(Request::create($path));
            self::assertSame(['ROLE_STAFF'], $roles, $path);
        }
    }

    public function testEveryMemberOfStaffHoldsTheStaffRole(): void
    {
        $teacher = (new User())->setFullName('Profe Test')->setEmail('profe@centro.test');

        self::assertContains('ROLE_STAFF', $teacher->getRoles());
    }

    public function testATeacherPublishesAnActivityInTheirOwnName(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($teacher);

        $crawler = $this->client->request('GET', '/actividades-familias/nueva');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Publicar actividad')->form();
        $form['family_activity_form[title]'] = 'Grupos interactivos de 1ºA';
        $form['family_activity_form[description]'] = 'Cuatro mesas de matemáticas; cada familia lleva una.';
        $form['family_activity_form[place]'] = 'Aula 12';
        $form['family_activity_form[day]'] = '2026-11-05';
        $form['family_activity_form[startTime]'] = '10:30';
        $form['family_activity_form[endTime]'] = '11:25';
        $form['family_activity_form[familiesWanted]'] = '4';
        $this->client->submit($form);

        self::assertResponseRedirects('/actividades-familias');
        $this->em->clear();
        $activity = $this->em->getRepository(OneOffFamilyActivity::class)->findOneBy(['title' => 'Grupos interactivos de 1ºA']);
        self::assertNotNull($activity);
        self::assertSame('2026-11-05 10:30', $activity->getStartAt()->format('Y-m-d H:i'));
        self::assertSame('2026-11-05 11:25', $activity->getEndAt()?->format('Y-m-d H:i'));
        self::assertSame('Aula 12', $activity->getPlace());
        self::assertSame(4, $activity->getFamiliesWanted());
        // Quien organiza sale de la sesión, nunca del formulario.
        self::assertSame('profe@centro.test', $activity->getOrganizer()?->getEmail());
    }

    /**
     * Lo opcional vacío se guarda como «no se sabe» (null), no como un cero ni una cadena vacía: «0
     * familias» diría que no hace falta nadie.
     */
    public function testOptionalFieldsLeftEmptyAreStoredAsUnknown(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($teacher);

        $crawler = $this->client->request('GET', '/actividades-familias/nueva');
        $form = $crawler->selectButton('Publicar actividad')->form();
        $form['family_activity_form[title]'] = 'Taller de cuentos';
        $form['family_activity_form[day]'] = '2026-11-05';
        $form['family_activity_form[startTime]'] = '12:00';
        $this->client->submit($form);

        self::assertResponseRedirects('/actividades-familias');
        $this->em->clear();
        $activity = $this->em->getRepository(OneOffFamilyActivity::class)->findOneBy(['title' => 'Taller de cuentos']);
        self::assertNotNull($activity);
        self::assertNull($activity->getEndAt());
        self::assertNull($activity->getFamiliesWanted());
        self::assertNull($activity->getPlace());
        self::assertNull($activity->getDescription());
    }

    public function testAnActivityEndingBeforeItStartsIsRefused(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($teacher);

        $crawler = $this->client->request('GET', '/actividades-familias/nueva');
        $form = $crawler->selectButton('Publicar actividad')->form();
        $form['family_activity_form[title]'] = 'Taller';
        $form['family_activity_form[day]'] = '2026-11-05';
        $form['family_activity_form[startTime]'] = '11:00';
        $form['family_activity_form[endTime]'] = '10:00';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La hora de fin debe ser posterior a la de inicio.');
        self::assertSame(0, $this->em->getRepository(FamilyActivity::class)->count([]));
    }

    /**
     * Una familia tiene que saber a qué hora venir: sin hora de inicio no se publica.
     */
    public function testAnActivityWithoutStartTimeIsRefused(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($teacher);

        $crawler = $this->client->request('GET', '/actividades-familias/nueva');
        $form = $crawler->selectButton('Publicar actividad')->form();
        $form['family_activity_form[title]'] = 'Taller';
        $form['family_activity_form[day]'] = '2026-11-05';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Pon la hora a la que empieza.');
    }

    public function testSomeoneElseCannotEditOrDeleteTheActivity(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $other = $this->user('otra@centro.test');
        $activity = $this->activity($organizer, '+10 days');
        $this->em->flush();
        $this->client->loginUser($other);

        $this->client->request('GET', '/actividades-familias/'.$activity->getId().'/editar');
        self::assertResponseStatusCodeSame(403);

        // Ni siquiera la ve: cada docente ve solo las que organiza.
        $this->client->request('GET', '/actividades-familias');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Taller de cuentos');

        // Ni borrarla, aunque el envío lleve un token válido.
        $this->client->request('POST', '/actividades-familias/'.$activity->getId().'/borrar', [
            '_token' => $this->mintCsrfToken($this->client, '/actividades-familias', 'family_activity_delete'.$activity->getId()),
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(FamilyActivity::class)->find($activity->getId()));
    }

    public function testDeletingWithoutAValidTokenIsRefusedEvenToTheOrganizer(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $activity = $this->activity($organizer, '+10 days');
        $this->em->flush();
        $this->client->loginUser($organizer);

        $this->client->request('POST', '/actividades-familias/'.$activity->getId().'/borrar', ['_token' => 'x']);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(FamilyActivity::class)->find($activity->getId()));
    }

    public function testTheOrganizerEditsTheActivity(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $activity = $this->activity($organizer, '+10 days');
        $activity->reschedule($activity->getStartAt(), $activity->getStartAt()->setTime(11, 25));
        $this->em->flush();
        $start = $activity->getStartAt()->format('Y-m-d H:i');
        $this->client->loginUser($organizer);

        $crawler = $this->client->request('GET', '/actividades-familias/'.$activity->getId().'/editar');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Guardar')->form();
        $form['family_activity_form[place]'] = 'Biblioteca';
        $form['family_activity_form[endTime]'] = '';
        $this->client->submit($form);

        self::assertResponseRedirects('/actividades-familias');
        $this->em->clear();
        $saved = $this->em->getRepository(OneOffFamilyActivity::class)->find($activity->getId());
        self::assertNotNull($saved);
        self::assertSame('Biblioteca', $saved->getPlace());
        // Vaciar la hora de fin la quita; el día y la hora de inicio vuelven tal cual del formulario.
        self::assertNull($saved->getEndAt());
        self::assertSame($start, $saved->getStartAt()->format('Y-m-d H:i'));
    }

    /**
     * La actividad sobrevive a quien la organizaba (las familias ya contaban con ella) y un administrador
     * puede hacerse cargo: si no, quedaría para siempre sin nadie que pueda tocarla.
     */
    public function testAnAdminTakesOverAnActivityWhoseOrganizerLeft(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $activity = $this->activity($organizer, '+10 days');
        $role = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        $this->em->persist($role);
        $this->user('director@centro.test')->addAssignedRole($role);
        $this->em->flush();
        $activityId = $activity->getId();
        $organizerId = $organizer->getId();

        $this->em->clear();
        $leaving = $this->em->getRepository(User::class)->find($organizerId);
        self::assertNotNull($leaving);
        $this->em->remove($leaving);
        $this->em->flush();
        $this->em->clear();
        $orphan = $this->em->getRepository(FamilyActivity::class)->find($activityId);
        self::assertNotNull($orphan);
        self::assertNull($orphan->getOrganizer());

        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => 'director@centro.test']);
        self::assertNotNull($admin);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/actividades-familias');
        self::assertSelectorTextContains('table', 'Nadie (ya no está en el centro)');
        $this->client->request('GET', '/actividades-familias/'.$activityId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Nadie (ya no está en el centro)');

        $crawler = $this->client->request('GET', '/actividades-familias/'.$activityId.'/editar');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Guardar')->form();
        $form['family_activity_form[place]'] = 'Gimnasio';
        $this->client->submit($form);
        self::assertResponseRedirects('/actividades-familias');
        $this->em->clear();
        self::assertSame('Gimnasio', $this->em->getRepository(FamilyActivity::class)->find($activityId)?->getPlace());

        $crawler = $this->client->request('GET', '/actividades-familias/'.$activityId.'/editar');
        $this->client->submit($crawler->selectButton('Borrar actividad')->form());
        self::assertResponseRedirects('/actividades-familias');
        $this->em->clear();
        self::assertNull($this->em->getRepository(FamilyActivity::class)->find($activityId));
    }

    /**
     * The families' calendar is the school week: a one-off on a Saturday would be published and never
     * shown, so it is refused.
     */
    public function testAOneOffOnAWeekendIsRefused(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($teacher);

        $crawler = $this->client->request('GET', '/actividades-familias/nueva');
        $form = $crawler->selectButton('Publicar actividad')->form();
        $form['family_activity_form[title]'] = 'Taller';
        $form['family_activity_form[day]'] = '2026-01-17';
        $form['family_activity_form[startTime]'] = '11:00';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Elige un día de lunes a viernes.');
    }

    /**
     * The families were coming to the activity, not to a date: moving it to another day takes them along,
     * still counted and still able to withdraw.
     */
    public function testMovingAOneOffTakesItsFamiliesAlong(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $activity = $this->activity($organizer, '+2 days');
        $family = (new FamilyVolunteer())->setFullName('Familia Marta')->setEmail('marta@gmail.test');
        $this->em->persist($family);
        $activity->signUp($activity->session(), $family, new \DateTimeImmutable(self::TODAY.' 08:00'));
        $this->em->flush();
        $this->client->loginUser($organizer);

        $crawler = $this->client->request('GET', '/actividades-familias/'.$activity->getId().'/editar');
        self::assertSelectorTextContains('.flash.warning', 'Hay 1 persona apuntada');
        self::assertSelectorTextContains('.flash.warning', 'les llegará un correo');
        $form = $crawler->selectButton('Guardar')->form();
        $form['family_activity_form[day]'] = '2026-01-21';
        $this->client->submit($form);
        self::assertResponseRedirects('/actividades-familias');
        self::assertEmailCount(1, null, 'la familia apuntada se entera del cambio');
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'marta@gmail.test');
        self::assertEmailHeaderSame($email, 'Subject', 'Cambio en «Taller de cuentos»');
        self::assertEmailTextBodyContains($email, 'jueves 15/01, a las 10:30 → ahora miércoles 21/01, a las 10:30.');

        self::assertSame(['2026-01-21'], $this->em->getConnection()->fetchFirstColumn('SELECT session_date FROM family_signup'));
        $this->client->request('GET', '/actividades-familias/'.$activity->getId());
        self::assertSelectorTextContains('.who-comes', 'Mié 21/01');
        self::assertSelectorTextContains('.who-comes', 'Familia Marta');
    }

    /**
     * Each family coming gets a message of its own, addressed to it alone: families never learn of one
     * another. A one-off keeping its day but changing hours and place is a change too.
     */
    public function testEachFamilyIsToldAloneOfANewTimeAndPlace(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $activity = $this->activity($organizer, '+2 days');
        foreach (['Familia Marta' => 'marta@gmail.test', 'Familia Luis' => 'luis@gmail.test'] as $name => $address) {
            $family = (new FamilyVolunteer())->setFullName($name)->setEmail($address);
            $this->em->persist($family);
            $activity->signUp($activity->session(), $family, new \DateTimeImmutable(self::TODAY.' 08:00'));
        }
        $this->em->flush();
        $this->client->loginUser($organizer);

        $form = $this->client->request('GET', '/actividades-familias/'.$activity->getId().'/editar')->selectButton('Guardar')->form();
        $form['family_activity_form[startTime]'] = '12:00';
        $form['family_activity_form[place]'] = 'Biblioteca';
        $this->client->submit($form);

        self::assertResponseRedirects('/actividades-familias');
        self::assertEmailCount(2, null, 'un correo por familia');
        $to = [];
        foreach (self::getMailerMessages() as $email) {
            self::assertInstanceOf(Email::class, $email);
            self::assertCount(1, $email->getTo(), 'a esa familia sola');
            self::assertSame([], $email->getCc());
            self::assertSame([], $email->getBcc());
            self::assertEmailTextBodyNotContains($email, 'Familia Marta');
            self::assertEmailTextBodyNotContains($email, 'Familia Luis');
            self::assertEmailTextBodyContains($email, 'jueves 15/01, a las 10:30 → ahora jueves 15/01, a las 12:00, en Biblioteca.');
            $to[] = $email->getTo()[0]->getAddress();
        }
        sort($to);
        self::assertSame(['luis@gmail.test', 'marta@gmail.test'], $to);
    }

    /**
     * Only what a family has to act on is mailed: a new title or description, with the day, hours and
     * place as they were, reaches nobody.
     */
    public function testEditingWhatDoesNotMoveASessionMailsNobody(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $activity = $this->activity($organizer, '+2 days');
        $family = (new FamilyVolunteer())->setFullName('Familia Marta')->setEmail('marta@gmail.test');
        $this->em->persist($family);
        $activity->signUp($activity->session(), $family, new \DateTimeImmutable(self::TODAY.' 08:00'));
        $this->em->flush();
        $this->client->loginUser($organizer);

        $form = $this->client->request('GET', '/actividades-familias/'.$activity->getId().'/editar')->selectButton('Guardar')->form();
        $form['family_activity_form[title]'] = 'Taller de cuentos del mundo';
        $this->client->submit($form);

        self::assertResponseRedirects('/actividades-familias');
        self::assertEmailCount(0, null, 'ni el día, ni la hora, ni el lugar han cambiado');
    }

    /**
     * Removing an activity tells each family coming, in a message of its own; a family that only came to
     * a session already past is not told.
     */
    public function testDeletingAnActivityTellsTheFamiliesComing(): void
    {
        $teacher = $this->teacherWithAClass();
        $this->em->flush();
        self::mockTime('2026-01-14 08:00');
        $sessions = self::getContainer()->get(FamilySessions::class);
        $class = self::getContainer()->get(MyClasses::class)->on($teacher, new \DateTimeImmutable('2026-01-13'))[0];
        $activity = new WeeklyFamilyActivity($teacher, 'Grupos interactivos', [['day' => new \DateTimeImmutable('2026-01-13'), 'class' => $class]]);
        $this->em->persist($activity);
        $comes = (new FamilyVolunteer())->setFullName('Familia Marta')->setEmail('marta@gmail.test');
        $came = (new FamilyVolunteer())->setFullName('Familia Luis')->setEmail('luis@gmail.test');
        $this->em->persist($comes);
        $this->em->persist($came);
        foreach ([[$came, '2026-01-13'], [$comes, '2026-01-20'], [$comes, '2026-01-27']] as [$family, $day]) {
            $session = $sessions->sessionOn($activity, new \DateTimeImmutable($day));
            self::assertNotNull($session);
            $activity->signUp($session, $family, new \DateTimeImmutable('2026-01-12 08:00'));
        }
        $this->em->flush();
        $this->client->loginUser($teacher);

        $this->client->submit($this->client->request('GET', '/actividades-familias/'.$activity->getId().'/editar')->selectButton('Borrar actividad')->form());

        self::assertResponseRedirects('/actividades-familias');
        self::assertEmailCount(1, null, 'un correo a la familia que iba a venir, con sus dos sesiones');
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'marta@gmail.test');
        self::assertEmailHeaderSame($email, 'Subject', 'Se cancela «Grupos interactivos»');
        self::assertEmailTextBodyContains($email, "- martes 20/01, de 10:15 a 11:10, en Aula 12\n- martes 27/01, de 10:15 a 11:10, en Aula 12");
        self::assertEmailTextBodyNotContains($email, 'Familia Luis');
    }

    /**
     * A weekly activity moved to another class: the family coming on a day it no longer takes place is
     * told so, since its signup has no session left.
     */
    public function testChangingTheClassesTellsAFamilyItsDayIsGone(): void
    {
        $teacher = $this->teacherWithClassesOfTwoGroups();
        self::mockTime('2026-01-12 08:00');
        $this->client->loginUser($teacher);
        $this->publishWeekly(['2-2']);
        $activity = $this->em->getRepository(WeeklyFamilyActivity::class)->findOneBy([]);
        self::assertNotNull($activity);
        $family = (new FamilyVolunteer())->setFullName('Familia Marta')->setEmail('marta@gmail.test');
        $this->em->persist($family);
        $session = self::getContainer()->get(FamilySessions::class)->sessionOn($activity, new \DateTimeImmutable('2026-01-20'));
        self::assertNotNull($session);
        $activity->signUp($session, $family, new \DateTimeImmutable('2026-01-12 08:00'));
        $this->em->flush();

        $form = $this->client->request('GET', '/actividades-familias/'.$activity->getId().'/editar')->selectButton('Guardar')->form();
        $values = $form->getPhpValues();
        $values['family_activity_form']['classKeys'] = ['4-7'];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects('/actividades-familias');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailTextBodyContains($email, 'martes 20/01, de 10:15 a 11:10, en Aula 12 → ya no hay sesión ese día.');
    }

    /**
     * The list keeps what is still to come; a past one-off is not lost, it is in the calendar of its week.
     */
    public function testThePastIsInTheCalendarOfItsWeek(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $this->activity($organizer, '+10 days', 'Taller que viene');
        $past = $this->activity($organizer, '-7 days', 'Taller que ya pasó');
        $this->em->flush();
        $this->client->loginUser($organizer);

        $this->client->request('GET', '/actividades-familias');
        self::assertSelectorTextContains('table', 'Taller que viene');
        self::assertSelectorTextNotContains('table', 'Taller que ya pasó');

        $this->client->request('GET', '/actividades-familias?semana='.$past->getStartAt()->format('Y-m-d'));
        self::assertSelectorTextContains('.family-week', 'Taller que ya pasó');
    }

    /**
     * A teacher picks one of their own classes; the activity then has a session each week that class is
     * given — on its day, at its times, in its room — and not on a non-teaching day.
     */
    public function testATeacherOpensOneOfTheirClassesEveryWeek(): void
    {
        $teacher = $this->teacherWithAClass();
        $this->em->persist((new NonLectiveDay())->setDate(new \DateTimeImmutable('2026-01-20'))->setDescription('Fiesta local'));
        $this->em->flush();
        self::mockTime('2026-01-12 08:00');
        $this->client->loginUser($teacher);

        $crawler = $this->client->request('GET', '/actividades-familias/nueva-en-clase');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.class-pick__grid tbody tr:first-child th', '3ª10:15–11:10');
        self::assertSelectorTextContains('.class-pick__grid tbody tr:first-child td:nth-of-type(2) .class-pick__cell', 'Martes · 3ª (10:15–11:10) · E1A · Matemáticas');
        self::assertSelectorExists('.class-pick__grid tbody tr:first-child td:nth-of-type(2) input[type="checkbox"][value="2-2"]');
        $form = $crawler->selectButton('Publicar actividad')->form();
        $form['family_activity_form[title]'] = 'Grupos interactivos';
        $form['family_activity_form[familiesWanted]'] = '4';
        $values = $form->getPhpValues();
        $values['family_activity_form']['classKeys'] = ['2-2'];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects('/actividades-familias');

        $this->em->clear();
        $activity = $this->em->getRepository(WeeklyFamilyActivity::class)->findOneBy(['title' => 'Grupos interactivos']);
        self::assertNotNull($activity);
        self::assertSame(['2025-2026', 'E1A', [['weekday' => Weekday::TUESDAY, 'slot' => 2, 'subject' => 'Matemáticas']]], [
            $activity->getSchoolYear(), $activity->getGroupNames(), $activity->classTimes(),
        ]);

        $this->client->request('GET', '/actividades-familias?semana=2026-01-12');
        self::assertSame(['Martes 13/01 10:15–11:10 Grupos interactivos Aula 12'], $this->calendarSessions());
        $this->client->request('GET', '/actividades-familias?semana=2026-01-19');
        self::assertSame([], $this->calendarSessions(), 'el martes 20 es fiesta: no hay clase, no hay sesión');
        $this->client->request('GET', '/actividades-familias');
        self::assertSelectorTextContains('table', 'Próxima: 13/01, 10:15');
    }

    /**
     * One activity in several classes of a group: a double period is one session, from the start of the
     * first class to the end of the last (the break between them included), and another day is another.
     */
    public function testOneActivityInSeveralClassesOfAGroup(): void
    {
        $teacher = $this->teacherWithClassesOfTwoGroups();
        self::mockTime('2026-01-12 08:00');
        $this->client->loginUser($teacher);

        $this->publishWeekly(['2-2', '2-4', '4-7']);
        self::assertResponseRedirects('/actividades-familias');

        $this->client->request('GET', '/actividades-familias?semana=2026-01-12');
        self::assertSame([
            'Martes 13/01 10:15–12:30 Grupos interactivos Aula 12',
            'Jueves 15/01 13:35–14:30 Grupos interactivos Aula 12',
        ], $this->calendarSessions());
        $this->client->request('GET', '/actividades-familias');
        self::assertSelectorTextContains('table', 'Cada martes y jueves');
        self::assertSelectorTextContains('table', 'Próxima: 13/01, 10:15');

        // After an import the 4ª goes to another group: Tuesday's session is the 3ª alone, not 3ª to 6ª.
        $this->em->getConnection()->executeStatement("UPDATE schedule_entry SET group_name = 'E2B' WHERE slot_index = 4");
        $this->client->request('GET', '/actividades-familias?semana=2026-01-12');
        self::assertSame([
            'Martes 13/01 10:15–11:10 Grupos interactivos Aula 12',
            'Jueves 15/01 13:35–14:30 Grupos interactivos Aula 12',
        ], $this->calendarSessions());
    }

    /**
     * Editing the classes: another day is added, and a session that day appears; the activity is still one.
     */
    public function testTheOrganizerChangesTheClassesOfAnActivity(): void
    {
        $teacher = $this->teacherWithClassesOfTwoGroups();
        self::mockTime('2026-01-12 08:00');
        $this->client->loginUser($teacher);
        $this->publishWeekly(['2-2']);
        $id = $this->em->getRepository(WeeklyFamilyActivity::class)->findOneBy([])?->getId();
        self::assertNotNull($id);

        $form = $this->client->request('GET', '/actividades-familias/'.$id.'/editar')->selectButton('Guardar')->form();
        $values = $form->getPhpValues();
        $values['family_activity_form']['classKeys'] = ['2-2', '4-7'];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects('/actividades-familias');

        $this->client->request('GET', '/actividades-familias?semana=2026-01-12');
        self::assertSame([
            'Martes 13/01 10:15–11:10 Grupos interactivos Aula 12',
            'Jueves 15/01 13:35–14:30 Grupos interactivos Aula 12',
        ], $this->calendarSessions());
        self::assertSame(1, $this->em->getRepository(FamilyActivity::class)->count([]));
    }

    /**
     * The classes of one activity are with one group, and on one day they follow one another: anything
     * else is sent back with the reason, and nothing is published.
     */
    public function testClassesOfTwoGroupsOrApartOnADayAreRefused(): void
    {
        $teacher = $this->teacherWithClassesOfTwoGroups();
        self::mockTime('2026-01-12 08:00');
        $this->client->loginUser($teacher);

        $this->publishWeekly(['2-2', '3-0']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.class-pick', 'Todas las clases tienen que ser del mismo grupo.');

        $this->publishWeekly(['2-2', '2-7']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.class-pick', 'Las clases de un mismo día tienen que ir seguidas');

        self::assertSame(0, $this->em->getRepository(FamilyActivity::class)->count([]));
    }

    /**
     * Someone else's class cannot be picked: the choices are the teacher's own timetable.
     */
    public function testATeacherCannotPickAClassThatIsNotTheirs(): void
    {
        $this->teacherWithAClass();
        $other = $this->user('otra@centro.test');
        $this->em->flush();
        self::mockTime('2026-01-12 08:00');
        $this->client->loginUser($other);

        $this->client->request('GET', '/actividades-familias/nueva-en-clase');
        self::assertSelectorTextContains('main', 'No tienes clases en las próximas semanas');

        $this->client->request('POST', '/actividades-familias/nueva-en-clase', ['family_activity_form' => [
            'title' => 'Colada',
            'classKeys' => ['2-2'],
            '_token' => $this->mintCsrfToken($this->client, '/actividades-familias', 'family_activity_form'),
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em->getRepository(FamilyActivity::class)->count([]));
    }

    /**
     * When the timetable no longer has the class (a new import, a change of groups), the activity is not
     * lost and nobody is offered a session that will not happen: it simply has no more, and the list says so.
     */
    public function testAWeeklyActivityWhoseClassLeftTheTimetableHasNoSessions(): void
    {
        $teacher = $this->teacherWithAClass();
        $this->em->flush();
        self::mockTime('2026-01-12 08:00');
        $class = self::getContainer()->get(MyClasses::class)->on($teacher, new \DateTimeImmutable('2026-01-13'))[0];
        $activity = new WeeklyFamilyActivity($teacher, 'Grupos interactivos', [['day' => new \DateTimeImmutable('2026-01-13'), 'class' => $class]]);
        $this->em->persist($activity);
        $this->em->flush();
        $this->em->getConnection()->executeStatement("UPDATE schedule_entry SET group_name = 'E1B'");
        // The first request shares this entity manager, which still holds the cells read above with
        // their old group: forget them so the request reads the timetable as it now is.
        $this->em->clear();
        $this->client->loginUser($teacher);

        $this->client->request('GET', '/actividades-familias');

        self::assertSame([], $this->calendarSessions());
        self::assertSelectorTextContains('table', 'Sin sesiones en las próximas semanas');
    }

    /**
     * Editing an activity whose class left the timetable: the class has no cell in the grid any more,
     * but it is still offered, picked, below it, so saving the rest does not force a move.
     */
    public function testEditingAnActivityWhoseClassLeftTheTimetableKeepsIt(): void
    {
        // Another class (Tuesday at 5ª) to change to: the one gone is still picked, so that is refused.
        $teacher = $this->teacherWithClassesOfTwoGroups();
        self::mockTime('2026-01-12 08:00');
        $class = self::getContainer()->get(MyClasses::class)->on($teacher, new \DateTimeImmutable('2026-01-13'))[0];
        $activity = new WeeklyFamilyActivity($teacher, 'Grupos interactivos', [['day' => new \DateTimeImmutable('2026-01-13'), 'class' => $class]]);
        $this->em->persist($activity);
        $this->em->flush();
        $this->em->getConnection()->executeStatement("UPDATE schedule_entry SET group_name = 'E1B'");
        // The first request shares this entity manager: forget the cells read above with their old group.
        $this->em->clear();
        $this->client->loginUser($teacher);

        $crawler = $this->client->request('GET', '/actividades-familias/'.$activity->getId().'/editar');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.class-pick__grid input[value="2-2"]:checked');
        self::assertSelectorTextContains('.class-pick__gone', 'Martes · E1A · Matemáticas (ya no está en el horario)');
        self::assertSelectorExists('.class-pick__gone input[value="2-2"][checked]');
        $form = $crawler->selectButton('Guardar')->form();

        // Changing the classes while keeping the one gone is refused: it can no longer be held.
        $values = $form->getPhpValues();
        $values['family_activity_form']['classKeys'] = ['2-2', '2-4'];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.class-pick', 'desmarca las que ya no están en el horario');

        $form['family_activity_form[title]'] = 'Grupos interactivos de E1A';
        $this->client->submit($form);
        self::assertResponseRedirects('/actividades-familias');

        $this->em->clear();
        $saved = $this->em->getRepository(WeeklyFamilyActivity::class)->find($activity->getId());
        self::assertNotNull($saved);
        self::assertSame(['Grupos interactivos de E1A', 'E1A'], [$saved->getTitle(), $saved->getGroupNames()]);
    }

    /**
     * Each week's session has its own places and its own list: the calendar counts, and the detail —
     * which only the organiser opens, and without e-mails — says who comes each day.
     */
    public function testEachSessionCountsItsOwnFamilies(): void
    {
        $teacher = $this->teacherWithAClass();
        $colleague = $this->user('otra@centro.test');
        $family = (new FamilyVolunteer())->setFullName('Familia Marta')->setEmail('marta@gmail.test');
        $this->em->persist($family);
        $this->em->flush();
        self::mockTime('2026-01-12 08:00');
        $sessions = self::getContainer()->get(FamilySessions::class);
        $class = self::getContainer()->get(MyClasses::class)->on($teacher, new \DateTimeImmutable('2026-01-13'))[0];
        $activity = (new WeeklyFamilyActivity($teacher, 'Grupos interactivos', [['day' => new \DateTimeImmutable('2026-01-13'), 'class' => $class]]))->setFamiliesWanted(3);
        $this->em->persist($activity);
        $session = $sessions->sessionOn($activity, new \DateTimeImmutable('2026-01-13'));
        self::assertNotNull($session);
        $activity->signUp($session, $family, new \DateTimeImmutable('2026-01-12 08:00'));
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/actividades-familias?semana=2026-01-12');
        self::assertSelectorTextContains('.family-week', '1 de 3 personas');
        self::assertSelectorTextNotContains('.family-week', 'Familia Marta');
        $this->client->request('GET', '/actividades-familias?semana=2026-01-26');
        self::assertSelectorTextContains('.family-week', '0 de 3 personas');

        $this->client->request('GET', '/actividades-familias/'.$activity->getId());
        self::assertSelectorTextContains('.who-comes', 'Mar 13/01');
        self::assertSelectorTextContains('.who-comes', 'Familia Marta');
        self::assertSelectorTextNotContains('main', 'marta@gmail.test');

        $this->client->loginUser($colleague);
        $this->client->request('GET', '/actividades-familias/'.$activity->getId());
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param string $email the person's e-mail, unique per test
     *
     * @return User a persisted member of staff
     */
    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * @param User   $organizer who organises it
     * @param string $when      a date relative to {@see TODAY}, e.g. "+10 days"
     * @param string $title     what it is
     *
     * @return OneOffFamilyActivity a persisted activity at 10:30 on that day
     */
    private function activity(User $organizer, string $when, string $title = 'Taller de cuentos'): OneOffFamilyActivity
    {
        $activity = new OneOffFamilyActivity($organizer, $title, (new \DateTimeImmutable(self::TODAY))->modify($when)->setTime(10, 30));
        $this->em->persist($activity);

        return $activity;
    }

    /**
     * @return User a persisted teacher who gives E1A Matemáticas on Tuesdays at the third period (10:15),
     *              in Aula 12, in the 2025-2026 course (not flushed)
     */
    private function teacherWithAClass(): User
    {
        $year = $this->courseWithTheCentresFrame($this->em);
        $teacher = $this->user('profe@centro.test');
        $this->classCell($this->em, $year, $teacher, Weekday::TUESDAY, 2, 'E1A', 'Aula 12', 'Matemáticas');

        return $teacher;
    }

    /**
     * @return User a teacher with E1A on Tuesdays at 3ª, 4ª and 6ª and on Thursdays at 6ª, and E2B on
     *              Wednesdays at 1ª, all in Aula 12 (flushed)
     */
    private function teacherWithClassesOfTwoGroups(): User
    {
        $year = $this->courseWithTheCentresFrame($this->em);
        $teacher = $this->user('profe@centro.test');
        foreach ([[Weekday::TUESDAY, 2], [Weekday::TUESDAY, 4], [Weekday::TUESDAY, 7], [Weekday::THURSDAY, 7]] as [$weekday, $slot]) {
            $this->classCell($this->em, $year, $teacher, $weekday, $slot, 'E1A', 'Aula 12', 'Matemáticas');
        }
        $this->classCell($this->em, $year, $teacher, Weekday::WEDNESDAY, 0, 'E2B', 'Aula 12', 'Matemáticas');
        $this->em->flush();

        return $teacher;
    }

    /**
     * Fills in and sends the form of a new weekly activity, "Grupos interactivos", with some classes ticked.
     * The ticks go in as the browser sends them: DomCrawler only reaches the first of a list of checkboxes.
     *
     * @param list<string> $classKeys the "weekday-period" keys ticked
     */
    private function publishWeekly(array $classKeys): void
    {
        $form = $this->client->request('GET', '/actividades-familias/nueva-en-clase')->selectButton('Publicar actividad')->form();
        $form['family_activity_form[title]'] = 'Grupos interactivos';
        $values = $form->getPhpValues();
        $values['family_activity_form']['classKeys'] = $classKeys;
        $this->client->request('POST', $form->getUri(), $values);
    }

    /**
     * @return list<string> each session of the calendar on screen, as "Día dd/mm time title place"
     */
    private function calendarSessions(): array
    {
        return $this->client->getCrawler()->filter('.family-week .family-chip')->each(static function (Crawler $session): string {
            $day = $session->closest('.week-day');
            \assert(null !== $day);

            return trim(preg_replace('/\s+/', ' ', $day->attr('aria-label').' '.implode(' ', [
                $session->filter('.week-chip__time')->text(),
                $session->filter('.week-chip__where')->text(),
                $session->filter('.week-chip__who')->text(),
            ])) ?? '');
        });
    }
}
