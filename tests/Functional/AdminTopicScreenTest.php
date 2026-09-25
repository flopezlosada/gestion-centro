<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\Department;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\EducationLevel;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La pantalla de administración de temas: solo la alcanza quien es jefe o jefa de departamento (o manda en
 * todo el centro), cada jefe solo para las materias de SU departamento —las que más da su profesorado—,
 * pega una programación como filas por revisar, y guardarlas las crea de verdad.
 */
final class AdminTopicScreenTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private ?AcademicYear $year = null;

    private function headOfDepartment(): User
    {
        // OrganizationHierarchy::commandedDepartment() devuelve el DEPARTAMENTO PROPIO de quien tiene el
        // rol con rango por departamento, no un booleano: sin unidad asignada, un jefe de departamento de
        // pega no manda ningún departamento y el guardián lo rechazaría igual que a un docente cualquiera.
        $department = (new Department())->setCode('dept_'.uniqid())->setName('Departamento de prueba');
        $this->em->persist($department);
        $role = (new Role())->setCode('head_dept_'.uniqid())->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($role);
        $user = (new User())->setFullName('Jefa Depto')->setEmail('jefa.depto.'.uniqid().'@educa.madrid.org')->setUnit($department)->addAssignedRole($role);
        $this->em->persist($user);

        return $user;
    }

    /**
     * Puts a teacher in this course's timetable giving a subject to a group: what makes the subject belong
     * to the teacher's department. The course is the one running today, as the screen reads it.
     *
     * @param User   $teacher the teacher
     * @param string $subject the subject
     * @param string $group   the group (its level is read from it: «E3A» is 3º de ESO)
     */
    private function teaches(User $teacher, string $subject, string $group): void
    {
        if (null === $this->year) {
            $schoolYear = SchoolYear::current(new \DateTimeImmutable('today'));
            $start = (int) substr($schoolYear, 0, 4);
            $this->year = (new AcademicYear())
                ->setSchoolYear($schoolYear)
                ->setTerm1Start(new \DateTimeImmutable($start.'-09-15'))->setTerm1End(new \DateTimeImmutable($start.'-12-22'))
                ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
                ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-22'));
            $this->em->persist($this->year);
        }
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)
            ->setWeekday(Weekday::MONDAY)->setSlotIndex(0)
            ->setStartsAt(new \DateTimeImmutable('08:25'))->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::LECTIVE)
            ->setGroupName($group)->setRoomName('AULA 1')->setSubjectName($subject));
    }

    private function plainTeacher(): User
    {
        $role = (new Role())->setCode('teacher_'.uniqid())->setName('Docente');
        $this->em->persist($role);
        $user = (new User())->setFullName('Profe Llano')->setEmail('profe.llano.'.uniqid().'@educa.madrid.org')->addAssignedRole($role);
        $this->em->persist($user);

        return $user;
    }

    public function testAPlainTeacherCannotReachTheScreen(): void
    {
        $teacher = $this->plainTeacher();
        $this->em->flush();
        $this->client->loginUser($teacher);

        $this->client->request('GET', '/admin/temas/Matem%C3%A1ticas/eso3');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAHeadOfDepartmentPastesReviewsAndSavesATopicList(): void
    {
        $head = $this->headOfDepartment();
        $this->teaches($head, 'Matemáticas', 'E3A');
        $this->em->flush();

        $this->client->loginUser($head);
        $crawler = $this->client->request('GET', '/admin/temas/Matem%C3%A1ticas/eso3/editar');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Todavía no hay ningún tema');

        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/temas/Matem%C3%A1ticas/eso3/pegar', [
            '_token' => $token,
            'pegado' => "Tema 1. Números racionales\nUD 2 - Potencias\nNúmeros racionales",
        ]);
        $preview = $this->client->followRedirect();

        self::assertSelectorTextContains('.topic-pasted', '2 temas nuevos del pegado');
        $names = $preview->filter('input[name="tema_nombre[]"]')->each(static fn ($i) => (string) $i->attr('value'));
        self::assertSame(['Números racionales', 'Potencias'], $names);

        $this->client->submit($preview->selectButton('Guardar la lista')->form());

        self::assertResponseRedirects('/admin/temas/Matem%C3%A1ticas/eso3', null, 'guardar lleva a la ficha, con la lista ya guardada');
        $saved = $this->em->getRepository(Topic::class)->findBy(['subject' => 'Matemáticas', 'level' => EducationLevel::ESO_3], ['position' => 'ASC']);
        self::assertCount(2, $saved);
        self::assertSame('Números racionales', $saved[0]->getName());
        self::assertSame('Potencias', $saved[1]->getName());
    }

    /** Fusionar dos temas por la pantalla los junta y no se puede deshacer con un simple GET. */
    public function testMergingFromTheScreenCombinesTwoTopics(): void
    {
        $head = $this->headOfDepartment();
        $this->teaches($head, 'Matemáticas', 'E3A');
        $a = (new Topic('Matemáticas', EducationLevel::ESO_3, 'Ecuaciones', 1, $head));
        $b = (new Topic('Matemáticas', EducationLevel::ESO_3, 'ecuaciones de primer grado', 2, $head));
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $this->client->loginUser($head);
        $crawler = $this->client->request('GET', '/admin/temas/Matem%C3%A1ticas/eso3/editar');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/temas/Matem%C3%A1ticas/eso3/fusionar', [
            '_token' => $token,
            'desde' => (string) $a->getId(),
            'hacia' => (string) $b->getId(),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNull($this->em->getRepository(Topic::class)->find($a->getId()));
        self::assertNotNull($this->em->getRepository(Topic::class)->find($b->getId()));
    }

    /**
     * La ficha lee el temario en orden, con los retirados aparte: lo que el profesorado ve al programar y
     * lo que ya no se ofrece.
     */
    public function testTheShowPageReadsTheSyllabusInOrderWithTheRetiredApart(): void
    {
        $head = $this->headOfDepartment();
        $this->teaches($head, 'Matemáticas', 'E3A');
        $second = new Topic('Matemáticas', EducationLevel::ESO_3, 'Potencias', 2, $head);
        $first = new Topic('Matemáticas', EducationLevel::ESO_3, 'Números racionales', 1, $head);
        $gone = new Topic('Matemáticas', EducationLevel::ESO_3, 'Estadística vieja', 3, $head);
        $gone->retire();
        array_map($this->em->persist(...), [$second, $first, $gone]);
        $this->em->flush();

        $this->client->loginUser($head);
        $crawler = $this->client->request('GET', '/admin/temas/Matem%C3%A1ticas/eso3');

        self::assertResponseIsSuccessful();
        self::assertSame(['Números racionales', 'Potencias'], $crawler->filter('.syllabus__name')->each(static fn ($n) => $n->text()));
        self::assertSelectorTextContains('.syllabus-retired', 'Estadística vieja');
        self::assertSelectorTextContains('.topic-head__meta', '2 temas · 1 retirado');
    }

    /**
     * El número de cada fila es el orden: renumerar mueve el tema. Y «retirado» va por el tema, no por el
     * sitio de la fila, porque las filas se reordenan antes de enviar.
     */
    public function testRenumberingARowMovesItAndRetiredFollowsTheTopic(): void
    {
        $head = $this->headOfDepartment();
        $this->teaches($head, 'Matemáticas', 'E3A');
        $a = new Topic('Matemáticas', EducationLevel::ESO_3, 'Números racionales', 1, $head);
        $b = new Topic('Matemáticas', EducationLevel::ESO_3, 'Potencias', 2, $head);
        array_map($this->em->persist(...), [$a, $b]);
        $this->em->flush();

        $this->client->loginUser($head);
        $crawler = $this->client->request('GET', '/admin/temas/Matem%C3%A1ticas/eso3/editar');
        $form = $crawler->selectButton('Guardar la lista')->form();
        $values = $form->getPhpValues();
        $values['tema_orden'] = ['2', '1'];
        $values['tema_retirado'] = [(string) $b->getId()];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects();
        $this->em->clear();
        $saved = $this->em->getRepository(Topic::class)->findBy(['subject' => 'Matemáticas', 'level' => EducationLevel::ESO_3], ['position' => 'ASC']);
        self::assertSame(['Potencias', 'Números racionales'], array_map(static fn (Topic $t): string => $t->getName(), $saved));
        self::assertTrue($saved[0]->isRetired(), 'Potencias, retirado aunque ahora vaya primero');
        self::assertFalse($saved[1]->isRetired());
    }

    /**
     * Cada jefe, solo lo de su departamento: la materia que da otro departamento no la ve en su índice ni
     * la alcanza escribiendo la dirección.
     */
    public function testAHeadOnlyReachesTheirOwnDepartmentsSubjects(): void
    {
        $head = $this->headOfDepartment();
        $this->teaches($head, 'Matemáticas', 'E3A');
        $otherHead = $this->headOfDepartment();
        $this->teaches($otherHead, 'Lengua Castellana y Literatura', 'E3A');
        $this->em->flush();

        $this->client->loginUser($head);
        $crawler = $this->client->request('GET', '/admin/temas');

        self::assertResponseIsSuccessful();
        self::assertSame(['Matemáticas'], $crawler->filter('.topic-subject__name')->each(static fn ($n) => $n->text()), 'entra directo a su departamento, sin elegir');
        $this->client->request('GET', '/admin/temas/Lengua%20Castellana%20y%20Literatura/eso3');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/temas/Lengua%20Castellana%20y%20Literatura/eso3/editar');
        self::assertResponseStatusCodeSame(403);
    }

    /** Fusionar solo junta temas de la lista de la dirección: un id de otra materia no cuela. */
    public function testMergingRefusesATopicFromAnotherList(): void
    {
        $head = $this->headOfDepartment();
        $this->teaches($head, 'Matemáticas', 'E3A');
        $mine = new Topic('Matemáticas', EducationLevel::ESO_3, 'Ecuaciones', 1, $head);
        $foreign = new Topic('Lengua Castellana y Literatura', EducationLevel::ESO_3, 'La lírica', 1, $head);
        $foreign2 = new Topic('Lengua Castellana y Literatura', EducationLevel::ESO_3, 'El teatro', 2, $head);
        array_map($this->em->persist(...), [$mine, $foreign, $foreign2]);
        $this->em->flush();

        $this->client->loginUser($head);
        $crawler = $this->client->request('GET', '/admin/temas/Matem%C3%A1ticas/eso3/editar');
        $this->client->request('POST', '/admin/temas/Matem%C3%A1ticas/eso3/fusionar', [
            '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'),
            'desde' => (string) $foreign->getId(),
            'hacia' => (string) $foreign2->getId(),
        ]);

        self::assertResponseStatusCodeSame(404);
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Topic::class)->find($foreign->getId()));
    }

    /** Pegar un tema retirado lo avisa y lo deja retirado: recuperarlo es desmarcar su fila y guardar. */
    public function testPastingARetiredTopicWarnsAndLeavesItRetired(): void
    {
        $head = $this->headOfDepartment();
        $this->teaches($head, 'Matemáticas', 'E3A');
        $old = new Topic('Matemáticas', EducationLevel::ESO_3, 'Estadística', 1, $head);
        $old->retire();
        $this->em->persist($old);
        $this->em->flush();

        $this->client->loginUser($head);
        $crawler = $this->client->request('GET', '/admin/temas/Matem%C3%A1ticas/eso3/editar');
        $this->client->request('POST', '/admin/temas/Matem%C3%A1ticas/eso3/pegar', [
            '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'),
            'pegado' => "Tema 1. Estadística\nProbabilidad",
        ]);
        $preview = $this->client->followRedirect();

        self::assertSelectorTextContains('.topic-repasted', '«Estadística»');
        self::assertSelectorTextContains('.topic-row.is-repasted', 'Estaba retirado');
        self::assertNotNull($preview->filter('.topic-row.is-repasted input[name="tema_retirado[]"]')->attr('checked'), 'sigue marcado como retirado');
        $this->em->clear();
        self::assertTrue($this->em->getRepository(Topic::class)->find($old->getId())?->isRetired());
    }

    /** El índice muestra los ámbitos del horario en curso, con su recuento de temas. */
    public function testIndexListsScopesFromTheCurrentTimetable(): void
    {
        $head = $this->headOfDepartment();
        $this->teaches($head, 'Matemáticas', 'E3A');
        $this->em->flush();

        $this->client->loginUser($head);
        $this->client->request('GET', '/admin/temas');

        self::assertResponseIsSuccessful();
    }
}
