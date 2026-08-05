<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\Substitution;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La pantalla de sustituciones de baja larga: dar de alta a quien cubre una baja, y cerrarla cuando la
 * persona vuelve. Reservada a quien administra; una docente cualquiera no llega a la sección.
 */
final class AdminSubstitutionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AcademicYear $year;
    private User $substituted;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // El curso al que pertenece el horario, con el año escolar en el que cae "hoy": la pantalla lo
        // resuelve por la fecha, así que atarlo a un año fijo la dejaría vacía cada septiembre.
        $today = new \DateTimeImmutable('today');
        $start = (int) $today->format('n') >= 9 ? (int) $today->format('Y') : (int) $today->format('Y') - 1;
        $this->year = (new AcademicYear())
            ->setSchoolYear($start.'-'.($start + 1))
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-15'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-19'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-23'));
        $this->em->persist($this->year);

        $this->substituted = (new User())->setFullName('Elena Titular')->setEmail('elena@centro.test');
        $this->em->persist($this->substituted);
        $this->em->persist(
            (new ScheduleEntry())
                ->setAcademicYear($this->year)
                ->setTeacher($this->substituted)
                ->setWeekday(Weekday::MONDAY)
                ->setSlotIndex(0)
                ->setStartsAt(new \DateTimeImmutable('08:25'))
                ->setEndsAt(new \DateTimeImmutable('09:20'))
                ->setKind(ScheduleActivityKind::LECTIVE)
                ->setGroupName('1ºA'),
        );
        $this->em->flush();
    }

    public function testAdminRegistersASubstitutionAndTheTimetableChangesHands(): void
    {
        $this->client->loginUser($this->admin());

        $substitutedId = (int) $this->substituted->getId();
        $crawler = $this->client->request('GET', '/admin/sustituciones');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Dar de alta y traspasar')->form();
        $form['substitution[substitutedTeacher]'] = (string) $this->substituted->getId();
        $form['substitution[substituteName]'] = 'Sara Sustituta';
        $form['substitution[substituteEmail]'] = 'sara@centro.test';
        $form['substitution[startedOn]'] = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();

        $substitute = $this->em->getRepository(User::class)->findOneBy(['email' => 'sara@centro.test']);
        self::assertInstanceOf(User::class, $substitute, 'quien sustituye se da de alta con su cuenta');
        self::assertCount(1, $this->em->getRepository(ScheduleEntry::class)->findBy(['teacher' => $substitute]));
        self::assertCount(0, $this->em->getRepository(ScheduleEntry::class)->findBy(['teacher' => $substitutedId]));
    }

    public function testAnExistingAccountIsReusedInsteadOfDuplicated(): void
    {
        // Quien sustituye suele ser alguien que ya estuvo en el centro. Crear una segunda cuenta con su
        // correo chocaría con el UNIQUE, y un flush que falla cierra el EntityManager: no habría forma
        // de recuperarse dentro de la petición.
        $this->client->loginUser($this->admin());
        $veteran = (new User())->setFullName('Sara Sustituta')->setEmail('sara@centro.test')->setActive(false);
        $this->em->persist($veteran);
        $this->em->flush();
        $veteranId = (int) $veteran->getId();

        $crawler = $this->client->request('GET', '/admin/sustituciones');
        $form = $crawler->selectButton('Dar de alta y traspasar')->form();
        $form['substitution[substitutedTeacher]'] = (string) $this->substituted->getId();
        $form['substitution[substituteName]'] = 'Sara Sustituta';
        $form['substitution[substituteEmail]'] = 'sara@centro.test';
        $form['substitution[startedOn]'] = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();

        self::assertCount(1, $this->em->getRepository(User::class)->findBy(['email' => 'sara@centro.test']));
        $reused = $this->em->getRepository(User::class)->find($veteranId);
        self::assertInstanceOf(User::class, $reused);
        self::assertTrue($reused->isActive(), 'y se reactiva: una sustitución con alguien que no puede entrar no sirve de nada');
    }

    public function testClosingReturnsTheTimetable(): void
    {
        $this->client->loginUser($this->admin());
        $substitute = (new User())->setFullName('Sara Sustituta')->setEmail('sara@centro.test');
        $this->em->persist($substitute);
        $substitution = (new Substitution())
            ->setAcademicYear($this->year)
            ->setSubstitutedTeacher($this->substituted)
            ->setSubstitute($substitute)
            ->setStartedOn(new \DateTimeImmutable('today'));
        $this->em->persist($substitution);
        $this->em->flush();
        $id = (int) $substitution->getId();

        $crawler = $this->client->request('GET', '/admin/sustituciones');
        $this->client->submit($crawler->filter('form[action="/admin/sustituciones/'.$id.'/cerrar"]')->form());

        self::assertResponseRedirects();
        $this->em->clear();
        $closed = $this->em->getRepository(Substitution::class)->find($id);
        self::assertInstanceOf(Substitution::class, $closed);
        self::assertFalse($closed->isOpen());
    }

    public function testAPlainTeacherCannotReachTheSection(): void
    {
        $teacher = (new User())->setFullName('Docente Test')->setEmail('docente@centro.test');
        $this->em->persist($teacher);
        $this->em->flush();
        $this->client->loginUser($teacher);

        $this->client->request('GET', '/admin/sustituciones');

        self::assertResponseStatusCodeSame(403);
    }

    /** Una persona que administra. */
    private function admin(): User
    {
        $role = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        $this->em->persist($role);
        $user = (new User())->setFullName('Directora Test')->setEmail('director@centro.test')->addAssignedRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
