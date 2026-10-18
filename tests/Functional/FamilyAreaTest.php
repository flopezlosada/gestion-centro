<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Contract\AuditRedacted;
use App\Entity\AuditLog;
use App\Entity\OneOffFamilyActivity;
use App\Family\FamilySessions;
use App\Entity\FamilyAvailability;
use App\Entity\FamilyVolunteer;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\WeeklyFamilyActivity;
use App\Agenda\MyClasses;
use App\Enum\Weekday;
use App\Service\AppSettings;
use App\Tests\Support\BuildsTheCentresTimetable;
use App\Tests\Support\MintsSessionCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
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
    use BuildsTheCentresTimetable;
    use ClockSensitiveTrait;
    use MintsSessionCsrfTokens;

    private const string TODAY = '2026-01-13';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $organizers = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(AppSettings::class)->setFamilyActivitiesEnabled(true);
        // A Tuesday: the dates below, relative to it, fall Monday to Friday whatever day CI runs on.
        self::mockTime(self::TODAY.' 08:00');
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
        $coming = $this->activity('+10 days', 'Grupos interactivos de 1ºA');
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
        self::assertSelectorExists('.family-week');
        $this->client->request('GET', $this->weekOf($coming));
        self::assertSelectorTextContains('.family-week', 'Grupos interactivos de 1ºA');
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

    public function testAFamilySignsUpAndWithdraws(): void
    {
        $family = $this->family('marta@gmail.test');
        $activity = $this->activity('+10 days', 'Taller de cocina')->setFamiliesWanted(2);
        $this->em->flush();
        $this->signInAsFamily($family);

        $this->client->request('GET', $this->weekOf($activity));
        self::assertSelectorTextContains('main', 'Quedan 2');
        $this->client->submitForm('Podemos ir');
        self::assertResponseRedirects($this->weekOf($activity));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'os esperamos en «Taller de cocina»');
        self::assertSelectorTextContains('main', 'Os esperamos');
        self::assertSelectorTextContains('main', 'Queda 1');
        self::assertSame(1, $this->signups());

        $this->client->submitForm('Ya no podemos ir');
        self::assertResponseRedirects($this->weekOf($activity));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Quedan 2');
        self::assertSame(0, $this->signups());
    }

    /**
     * A full activity offers no button, and a request sent anyway (an old page, a second tab) is refused
     * with the reason instead of taking a place that is not there.
     */
    public function testAFullActivityTakesNoMoreFamilies(): void
    {
        $family = $this->family('marta@gmail.test');
        $other = $this->family('otra@gmail.test');
        $activity = $this->activity('+10 days', 'Grupos interactivos')->setFamiliesWanted(1);
        $activity->signUp($activity->session(), $other, new \DateTimeImmutable(self::TODAY.' 08:00'));
        $this->em->flush();
        $this->signInAsFamily($family);

        $crawler = $this->client->request('GET', $this->weekOf($activity));
        self::assertSelectorTextContains('main', 'Completa');
        self::assertCount(0, $crawler->selectButton('Podemos ir'));
        // Nor who the other family is: only the count is shown.
        self::assertSelectorTextNotContains('main', 'Familia otra');

        $this->client->request('POST', $this->actionPath($activity, 'apuntarme'), [
            '_token' => $this->mintCsrfToken($this->client, '/familias', $this->tokenId('family_signup', $activity)),
        ]);
        self::assertResponseRedirects($this->weekOf($activity));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'está completa o ya ha empezado');
        self::assertSame(1, $this->signups());
    }

    public function testNoSigningUpOnceItHasStarted(): void
    {
        $family = $this->family('marta@gmail.test');
        $started = $this->activity('-1 day', 'Taller ya empezado');
        $this->em->flush();
        $this->signInAsFamily($family);

        $this->client->request('POST', $this->actionPath($started, 'apuntarme'), [
            '_token' => $this->mintCsrfToken($this->client, '/familias', $this->tokenId('family_signup', $started)),
        ]);

        self::assertResponseRedirects($this->weekOf($started));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'está completa o ya ha empezado');
        self::assertSame(0, $this->signups());
    }

    public function testNoWithdrawingOnceItHasStarted(): void
    {
        $family = $this->family('marta@gmail.test');
        $started = $this->activity('-1 day', 'Taller ya empezado');
        $started->signUp($started->session(), $family, new \DateTimeImmutable('2026-01-11 08:00'));
        $this->em->flush();
        $this->signInAsFamily($family);

        $this->client->request('POST', $this->actionPath($started, 'desapuntarme'), [
            '_token' => $this->mintCsrfToken($this->client, '/familias', $this->tokenId('family_withdraw', $started)),
        ]);

        self::assertResponseRedirects($this->weekOf($started));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'ya ha empezado: si no podéis venir');
        self::assertSame(1, $this->signups());
    }

    /**
     * A second press (a page reloaded, a slow connection) is answered as done and keeps one signup.
     */
    public function testSigningUpTwiceKeepsOnePlace(): void
    {
        $family = $this->family('marta@gmail.test');
        $activity = $this->activity('+10 days', 'Taller de cocina')->setFamiliesWanted(1);
        $this->em->flush();
        $this->signInAsFamily($family);
        $token = $this->mintCsrfToken($this->client, '/familias', $this->tokenId('family_signup', $activity));

        foreach ([1, 2] as $press) {
            $this->client->request('POST', $this->actionPath($activity, 'apuntarme'), ['_token' => $token]);
            self::assertResponseRedirects($this->weekOf($activity), null, 'press '.$press);
            $this->client->followRedirect();
            self::assertSelectorExists('.flash.success', 'press '.$press);
        }

        self::assertSame(1, $this->signups());
    }

    /**
     * The trail outlives the account: where a family went must not be written to it.
     */
    public function testSigningUpLeavesNothingInTheTrail(): void
    {
        $family = $this->family('marta@gmail.test');
        $activity = $this->activity('+10 days', 'Taller de cocina');
        $this->em->flush();
        $this->signInAsFamily($family);
        $before = $this->em->getRepository(AuditLog::class)->count([]);

        $this->client->request('GET', $this->weekOf($activity));
        $this->client->submitForm('Podemos ir');

        self::assertSame(1, $this->signups());
        self::assertSame($before, $this->em->getRepository(AuditLog::class)->count([]));
    }

    public function testSigningUpNeedsAValidToken(): void
    {
        $family = $this->family('marta@gmail.test');
        $activity = $this->activity('+10 days', 'Taller de cocina');
        $this->em->flush();
        $this->signInAsFamily($family);

        $this->client->request('POST', $this->actionPath($activity, 'apuntarme'), ['_token' => 'falso']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->signups());
    }

    /**
     * Whoever organises it needs to know who comes; the rest of the staff, only how many.
     */
    public function testOnlyTheOrganiserSeesWhichFamiliesAreComing(): void
    {
        $activity = $this->activity('+10 days', 'Taller de cocina')->setFamiliesWanted(3);
        $activity->signUp($activity->session(), $this->family('marta@gmail.test'), new \DateTimeImmutable(self::TODAY.' 08:00'));
        $teacher = $this->user('otro.profe@centro.test');
        $this->em->flush();
        $organizer = $activity->getOrganizer();
        self::assertNotNull($organizer);

        $this->client->loginUser($organizer);
        $this->client->request('GET', '/actividades-familias?semana='.$activity->getStartAt()->format('Y-m-d'));
        self::assertSelectorTextContains('.family-week', '1 de 3');
        self::assertSelectorTextContains('.family-week', 'Familia marta');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/actividades-familias?semana='.$activity->getStartAt()->format('Y-m-d'));
        self::assertSelectorTextContains('.family-week', '1 de 3');
        self::assertSelectorTextNotContains('.family-week', 'Familia marta');
    }

    /**
     * A weekly activity in a class: the family sees one session a week and signs up for one day, which
     * leaves the next week's session as it was.
     */
    public function testAFamilySignsUpForOneWeekOfAWeeklyActivity(): void
    {
        $family = $this->family('marta@gmail.test');
        $this->weeklyActivity()->setFamiliesWanted(2);
        $this->em->flush();
        self::mockTime('2026-01-12 08:00');
        $this->signInAsFamily($family);

        $this->client->request('GET', '/familias');
        self::assertSelectorTextContains('.family-week', 'Martes 13/01');
        self::assertSelectorTextContains('.family-week', '10:15–11:10');
        $this->client->submitForm('Podemos ir');
        self::assertResponseRedirects('/familias?semana=2026-01-12');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'el 13/01');
        self::assertSelectorTextContains('.family-week', 'Os esperamos');

        $this->client->request('GET', '/familias?semana=2026-01-19');
        self::assertSelectorTextContains('.family-week', 'Quedan 2');
        self::assertSelectorTextNotContains('.family-week', 'Os esperamos');
        self::assertSame(['2026-01-13'], $this->em->getConnection()->fetchFirstColumn('SELECT session_date FROM family_signup'));
    }

    /**
     * A day the class is not given has no session: a request for it (a crafted URL, a timetable changed
     * since the page was opened) is not found, and takes no place.
     */
    public function testNoSigningUpForADayTheClassIsNotGiven(): void
    {
        $family = $this->family('marta@gmail.test');
        $activity = $this->weeklyActivity();
        $this->em->flush();
        self::mockTime('2026-01-12 08:00');
        $this->signInAsFamily($family);
        $wednesday = '2026-01-14';

        $this->client->request('POST', '/familias/actividades/'.$activity->getId().'/'.$wednesday.'/apuntarme', [
            '_token' => $this->mintCsrfToken($this->client, '/familias', 'family_signup'.$activity->getId().'-'.$wednesday),
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->signups());
    }

    /**
     * Removing the account takes with it the record of which activities that person was coming to.
     */
    public function testRemovingAFamilyRemovesItsSignups(): void
    {
        $family = $this->family('marta@gmail.test');
        $activity = $this->activity('+10 days', 'Taller de cocina');
        $activity->signUp($activity->session(), $family, new \DateTimeImmutable(self::TODAY.' 08:00'));
        $this->em->flush();
        $this->client->loginUser($this->admin());

        $this->client->request('POST', '/actividades-familias/cuentas/'.$family->getId().'/borrar', [
            '_token' => $this->mintCsrfToken($this->client, '/actividades-familias/cuentas', 'family_volunteer_delete'.$family->getId()),
        ]);

        self::assertResponseRedirects('/actividades-familias/cuentas');
        self::assertSame(0, $this->signups());
    }

    /**
     * Saving again moves a day kept, drops a day emptied and adds a new one — the day kept is the same
     * row, which is what keeps the unique (family, day) index from refusing the save.
     */
    public function testAFamilySaysWhenItCanComeAndChangesIt(): void
    {
        $family = $this->family('marta@gmail.test');
        $this->em->flush();
        $this->signInAsFamily($family);

        $this->client->request('GET', '/familias');
        $this->client->clickLink('Cuándo podemos venir');
        $this->client->submitForm('Guardar', $this->availabilityForm([0 => ['09:00', '12:00'], 2 => ['10:00', '13:00']]));
        self::assertResponseRedirects('/familias');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'ya sabe cuándo podéis venir');
        $monday = $this->availabilityOf($family)[Weekday::MONDAY->value]['id'];

        $this->client->request('GET', '/familias/disponibilidad');
        self::assertInputValueSame('family_availability_form[days][0][from]', '09:00');
        $this->client->submitForm('Guardar', $this->availabilityForm([0 => ['09:30', '11:00'], 2 => ['', ''], 4 => ['16:00', '18:00']]));
        self::assertResponseRedirects('/familias');

        $after = $this->availabilityOf($family);
        self::assertSame([Weekday::MONDAY->value, Weekday::FRIDAY->value], array_keys($after));
        self::assertSame(['id' => $monday, 'span' => '09:30:00–11:00:00'], $after[Weekday::MONDAY->value]);
        self::assertSame('16:00:00–18:00:00', $after[Weekday::FRIDAY->value]['span']);
    }

    /**
     * A day with one time only, or with "until" before "from", is sent back with the reason and nothing
     * of the form is saved, not even the days that were right: what was stored stays as it was.
     */
    public function testAHalfGivenOrBackwardsDayIsRefused(): void
    {
        $family = $this->family('marta@gmail.test');
        $this->em->persist(new FamilyAvailability($family, Weekday::FRIDAY, new \DateTimeImmutable('16:00'), new \DateTimeImmutable('18:00')));
        $this->em->flush();
        $stored = $this->availabilityOf($family);
        $this->signInAsFamily($family);
        $this->client->request('GET', '/familias/disponibilidad');

        $this->client->submitForm('Guardar', $this->availabilityForm([0 => ['09:00', '12:00'], 1 => ['09:00', ''], 3 => ['12:00', '10:00']]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Pon las dos horas');
        self::assertSelectorTextContains('main', 'tiene que ser posterior');
        self::assertSame($stored, $this->availabilityOf($family));
    }

    /**
     * The staff see, per day, how many families can come and at what hours — never who.
     */
    public function testTheStaffSeeHowManyFamiliesCanComeEachDayButNotWho(): void
    {
        $marta = $this->family('marta@gmail.test');
        $luis = $this->family('luis@gmail.test');
        $this->em->persist(new FamilyAvailability($marta, Weekday::TUESDAY, new \DateTimeImmutable('09:00'), new \DateTimeImmutable('12:00')));
        $this->em->persist(new FamilyAvailability($luis, Weekday::TUESDAY, new \DateTimeImmutable('08:30'), new \DateTimeImmutable('10:00')));
        $this->em->persist(new FamilyAvailability($luis, Weekday::THURSDAY, new \DateTimeImmutable('16:00'), new \DateTimeImmutable('18:00')));
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($teacher);

        $this->client->request('GET', '/actividades-familias');

        $rows = $this->client->getCrawler()->filter('#family-availability-title + table tbody tr')
            ->each(static fn (Crawler $row): string => preg_replace('/\s+/', ' ', trim($row->text())));
        self::assertSame([
            'Lunes 0 —',
            'Martes 2 08:30–10:00, 09:00–12:00',
            'Miércoles 0 —',
            'Jueves 1 16:00–18:00',
            'Viernes 0 —',
        ], $rows);
        self::assertSelectorTextNotContains('main', 'Familia marta');
        self::assertSelectorTextNotContains('main', 'Familia luis');
    }

    /**
     * Removing the account takes with it the days and hours that person could come.
     */
    public function testRemovingAFamilyRemovesItsAvailability(): void
    {
        $family = $this->family('marta@gmail.test');
        $this->em->persist(new FamilyAvailability($family, Weekday::MONDAY, new \DateTimeImmutable('09:00'), new \DateTimeImmutable('12:00')));
        $this->em->flush();
        $this->client->loginUser($this->admin());

        $this->client->request('POST', '/actividades-familias/cuentas/'.$family->getId().'/borrar', [
            '_token' => $this->mintCsrfToken($this->client, '/actividades-familias/cuentas', 'family_volunteer_delete'.$family->getId()),
        ]);

        self::assertResponseRedirects('/actividades-familias/cuentas');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM family_availability'));
    }

    /**
     * @param array<int, array{string, string}> $days "from" and "until" by row (0 = Monday), the rest left empty
     *
     * @return array<string, string> the fields of the availability form
     */
    private function availabilityForm(array $days): array
    {
        $fields = [];
        foreach (array_keys(Weekday::schoolWeek()) as $row) {
            [$from, $until] = $days[$row] ?? ['', ''];
            $fields['family_availability_form[days]['.$row.'][from]'] = $from;
            $fields['family_availability_form[days]['.$row.'][until]'] = $until;
        }

        return $fields;
    }

    /**
     * @param FamilyVolunteer $family the family
     *
     * @return array<int, array{id: int, span: string}> its stored spans by weekday, read past the identity map
     */
    private function availabilityOf(FamilyVolunteer $family): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, weekday, available_from, available_until FROM family_availability WHERE family_id = ? ORDER BY weekday',
            [$family->getId()],
        );

        return array_combine(
            array_map(static fn (array $r): int => (int) $r['weekday'], $rows),
            array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'span' => $r['available_from'].'–'.$r['available_until']], $rows),
        );
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
     * @return int how many signups the database holds, read past the identity map
     */
    private function signups(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM family_signup');
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
     * @param string $when  a date relative to {@see TODAY}, e.g. "+10 days"
     * @param string $title what it is
     *
     * @return OneOffFamilyActivity a persisted activity at 10:30 on that day
     */
    private function activity(string $when, string $title): OneOffFamilyActivity
    {
        $organizer = $this->user('organiza'.++$this->organizers.'@centro.test');
        $activity = new OneOffFamilyActivity($organizer, $title, (new \DateTimeImmutable(self::TODAY))->modify($when)->setTime(10, 30));
        $this->em->persist($activity);

        return $activity;
    }

    /**
     * @param OneOffFamilyActivity $activity an activity
     *
     * @return string the families' calendar on the week of its session
     */
    private function weekOf(OneOffFamilyActivity $activity): string
    {
        return '/familias?semana='.FamilySessions::mondayOf($activity->getStartAt())->format('Y-m-d');
    }

    /**
     * @param OneOffFamilyActivity $activity an activity
     * @param string               $action   "apuntarme" or "desapuntarme"
     *
     * @return string where that action on its session is posted
     */
    private function actionPath(OneOffFamilyActivity $activity, string $action): string
    {
        return '/familias/actividades/'.$activity->getId().'/'.$activity->getStartAt()->format('Y-m-d').'/'.$action;
    }

    /**
     * @param string               $action   "family_signup" or "family_withdraw"
     * @param OneOffFamilyActivity $activity an activity
     *
     * @return string the CSRF token id of that action on its session
     */
    private function tokenId(string $action, OneOffFamilyActivity $activity): string
    {
        return $action.$activity->getId().'-'.$activity->getStartAt()->format('Y-m-d');
    }

    /**
     * @return WeeklyFamilyActivity a persisted weekly activity in a class given on Tuesdays at 10:15, in
     *                              the 2025-2026 course (flushed, the timetable with it)
     */
    private function weeklyActivity(): WeeklyFamilyActivity
    {
        $year = $this->courseWithTheCentresFrame($this->em);
        $teacher = $this->user('profe.clase@centro.test');
        $this->classCell($this->em, $year, $teacher, Weekday::TUESDAY, 2, 'E1A', 'Aula 12', 'Matemáticas');
        $this->em->flush();
        $tuesday = new \DateTimeImmutable('2026-01-13');
        $activity = new WeeklyFamilyActivity($teacher, 'Grupos interactivos', self::getContainer()->get(MyClasses::class)->on($teacher, $tuesday)[0], $tuesday);
        $this->em->persist($activity);

        return $activity;
    }
}
