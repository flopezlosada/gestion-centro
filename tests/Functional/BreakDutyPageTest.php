<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakDutyGap;
use App\Entity\BreakZone;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\BreakPeriod;
use App\Enum\PermissionLevel;
use App\Enum\Weekday;
use App\Util\SchoolYear;
use App\Tests\Support\OwnsTheBreakZoneCatalogue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The break duty rota screens: who may see and change them, that a duty can be added and removed, and
 * that the two rules the model enforces surface as readable messages rather than as a 500 — a teacher
 * cannot hold two zones on the same day, and a zone name cannot repeat.
 *
 * Gated by the {@see Area::GUARDIAS} matrix, like the rest of the module: READ to look, WRITE to change.
 */
final class BreakDutyPageTest extends WebTestCase
{
    use OwnsTheBreakZoneCatalogue;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Crear "Patio" chocaría con el UNIQUE del nombre, y los recuentos de zonas contarían las
        // sembradas: este escenario es dueño del catálogo.
        $this->emptyTheBreakZoneCatalogue($this->em);
    }

    public function testAPlainTeacherCannotReachTheRota(): void
    {
        $this->login(PermissionLevel::NONE);

        $this->client->request('GET', '/guardias/recreo');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/guardias/recreo/huecos');
        self::assertResponseStatusCodeSame(403);
    }

    public function testReadAccessSeesTheRotaButNotTheZoneSetup(): void
    {
        $this->login(PermissionLevel::READ);

        $this->client->request('GET', '/guardias/recreo');
        self::assertResponseIsSuccessful();

        // Zones and weights are a setup surface: looking is not enough.
        $this->client->request('GET', '/guardias/recreo/zonas');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCoordinatorAddsADutyToTheRota(): void
    {
        $this->login();
        $year = $this->currentYear();
        $zone = $this->zone('Patio');
        $teacher = $this->user('Ana Patio Ruiz', 'ana.patio@centro.test');
        $this->em->flush();

        $this->post('/guardias/recreo?enblanco=1', '/guardias/recreo/asignar', [
            'curso' => $year->getSchoolYear(),
            'teacher' => (string) $teacher->getId(),
            'zone' => (string) $zone->getId(),
            'weekday' => (string) Weekday::MONDAY->value,
            'period' => BreakPeriod::FIRST->value,
        ]);

        self::assertResponseRedirects();
        $duties = $this->em->getRepository(BreakDutyAssignment::class)->findAll();
        self::assertCount(1, $duties);
        self::assertSame(BreakPeriod::FIRST, $duties[0]->getPeriod());
        self::assertSame('Patio', $duties[0]->getZone()->getName());
    }

    public function testASecondZoneAtTheSameRecreoIsRefusedWithAnExplanation(): void
    {
        $this->login();
        $year = $this->currentYear();
        $patio = $this->zone('Patio');
        $biblioteca = $this->zone('Biblioteca');
        $teacher = $this->user('Ana Patio Ruiz', 'ana.patio@centro.test');
        $this->em->flush();

        $payload = [
            'curso' => $year->getSchoolYear(),
            'teacher' => (string) $teacher->getId(),
            'zone' => (string) $patio->getId(),
            'weekday' => (string) Weekday::MONDAY->value,
            'period' => BreakPeriod::FIRST->value,
        ];
        $this->post('/guardias/recreo?enblanco=1', '/guardias/recreo/asignar', $payload);
        $this->post('/guardias/recreo?enblanco=1', '/guardias/recreo/asignar', ['zone' => (string) $biblioteca->getId()] + $payload);

        // Nobody can be in two places at once: the clash is a message, not a crash. The clash is now the
        // RECREO, not the day — the same person watching two zones on one day is fine as long as they are
        // at different breaks, which is what the centre asked for.
        self::assertResponseRedirects();
        self::assertCount(1, $this->em->getRepository(BreakDutyAssignment::class)->findAll());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('ya vigila una zona', $crawler->filter('.flash.error')->text());
        self::assertStringContainsString('recreo grande', $crawler->filter('.flash.error')->text(), 'the message names which recreo is taken');

        // And the other recreo of that same day is accepted, which the old model forbade outright.
        $this->post('/guardias/recreo?enblanco=1', '/guardias/recreo/asignar', ['zone' => (string) $biblioteca->getId(), 'period' => BreakPeriod::SECOND->value] + $payload);
        self::assertCount(2, $this->em->getRepository(BreakDutyAssignment::class)->findAll());
    }

    public function testRemovingADutyTakesItsRecordedGapsWithIt(): void
    {
        $this->login();
        $year = $this->currentYear();
        $duty = $this->duty($year, $this->user('Ana Patio Ruiz', 'ana.patio@centro.test'), $this->zone('Patio'));
        $gap = (new BreakDutyGap())->setAssignment($duty)->setDate(new \DateTimeImmutable('today'));
        $this->em->persist($gap);
        $this->em->flush();

        $this->post('/guardias/recreo', '/guardias/recreo/'.$duty->getId().'/quitar', []);

        self::assertResponseRedirects();
        self::assertSame([], $this->em->getRepository(BreakDutyAssignment::class)->findAll());
        self::assertSame([], $this->em->getRepository(BreakDutyGap::class)->findAll(), 'a gap is an event of its duty');
    }

    public function testVolunteerIsRecordedOnTheGapWithoutDeletingIt(): void
    {
        $this->login();
        $year = $this->currentYear();
        $duty = $this->duty($year, $this->user('Ana Patio Ruiz', 'ana.patio@centro.test'), $this->zone('Patio'));
        $gap = (new BreakDutyGap())->setAssignment($duty)->setDate(new \DateTimeImmutable('today'));
        $this->em->persist($gap);
        $volunteer = $this->user('Voluntario Solidario Paz', 'voluntario@centro.test');
        $this->em->flush();

        $this->post('/guardias/recreo/huecos', '/guardias/recreo/huecos/'.$gap->getId(), [
            'volunteer' => (string) $volunteer->getId(),
            'note' => 'Se ofrece él mismo',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(BreakDutyGap::class)->find($gap->getId());
        self::assertNotNull($reloaded, 'the gap survives: that the recreo went uncovered is the record');
        self::assertSame($volunteer->getId(), $reloaded->getVolunteer()?->getId());
        self::assertSame('Se ofrece él mismo', $reloaded->getNote());
    }

    public function testThePhoneViewShowsOneDayAndTakesItFromTheUrl(): void
    {
        // En el móvil la rejilla no cabe y se consulta UN día (por defecto hoy, porque quien mira el
        // cuadrante desde el pasillo quiere saber quién está en el patio ahora). Aquí se pide uno concreto:
        // el defecto depende del reloj y no se afirma en un test.
        $this->login(PermissionLevel::READ);
        $year = $this->currentYear();
        $zone = $this->zone('Patio');
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@centro.test');
        $luis = $this->user('Luis Lunes Soto', 'luis.lunes@centro.test');
        $this->duty($year, $ana, $zone, Weekday::MONDAY);
        $this->duty($year, $luis, $zone, Weekday::FRIDAY);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/recreo?curso='.$year->getSchoolYear().'&dia='.Weekday::FRIDAY->value);

        self::assertResponseIsSuccessful();
        $day = implode(' ', $crawler->filter('.recreo-phone .recreo-zone')->each(static fn ($node): string => $node->text()));
        self::assertStringContainsString('Luis Lunes Soto', $day);
        self::assertStringNotContainsString('Ana Patio Ruiz', $day, 'la consulta es de un día, no de la semana');
        self::assertSame('page', $crawler->filter('.recreo-day.is-active')->attr('aria-current'));
        self::assertStringContainsString('Viernes', $crawler->filter('.recreo-day.is-active')->text());
    }

    public function testAWeekendDayFallsBackToMondayInsteadOfAnEmptyScreen(): void
    {
        $this->login(PermissionLevel::READ);
        $year = $this->currentYear();
        // Con una plaza, porque los chips de día son de la consulta y esa solo existe con cuadrante.
        $this->duty($year, $this->user('Ana Patio Ruiz', 'ana.patio@centro.test'), $this->zone('Patio'));
        $this->em->flush();

        // El sábado no tiene recreo, así que pedirlo cae al lunes en vez de dejar la pantalla en blanco o
        // reventar al indexar una rejilla que no tiene ese día.
        $crawler = $this->client->request('GET', '/guardias/recreo?curso='.$year->getSchoolYear().'&dia='.Weekday::SATURDAY->value);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Lunes', $crawler->filter('.recreo-day.is-active')->text());
    }

    public function testANonsenseDayIsIgnoredAndTheScreenOpensOnItsDefault(): void
    {
        // Un `dia` que no es un día se descarta y manda el defecto (hoy), no un valor inventado. El test NO
        // afirma qué día es hoy a propósito: la versión anterior decía «cae al lunes» y solo pasaba los
        // lunes — verde el 03/08 y roja el 04/08. Lo que hay que garantizar es que la pantalla responde y
        // que queda UN día elegido, no cero ni dos.
        $this->login(PermissionLevel::READ);
        $year = $this->currentYear();
        $this->duty($year, $this->user('Ana Patio Ruiz', 'ana.patio@centro.test'), $this->zone('Patio'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/recreo?curso='.$year->getSchoolYear().'&dia=99');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.recreo-day.is-active'));
        self::assertCount(\count(Weekday::schoolWeek()), $crawler->filter('.recreo-day'));
    }

    public function testACourseWithNoRotaOffersTheTwoWaysInAndNoEmptyGrid(): void
    {
        // Dos tablas de veinticinco celdas vacías no son un cuadrante: le disputaban la pantalla a lo único
        // que hay que hacer ahí. La rejilla se pide, con «Empezar en blanco», para montarlo a mano.
        $this->login();
        $year = $this->currentYear();
        $this->zone('Patio');
        $this->em->flush();

        $vacio = $this->client->request('GET', '/guardias/recreo?curso='.$year->getSchoolYear());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $vacio->filter('table.recreo-grid'), 'sin cuadrante no se dibuja la rejilla');
        self::assertCount(0, $vacio->filter('form[action="/guardias/recreo/asignar"]'));
        self::assertStringContainsString('Todavía no hay cuadrante', $vacio->filter('.recreo-start__title')->text());

        $enBlanco = $this->client->request('GET', '/guardias/recreo?curso='.$year->getSchoolYear().'&enblanco=1');
        self::assertCount(2, $enBlanco->filter('table.recreo-grid'), 'una rejilla por recreo, ya pedidas');
        self::assertCount(1, $enBlanco->filter('form[action="/guardias/recreo/asignar"]'));
    }

    public function testReadAccessGetsNoWayToChangeTheRota(): void
    {
        // Quien solo consulta ve el cuadrante entero, pero ni el panel de quitar ni la barra de añadir: el
        // controlador rechazaría la acción, y ofrecerla sería mandar a alguien contra un 403.
        $this->login(PermissionLevel::READ);
        $year = $this->currentYear();
        $duty = $this->duty($year, $this->user('Ana Patio Ruiz', 'ana.patio@centro.test'), $this->zone('Patio'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/recreo?curso='.$year->getSchoolYear());

        self::assertStringContainsString('Ana Patio Ruiz', $crawler->filter('table.recreo-grid')->first()->text());
        self::assertCount(0, $crawler->filter('form[action="/guardias/recreo/'.$duty->getId().'/quitar"]'));
        self::assertCount(0, $crawler->filter('form[action="/guardias/recreo/asignar"]'));
        self::assertCount(0, $crawler->filter('form[action="/guardias/recreo/anunciar"]'));
    }

    public function testTheAddBarComesPreFilledFromTheCellThatWasClicked(): void
    {
        // El "+ añadir" de una celda incompleta trae día, zona y recreo resueltos: son datos que la celda
        // conoce y quien la pulsa no tiene por qué volver a teclear.
        $this->login();
        $year = $this->currentYear();
        $patio = $this->zone('Patio');
        $biblioteca = $this->zone('Biblioteca');
        $this->em->flush();

        // Con `enblanco`, que es la vía de montarlo a mano en un curso todavía vacío: es exactamente donde
        // más falta hace no volver a teclear los tres datos que la celda ya conoce.
        $crawler = $this->client->request('GET', sprintf(
            '/guardias/recreo?curso=%s&enblanco=1&zona=%d&d=%d&recreo=%s',
            $year->getSchoolYear(),
            $biblioteca->getId(),
            Weekday::THURSDAY->value,
            BreakPeriod::SECOND->value,
        ));

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/guardias/recreo/asignar"]')->form();
        self::assertSame((string) $biblioteca->getId(), $form['zone']->getValue());
        self::assertSame((string) Weekday::THURSDAY->value, $form['weekday']->getValue());
        self::assertSame(BreakPeriod::SECOND->value, $form['period']->getValue());
        self::assertNotSame((string) $patio->getId(), $form['zone']->getValue());
    }

    public function testAProposalOnScreenSaysSoAndDoesNotOfferToAnnounceWhatIsStored(): void
    {
        // Anunciar mientras se mira una propuesta anunciaría lo GUARDADO, que no es lo que está en pantalla.
        $this->login();
        $year = $this->currentYear();
        $this->duty($year, $this->user('Ana Patio Ruiz', 'ana.patio@centro.test'), $this->zone('Patio'));
        $this->em->flush();

        $guardado = $this->client->request('GET', '/guardias/recreo?curso='.$year->getSchoolYear());
        self::assertStringContainsString('Borrador', $guardado->filter('.recreo-state')->text());
        self::assertGreaterThan(0, $guardado->filter('form[action="/guardias/recreo/anunciar"]')->count());

        $propuesta = $this->client->request('GET', '/guardias/recreo?curso='.$year->getSchoolYear().'&propuesta=1');
        self::assertStringContainsString('Propuesta', $propuesta->filter('.recreo-state')->text());
        self::assertCount(0, $propuesta->filter('form[action="/guardias/recreo/anunciar"]'), 'no se anuncia lo que no se está mirando');
        self::assertCount(0, $propuesta->filter('.recreo-anchor .recreo-figure'), 'las cifras de la propuesta las da su panel, y solo él');
    }

    public function testDuplicateZoneNameIsRefusedWithAnExplanation(): void
    {
        $this->login();
        $this->zone('Patio');
        $this->em->flush();

        $this->post('/guardias/recreo/zonas', '/guardias/recreo/zonas', ['name' => 'Patio', 'weight' => '2', 'required' => '1']);

        self::assertResponseRedirects();
        self::assertCount(1, $this->em->getRepository(BreakZone::class)->findAll());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Ya existe una zona', $crawler->filter('.flash.error')->text());
    }

    public function testZoneWeightIsClampedToTheAllowedScale(): void
    {
        $this->login();

        $this->post('/guardias/recreo/zonas', '/guardias/recreo/zonas', ['name' => 'Pistas', 'weight' => '99', 'required' => '0']);

        $zones = $this->em->getRepository(BreakZone::class)->findBy(['name' => 'Pistas']);
        self::assertCount(1, $zones);
        self::assertSame(BreakZone::MAX_WEIGHT, $zones[0]->getWeight(), 'a wild weight is clamped, not stored');
        self::assertSame(1, $zones[0]->getRequiredTeachers(), 'a zone always needs at least one person');
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->login();
        $year = $this->currentYear();
        $zone = $this->zone('Patio');
        $teacher = $this->user('Ana Patio Ruiz', 'ana.patio@centro.test');
        $this->em->flush();

        $this->client->request('POST', '/guardias/recreo/asignar', [
            '_token' => 'no-es-el-token',
            'curso' => $year->getSchoolYear(),
            'teacher' => (string) $teacher->getId(),
            'zone' => (string) $zone->getId(),
            'weekday' => (string) Weekday::MONDAY->value,
            'period' => BreakPeriod::FIRST->value,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->em->getRepository(BreakDutyAssignment::class)->findAll());
    }

    /**
     * Logs in a user with the given level of access to the Guardias area.
     *
     * @param PermissionLevel $level the access to grant (WRITE = coordinator)
     *
     * @return User the logged-in user
     */
    private function login(PermissionLevel $level = PermissionLevel::WRITE): User
    {
        $user = (new User())->setFullName('Docente Test')->setEmail('profe@centro.test');
        if (PermissionLevel::NONE !== $level) {
            $role = (new Role())->setCode('guardias')->setName('Coordinación de guardias')->setLevel(Area::GUARDIAS, $level);
            $this->em->persist($role);
            $user->addAssignedRole($role);
        }
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    /**
     * POSTs to a route with the CSRF token the application itself put in that form.
     *
     * The token is read from the rendered page rather than asked of the container: the token storage is
     * session-backed, so building one outside a request raises SessionNotFoundException. Same approach as
     * {@see GuardiaPageTest}, and it also proves the form really carries the token the controller expects.
     *
     * @param string                $page    the page whose form to read the token from
     * @param string                $uri     the route to post to, matching the form's action
     * @param array<string, string> $payload the form fields
     */
    private function post(string $page, string $uri, array $payload): void
    {
        $crawler = $this->client->request('GET', $page);
        $token = $crawler->filter(sprintf('form[action="%s"] input[name="_token"]', $uri));
        self::assertGreaterThan(0, $token->count(), sprintf('no form posting to %s was rendered on %s', $uri, $page));

        $this->client->request('POST', $uri, ['_token' => (string) $token->first()->attr('value')] + $payload);
    }

    /**
     * The course today falls into, persisted so the rota screens have one to work on.
     *
     * @return AcademicYear the persisted course
     */
    private function currentYear(): AcademicYear
    {
        $schoolYear = SchoolYear::current(new \DateTimeImmutable('today'));
        $start = (int) substr($schoolYear, 0, 4);
        $year = (new AcademicYear())
            ->setSchoolYear($schoolYear)
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-15'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-22'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-22'));
        $this->em->persist($year);

        return $year;
    }

    /**
     * Persists a break zone.
     *
     * @param string $name the zone name
     *
     * @return BreakZone the persisted zone
     */
    private function zone(string $name): BreakZone
    {
        $zone = (new BreakZone())->setName($name)->setWeight(2);
        $this->em->persist($zone);

        return $zone;
    }

    /**
     * Persists a user with a name and e-mail.
     *
     * @param string $name  the full name
     * @param string $email the e-mail
     *
     * @return User the persisted user
     */
    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * Persists one rota line, on Monday's long recreo unless told otherwise.
     *
     * @param AcademicYear $year    the course
     * @param User         $teacher the teacher on duty
     * @param BreakZone    $zone    the zone to watch
     * @param Weekday      $weekday the weekday, Monday by default
     * @param BreakPeriod  $period  which recreo, the long one by default
     *
     * @return BreakDutyAssignment the persisted duty
     */
    private function duty(AcademicYear $year, User $teacher, BreakZone $zone, Weekday $weekday = Weekday::MONDAY, BreakPeriod $period = BreakPeriod::FIRST): BreakDutyAssignment
    {
        $duty = (new BreakDutyAssignment())
            ->setAcademicYear($year)
            ->setTeacher($teacher)
            ->setWeekday($weekday)
            ->setZone($zone)
            ->setPeriod($period);
        $this->em->persist($duty);

        return $duty;
    }
}
