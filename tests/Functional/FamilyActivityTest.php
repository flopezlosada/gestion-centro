<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FamilyActivity;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Las actividades con familias, por el lado del profesorado: cualquiera publica, solo quien organiza (o un
 * administrador) cambia o borra. Y la puerta que hace posible abrir la app a familias más adelante: el área
 * del personal exige ROLE_STAFF, que solo da {@see User}, y no ROLE_USER, que se le supone a cualquier cuenta.
 */
final class FamilyActivityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Si alguien vuelve a poner ROLE_USER en la regla general, cualquier cuenta que no sea del personal
     * vería la app entera. Se comprueba la regla misma, no una pantalla, para que valga para todas.
     */
    public function testTheStaffAreaAsksForTheStaffRole(): void
    {
        $accessMap = self::getContainer()->get('security.access_map');
        \assert($accessMap instanceof AccessMapInterface);

        foreach (['/', '/guardias', '/reuniones', '/admin/usuarios', '/actividades-familias'] as $path) {
            [$roles] = $accessMap->getPatterns(Request::create($path));
            self::assertSame(['ROLE_STAFF'], $roles, $path);
        }
    }

    public function testEveryMemberOfStaffHoldsTheStaffRole(): void
    {
        $teacher = (new User())->setFullName('Profe Test')->setEmail('profe@centro.test');

        self::assertContains('ROLE_STAFF', $teacher->getRoles());
    }

    public function testATeacherPublishesAnActivityInTheirOwnName(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($teacher);

        $crawler = $this->client->request('GET', '/actividades-familias/nueva');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Publicar actividad')->form();
        $form['family_activity_form[title]'] = 'Grupos interactivos de 1ºA';
        $form['family_activity_form[description]'] = 'Cuatro mesas de matemáticas; cada familia lleva una.';
        $form['family_activity_form[place]'] = 'Aula 12';
        $form['family_activity_form[day]'] = '2026-11-05';
        $form['family_activity_form[startTime]'] = '10:30';
        $form['family_activity_form[endTime]'] = '11:25';
        $form['family_activity_form[familiesWanted]'] = '4';
        $this->client->submit($form);

        self::assertResponseRedirects('/actividades-familias');
        $this->em->clear();
        $activity = $this->em->getRepository(FamilyActivity::class)->findOneBy(['title' => 'Grupos interactivos de 1ºA']);
        self::assertNotNull($activity);
        self::assertSame('2026-11-05 10:30', $activity->getStartAt()->format('Y-m-d H:i'));
        self::assertSame('2026-11-05 11:25', $activity->getEndAt()?->format('Y-m-d H:i'));
        self::assertSame('Aula 12', $activity->getPlace());
        self::assertSame(4, $activity->getFamiliesWanted());
        // Quien organiza sale de la sesión, nunca del formulario.
        self::assertSame('profe@centro.test', $activity->getOrganizer()?->getEmail());
    }

    /**
     * Lo opcional vacío se guarda como «no se sabe» (null), no como un cero ni una cadena vacía: «0
     * familias» diría que no hace falta nadie.
     */
    public function testOptionalFieldsLeftEmptyAreStoredAsUnknown(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($teacher);

        $crawler = $this->client->request('GET', '/actividades-familias/nueva');
        $form = $crawler->selectButton('Publicar actividad')->form();
        $form['family_activity_form[title]'] = 'Taller de cuentos';
        $form['family_activity_form[day]'] = '2026-11-05';
        $form['family_activity_form[startTime]'] = '12:00';
        $this->client->submit($form);

        self::assertResponseRedirects('/actividades-familias');
        $this->em->clear();
        $activity = $this->em->getRepository(FamilyActivity::class)->findOneBy(['title' => 'Taller de cuentos']);
        self::assertNotNull($activity);
        self::assertNull($activity->getEndAt());
        self::assertNull($activity->getFamiliesWanted());
        self::assertNull($activity->getPlace());
        self::assertNull($activity->getDescription());
    }

    public function testAnActivityEndingBeforeItStartsIsRefused(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($teacher);

        $crawler = $this->client->request('GET', '/actividades-familias/nueva');
        $form = $crawler->selectButton('Publicar actividad')->form();
        $form['family_activity_form[title]'] = 'Taller';
        $form['family_activity_form[day]'] = '2026-11-05';
        $form['family_activity_form[startTime]'] = '11:00';
        $form['family_activity_form[endTime]'] = '10:00';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La hora de fin debe ser posterior a la de inicio.');
        self::assertSame(0, $this->em->getRepository(FamilyActivity::class)->count([]));
    }

    /**
     * Una familia tiene que saber a qué hora venir: sin hora de inicio no se publica.
     */
    public function testAnActivityWithoutStartTimeIsRefused(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($teacher);

        $crawler = $this->client->request('GET', '/actividades-familias/nueva');
        $form = $crawler->selectButton('Publicar actividad')->form();
        $form['family_activity_form[title]'] = 'Taller';
        $form['family_activity_form[day]'] = '2026-11-05';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Pon la hora a la que empieza.');
    }

    public function testSomeoneElseCannotEditOrDeleteTheActivity(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $other = $this->user('otra@centro.test');
        $activity = $this->activity($organizer, '+10 days');
        $this->em->flush();
        $this->client->loginUser($other);

        $this->client->request('GET', '/actividades-familias/'.$activity->getId().'/editar');
        self::assertResponseStatusCodeSame(403);

        // El enlace de editar tampoco se le ofrece en la lista.
        $this->client->request('GET', '/actividades-familias');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Taller de cuentos');
        self::assertSelectorNotExists('a[href$="/editar"]');

        // Ni borrarla, aunque el envío lleve un token válido.
        $this->client->request('POST', '/actividades-familias/'.$activity->getId().'/borrar', [
            '_token' => $this->csrfToken('family_activity_delete'.$activity->getId()),
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(FamilyActivity::class)->find($activity->getId()));
    }

    public function testDeletingWithoutAValidTokenIsRefusedEvenToTheOrganizer(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $activity = $this->activity($organizer, '+10 days');
        $this->em->flush();
        $this->client->loginUser($organizer);

        $this->client->request('POST', '/actividades-familias/'.$activity->getId().'/borrar', ['_token' => 'x']);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(FamilyActivity::class)->find($activity->getId()));
    }

    public function testTheOrganizerEditsTheActivity(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $activity = $this->activity($organizer, '+10 days');
        $activity->reschedule($activity->getStartAt(), $activity->getStartAt()->setTime(11, 25));
        $this->em->flush();
        $start = $activity->getStartAt()->format('Y-m-d H:i');
        $this->client->loginUser($organizer);

        $crawler = $this->client->request('GET', '/actividades-familias/'.$activity->getId().'/editar');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Guardar')->form();
        $form['family_activity_form[place]'] = 'Biblioteca';
        $form['family_activity_form[endTime]'] = '';
        $this->client->submit($form);

        self::assertResponseRedirects('/actividades-familias');
        $this->em->clear();
        $saved = $this->em->getRepository(FamilyActivity::class)->find($activity->getId());
        self::assertNotNull($saved);
        self::assertSame('Biblioteca', $saved->getPlace());
        // Vaciar la hora de fin la quita; el día y la hora de inicio vuelven tal cual del formulario.
        self::assertNull($saved->getEndAt());
        self::assertSame($start, $saved->getStartAt()->format('Y-m-d H:i'));
    }

    /**
     * La actividad sobrevive a quien la organizaba (las familias ya contaban con ella) y un administrador
     * puede hacerse cargo: si no, quedaría para siempre sin nadie que pueda tocarla.
     */
    public function testAnAdminTakesOverAnActivityWhoseOrganizerLeft(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $activity = $this->activity($organizer, '+10 days');
        $role = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        $this->em->persist($role);
        $this->user('director@centro.test')->addAssignedRole($role);
        $this->em->flush();
        $activityId = $activity->getId();
        $organizerId = $organizer->getId();

        $this->em->clear();
        $leaving = $this->em->getRepository(User::class)->find($organizerId);
        self::assertNotNull($leaving);
        $this->em->remove($leaving);
        $this->em->flush();
        $this->em->clear();
        $orphan = $this->em->getRepository(FamilyActivity::class)->find($activityId);
        self::assertNotNull($orphan);
        self::assertNull($orphan->getOrganizer());

        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => 'director@centro.test']);
        self::assertNotNull($admin);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/actividades-familias');
        self::assertSelectorTextContains('table', 'Nadie (ya no está en el centro)');

        $crawler = $this->client->request('GET', '/actividades-familias/'.$activityId.'/editar');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Guardar')->form();
        $form['family_activity_form[place]'] = 'Gimnasio';
        $this->client->submit($form);
        self::assertResponseRedirects('/actividades-familias');
        $this->em->clear();
        self::assertSame('Gimnasio', $this->em->getRepository(FamilyActivity::class)->find($activityId)?->getPlace());

        $crawler = $this->client->request('GET', '/actividades-familias/'.$activityId.'/editar');
        $this->client->submit($crawler->selectButton('Borrar actividad')->form());
        self::assertResponseRedirects('/actividades-familias');
        $this->em->clear();
        self::assertNull($this->em->getRepository(FamilyActivity::class)->find($activityId));
    }

    public function testTheListShowsWhatIsComingAndThePastApart(): void
    {
        $organizer = $this->user('organiza@centro.test');
        $this->activity($organizer, '+10 days', 'Taller que viene');
        $this->activity($organizer, '-10 days', 'Taller que ya pasó');
        $this->em->flush();
        $this->client->loginUser($organizer);

        $this->client->request('GET', '/actividades-familias');
        self::assertSelectorTextContains('table', 'Taller que viene');
        self::assertSelectorTextNotContains('table', 'Taller que ya pasó');

        $this->client->request('GET', '/actividades-familias?ver=pasadas');
        self::assertSelectorTextContains('table', 'Taller que ya pasó');
        self::assertSelectorTextNotContains('table', 'Taller que viene');
    }

    /**
     * A CSRF token valid in the logged-in person's own session, for an action whose form that person is
     * never shown. Without it a refused request would be refused for the token, not for who sends it,
     * and the test would pass whatever the authorisation check did. Tokens live in the session, so it
     * is minted against that session and saved back for the next request to find.
     *
     * @param string $id the token id
     *
     * @return string the token value
     */
    private function csrfToken(string $id): string
    {
        $this->client->request('GET', '/actividades-familias');
        $session = $this->client->getRequest()->getSession();
        $request = new Request();
        $request->setSession($session);
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);
        $token = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($id)->getValue();
        $requestStack->pop();
        $session->save();

        return $token;
    }

    /**
     * @param string $email the person's e-mail, unique per test
     *
     * @return User a persisted member of staff
     */
    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * @param User   $organizer who organises it
     * @param string $when      a relative date from today, e.g. "+10 days"
     * @param string $title     what it is
     *
     * @return FamilyActivity a persisted activity at 10:30 on that day
     */
    private function activity(User $organizer, string $when, string $title = 'Taller de cuentos'): FamilyActivity
    {
        $activity = new FamilyActivity($organizer, $title, (new \DateTimeImmutable($when))->setTime(10, 30));
        $this->em->persist($activity);

        return $activity;
    }
}
