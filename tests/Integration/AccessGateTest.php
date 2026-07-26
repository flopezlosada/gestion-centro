<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Role;
use App\Entity\User;
use App\Enum\AccessDenial;
use App\Security\AccessGate;
use App\Security\UserChecker;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * The rule that decides who may be in the application, exercised against the real settings store
 * (the switch lives in the database, so a test with a mocked one would not prove it round-trips).
 */
final class AccessGateTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AppSettings $settings;
    private AccessGate $gate;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->settings = self::getContainer()->get(AppSettings::class);
        $this->gate = self::getContainer()->get(AccessGate::class);
    }

    public function testSignInIsOpenByDefaultSoADeployNeverLocksAnybodyOut(): void
    {
        // No row written: the code default must be "open".
        self::assertTrue($this->settings->isLoginOpen());
        self::assertTrue($this->gate->allows($this->user('abierto@centro.test')));
    }

    public function testWhileOpenEveryActiveUserGetsInWithoutEarlyAccess(): void
    {
        $this->settings->setLoginOpen(true);
        $user = $this->user('docente@centro.test');

        self::assertFalse($user->hasEarlyAccess());
        self::assertNull($this->gate->denialFor($user));
    }

    public function testADeactivatedAccountIsTurnedAwayEvenWhileOpen(): void
    {
        $this->settings->setLoginOpen(true);
        $user = $this->user('baja@centro.test')->setActive(false);

        self::assertSame(AccessDenial::ACCOUNT_DISABLED, $this->gate->denialFor($user));
    }

    public function testWhileClosedAPlainUserIsTurnedAway(): void
    {
        $this->settings->setLoginOpen(false);

        self::assertSame(AccessDenial::ACCESS_CLOSED, $this->gate->denialFor($this->user('fuera@centro.test')));
    }

    public function testWhileClosedEarlyAccessGetsIn(): void
    {
        $this->settings->setLoginOpen(false);
        $user = $this->user('beta@centro.test')->setEarlyAccess(true);

        self::assertNull($this->gate->denialFor($user));
    }

    public function testAnAdministratorGetsInWhileClosedWithoutEarlyAccess(): void
    {
        // The lifeline: whoever can reopen sign-in must never be locked out by closing it.
        $this->settings->setLoginOpen(false);
        $admin = $this->admin('jefatura@centro.test');

        self::assertFalse($admin->hasEarlyAccess());
        self::assertNull($this->gate->denialFor($admin));
    }

    public function testAnAdministratorIsStillSubjectToBeingDeactivated(): void
    {
        // Being exempt from the global switch is not being exempt from the allow-list: deactivating
        // an account is a deliberate decision about one person and must hold for anybody.
        $this->settings->setLoginOpen(true);
        $admin = $this->admin('exdirector@centro.test')->setActive(false);

        self::assertSame(AccessDenial::ACCOUNT_DISABLED, $this->gate->denialFor($admin));
    }

    public function testTheUserCheckerRefusesToAuthenticateSomeoneTheGateDenies(): void
    {
        $this->settings->setLoginOpen(false);
        // Built by hand rather than pulled from the container: the checker is referenced only by
        // the firewall, so the test container is not guaranteed to still expose it.
        $checker = new UserChecker($this->gate);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $checker->checkPreAuth($this->user('sinacceso@centro.test'));
    }

    public function testChangingAccessStampsTheUserSoOpenSessionsCanBeRechecked(): void
    {
        $user = $this->user('sellada@centro.test');
        self::assertNull($user->getAccessChangedAt(), 'Un usuario recién creado no ha tenido cambios de acceso.');

        // A no-op write must not invalidate anybody's session.
        $user->setActive(true);
        self::assertNull($user->getAccessChangedAt());

        $user->setEarlyAccess(true);
        self::assertNotNull($user->getAccessChangedAt());
    }

    /**
     * A plain active user, persisted.
     *
     * @param string $email the address, unique per test
     *
     * @return User the persisted user
     */
    private function user(string $email): User
    {
        $user = (new User())->setFullName('Docente Test')->setEmail($email);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * A user holding an admin-flagged role (so ROLE_ADMIN), persisted.
     *
     * @param string $email the address, unique per test
     *
     * @return User the persisted administrator
     */
    private function admin(string $email): User
    {
        $role = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        $this->em->persist($role);
        $user = (new User())->setFullName('Directora Test')->setEmail($email)->addAssignedRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
