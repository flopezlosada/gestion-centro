<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The projects back-office: the direction team creates a project, names its coordinator and puts teachers
 * in it. Gated like every other /admin screen by write access to the Administration area — NOT by
 * security.yaml, which leaves /admin at ROLE_USER, so a missing gate would let any logged-in teacher in.
 */
final class AdminProjectTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $name, string $email, ?Role $role = null): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        if (null !== $role) {
            $user->addAssignedRole($role);
        }
        $this->em->persist($user);

        return $user;
    }

    private function role(string $code, ?PermissionLevel $administration = null): Role
    {
        $role = (new Role())->setCode($code)->setName(ucfirst($code));
        if (null !== $administration) {
            $role->setLevel(Area::ADMINISTRATION, $administration);
        }
        $this->em->persist($role);

        return $role;
    }

    public function testAPlainTeacherCannotReachTheProjectsBackOffice(): void
    {
        $docente = $this->user('Pedro Docente', 'pedro.proj@centro.test', $this->role('teacher-proj'));
        $this->em->flush();
        $this->client->loginUser($docente);

        $this->client->request('GET', '/admin/proyectos');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDirectionCreatesAProjectWithItsCoordinatorAndTeachers(): void
    {
        $director = $this->user('Ana Directora', 'ana.proj@centro.test', $this->role('direction-proj', PermissionLevel::WRITE));
        $coordinator = $this->user('Lucía Coordina', 'lucia.proj@centro.test');
        $member = $this->user('Pedro Miembro', 'pedro2.proj@centro.test');
        $this->em->flush();
        $this->client->loginUser($director);

        $crawler = $this->client->request('GET', '/admin/proyectos/nuevo');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Guardar')->form();
        $values = $form->getPhpValues();
        $values['project']['name'] = 'Huerto escolar';
        $values['project']['coordinator'] = (string) $coordinator->getId();
        $values['project']['members'] = [(string) $member->getId()];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects('/admin/proyectos');
        $this->em->clear();
        $project = $this->em->getRepository(Project::class)->findOneBy(['name' => 'Huerto escolar']);
        self::assertInstanceOf(Project::class, $project);
        self::assertSame($coordinator->getId(), $project->getCoordinator()?->getId());
        self::assertCount(1, $project->getMembers());
        self::assertTrue($project->isActive(), 'un proyecto nuevo está en marcha');
    }

    public function testTheProjectRecordListsItsMeetingsAndActas(): void
    {
        $director = $this->user('Ana Directora', 'ana2.proj@centro.test', $this->role('direction2-proj', PermissionLevel::WRITE));
        $coordinator = $this->user('Lucía Coordina', 'lucia2.proj@centro.test');
        $project = (new Project())->setName('Plan digital')->setCoordinator($coordinator);
        $this->em->persist($project);
        $this->em->flush();

        $this->client->loginUser($director);
        $this->client->request('GET', '/admin/proyectos/'.$project->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Plan digital');
        self::assertSelectorTextContains('body', 'Reuniones y actas');
    }
}
