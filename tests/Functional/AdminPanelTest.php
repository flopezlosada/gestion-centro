<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
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

    /**
     * A user who manages the administration area through the permission matrix (write access) but is
     * NOT a superuser: no admin flag. This is how Direction runs the centre without ROLE_ADMIN.
     */
    private function directionNonSuperuser(): User
    {
        $role = (new Role())->setCode('direction')->setName('Dirección')->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE);
        $this->em->persist($role);
        $user = (new User())->setFullName('Directora Test')->setEmail('direccion@centro.test')->addAssignedRole($role);
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

    public function testDirectionWithAdministrationWriteReachesUserAdmin(): void
    {
        // No admin flag: access comes from the ADMINISTRATION area write level via the AreaVoter.
        $this->client->loginUser($this->directionNonSuperuser());

        $this->client->request('GET', '/admin/usuarios');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Usuarios');
    }

    public function testDirectionNonSuperuserSeesAdminNav(): void
    {
        $this->client->loginUser($this->directionNonSuperuser());

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nav-section-title', 'Administración');
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
}
