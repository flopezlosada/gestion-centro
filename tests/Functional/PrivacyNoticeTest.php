<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PrivacyNotice;
use App\Entity\PrivacyNoticeAck;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The data-protection information: nobody gets past it without reading the version in force, a new
 * version asks everyone again, and nothing is asked while none has been published.
 */
final class PrivacyNoticeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Until the centre publishes a text, the application works as before: an empty screen must never
     * stand at the door.
     */
    public function testNothingIsAskedWhileNoVersionIsPublished(): void
    {
        $this->client->loginUser($this->user('profe@centro.test'));

        $this->client->request('GET', '/tareas');

        self::assertResponseIsSuccessful();
    }

    /**
     * With a version in force, any page sends the person to read it, remembering where they were going,
     * and "Entendido" records the reading and takes them there.
     */
    public function testAPersonMustReadTheCurrentVersionBeforeGoingOn(): void
    {
        $user = $this->user('profe@centro.test');
        $notice = $this->publish($this->user('tic@centro.test'), 'Resumen de prueba');
        $this->client->loginUser($user);

        $this->client->request('GET', '/tareas');
        self::assertResponseRedirects('/proteccion-datos/leer?volver=/tareas');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.privacy-doc__summary', 'Resumen de prueba');
        $this->client->submitForm('Entendido');

        self::assertResponseRedirects('/tareas');
        $acks = $this->em->getRepository(PrivacyNoticeAck::class)->findBy(['user' => $user, 'notice' => $notice]);
        self::assertCount(1, $acks);

        $this->client->request('GET', '/tareas');
        self::assertResponseIsSuccessful();
    }

    /**
     * A new version asks again, even of someone who read the previous one — and keeps that earlier
     * reading on record.
     */
    public function testANewVersionAsksAgain(): void
    {
        $user = $this->user('profe@centro.test');
        $publisher = $this->user('tic@centro.test');
        $first = $this->publish($publisher, 'Primera');
        $this->em->persist(new PrivacyNoticeAck($user, $first, new \DateTimeImmutable('-1 day')));
        $this->em->flush();
        $this->publish($publisher, 'Segunda');
        $this->client->loginUser($user);

        $this->client->request('GET', '/tareas');

        self::assertResponseRedirects('/proteccion-datos/leer?volver=/tareas');
        self::assertCount(1, $this->em->getRepository(PrivacyNoticeAck::class)->findBy(['user' => $user]));
    }

    /**
     * A script's request is refused, not redirected: a redirect would hand fetch() the reading page as
     * a 200, and the script would believe its call had worked.
     */
    public function testAScriptRequestIsRefusedInsteadOfRedirected(): void
    {
        $this->publish($this->user('tic@centro.test'), 'Resumen');
        $this->client->loginUser($this->user('profe@centro.test'));

        $this->client->request('POST', '/tareas', [], [], ['HTTP_SEC_FETCH_MODE' => 'cors']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The copy linked from the sign-in page opens without signing in.
     */
    public function testThePublicCopyOpensWithoutSigningIn(): void
    {
        $this->publish($this->user('tic@centro.test'), 'Texto visible sin entrar');

        $this->client->request('GET', '/proteccion-datos');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.privacy-doc__summary', 'Texto visible sin entrar');
    }

    /**
     * "Where to go afterwards" only accepts a path on this site: anything else would turn the screen
     * into an open redirect.
     */
    public function testTheWayBackCannotLeaveTheSite(): void
    {
        $this->publish($this->user('tic@centro.test'), 'Resumen');
        $this->client->loginUser($this->user('profe@centro.test'));

        $this->client->request('GET', '/proteccion-datos/leer?volver=//evil.example');
        $this->client->submitForm('Entendido');

        self::assertResponseRedirects('/');
    }

    /**
     * Only a superuser publishes: a version stops the whole staff at the door.
     */
    public function testOnlyASuperuserPublishes(): void
    {
        $this->client->loginUser($this->user('profe@centro.test'));

        $this->client->request('GET', '/admin/proteccion-datos');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Publishing creates a new version and never touches the one in force, so every past reading keeps
     * pointing at the text that was actually read.
     */
    public function testPublishingAddsAVersionWithoutEditingTheCurrentOne(): void
    {
        $admin = $this->admin();
        $first = $this->publish($admin, 'Primera');
        $this->em->persist(new PrivacyNoticeAck($admin, $first, new \DateTimeImmutable()));
        $this->em->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/proteccion-datos');
        $this->client->submitForm('Publicar', ['summary' => 'Segunda', 'body' => 'Completa segunda']);

        self::assertResponseRedirects('/admin/proteccion-datos');
        $this->em->clear();
        $notices = $this->em->getRepository(PrivacyNotice::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $notices);
        self::assertSame('Primera', $notices[0]->getSummary());
        self::assertSame('Segunda', $notices[1]->getSummary());
    }

    /**
     * A person, persisted.
     *
     * @param string $email the address, unique per test
     *
     * @return User the persisted user
     */
    private function user(string $email): User
    {
        $user = (new User())->setFullName('Persona '.$email)->setEmail($email);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * A superuser: holds a role with the admin flag, so ROLE_ADMIN.
     *
     * @return User the persisted superuser
     */
    private function admin(): User
    {
        $role = (new Role())->setCode('tic')->setName('TIC')->setAdmin(true);
        $this->em->persist($role);
        $user = (new User())->setFullName('Admin')->setEmail('admin@centro.test')->addAssignedRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Publishes a version straight in the database.
     *
     * @param User   $publisher who publishes it
     * @param string $summary   its short text
     *
     * @return PrivacyNotice the persisted version
     */
    private function publish(User $publisher, string $summary): PrivacyNotice
    {
        $notice = new PrivacyNotice($summary, 'Información completa', $publisher, new \DateTimeImmutable());
        $this->em->persist($notice);
        $this->em->flush();

        return $notice;
    }
}
