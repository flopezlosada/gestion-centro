<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phased roll-out of the application: the /admin/acceso screen (reserved to superusers) and what
 * closing the door does to the people already inside.
 *
 * The two are deliberately different: closing sign-in globally only stops new sessions, while
 * revoking one person's access ends theirs on their next page.
 */
final class AccessControlTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AppSettings $settings;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->settings = self::getContainer()->get(AppSettings::class);
    }

    public function testTheAccessScreenIsReservedToSuperusers(): void
    {
        // Write access to Administration is enough for the rest of /admin, but not for the door.
        $this->client->loginUser($this->administrationManager());

        $this->client->request('GET', '/admin/acceso');

        self::assertResponseStatusCodeSame(403);
    }

    public function testASuperuserSeesTheAccessScreen(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('GET', '/admin/acceso');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Acceso a la aplicación');
    }

    public function testSavingClosesSignInAndRecordsTheRollOutList(): void
    {
        $this->client->loginUser($this->admin());
        $insideId = $this->teacher('beta@centro.test')->getId();
        $outsideId = $this->teacher('espera@centro.test')->getId();

        $crawler = $this->client->request('GET', '/admin/acceso');
        $form = $crawler->selectButton('Guardar')->form();
        // The switch renders ticked (sign-in starts open), so closing it means unticking it: the
        // browser would otherwise post it back as it stands.
        $form['login_open']->untick();
        $form['early['.$insideId.']']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/acceso');
        $this->em->clear();
        self::assertFalse(self::getContainer()->get(AppSettings::class)->isLoginOpen());
        self::assertTrue($this->reload($insideId)->hasEarlyAccess());
        self::assertFalse($this->reload($outsideId)->hasEarlyAccess(), 'quien no se marca se queda fuera');
    }

    public function testClosingSignInLeavesTheAlreadySignedInAlone(): void
    {
        // The whole point of a phased roll-out: flipping the switch must not yank the application
        // from under whoever is working right now.
        $teacher = $this->teacher('trabajando@centro.test');
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->settings->setLoginOpen(false);

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testWithdrawingSomeonesEarlyAccessEndsTheirSession(): void
    {
        $this->settings->setLoginOpen(false);
        $teacher = $this->teacher('revocada@centro.test')->setEarlyAccess(true);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $teacher->setEarlyAccess(false);
        $this->em->flush();

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testDeactivatingAnAccountEndsItsSessionEvenWhileSignInIsOpen(): void
    {
        $teacher = $this->teacher('baja@centro.test');
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $teacher->setActive(false);
        $this->em->flush();

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testAnAdministratorKeepsWorkingWithSignInClosed(): void
    {
        $admin = $this->admin();
        $this->client->loginUser($admin);

        $this->settings->setLoginOpen(false);

        $this->client->request('GET', '/admin/acceso');
        self::assertResponseIsSuccessful();
    }

    /**
     * Re-reads a user from the database, so an assertion cannot pass on a stale in-memory object.
     *
     * @param int|null $id the user's id
     *
     * @return User the freshly loaded user
     */
    private function reload(?int $id): User
    {
        $reloaded = $this->em->getRepository(User::class)->find($id);
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }

    /**
     * A superuser: holds a role with the admin flag, so ROLE_ADMIN.
     *
     * @return User the persisted administrator
     */
    private function admin(): User
    {
        $role = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        $this->em->persist($role);
        $user = (new User())->setFullName('Directora Test')->setEmail('director@centro.test')->addAssignedRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Someone who can manage the back-office through the permission matrix but is NOT a superuser.
     *
     * @return User the persisted manager
     */
    private function administrationManager(): User
    {
        $role = (new Role())->setCode('secretaria')->setName('Secretaría')->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE);
        $this->em->persist($role);
        $user = (new User())->setFullName('Secretaria Test')->setEmail('secretaria@centro.test')->addAssignedRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * A plain active user with no roles.
     *
     * @param string $email the address, unique per test
     *
     * @return User the persisted user
     */
    private function teacher(string $email): User
    {
        $user = (new User())->setFullName('Docente Test')->setEmail($email);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
