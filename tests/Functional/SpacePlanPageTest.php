<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\Role;
use App\Entity\SpacePlan;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\SpacePlanStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Who may touch a plan, and what an approved plan refuses to let anybody do.
 *
 * A plan decides where groups go, so the whole workflow needs WRITE on {@see Area::ESPACIOS} — read
 * access to the free-rooms consultation is not enough. And once approved, a plan is what the centre is
 * doing: editing it silently would leave the notices and the printed boards saying something else.
 */
final class SpacePlanPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testReadAccessIsNotEnoughToSeeThePlans(): void
    {
        $this->login(PermissionLevel::READ);
        $this->client->request('GET', '/espacios/planes');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testWriteAccessSeesTheList(): void
    {
        $this->login(PermissionLevel::WRITE);
        $this->client->request('GET', '/espacios/planes');

        self::assertResponseIsSuccessful();
    }

    public function testAnApprovedPlanCannotBeEdited(): void
    {
        $user = $this->login(PermissionLevel::WRITE);
        $plan = $this->plan($user, SpacePlanStatus::APPROVED);

        $this->client->request('GET', '/espacios/planes/'.$plan->getId().'/editar');

        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'what the centre is doing is not a draft');
    }

    public function testADraftPlanShowsItsPageWithNoOptionsYet(): void
    {
        $user = $this->login(PermissionLevel::WRITE);
        $plan = $this->plan($user, SpacePlanStatus::DRAFT);

        $crawler = $this->client->request('GET', '/espacios/planes/'.$plan->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sin propuestas todavía', $crawler->filter('body')->text());
    }

    public function testGeneratingWithABadCsrfTokenIsRejected(): void
    {
        $user = $this->login(PermissionLevel::WRITE);
        $plan = $this->plan($user, SpacePlanStatus::DRAFT);

        $this->client->request('POST', '/espacios/planes/'.$plan->getId().'/generar', ['_token' => 'no']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheDocumentOfAnApprovedPlanIsOpenToAnybodySignedIn(): void
    {
        // It is the digital notice board, and it is where the notice sent to each affected teacher
        // points: gating it behind the area would send them to a 403.
        $author = $this->login(PermissionLevel::WRITE);
        $plan = $this->plan($author, SpacePlanStatus::APPROVED);

        $plain = (new User())->setFullName('Docente Sin Permisos')->setEmail('profe@centro.test');
        $this->em->persist($plain);
        $this->em->flush();
        $this->client->loginUser($plain);

        $this->client->request('GET', '/espacios/planes/'.$plan->getId().'/documento');

        self::assertResponseIsSuccessful();
    }

    public function testTheDocumentOfAPlanThatIsNotApprovedIsHiddenFromEverybodyElse(): void
    {
        $author = $this->login(PermissionLevel::WRITE);
        $plan = $this->plan($author, SpacePlanStatus::DRAFT);

        $plain = (new User())->setFullName('Docente Sin Permisos')->setEmail('profe@centro.test');
        $this->em->persist($plain);
        $this->em->flush();
        $this->client->loginUser($plain);

        $this->client->request('GET', '/espacios/planes/'.$plan->getId().'/documento');

        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'an undecided plan is nobody else\'s business');
    }

    public function testAPlanThatIsNotApprovedCannotBeAnnounced(): void
    {
        $author = $this->login(PermissionLevel::WRITE);
        $plan = $this->plan($author, SpacePlanStatus::PROPOSED);

        // A GET first: the CSRF token lives in the session, and outside a request there is none.
        $this->client->request('GET', '/espacios/planes/'.$plan->getId());
        $token = (string) self::getContainer()->get('security.csrf.token_manager')->getToken('space_plan_notify'.$plan->getId());

        $this->client->request('POST', '/espacios/planes/'.$plan->getId().'/avisar', ['_token' => $token]);

        self::assertResponseRedirects('/espacios/planes/'.$plan->getId());
        $this->em->clear();
        self::assertNull($this->em->getRepository(SpacePlan::class)->find($plan->getId())?->getNotifiedAt());
    }

    /**
     * Logs in a user with the given level on the Espacios area.
     *
     * @param PermissionLevel $level the level to grant
     */
    private function login(PermissionLevel $level): User
    {
        $role = (new Role())->setCode('espacios_test')->setName('Prueba de espacios')->setLevel(Area::ESPACIOS, $level);
        $this->em->persist($role);
        $user = (new User())->setFullName('Directora Test')->setEmail('direccion@centro.test');
        $user->addAssignedRole($role);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function plan(User $user, SpacePlanStatus $status): SpacePlan
    {
        $year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-22'));
        $this->em->persist($year);

        $plan = (new SpacePlan())
            ->setAcademicYear($year)
            ->setCreatedBy($user)
            ->setTitle('Talleres de prueba')
            ->setDateFrom(new \DateTimeImmutable('2026-01-12'))
            ->setDateTo(new \DateTimeImmutable('2026-01-12'))
            ->setStatus($status);
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }
}
