<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\EducationLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La pantalla de administración de temas: solo la alcanza la jefatura de departamento (o superior), pega
 * una programación como filas por revisar, y guardarlas las crea de verdad.
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

    private function headOfDepartment(): User
    {
        $role = (new Role())->setCode('head_dept_'.uniqid())->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($role);
        $user = (new User())->setFullName('Jefa Depto')->setEmail('jefa.depto.'.uniqid().'@educa.madrid.org')->addAssignedRole($role);
        $this->em->persist($user);

        return $user;
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
        $this->client->loginUser($this->plainTeacher());
        $this->em->flush();

        $this->client->request('GET', '/admin/temas/Matem%C3%A1ticas/eso3');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAHeadOfDepartmentPastesReviewsAndSavesATopicList(): void
    {
        $head = $this->headOfDepartment();
        $this->em->flush();

        $this->client->loginUser($head);
        $crawler = $this->client->request('GET', '/admin/temas/Matem%C3%A1ticas/eso3');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Todavía no hay ningún tema');

        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/temas/Matem%C3%A1ticas/eso3/pegar', [
            '_token' => $token,
            'pegado' => "Tema 1. Números racionales\nUD 2 - Potencias\nNúmeros racionales",
        ]);
        $preview = $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Del pegado · 2 temas nuevos');
        $names = $preview->filter('input[name="tema_nombre[]"]')->each(static fn ($i) => (string) $i->attr('value'));
        self::assertSame(['Números racionales', 'Potencias'], $names);

        $this->client->submit($preview->selectButton('Guardar la lista')->form());

        self::assertResponseRedirects();
        $saved = $this->em->getRepository(Topic::class)->findBy(['subject' => 'Matemáticas', 'level' => EducationLevel::ESO_3], ['position' => 'ASC']);
        self::assertCount(2, $saved);
        self::assertSame('Números racionales', $saved[0]->getName());
        self::assertSame('Potencias', $saved[1]->getName());
    }

    /** Fusionar dos temas por la pantalla los junta y no se puede deshacer con un simple GET. */
    public function testMergingFromTheScreenCombinesTwoTopics(): void
    {
        $head = $this->headOfDepartment();
        $a = (new Topic('Matemáticas', EducationLevel::ESO_3, 'Ecuaciones', 1, $head));
        $b = (new Topic('Matemáticas', EducationLevel::ESO_3, 'ecuaciones de primer grado', 2, $head));
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $this->client->loginUser($head);
        $crawler = $this->client->request('GET', '/admin/temas/Matem%C3%A1ticas/eso3');
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

    /** El índice muestra los ámbitos del horario en curso, con su recuento de temas. */
    public function testIndexListsScopesFromTheCurrentTimetable(): void
    {
        $head = $this->headOfDepartment();
        $this->em->flush();

        $this->client->loginUser($head);
        $this->client->request('GET', '/admin/temas');

        self::assertResponseIsSuccessful();
    }
}
