<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\PersonalEvent;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\Weekday;
use App\Tests\Support\BuildsTheCentresTimetable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Las clases del propio docente en el Calendario: en la vista de día y en la de semana, no en la de mes
 * (serían treinta por celda). Fecha FIJA: un lunes del curso 2025-2026.
 */
final class CalendarClassesTest extends WebTestCase
{
    use BuildsTheCentresTimetable;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $teacher;
    private AcademicYear $year;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->year = $this->courseWithTheCentresFrame($this->em);
        $this->teacher = (new User())->setFullName('Carolina Calendario')->setEmail('carolina.cal@educa.madrid.org');
        $this->em->persist($this->teacher);
        $this->classCell($this->em, $this->year, $this->teacher, Weekday::MONDAY, 7, 'B1A', 'S ACTOS');
        $this->classCell($this->em, $this->year, $this->teacher, Weekday::MONDAY, 7, 'B1B', 'S ACTOS');
        $this->em->flush();
    }

    public function testTheDayViewListsTheTeachersClassesWithHourGroupsAndRoom(): void
    {
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/calendario?vista=dia&fecha=2026-01-12');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cal-block--class .cal-block__time', '13:35–14:30');
        self::assertSelectorTextContains('.cal-block--class .cal-block__title', 'B1A, B1B');
        self::assertSelectorTextContains('.cal-block--class .cal-block__subtitle', 'Literatura Universal');
        self::assertSelectorTextContains('.cal-block--class .cal-block__subtitle', 'S ACTOS');
    }

    public function testTheWeekViewShowsOneBlockPerClass(): void
    {
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=semana&fecha=2026-01-12');

        self::assertCount(1, $crawler->filter('.cal-block--class'), 'una clase aunque sean dos celdas');
        self::assertSelectorTextContains('.cal-block--class', 'B1A, B1B');
    }

    public function testTheMonthViewDoesNotListClasses(): void
    {
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=mes&fecha=2026-01-12');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.calendar-class'));
    }

    /**
     * Las horas de guardia del horario salen en la rejilla aunque no falte nadie: sin ellas, un día de
     * guardia sin ausencias parecía un día sin guardia. La del recreo, con su nombre.
     */
    public function testTheDayViewShowsTheStandingGuardiaHoursRecreoIncluded(): void
    {
        $this->guardiaCell($this->em, $this->year, $this->teacher, Weekday::MONDAY, 2, '10:15', '11:10');
        $this->guardiaCell($this->em, $this->year, $this->teacher, Weekday::MONDAY, 3, '11:10', '11:35');
        $this->em->flush();

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha=2026-01-12');

        self::assertResponseIsSuccessful();
        $duties = $crawler->filter('.cal-block--duty');
        self::assertCount(2, $duties);
        self::assertStringContainsString('10:15–11:10', $duties->eq(0)->filter('.cal-block__time')->text());
        self::assertSame('Guardia', $duties->eq(0)->filter('.cal-block__title')->text());
        self::assertSame('Guardia de recreo', $duties->eq(1)->filter('.cal-block__title')->text());
        self::assertSame('/guardias/mias', $duties->eq(0)->attr('href'));
    }

    /**
     * Con alguien ya a quien cubrir en esa hora, manda la cobertura (dice a quién y dónde): la hora de
     * guardia no se pinta además, que sería el mismo rato dos veces.
     */
    public function testAnAssignedCoverReplacesTheStandingGuardiaHour(): void
    {
        $this->guardiaCell($this->em, $this->year, $this->teacher, Weekday::MONDAY, 2, '10:15', '11:10');
        $absent = (new User())->setFullName('Ausente Calendario')->setEmail('ausente.cal@educa.madrid.org');
        $this->em->persist($absent);
        $date = new \DateTimeImmutable('2026-01-12');
        $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date)->addSlotIndexes([2]);
        $this->em->persist($absence);
        $this->em->persist((new GuardiaCover())->setAbsence($absence)->setDate($date)->setSlotIndex(2)
            ->setAbsentTeacher($absent)->setAssignedGuardia($this->teacher)->setGroupName('1ºA'));
        $this->em->flush();

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha=2026-01-12');

        self::assertCount(0, $crawler->filter('.cal-block--duty'));
        self::assertCount(1, $crawler->filter('.cal-block--guardia'));
    }

    /** Otra persona no ve las clases de nadie más: el calendario es de quien lo mira. */
    public function testAnotherPersonDoesNotSeeThem(): void
    {
        $other = (new User())->setFullName('Otra Persona')->setEmail('otra.cal@educa.madrid.org');
        $this->em->persist($other);
        $this->em->flush();

        $this->client->loginUser($other);
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha=2026-01-12');

        self::assertCount(0, $crawler->filter('.cal-block--class'));
    }

    /**
     * Dirección ve el horario de un docente: sus clases, con materia y aula, pero sin enlace a su
     * programación ni rastro de si la tiene — eso es de quien da la clase.
     */
    public function testTheLeadershipSeesATeachersClassesWithoutTheirLessonPlans(): void
    {
        $this->client->loginUser($this->director());
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha=2026-01-12&docente='.$this->teacher->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Horario de Carolina Calendario');
        $block = $crawler->filter('.cal-block--class');
        self::assertCount(1, $block);
        self::assertSame('div', $block->nodeName(), 'sin enlace a la programación de otra persona');
        self::assertStringContainsString('S ACTOS', $block->filter('.cal-block__subtitle')->text());
        self::assertStringNotContainsString('programar', $block->filter('.cal-block__subtitle')->text());
    }

    /**
     * Su agenda personal no sale, y el mes (que solo tendría tareas y eventos) cae a la semana. Los
     * enlaces de navegación conservan a quién se mira.
     */
    public function testTheLeadershipNeverSeesTheTeachersPersonalAgenda(): void
    {
        $this->em->persist(new PersonalEvent($this->teacher, 'Médico privado', new \DateTimeImmutable('2026-01-12 17:00')));
        $this->em->flush();

        $this->client->loginUser($this->director());
        $crawler = $this->client->request('GET', '/calendario?vista=mes&fecha=2026-01-12&docente='.$this->teacher->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.calendar--week'), 'el mes cae a la semana');
        self::assertStringNotContainsString('Médico privado', $crawler->text());
        self::assertCount(0, $crawler->filter('a[href^="/agenda"]'), 'ni crear ni editar eventos de otra persona');
        self::assertStringContainsString('docente='.$this->teacher->getId(), (string) $crawler->filter('a[rel="next"]')->attr('href'));
    }

    /** Solo dirección (y admin) tiene el selector; los demás ni lo ven ni pueden pedir el de otro. */
    public function testOnlyTheLeadershipGetsThePickerAndAnyoneElseIsRefused(): void
    {
        $other = (new User())->setFullName('Otra Persona')->setEmail('otra2.cal@educa.madrid.org');
        $this->em->persist($other);
        $this->em->flush();

        $this->client->loginUser($other);
        $crawler = $this->client->request('GET', '/calendario?vista=semana&fecha=2026-01-12');
        self::assertCount(0, $crawler->filter('select#docente'));

        $this->client->request('GET', '/calendario?vista=semana&fecha=2026-01-12&docente='.$this->teacher->getId());
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->director());
        $crawler = $this->client->request('GET', '/calendario?vista=semana&fecha=2026-01-12');
        self::assertStringContainsString('Carolina Calendario', $crawler->filter('select#docente')->text());
    }

    /**
     * Las guardias que se ven son las del docente mirado, no las de quien mira; y sin acceso al área de
     * Guardias, el bloque no enlaza a una ficha que le daría 403.
     */
    public function testTheLeadershipSeesTheTeachersCoversNotTheirOwn(): void
    {
        $director = $this->director();
        $absent = (new User())->setFullName('Ausente Calendario')->setEmail('ausente2.cal@educa.madrid.org');
        $this->em->persist($absent);
        $date = new \DateTimeImmutable('2026-01-12');
        $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date)->addSlotIndexes([2, 4]);
        $this->em->persist($absence);
        $this->em->persist((new GuardiaCover())->setAbsence($absence)->setDate($date)->setSlotIndex(2)
            ->setAbsentTeacher($absent)->setAssignedGuardia($this->teacher)->setGroupName('1ºA'));
        $this->em->persist((new GuardiaCover())->setAbsence($absence)->setDate($date)->setSlotIndex(4)
            ->setAbsentTeacher($absent)->setAssignedGuardia($director)->setGroupName('2ºB'));
        $this->em->flush();

        $this->client->loginUser($director);
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha=2026-01-12&docente='.$this->teacher->getId());

        $covers = $crawler->filter('.cal-block--guardia');
        self::assertCount(1, $covers, 'solo la del docente, no la de dirección');
        self::assertStringContainsString('Cubre a Ausente Calendario · 1ºA', $covers->text());
        self::assertSame('div', $covers->nodeName());
    }

    /** Un id que no existe, o de alguien dado de baja, es un 404: no el calendario propio con otro título. */
    public function testAnUnknownOrInactiveTeacherIsNotFound(): void
    {
        $gone = (new User())->setFullName('Ya No Está')->setEmail('baja.cal@educa.madrid.org')->setActive(false);
        $this->em->persist($gone);
        $this->em->flush();

        $this->client->loginUser($this->director());
        $this->client->request('GET', '/calendario?vista=semana&docente=999999');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/calendario?vista=semana&docente='.$gone->getId());
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Someone in dirección: a role that reads the Administración area, which is what
     * {@see \App\Service\MeetingAccess::isLeadership()} counts as equipo directivo.
     *
     * @return User the persisted director
     */
    private function director(): User
    {
        $role = (new Role())->setCode('direccion')->setName('Dirección')->setLevel(Area::ADMINISTRATION, PermissionLevel::READ);
        $this->em->persist($role);
        $director = (new User())->setFullName('Directora Calendario')->setEmail('directora.cal@educa.madrid.org')->addAssignedRole($role);
        $this->em->persist($director);
        $this->em->flush();

        return $director;
    }
}
