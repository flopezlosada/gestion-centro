<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AuditLog;
use App\Entity\FamilyAvailability;
use App\Entity\FamilyConsent;
use App\Entity\FamilyPrivacyNotice;
use App\Entity\FamilyVolunteer;
use App\Entity\Role;
use App\Entity\StaffPrivacyNotice;
use App\Entity\User;
use App\Enum\Weekday;
use App\Service\AppSettings;
use App\Tests\Support\MintsSessionCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A family's data is processed on its consent: no family uses its area without having accepted the
 * families' text in force (and with none published, none uses it at all), and saying no — then or later —
 * deletes the account and what hangs from it. The staff's text and the families' never stand in for each
 * other.
 */
final class FamilyConsentTest extends WebTestCase
{
    use MintsSessionCsrfTokens;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(AppSettings::class)->setFamilyActivitiesEnabled(true);
    }

    /**
     * Fail-closed, unlike the staff's: with nothing to consent to, the area does not open.
     */
    public function testWithNoFamiliesTextTheAreaStaysShut(): void
    {
        $this->signIn($this->family('marta@gmail.test'));

        $this->client->request('GET', '/familias');
        self::assertResponseRedirects('/familias/consentimiento');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.privacy-sheet', 'todavía no ha publicado');
        self::assertCount(0, $crawler->selectButton('Acepto'));
    }

    /**
     * A staff version does not open the families' area either: it is not their text.
     */
    public function testTheStaffsTextDoesNotOpenTheFamiliesArea(): void
    {
        $this->em->persist(new StaffPrivacyNotice('Texto del personal', 'Completa', $this->user('dpd@centro.test'), new \DateTimeImmutable()));
        $this->signIn($this->family('marta@gmail.test'));

        $this->client->request('GET', '/familias');

        self::assertResponseRedirects('/familias/consentimiento');
    }

    /**
     * "Acepto" records the consent to the version shown and lets the family through.
     */
    public function testAFamilyAcceptsAndGoesOn(): void
    {
        $notice = $this->publish('Responsable: la Dirección General');
        $family = $this->family('marta@gmail.test');
        $this->signIn($family);

        $this->client->request('GET', '/familias/consentimiento');
        self::assertSelectorTextContains('.privacy-sheet', 'la Dirección General');
        $this->client->submitForm('Acepto');

        self::assertResponseRedirects('/familias');
        self::assertSame(1, $this->em->getRepository(FamilyConsent::class)->count(['family' => $family, 'notice' => $notice]));
        $this->client->request('GET', '/familias');
        self::assertResponseIsSuccessful();
    }

    /**
     * A new version asks again, and keeps the earlier consent on record.
     */
    public function testANewVersionAsksAgain(): void
    {
        $family = $this->family('marta@gmail.test');
        $this->em->persist(new FamilyConsent($family, $this->publish('Primera'), new \DateTimeImmutable('-1 day')));
        $this->publish('Segunda');
        $this->signIn($family);

        $this->client->request('GET', '/familias');

        self::assertResponseRedirects('/familias/consentimiento');
        self::assertSame(1, $this->em->getRepository(FamilyConsent::class)->count(['family' => $family]));
    }

    /**
     * The two texts share a table: the id of the staff's, posted as the families', is not a consent.
     */
    public function testTheStaffsVersionCannotBeAcceptedAsTheFamilies(): void
    {
        $this->publish('De familias');
        $staffNotice = new StaffPrivacyNotice('Del personal', 'Completa', $this->user('otra@centro.test'), new \DateTimeImmutable());
        $this->em->persist($staffNotice);
        $this->signIn($this->family('marta@gmail.test'));
        $crawler = $this->client->request('GET', '/familias/consentimiento');
        $form = $crawler->selectButton('Acepto')->form();

        $this->client->request('POST', '/familias/consentimiento', ['_token' => $form['_token']->getValue(), 'version' => $staffNotice->getId()]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->em->getRepository(FamilyConsent::class)->count([]));
    }

    /**
     * A script's request is refused, not redirected, as in the staff's gate.
     */
    public function testAScriptRequestIsRefusedInsteadOfRedirected(): void
    {
        $this->publish('Resumen');
        $this->signIn($this->family('marta@gmail.test'));

        $this->client->request('GET', '/familias', [], [], ['HTTP_SEC_FETCH_MODE' => 'cors']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Saying no deletes the account and everything hanging from it, and the next click is signed out.
     * The trail records the deletion with the family as the one who did it, by number and not by name.
     */
    public function testSayingNoDeletesTheAccountAndItsData(): void
    {
        $this->publish('Resumen');
        $family = $this->family('marta.no@gmail.test');
        $this->em->persist(new FamilyAvailability($family, Weekday::MONDAY, new \DateTimeImmutable('09:00'), new \DateTimeImmutable('12:00')));
        $this->signIn($family);
        $id = $family->getId();

        $this->client->request('GET', '/familias/consentimiento');
        $this->client->submitForm('No acepto: borrar mi cuenta');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Cuenta borrada');
        $db = $this->em->getConnection();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM family_volunteer WHERE id = ?', [$id]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM family_availability WHERE family_id = ?', [$id]));
        $deletions = $this->em->getRepository(AuditLog::class)->findBy(['action' => 'family_volunteer.deleted']);
        self::assertCount(1, $deletions);
        self::assertSame('familia #'.$id, $deletions[0]->getActor());

        $this->client->request('GET', '/familias');
        self::assertResponseRedirects('/familias/entrar');
    }

    /**
     * Withdrawing later is as easy: a button at the foot of every page, which takes the consents too.
     */
    public function testAFamilyThatAcceptedCanLeaveFromAnyPage(): void
    {
        $family = $this->family('marta@gmail.test');
        $this->em->persist(new FamilyConsent($family, $this->publish('Resumen'), new \DateTimeImmutable()));
        $this->signIn($family);
        $id = $family->getId();

        $this->client->request('GET', '/familias/disponibilidad');
        $this->client->submitForm('Darme de baja');

        self::assertSelectorTextContains('h1', 'Cuenta borrada');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM family_consent WHERE family_id = ?', [$id]));
    }

    /**
     * The session outlives a family's sign-out: the next family on the same browser must still be asked,
     * whatever the first one accepted.
     */
    public function testTheNextFamilyOnTheSameBrowserIsAskedToo(): void
    {
        $notice = $this->publish('Resumen');
        $first = $this->family('marta@gmail.test');
        $this->em->persist(new FamilyConsent($first, $notice, new \DateTimeImmutable()));
        $second = $this->family('luis@gmail.test');
        $this->signIn($first);
        $this->client->request('GET', '/familias');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/familias/salir');
        $this->client->loginUser($second, 'families');
        $this->client->request('GET', '/familias');

        self::assertResponseRedirects('/familias/consentimiento');
    }

    /**
     * Switching the area off does not take away the way out: a family signed in can still leave.
     */
    public function testWithTheAreaSwitchedOffAFamilyCanStillLeave(): void
    {
        $family = $this->family('marta@gmail.test');
        $this->em->persist(new FamilyConsent($family, $this->publish('Resumen'), new \DateTimeImmutable()));
        $this->signIn($family);
        $id = $family->getId();
        self::getContainer()->get(AppSettings::class)->setFamilyActivitiesEnabled(false);
        $token = $this->mintCsrfToken($this->client, '/familias/entrar/proteccion-datos', 'family_leave');

        $this->client->request('POST', '/familias/baja', ['_token' => $token]);

        self::assertSelectorTextContains('h1', 'Cuenta borrada');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM family_volunteer WHERE id = ?', [$id]));
    }

    public function testLeavingNeedsTheToken(): void
    {
        $this->publish('Resumen');
        $family = $this->family('marta@gmail.test');
        $this->signIn($family);

        $this->client->request('POST', '/familias/baja');

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em->getRepository(FamilyVolunteer::class)->find($family->getId()));
    }

    /**
     * The families' copy opens without signing in, so the centre can send it before creating the account.
     */
    public function testThePublicCopyOpensWithoutSigningIn(): void
    {
        $this->publish('Texto para familias sin entrar');

        $this->client->request('GET', '/familias/entrar/proteccion-datos');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.privacy-sheet', 'Texto para familias sin entrar');
    }

    /**
     * The families' text does not stop the staff: a teacher who read theirs goes on working.
     */
    public function testTheFamiliesTextDoesNotStopTheStaff(): void
    {
        $this->publish('Solo para familias');
        $this->client->loginUser($this->user('profe@centro.test'));

        $this->client->request('GET', '/tareas');

        self::assertResponseIsSuccessful();
    }

    /**
     * The "Familias" tab of the admin screen publishes the families' text and leaves the staff's alone.
     */
    public function testTheAdminPublishesTheFamiliesTextApart(): void
    {
        $role = (new Role())->setCode('ROLE_ADMIN')->setName('Administración del sistema')->setAdmin(true);
        $this->em->persist($role);
        $this->client->loginUser($this->user('admin@centro.test')->addAssignedRole($role));
        $this->em->flush();

        $this->client->request('GET', '/admin/proteccion-datos?para=familias');
        $this->client->submitForm('Publicar', ['summary' => 'Para familias', 'body' => 'Completa']);

        self::assertResponseRedirects('/admin/proteccion-datos?para=familias');
        $this->em->clear();
        self::assertSame('Para familias', $this->em->getRepository(FamilyPrivacyNotice::class)->findOneBy([])?->getSummary());
        self::assertSame(0, $this->em->getRepository(StaffPrivacyNotice::class)->count([]));
    }

    /**
     * @param string $summary the short text
     *
     * @return FamilyPrivacyNotice a families' version, persisted and flushed
     */
    private function publish(string $summary): FamilyPrivacyNotice
    {
        $notice = new FamilyPrivacyNotice($summary, 'Información completa', $this->user('dpd-'.uniqid().'@centro.test'), new \DateTimeImmutable());
        $this->em->persist($notice);
        $this->em->flush();

        return $notice;
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
        $user = (new User())->setFullName('Persona '.$email)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * Flushes what is pending and signs the family in through its own firewall, as the sign-in link would.
     *
     * @param FamilyVolunteer $family the account
     */
    private function signIn(FamilyVolunteer $family): void
    {
        $this->em->flush();
        $this->client->loginUser($family, 'families');
    }
}
