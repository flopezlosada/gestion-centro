<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Contract\AuditRedacted;
use App\Entity\AuditLog;
use App\Entity\FamilyActivity;
use App\Entity\FamilyVolunteer;
use App\Entity\Role;
use App\Entity\User;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * El espacio de familias: cuentas que no son del personal, con un firewall propio. Lo que importa es la
 * separación en los dos sentidos —una familia no entra en la app del personal, ni con su sesión ni con
 * un enlace del personal— y que borrar la cuenta corte el acceso y no deje sus datos en la auditoría.
 */
final class FamilyAreaTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $organizers = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(AppSettings::class)->setFamilyActivitiesEnabled(true);
    }

    public function testWithTheModuleOffNothingOfTheFamiliesAreaAnswers(): void
    {
        self::getContainer()->get(AppSettings::class)->setFamilyActivitiesEnabled(false);
        $this->client->loginUser($this->admin());

        $this->client->request('GET', '/familias/entrar');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/actividades-familias/cuentas');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheFamiliesAreaAsksForTheFamilyRoleExceptToSignIn(): void
    {
        $accessMap = self::getContainer()->get('security.access_map');
        \assert($accessMap instanceof AccessMapInterface);

        self::assertSame([FamilyVolunteer::ROLE], $accessMap->getPatterns(Request::create('/familias'))[0]);
        self::assertSame(['PUBLIC_ACCESS'], $accessMap->getPatterns(Request::create('/familias/entrar'))[0]);
        self::assertSame(['PUBLIC_ACCESS'], $accessMap->getPatterns(Request::create('/familias/entrar/check'))[0]);
    }

    public function testAFamilySignsInWithTheLinkAndSeesWhatIsComing(): void
    {
        $this->family('marta@gmail.test');
        $this->activity('+10 days', 'Grupos interactivos de 1ºA');
        $this->activity('-10 days', 'Taller ya celebrado');
        $this->em->flush();

        $this->client->request('POST', '/familias/entrar', ['email' => 'Marta@Gmail.test ']);
        self::assertResponseIsSuccessful();
        $link = $this->sentLink();
        self::assertStringContainsString('/familias/entrar/check', $link);
        self::assertStringContainsString('el espacio de familias', $this->lastEmail()->getTextBody() ?? '');

        $this->follow($link);
        self::assertResponseRedirects('/familias');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Grupos interactivos de 1ºA');
        self::assertSelectorTextNotContains('main', 'Taller ya celebrado');
    }

    /**
     * The sign-in log is a file kept for days, out of reach of removing the account: a family's address
     * is masked in it, in the accepted line and in the raw address of a refused one.
     */
    public function testTheSignInLogMasksAFamilysAddress(): void
    {
        $this->family('marta.log@gmail.test');
        $this->em->flush();
        $this->client->request('POST', '/familias/entrar', ['email' => 'marta.log@gmail.test']);
        $link = $this->sentLink();

        // The kernel reboots between requests, and the log handler with it: read it after each one.
        $this->follow($link);
        $this->assertLoggedWithoutTheAddress('login link accepted', 'marta.log');
        $this->follow($link);
        $this->assertLoggedWithoutTheAddress('login link refused', 'marta.log');
    }


    public function testAnUnknownAddressGetsTheSameAnswerAndNoEmail(): void
    {
        $this->client->request('POST', '/familias/entrar', ['email' => 'nadie@gmail.test']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.auth-lead', 'nadie@gmail.test');
        self::assertEmailCount(0);
    }

    /**
     * A staff address typed in the families' sign-in gets nothing: the lists are separate.
     */
    public function testAStaffAddressGetsNoLinkFromTheFamiliesSignIn(): void
    {
        $this->user('profe@centro.test');
        $this->em->flush();

        $this->client->request('POST', '/familias/entrar', ['email' => 'profe@centro.test']);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
    }

    public function testASignedInFamilyCannotOpenTheStaffArea(): void
    {
        $family = $this->family('marta@gmail.test');
        $this->em->flush();
        $this->signInAsFamily($family);

        foreach (['/', '/guardias', '/actividades-familias', '/admin/usuarios'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/login', null, $path);
        }
    }

    public function testASignedInTeacherCannotOpenTheFamiliesArea(): void
    {
        $this->client->loginUser($this->user('profe@centro.test'));

        $this->client->request('GET', '/familias');

        self::assertResponseRedirects('/familias/entrar');
    }

    /**
     * The signature of a link covers neither the account class nor the firewall. With one key for both,
     * a staff link would open the families' area for an account with the same e-mail and the same id; the
     * families' firewall has its own key so that it does not.
     */
    public function testAStaffLinkDoesNotOpenTheFamiliesArea(): void
    {
        $teacher = $this->user('doble@centro.test');
        $this->em->flush();
        // Same address and same id, the only case where the signatures could match.
        $this->em->getConnection()->insert('family_volunteer', [
            'id' => $teacher->getId(),
            'full_name' => 'Doble Familia',
            'email' => 'doble@centro.test',
            'created_at' => '2026-10-18 10:00:00',
        ]);

        $this->client->request('POST', '/login', ['email' => 'doble@centro.test']);
        $staffLink = $this->sentLink();
        self::assertStringContainsString('/login/check', $staffLink);

        $this->follow(str_replace('/login/check', '/familias/entrar/check', $staffLink));

        // Refused, and back on the families' sign-in (not the staff one) with the reason shown.
        self::assertResponseRedirects('/familias/entrar');
        $this->client->followRedirect();
        self::assertSelectorExists('.auth-card .form-error');
        $this->client->request('GET', '/familias');
        self::assertResponseRedirects('/familias/entrar');
    }

    public function testARemovedFamilyIsSignedOutOnTheNextClick(): void
    {
        $family = $this->family('marta@gmail.test');
        $this->em->flush();
        $this->signInAsFamily($family);
        $this->client->request('GET', '/familias');
        self::assertResponseIsSuccessful();

        $this->em->remove($this->em->find(FamilyVolunteer::class, $family->getId()));
        $this->em->flush();

        $this->client->request('GET', '/familias');
        self::assertResponseRedirects('/familias/entrar');
    }

    public function testAdministrationGivesAFamilyAnAccount(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/actividades-familias/cuentas/nueva');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Guardar')->form();
        $form['family_volunteer_form[fullName]'] = '  Marta, madre de Lucía (2º B) ';
        $form['family_volunteer_form[email]'] = 'Marta@Gmail.test';
        $this->client->submit($form);

        self::assertResponseRedirects('/actividades-familias/cuentas');
        $this->em->clear();
        $family = $this->em->getRepository(FamilyVolunteer::class)->findOneBy(['email' => 'marta@gmail.test']);
        self::assertNotNull($family);
        self::assertSame('Marta, madre de Lucía (2º B)', $family->getFullName());
        self::assertSame([FamilyVolunteer::ROLE], $family->getRoles());
    }

    public function testAnAccountWithoutNameOrEmailIsRefused(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/actividades-familias/cuentas/nueva');
        $this->client->submit($crawler->selectButton('Guardar')->form());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em->getRepository(FamilyVolunteer::class)->count([]));
    }

    public function testTwoAccountsCannotShareAnAddress(): void
    {
        $this->family('marta@gmail.test');
        $this->em->flush();
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/actividades-familias/cuentas/nueva');
        $form = $crawler->selectButton('Guardar')->form();
        $form['family_volunteer_form[fullName]'] = 'Otra persona';
        $form['family_volunteer_form[email]'] = 'MARTA@gmail.test';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Ya hay una familia con ese correo.');
    }

    public function testATeacherWithoutAdministrationCannotManageTheAccounts(): void
    {
        $family = $this->family('marta@gmail.test');
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($teacher);

        $this->client->request('GET', '/actividades-familias/cuentas');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/actividades-familias/cuentas/'.$family->getId().'/editar');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRemovingAnAccountNeedsAValidToken(): void
    {
        $family = $this->family('marta@gmail.test');
        $this->em->flush();
        $this->client->loginUser($this->admin());

        $this->client->request('POST', '/actividades-familias/cuentas/'.$family->getId().'/borrar', ['_token' => 'falso']);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em->find(FamilyVolunteer::class, $family->getId()));
    }

    public function testAdministrationRemovesAnAccountAndItsDataWithIt(): void
    {
        $family = $this->family('marta.borrada@gmail.test');
        $this->em->flush();
        $id = $family->getId();
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/actividades-familias/cuentas/'.$id.'/editar');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Guardar')->form();
        $form['family_volunteer_form[email]'] = 'marta.cambiada@gmail.test';
        $this->client->submit($form);
        self::assertResponseRedirects('/actividades-familias/cuentas');

        $crawler = $this->client->request('GET', '/actividades-familias/cuentas/'.$id.'/editar');
        $this->client->submit($crawler->selectButton('Borrar cuenta')->form());

        self::assertResponseRedirects('/actividades-familias/cuentas');
        $this->em->clear();
        self::assertNull($this->em->find(FamilyVolunteer::class, $id));
        $trail = $this->trail();
        self::assertStringNotContainsString('marta.borrada@gmail.test', $trail);
        self::assertStringNotContainsString('marta.cambiada@gmail.test', $trail);
    }

    /**
     * The trail records that an account came and went, and what a family did, but never its name or
     * address: those must leave with the account.
     */
    public function testTheTrailKeepsNoNameOrAddressOfAFamily(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/actividades-familias/cuentas/nueva');
        $form = $crawler->selectButton('Guardar')->form();
        $form['family_volunteer_form[fullName]'] = 'Marta Rastro';
        $form['family_volunteer_form[email]'] = 'marta.rastro@gmail.test';
        $this->client->submit($form);
        $family = $this->em->getRepository(FamilyVolunteer::class)->findOneBy(['email' => 'marta.rastro@gmail.test']);
        self::assertNotNull($family);

        // The admin's staff session stays; the family signs in through its own firewall alongside it.
        $this->client->request('POST', '/familias/entrar', ['email' => 'marta.rastro@gmail.test']);
        $this->follow($this->sentLink());
        self::assertResponseRedirects('/familias');

        $entries = $this->em->getRepository(AuditLog::class)->findAll();
        $trail = $this->trail();
        self::assertStringNotContainsString('Marta Rastro', $trail);
        self::assertStringNotContainsString('marta.rastro@gmail.test', $trail);
        self::assertStringContainsString(json_encode(AuditRedacted::MARK, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), $trail);
        self::assertContains('familia #'.$family->getId(), array_map(static fn (AuditLog $e): ?string => $e->getActor(), $entries));
    }

    /**
     * @param string $message what the last request should have logged, alone
     * @param string $address part of the address that must not appear in it
     */
    private function assertLoggedWithoutTheAddress(string $message, string $address): void
    {
        $handler = self::getContainer()->get('monolog.handler.login_link');
        self::assertInstanceOf(TestHandler::class, $handler);
        $records = $handler->getRecords();
        self::assertSame([$message], array_map(static fn (LogRecord $r): string => $r->message, $records));
        self::assertStringNotContainsString($address, json_encode($records[0]->context, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return string the whole activity trail (actor, summary, changes), as JSON, to search for leaks
     */
    private function trail(): string
    {
        return json_encode(array_map(
            static fn (AuditLog $e): array => [$e->getActor(), $e->getSummary(), $e->getChanges()],
            $this->em->getRepository(AuditLog::class)->findAll(),
        ), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * Signs a family in through its own firewall, as the sign-in link would.
     *
     * @param FamilyVolunteer $family the account
     */
    private function signInAsFamily(FamilyVolunteer $family): void
    {
        $this->client->loginUser($family, 'families');
    }

    /**
     * Opens a sign-in link and presses its "Entrar" button, as the person does.
     *
     * @param string $link the link from the e-mail
     */
    private function follow(string $link): void
    {
        $this->client->request('GET', $link);
        $this->client->submitForm('Entrar');
    }

    /**
     * @return Email the last e-mail sent
     */
    private function lastEmail(): Email
    {
        $messages = self::getMailerMessages();
        $last = end($messages);
        self::assertInstanceOf(Email::class, $last);

        return $last;
    }

    /**
     * @return string the sign-in URL inside the last e-mail sent
     */
    private function sentLink(): string
    {
        self::assertSame(1, preg_match('~https?://\S+~', (string) $this->lastEmail()->getTextBody(), $match));

        return $match[0];
    }

    /**
     * @param string $email the address
     *
     * @return FamilyVolunteer a persisted (not yet flushed) family account
     */
    private function family(string $email): FamilyVolunteer
    {
        $family = (new FamilyVolunteer())->setFullName('Familia '.explode('@', $email)[0])->setEmail($email);
        $this->em->persist($family);

        return $family;
    }

    /**
     * @param string $email the address
     *
     * @return User a persisted (not yet flushed) member of staff
     */
    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * @return User a flushed member of staff with an admin role
     */
    private function admin(): User
    {
        $role = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        $this->em->persist($role);
        $admin = $this->user('director@centro.test')->addAssignedRole($role);
        $this->em->flush();

        return $admin;
    }

    /**
     * @param string $when  a relative date from today, e.g. "+10 days"
     * @param string $title what it is
     *
     * @return FamilyActivity a persisted activity at 10:30 on that day
     */
    private function activity(string $when, string $title): FamilyActivity
    {
        $organizer = $this->user('organiza'.++$this->organizers.'@centro.test');
        $activity = new FamilyActivity($organizer, $title, new \DateTimeImmutable($when.' 10:30'));
        $this->em->persist($activity);

        return $activity;
    }
}
