<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\Department;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaTaskBankItem;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\EducationLevel;
use App\Enum\PermissionLevel;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The guardia task bank: any teacher may browse it and contribute to it (filling it is the
 * departments' job), but only the people a guardia concerns may attach a task to it, and only the
 * author / department head / guardia coordination may edit an entry.
 */
final class GuardiaTaskBankTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AcademicYear $year;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // El curso se toma de la misma función que usa la aplicación: fijarlo a "2025-2026" haría que
        // la suite entera empezara a fallar el 1 de septiembre.
        $this->year = $this->academicYear(SchoolYear::current(new \DateTimeImmutable('today')));
        $this->em->persist($this->year);
    }

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

    /**
     * A lective cell in the timetable, so the course has that subject on offer (the bank form only
     * accepts subjects that are really taught).
     */
    private function taughtSubject(string $subject, User $teacher): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)
            ->setTeacher($teacher)
            ->setWeekday(Weekday::THURSDAY)
            ->setSlotIndex(2)
            ->setStartsAt(new \DateTimeImmutable('10:15'))
            ->setEndsAt(new \DateTimeImmutable('11:10'))
            ->setKind(ScheduleActivityKind::LECTIVE)
            ->setGroupName('E4D')
            ->setSubjectName($subject));
    }

    private function department(string $name = 'Matemáticas'): Department
    {
        $department = (new Department())->setCode(strtolower($name))->setName($name);
        $this->em->persist($department);

        return $department;
    }

    /**
     * Persists a user and signs them in, optionally as a guardia coordinator.
     */
    private function login(string $email = 'profe@centro.test', bool $coordinator = false, ?Department $unit = null): User
    {
        $user = (new User())->setFullName('Docente '.$email)->setEmail($email);
        if (null !== $unit) {
            $user->setUnit($unit);
        }
        if ($coordinator) {
            $role = (new Role())->setCode('guardias-'.uniqid())->setName('Coordinación de guardias')->setLevel(Area::GUARDIAS, PermissionLevel::WRITE);
            $this->em->persist($role);
            $user->addAssignedRole($role);
        }
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function bankItem(Department $department, EducationLevel $level, string $title, string $subject = 'Matemáticas', int $timesUsed = 0, bool $active = true, ?string $sections = null): GuardiaTaskBankItem
    {
        $item = (new GuardiaTaskBankItem())
            ->setAcademicYear($this->year)
            ->setDepartment($department)
            ->setLevel($level)
            ->setTitle($title)
            ->setSubject($subject)
            ->setSections($sections)
            ->setActive($active);
        for ($i = 0; $i < $timesUsed; ++$i) {
            $item->recordUse();
        }
        $this->em->persist($item);

        return $item;
    }

    /**
     * A parte line for a group of the given level, assigned to $assigned (who therefore may act on it).
     */
    private function cover(User $absent, ?User $assigned, string $group = 'E4D', ?string $subject = 'Matemáticas'): GuardiaCover
    {
        // Un día lectivo del curso en marcha, sea cual sea el día en que se ejecute la suite.
        $date = $this->year->getTerm2Start();
        $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date);
        $this->em->persist($absence);

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate($date)
            ->setSlotIndex(2)
            ->setAbsentTeacher($absent)
            ->setAssignedGuardia($assigned)
            ->setGroupName($group)
            ->setRoomName('A12')
            ->setSubjectName($subject);
        $this->em->persist($cover);

        return $cover;
    }

    private function reloadCover(int $id): GuardiaCover
    {
        $this->em->clear();
        $cover = $this->em->getRepository(GuardiaCover::class)->find($id);
        self::assertInstanceOf(GuardiaCover::class, $cover);

        return $cover;
    }

    public function testAnyTeacherCanBrowseTheBank(): void
    {
        $maths = $this->department();
        $this->login();
        $this->bankItem($maths, EducationLevel::ESO_3, 'Ficha de fracciones', 'Matemáticas');
        $this->em->flush();

        $this->client->request('GET', '/guardias/banco');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Ficha de fracciones');
    }

    public function testTheLevelFilterNarrowsTheListing(): void
    {
        $maths = $this->department();
        $this->login();
        $this->bankItem($maths, EducationLevel::ESO_3, 'Ficha de fracciones');
        $this->bankItem($maths, EducationLevel::BACH_2, 'Comentario de texto');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/banco?nivel=eso3');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ficha de fracciones', $crawler->filter('table')->text());
        self::assertStringNotContainsString('Comentario de texto', $crawler->filter('table')->text());
    }

    public function testTheSubjectFilterIsExact(): void
    {
        // Regla del centro: el grupo trabaja la asignatura que le tocaba, así que la materia no es
        // orientativa — una tarea de Lengua no puede salir en una guardia de Matemáticas.
        $maths = $this->department();
        $this->login();
        $this->bankItem($maths, EducationLevel::ESO_3, 'Ficha de fracciones', 'Matemáticas');
        $this->bankItem($maths, EducationLevel::ESO_3, 'Análisis sintáctico', 'Lengua');
        $this->em->flush();

        $text = $this->client->request('GET', '/guardias/banco?nivel=eso3&materia=Matem%C3%A1ticas')->filter('table')->text();

        self::assertStringContainsString('Ficha de fracciones', $text);
        self::assertStringNotContainsString('Análisis sintáctico', $text);
    }

    public function testPickingForAGuardiaOnlyOffersTasksOfTheSubjectThatWasMissed(): void
    {
        $maths = $this->department();
        $guardia = $this->login('guardia@centro.test');
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente@centro.test');
        $this->em->persist($absent);
        $this->bankItem($maths, EducationLevel::ESO_4, 'Ficha de fracciones', 'Matemáticas');
        $this->bankItem($maths, EducationLevel::ESO_4, 'Análisis sintáctico', 'Lengua');
        $cover = $this->cover($absent, $guardia, subject: 'Matemáticas');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/banco?para='.$cover->getId());

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('table')->text();
        self::assertStringContainsString('Ficha de fracciones', $text);
        self::assertStringNotContainsString('Análisis sintáctico', $text);
    }

    public function testATaskLimitedToSomeSectionsIsNotOfferedToAnotherGroup(): void
    {
        $maths = $this->department();
        $guardia = $this->login('guardia@centro.test');
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente@centro.test');
        $this->em->persist($absent);
        $this->bankItem($maths, EducationLevel::ESO_4, 'Solo para A y C', 'Matemáticas', sections: 'A, C');
        $this->bankItem($maths, EducationLevel::ESO_4, 'Para todo el nivel', 'Matemáticas');
        // E4D: la letra es D, así que la tarea restringida a A y C no le vale.
        $cover = $this->cover($absent, $guardia, group: 'E4D');
        $this->em->flush();

        $text = $this->client->request('GET', '/guardias/banco?para='.$cover->getId())->filter('table')->text();

        self::assertStringContainsString('Para todo el nivel', $text);
        self::assertStringNotContainsString('Solo para A y C', $text);
    }

    public function testAMultiGroupClassTakesTheTasksOfAnyOfItsSections(): void
    {
        // Varias letras a la vez (optativa agrupada): le vale tanto la tarea de "A" como la libre.
        $maths = $this->department();
        $guardia = $this->login('guardia@centro.test');
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente@centro.test');
        $this->em->persist($absent);
        $this->bankItem($maths, EducationLevel::ESO_4, 'Solo para A', 'Matemáticas', sections: 'A');
        $this->bankItem($maths, EducationLevel::ESO_4, 'Para todo el nivel', 'Matemáticas');
        $cover = $this->cover($absent, $guardia, group: 'E4A, E4B, E4C');
        $this->em->flush();

        $text = $this->client->request('GET', '/guardias/banco?para='.$cover->getId())->filter('table')->text();

        // El grupo lleva las letras A, B y C: la tarea de A sí le vale.
        self::assertStringContainsString('Solo para A', $text);
        self::assertStringContainsString('Para todo el nivel', $text);
    }

    public function testTheBankOfAPastCourseIsNotOffered(): void
    {
        // El centro vacía el banco en septiembre: lo del curso pasado no se ofrece, pero no se borra.
        $maths = $this->department();
        $this->login();
        $lastYear = $this->academicYear('2019-2020');
        $this->em->persist($lastYear);
        $old = $this->bankItem($maths, EducationLevel::ESO_3, 'Ficha del año pasado');
        $old->setAcademicYear($lastYear);
        $this->em->flush();

        $this->client->request('GET', '/guardias/banco');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.empty-state');
        self::assertNotNull($this->em->getRepository(GuardiaTaskBankItem::class)->find($old->getId()));
    }

    public function testRetiredTasksAreHiddenUnlessAskedFor(): void
    {
        $maths = $this->department();
        $this->login();
        $this->bankItem($maths, EducationLevel::ESO_1, 'Tarea retirada', active: false);
        $this->em->flush();

        $this->client->request('GET', '/guardias/banco');
        self::assertSelectorExists('.empty-state', 'una tarea retirada no se ofrece para las guardias');

        $crawler = $this->client->request('GET', '/guardias/banco?retiradas=1');
        self::assertStringContainsString('Tarea retirada', $crawler->filter('table')->text());
    }

    public function testATeacherAddsATaskToTheBank(): void
    {
        $maths = $this->department();
        $teacher = $this->login(unit: $maths);
        // Solo se pueden elegir materias que de verdad se dan este curso.
        $this->taughtSubject('Lengua Castellana y Literatura', $teacher);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/banco/nueva');
        self::assertResponseIsSuccessful();

        $token = (string) $crawler->filter('input[name="guardia_task_bank_item[_token]"]')->attr('value');
        $this->client->request('POST', '/guardias/banco/nueva', ['guardia_task_bank_item' => [
            'level' => EducationLevel::ESO_2->value,
            'subject' => 'Lengua Castellana y Literatura',
            'sections' => 'a, c',
            'department' => (string) $maths->getId(),
            'title' => 'Comprensión lectora',
            'description' => 'Leer el texto y responder',
            'suggestedCopies' => '30',
            'active' => '1',
            '_token' => $token,
        ]]);

        self::assertResponseRedirects();
        $this->em->clear();
        $saved = $this->em->getRepository(GuardiaTaskBankItem::class)->findOneBy(['title' => 'Comprensión lectora']);
        self::assertInstanceOf(GuardiaTaskBankItem::class, $saved);
        self::assertSame(EducationLevel::ESO_2, $saved->getLevel());
        self::assertSame('Lengua Castellana y Literatura', $saved->getSubject());
        self::assertSame(['A', 'C'], $saved->getSections(), 'las letras se guardan normalizadas');
        self::assertSame(30, $saved->getSuggestedCopies());
        self::assertSame(0, $saved->getTimesUsed());
        self::assertSame('2025-2026', $saved->getAcademicYear()->getSchoolYear());
    }

    public function testATaskCannotBeSavedWithoutSubject(): void
    {
        $maths = $this->department();
        $teacher = $this->login(unit: $maths);
        $this->taughtSubject('Matemáticas', $teacher);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/banco/nueva');
        $token = (string) $crawler->filter('input[name="guardia_task_bank_item[_token]"]')->attr('value');
        $this->client->request('POST', '/guardias/banco/nueva', ['guardia_task_bank_item' => [
            'level' => EducationLevel::ESO_2->value,
            'subject' => '',
            'department' => (string) $maths->getId(),
            'title' => 'Sin materia',
            'active' => '1',
            '_token' => $token,
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(GuardiaTaskBankItem::class)->findAll());
    }

    public function testTheCoveringTeacherPicksAGivenTaskForTheirGuardia(): void
    {
        $maths = $this->department();
        $guardia = $this->login('guardia@centro.test');
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente@centro.test');
        $this->em->persist($absent);
        $item = $this->bankItem($maths, EducationLevel::ESO_4, 'Ficha de repaso');
        $cover = $this->cover($absent, $guardia);
        $this->em->flush();
        $coverId = (int) $cover->getId();

        $crawler = $this->client->request('GET', '/guardias/banco?para='.$coverId);
        self::assertResponseIsSuccessful();
        // The level is suggested from the group name (E4D → 4º de ESO), no typing needed.
        self::assertSelectorTextContains('.bank-picking', '4º de ESO');

        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/guardias/banco/asignar/'.$coverId, ['item' => (string) $item->getId(), '_token' => $token]);

        self::assertResponseRedirects('/guardias/'.$coverId.'/ver');
        $reloaded = $this->reloadCover($coverId);
        self::assertNotNull($reloaded->getBankItem());
        self::assertSame('Ficha de repaso', $reloaded->getBankItem()->getTitle());
        self::assertTrue($reloaded->hasTask(), 'una guardia con tarea del banco ya no está "sin tarea"');
        self::assertSame(1, $reloaded->getBankItem()->getTimesUsed());
    }

    public function testTheRandomPickTakesOneOfTheLeastUsedTasksOfThatLevel(): void
    {
        $maths = $this->department();
        $guardia = $this->login('guardia@centro.test');
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente@centro.test');
        $this->em->persist($absent);
        $this->bankItem($maths, EducationLevel::ESO_4, 'Muy usada', timesUsed: 9);
        $fresh = $this->bankItem($maths, EducationLevel::ESO_4, 'Sin estrenar');
        $this->bankItem($maths, EducationLevel::BACH_1, 'De otro nivel');
        $this->bankItem($maths, EducationLevel::ESO_4, 'De otra materia', 'Lengua');
        $cover = $this->cover($absent, $guardia);
        $this->em->flush();
        $coverId = (int) $cover->getId();

        $crawler = $this->client->request('GET', '/guardias/banco?para='.$coverId);
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/guardias/banco/asignar/'.$coverId, ['_token' => $token]);

        self::assertResponseRedirects();
        $reloaded = $this->reloadCover($coverId);
        self::assertNotNull($reloaded->getBankItem());
        self::assertSame($fresh->getId(), $reloaded->getBankItem()->getId(), 'el azar reparte entre las menos usadas del nivel y la materia');
    }

    public function testTheCoordinatorPicksFromTheParteLineAndStaysOnTheParte(): void
    {
        $maths = $this->department();
        // La coordinación no cubre esta guardia ni es la ausente: lo que le deja actuar es el WRITE del área.
        $this->login('coordina@centro.test', coordinator: true);
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente@centro.test');
        $this->em->persist($absent);
        $this->bankItem($maths, EducationLevel::ESO_4, 'Ficha de repaso');
        // Nobody covering it yet: the coordinator is going down the period assigning work.
        $cover = $this->cover($absent, null);
        $this->em->flush();
        $coverId = (int) $cover->getId();

        $this->client->request('POST', '/guardias/banco/asignar/'.$coverId, [
            'volver' => 'parte',
            '_token' => (string) static::getContainer()->get('security.csrf.token_manager')->getToken('guardia_bank_apply'.$coverId),
        ]);

        // Back to the parte on the cover's own day and period, not to the guardia's detail.
        self::assertResponseRedirects('/guardias?date='.$this->year->getTerm2Start()->format('Y-m-d').'&slot=2');
        self::assertNotNull($this->reloadCover($coverId)->getBankItem());
    }

    public function testTheRandomPickRespectsSectionsRetiredTasksAndCourse(): void
    {
        // El listado ya se prueba aparte: esto fija que el SORTEO no puede sacar algo que la pantalla
        // no ofrece — si perdiera un filtro, aquí se caería.
        $maths = $this->department();
        $guardia = $this->login('guardia@centro.test');
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente@centro.test');
        $this->em->persist($absent);
        $lastYear = $this->academicYear('2019-2020');
        $this->em->persist($lastYear);

        $this->bankItem($maths, EducationLevel::ESO_4, 'Solo para A', sections: 'A');
        $this->bankItem($maths, EducationLevel::ESO_4, 'Retirada', active: false);
        $this->bankItem($maths, EducationLevel::ESO_4, 'De otra materia', 'Lengua');
        $this->bankItem($maths, EducationLevel::ESO_4, 'De otro curso')->setAcademicYear($lastYear);
        $valid = $this->bankItem($maths, EducationLevel::ESO_4, 'La única válida');
        $cover = $this->cover($absent, $guardia, group: 'E4D');
        $this->em->flush();
        $coverId = (int) $cover->getId();

        $this->client->request('POST', '/guardias/banco/asignar/'.$coverId, [
            '_token' => (string) static::getContainer()->get('security.csrf.token_manager')->getToken('guardia_bank_apply'.$coverId),
        ]);

        self::assertResponseRedirects();
        self::assertSame($valid->getId(), $this->reloadCover($coverId)->getBankItem()?->getId());
    }

    public function testATaskFromAnotherCourseCannotBeAttachedByHand(): void
    {
        $maths = $this->department();
        $guardia = $this->login('guardia@centro.test');
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente@centro.test');
        $this->em->persist($absent);
        $lastYear = $this->academicYear('2019-2020');
        $this->em->persist($lastYear);
        $old = $this->bankItem($maths, EducationLevel::ESO_4, 'Ficha del año pasado');
        $old->setAcademicYear($lastYear);
        $cover = $this->cover($absent, $guardia);
        $this->em->flush();
        $coverId = (int) $cover->getId();

        $this->client->request('POST', '/guardias/banco/asignar/'.$coverId, [
            'item' => (string) $old->getId(),
            '_token' => (string) static::getContainer()->get('security.csrf.token_manager')->getToken('guardia_bank_apply'.$coverId),
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->reloadCover($coverId)->getBankItem(), 'el banco se vacía cada septiembre: no se cuela lo del curso pasado');
    }

    public function testARandomPickWithNothingInTheBankSaysSoInsteadOfFailing(): void
    {
        $guardia = $this->login('guardia@centro.test');
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente@centro.test');
        $this->em->persist($absent);
        $cover = $this->cover($absent, $guardia);
        $this->em->flush();
        $coverId = (int) $cover->getId();

        $crawler = $this->client->request('GET', '/guardias/banco?para='.$coverId);
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/guardias/banco/asignar/'.$coverId, ['_token' => $token]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'no tiene ninguna tarea disponible');
        self::assertNull($this->reloadCover($coverId)->getBankItem());
    }

    public function testAnotherTeacherCannotPickATaskForSomeoneElsesGuardia(): void
    {
        $maths = $this->department();
        $covering = (new User())->setFullName('Quien cubre')->setEmail('cubre@centro.test');
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente@centro.test');
        $this->em->persist($covering);
        $this->em->persist($absent);
        $item = $this->bankItem($maths, EducationLevel::ESO_4, 'Ficha de repaso');
        $cover = $this->cover($absent, $covering);
        $this->em->flush();
        $coverId = (int) $cover->getId();

        // A teacher with nothing to do with that guardia (and no coordination role).
        $this->login('curioso@centro.test');

        $this->client->request('GET', '/guardias/banco?para='.$coverId);
        self::assertResponseStatusCodeSame(403);

        // Token VÁLIDO a propósito: con uno inválido el 403 lo daría el CSRF y este test seguiría verde
        // aunque el control de acceso desapareciera.
        $this->client->request('POST', '/guardias/banco/asignar/'.$coverId, [
            'item' => (string) $item->getId(),
            '_token' => (string) static::getContainer()->get('security.csrf.token_manager')->getToken('guardia_bank_apply'.$coverId),
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reloadCover($coverId)->getBankItem(), 'la guardia ajena queda intacta');
    }

    public function testABadCsrfTokenIsRejected(): void
    {
        $maths = $this->department();
        $guardia = $this->login('guardia@centro.test');
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente@centro.test');
        $this->em->persist($absent);
        $item = $this->bankItem($maths, EducationLevel::ESO_4, 'Ficha de repaso');
        $cover = $this->cover($absent, $guardia);
        $this->em->flush();
        $coverId = (int) $cover->getId();

        $this->client->request('POST', '/guardias/banco/asignar/'.$coverId, ['item' => (string) $item->getId(), '_token' => 'invalido']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reloadCover($coverId)->getBankItem());
    }

    public function testOnlyTheAuthorTheDepartmentHeadOrTheCoordinationMayEditAnEntry(): void
    {
        $maths = $this->department();
        $author = (new User())->setFullName('Autora')->setEmail('autora@centro.test');
        $this->em->persist($author);
        $item = $this->bankItem($maths, EducationLevel::ESO_1, 'Ficha ajena')->setCreatedBy($author);
        $this->em->flush();
        $itemId = (int) $item->getId();

        $this->login('otro@centro.test');
        $this->client->request('GET', '/guardias/banco/'.$itemId.'/editar');
        self::assertResponseStatusCodeSame(403);

        // The guardia coordination may curate the shared bank.
        $this->login('coordina@centro.test', coordinator: true);
        $this->client->request('GET', '/guardias/banco/'.$itemId.'/editar');
        self::assertResponseIsSuccessful();
    }

    public function testTheListingOnlyOffersEditWhereItIsAllowed(): void
    {
        $maths = $this->department();
        $author = (new User())->setFullName('Autora')->setEmail('autora@centro.test');
        $this->em->persist($author);
        $this->bankItem($maths, EducationLevel::ESO_1, 'Ficha ajena')->setCreatedBy($author);
        $this->em->flush();

        $this->login('otro@centro.test');
        $crawler = $this->client->request('GET', '/guardias/banco');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a:contains("Editar")'), 'no se enseña un enlace que daría 403');
    }
}
