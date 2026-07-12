<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DueDate\PerTerm;
use App\Entity\AcademicYear;
use App\Entity\NonLectiveDay;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskTemplate;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\DueDateRuleKind;
use App\Enum\PermissionLevel;
use App\Enum\TaskType;
use App\Enum\TermBoundary;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The /admin panel (users, roles, org chart) must be reachable by admins and forbidden to everyone
 * else. Also checks that the admin navigation only appears for admins, and that the create/edit
 * flows round-trip through the database.
 */
final class AdminPanelTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function admin(): User
    {
        $adminRole = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        $this->em->persist($adminRole);
        $user = (new User())->setFullName('Directora Test')->setEmail('director@centro.test')->addAssignedRole($adminRole);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function teacher(): User
    {
        // A plain user: no admin flag, so no ROLE_ADMIN.
        $user = (new User())->setFullName('Docente Test')->setEmail('profe@centro.test');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function administrationManager(): User
    {
        // Write access to Administration via the matrix, but WITHOUT the superuser flag: reaches the
        // back-office without being ROLE_ADMIN.
        $role = (new Role())->setCode('direction')->setName('Dirección')->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE);
        $this->em->persist($role);
        $user = (new User())->setFullName('Directora Test')->setEmail('director@centro.test')->addAssignedRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testAdminSeesUserList(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('GET', '/admin/usuarios');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Usuarios');
        self::assertSelectorTextContains('table', 'director@centro.test');
    }

    public function testAdminSeesRoleList(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('GET', '/admin/roles');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Roles y permisos');
    }

    public function testAdminSeesUnitOrgChart(): void
    {
        $unit = (new Unit())->setCode('management')->setName('Equipo directivo');
        $this->em->persist($unit);
        $this->em->flush();

        $this->client->loginUser($this->admin());
        $this->client->request('GET', '/admin/unidades');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.unit-tree', 'Equipo directivo');
    }

    public function testAdminNavAppearsForAdmin(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nav-section-title', 'Administración');
    }

    public function testAdminNavHiddenForNonAdmin(): void
    {
        $this->client->loginUser($this->teacher());

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.nav-section-title');
    }

    public function testAdministrationManagerReachesAdminWithoutSuperuserFlag(): void
    {
        $this->client->loginUser($this->administrationManager());

        $this->client->request('GET', '/admin/usuarios');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Usuarios');
    }

    public function testRoleFormHidesAdminToggleForAdministrationManager(): void
    {
        // A non-superuser managing the administration area must not even see the admin flag control,
        // so they cannot promote a role (nor themselves) to superuser.
        $this->client->loginUser($this->administrationManager());

        $this->client->request('GET', '/admin/roles/nuevo');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.perm-admin__input');
    }

    public function testRoleFormShowsAdminToggleForSuperuser(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('GET', '/admin/roles/nuevo');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.perm-admin__input');
    }

    public function testAdministrationManagerCannotAssignAnAdminRole(): void
    {
        $adminRole = (new Role())->setCode('tic')->setName('TIC')->setAdmin(true);
        $this->em->persist($adminRole);
        $this->em->flush();

        $this->client->loginUser($this->administrationManager());

        $crawler = $this->client->request('GET', '/admin/usuarios/nuevo');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Guardar')->form([
            'user[fullName]' => 'Aspirante Admin',
            'user[email]' => 'aspirante@centro.test',
            'user[active]' => true,
        ]);
        // Tick the superuser role: the controller must reject this self-escalation attempt.
        $form['user[assignedRoles]'] = [(string) $adminRole->getId()];
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'aspirante@centro.test']));
    }

    public function testNonAdminIsForbiddenFromAdminUsers(): void
    {
        $this->client->loginUser($this->teacher());

        $this->client->request('GET', '/admin/usuarios');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonAdminIsForbiddenFromAdminUnits(): void
    {
        $this->client->loginUser($this->teacher());

        $this->client->request('GET', '/admin/unidades');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreatingAUserPersistsIt(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/usuarios/nuevo');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Guardar')->form([
            'user[fullName]' => 'Nueva Persona',
            'user[email]' => 'nueva@centro.test',
            'user[active]' => true,
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/usuarios');

        $created = $this->em->getRepository(User::class)->findOneBy(['email' => 'nueva@centro.test']);
        self::assertInstanceOf(User::class, $created);
        self::assertSame('Nueva Persona', $created->getFullName());
    }

    public function testCreatingANonLectiveDayPersistsIt(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/dias-no-lectivos/nuevo');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Guardar')->form([
            'non_lective_day[date]' => '2026-12-25',
            'non_lective_day[description]' => 'Navidad',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/dias-no-lectivos');

        $created = $this->em->getRepository(NonLectiveDay::class)->findOneBy(['description' => 'Navidad']);
        self::assertInstanceOf(NonLectiveDay::class, $created);
        self::assertSame('2026-12-25', $created->getDate()->format('Y-m-d'));
    }

    public function testDeletingANonLectiveDayRemovesIt(): void
    {
        $day = (new NonLectiveDay())->setDate(new \DateTimeImmutable('2026-12-25'))->setDescription('Navidad');
        $this->em->persist($day);
        $this->em->flush();
        $id = $day->getId();

        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/dias-no-lectivos');
        $this->client->submit($crawler->selectButton('Borrar')->form());

        self::assertResponseRedirects('/admin/dias-no-lectivos');
        self::assertNull($this->em->getRepository(NonLectiveDay::class)->find($id));
    }

    public function testDuplicateNonLectiveDayDateIsRejected(): void
    {
        $this->em->persist((new NonLectiveDay())->setDate(new \DateTimeImmutable('2026-12-25'))->setDescription('Navidad'));
        $this->em->flush();

        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/dias-no-lectivos/nuevo');
        $form = $crawler->selectButton('Guardar')->form([
            'non_lective_day[date]' => '2026-12-25',
            'non_lective_day[description]' => 'Navidad (repetido)',
        ]);
        $this->client->submit($form);

        // The unique-date constraint rejects it: the form is redisplayed (HTTP 422) and only one row exists.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Ya existe');
        self::assertCount(1, $this->em->getRepository(NonLectiveDay::class)->findAll());
    }

    public function testNonAdminIsForbiddenFromNonLectiveDays(): void
    {
        $this->client->loginUser($this->teacher());

        $this->client->request('GET', '/admin/dias-no-lectivos');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreatingAUnitPersistsIt(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/unidades/nueva');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Guardar')->form([
            'unit[code]' => 'maths',
            'unit[name]' => 'Matemáticas',
            'unit[active]' => true,
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/unidades');

        $created = $this->em->getRepository(Unit::class)->findOneBy(['code' => 'maths']);
        self::assertInstanceOf(Unit::class, $created);
        self::assertSame('Matemáticas', $created->getName());
    }

    public function testCreatingAnAcademicYearPersistsIt(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/trimestres/nuevo');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Guardar')->form([
            'academic_year[schoolYear]' => '2026-2027',
            'academic_year[term1Start]' => '2026-09-15',
            'academic_year[term1End]' => '2026-12-22',
            'academic_year[term2Start]' => '2027-01-08',
            'academic_year[term2End]' => '2027-03-27',
            'academic_year[term3Start]' => '2027-04-07',
            'academic_year[term3End]' => '2027-06-22',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/trimestres');

        $created = $this->em->getRepository(AcademicYear::class)->findOneBy(['schoolYear' => '2026-2027']);
        self::assertInstanceOf(AcademicYear::class, $created);
        self::assertSame('2027-06-22', $created->getYearEnd()->format('Y-m-d'));
    }

    public function testOutOfOrderTermsAreRejected(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/trimestres/nuevo');
        // Second term starts before the first one ends: the class invariant must reject it.
        $form = $crawler->selectButton('Guardar')->form([
            'academic_year[schoolYear]' => '2026-2027',
            'academic_year[term1Start]' => '2026-09-15',
            'academic_year[term1End]' => '2026-12-22',
            'academic_year[term2Start]' => '2026-12-01',
            'academic_year[term2End]' => '2027-03-27',
            'academic_year[term3Start]' => '2027-04-07',
            'academic_year[term3End]' => '2027-06-22',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'orden');
        self::assertCount(0, $this->em->getRepository(AcademicYear::class)->findAll());
    }

    public function testMislabelledAcademicYearIsRejected(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/trimestres/nuevo');
        // Dates are well ordered but belong to 2026-2027, labelled as a different course.
        $form = $crawler->selectButton('Guardar')->form([
            'academic_year[schoolYear]' => '2030-2031',
            'academic_year[term1Start]' => '2026-09-15',
            'academic_year[term1End]' => '2026-12-22',
            'academic_year[term2Start]' => '2027-01-08',
            'academic_year[term2End]' => '2027-03-27',
            'academic_year[term3Start]' => '2027-04-07',
            'academic_year[term3End]' => '2027-06-22',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'no corresponden al curso');
        self::assertCount(0, $this->em->getRepository(AcademicYear::class)->findAll());
    }

    public function testInvalidSchoolYearFormatIsRejected(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/trimestres/nuevo');
        $form = $crawler->selectButton('Guardar')->form([
            'academic_year[schoolYear]' => '26-27',
            'academic_year[term1Start]' => '2026-09-15',
            'academic_year[term1End]' => '2026-12-22',
            'academic_year[term2Start]' => '2027-01-08',
            'academic_year[term2End]' => '2027-03-27',
            'academic_year[term3Start]' => '2027-04-07',
            'academic_year[term3End]' => '2027-06-22',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'formato');
        self::assertCount(0, $this->em->getRepository(AcademicYear::class)->findAll());
    }

    public function testDuplicateAcademicYearIsRejected(): void
    {
        $this->em->persist($this->academicYear('2026-2027'));
        $this->em->flush();

        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/trimestres/nuevo');
        $form = $crawler->selectButton('Guardar')->form([
            'academic_year[schoolYear]' => '2026-2027',
            'academic_year[term1Start]' => '2026-09-15',
            'academic_year[term1End]' => '2026-12-22',
            'academic_year[term2Start]' => '2027-01-08',
            'academic_year[term2End]' => '2027-03-27',
            'academic_year[term3Start]' => '2027-04-07',
            'academic_year[term3End]' => '2027-06-22',
        ]);
        $this->client->submit($form);

        // The unique-course constraint rejects it: the form is redisplayed (422) and only one row exists.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Ya existe');
        self::assertCount(1, $this->em->getRepository(AcademicYear::class)->findAll());
    }

    public function testDeletingAnAcademicYearRemovesIt(): void
    {
        $year = $this->academicYear('2026-2027');
        $this->em->persist($year);
        $this->em->flush();
        $id = $year->getId();

        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/trimestres');
        $this->client->submit($crawler->selectButton('Borrar')->form());

        self::assertResponseRedirects('/admin/trimestres');
        self::assertNull($this->em->getRepository(AcademicYear::class)->find($id));
    }

    public function testNonAdminIsForbiddenFromAcademicYears(): void
    {
        $this->client->loginUser($this->teacher());

        $this->client->request('GET', '/admin/trimestres');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A valid, well-ordered course structure for the given school year, ready to persist.
     *
     * @param string $schoolYear the course code, in "YYYY-YYYY" form
     */
    private function academicYear(string $schoolYear): AcademicYear
    {
        $start = (int) substr($schoolYear, 0, 4);

        return (new AcademicYear())
            ->setSchoolYear($schoolYear)
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-15'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-22'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-22'));
    }

    public function testCreatingATaskTemplatePersistsIt(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/catalogo/nueva');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Guardar')->form([
            'task_template[title]' => 'Memoria del departamento',
            'task_template[type]' => TaskType::WITH_DELIVERABLE->value,
            'task_template[active]' => true,
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/catalogo');

        $created = $this->em->getRepository(TaskTemplate::class)->findOneBy(['title' => 'Memoria del departamento']);
        self::assertInstanceOf(TaskTemplate::class, $created);
        self::assertNull($created->getDueDateRule(), 'no rule chosen');
    }

    public function testCreatingATemplateWithAPerTermRuleStoresTheRule(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/catalogo/nueva');
        $form = $crawler->selectButton('Guardar')->form([
            'task_template[title]' => 'Acta de reunión',
            'task_template[type]' => TaskType::SIMPLE->value,
            'task_template[active]' => true,
            'task_template[ruleKind]' => DueDateRuleKind::PER_TERM->value,
            'task_template[ruleBoundary]' => TermBoundary::END->value,
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/catalogo');

        $created = $this->em->getRepository(TaskTemplate::class)->findOneBy(['title' => 'Acta de reunión']);
        self::assertInstanceOf(TaskTemplate::class, $created);
        $rule = $created->getDueDateRule();
        self::assertInstanceOf(PerTerm::class, $rule);
        self::assertSame(['kind' => 'per_term', 'boundary' => 'end'], $rule->toArray());
    }

    public function testCreatingATemplateWithAFixedRuleStoresTheRule(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/catalogo/nueva');
        $form = $crawler->selectButton('Guardar')->form([
            'task_template[title]' => 'Entregar programación',
            'task_template[type]' => TaskType::SIMPLE->value,
            'task_template[active]' => true,
            'task_template[ruleKind]' => DueDateRuleKind::FIXED->value,
            'task_template[ruleMonth]' => '9',
            'task_template[ruleDay]' => '30',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/catalogo');

        $created = $this->em->getRepository(TaskTemplate::class)->findOneBy(['title' => 'Entregar programación']);
        self::assertInstanceOf(TaskTemplate::class, $created);
        self::assertSame(['kind' => 'fixed', 'month' => 9, 'day' => 30], $created->getDueDateRule()?->toArray());
    }

    public function testIncompleteRuleIsRejectedNotFatal(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/catalogo/nueva');
        // "Fixed" chosen but the day is left blank: a form error, never a 500.
        $form = $crawler->selectButton('Guardar')->form([
            'task_template[title]' => 'Regla incompleta',
            'task_template[type]' => TaskType::SIMPLE->value,
            'task_template[active]' => true,
            'task_template[ruleKind]' => DueDateRuleKind::FIXED->value,
            'task_template[ruleMonth]' => '9',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(TaskTemplate::class)->findAll());
    }

    public function testDeletingATaskTemplateRemovesIt(): void
    {
        $template = (new TaskTemplate())->setTitle('A borrar')->setType(TaskType::SIMPLE);
        $this->em->persist($template);
        $this->em->flush();
        $id = $template->getId();

        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/catalogo');
        $this->client->submit($crawler->selectButton('Borrar')->form());

        self::assertResponseRedirects('/admin/catalogo');
        self::assertNull($this->em->getRepository(TaskTemplate::class)->find($id));
    }

    public function testNonAdminIsForbiddenFromTaskTemplates(): void
    {
        $this->client->loginUser($this->teacher());

        $this->client->request('GET', '/admin/catalogo');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGeneratingCourseTasksCreatesThemAndRedirectsToThePlan(): void
    {
        $year = $this->academicYear('2026-2027');
        $this->em->persist($year);
        $this->em->persist((new TaskTemplate())->setTitle('Acta de reunión')->setType(TaskType::SIMPLE)->setDueDateRule(new PerTerm(TermBoundary::END)));
        $this->em->flush();

        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/trimestres');
        $this->client->submit($crawler->selectButton('Generar año escolar')->form());

        self::assertResponseRedirects('/tareas?curso=2026-2027');
        self::assertCount(3, $this->em->getRepository(Task::class)->findBy(['schoolYear' => '2026-2027']));
    }

    public function testNonAdminCannotGenerateCourseTasks(): void
    {
        $year = $this->academicYear('2026-2027');
        $this->em->persist($year);
        $this->em->flush();

        $this->client->loginUser($this->teacher());
        $this->client->request('POST', '/admin/trimestres/'.$year->getId().'/generar');

        self::assertResponseStatusCodeSame(403);
    }
}
