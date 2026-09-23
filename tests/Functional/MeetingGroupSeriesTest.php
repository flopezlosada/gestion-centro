<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Meeting;
use App\Entity\MeetingGroup;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\Weekday;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Las reuniones de un grupo semanal, vistas desde la pantalla: la siguiente enseña el acta de la anterior
 * mientras está por aprobar y deja aprobarla con un clic a quien la levantó; y en /admin, cambiar a mano
 * el día, la hora o las personas de un grupo de Peñalara lo marca para que el próximo import pregunte.
 */
final class MeetingGroupSeriesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheNextMeetingOffersToApproveThePreviousActaAndComesBack(): void
    {
        $keeper = $this->user('Jefa Lengua', 'jefa.lengua@educa.madrid.org');
        $member = $this->user('Profe Lengua', 'profe.lengua@educa.madrid.org');
        $group = $this->group('DPTO LENGUA', $keeper, $member);
        $previous = $this->meetingOf($group, $keeper, $member, '-6 days', approval: true);
        $previous->attachMinutes('actas/lengua.pdf', 'Acta.pdf', $keeper, new \DateTimeImmutable('-5 days'));
        $previous->publishMinutes($keeper);
        $next = $this->meetingOf($group, $keeper, $member, '+1 day');
        $this->em->flush();

        $this->client->loginUser($keeper);
        $crawler = $this->client->request('GET', '/reuniones/'.$next->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Acta anterior · pendiente de aprobación');

        $this->client->submit($crawler->selectButton('Dar el acta anterior por aprobada')->form());

        self::assertResponseRedirects('/reuniones/'.$next->getId(), null, 'vuelve a la reunión en la que se estaba');
        $this->em->clear();
        self::assertTrue($this->em->getRepository(Meeting::class)->find($previous->getId())?->areMinutesApproved());
        $this->client->request('GET', '/reuniones/'.$next->getId());
        self::assertSelectorTextNotContains('body', 'Acta anterior · pendiente de aprobación', 'aprobada, deja de salir');
    }

    /** Quien no levantó el acta la ve pendiente, pero no la aprueba. */
    public function testOnlyWhoeverKeptThePreviousActaApprovesIt(): void
    {
        $keeper = $this->user('Jefa Mates', 'jefa.mates@educa.madrid.org');
        $member = $this->user('Profe Mates', 'profe.mates@educa.madrid.org');
        $group = $this->group('DPTO MATEMÁTICAS', $keeper, $member);
        $previous = $this->meetingOf($group, $keeper, $member, '-6 days', approval: true);
        $previous->attachMinutes('actas/mates.pdf', 'Acta.pdf', $keeper, new \DateTimeImmutable('-5 days'));
        $previous->publishMinutes($keeper);
        $next = $this->meetingOf($group, $keeper, $member, '+1 day');
        $this->em->flush();

        $this->client->loginUser($member);
        $this->client->request('GET', '/reuniones/'.$next->getId());

        self::assertSelectorTextContains('body', 'Acta anterior · pendiente de aprobación');
        self::assertSelectorNotExists('input[name="volver"]');
    }

    /** Un acta sin publicar todavía no se puede aprobar: se dice, sin botón. */
    public function testAnUnpublishedPreviousActaIsAnnouncedButNotApprovable(): void
    {
        $keeper = $this->user('Jefa FQ', 'jefa.fq@educa.madrid.org');
        $member = $this->user('Profe FQ', 'profe.fq@educa.madrid.org');
        $group = $this->group('DPTO FQ', $keeper, $member);
        $this->meetingOf($group, $keeper, $member, '-6 days', approval: true);
        $next = $this->meetingOf($group, $keeper, $member, '+1 day');
        $this->em->flush();

        $this->client->loginUser($keeper);
        $this->client->request('GET', '/reuniones/'.$next->getId());

        self::assertSelectorTextContains('body', 'todavía no se ha publicado');
        self::assertSelectorNotExists('input[name="volver"]');
    }

    /** Una reunión convocada a mano, sin grupo, no tiene «anterior». */
    public function testAMeetingWithoutGroupShowsNoPreviousActa(): void
    {
        $keeper = $this->user('Jefa GH', 'jefa.gh@educa.madrid.org');
        $meeting = new Meeting($keeper, 'Reunión suelta', (new \DateTimeImmutable('+1 day'))->setTime(10, 0));
        $this->em->persist($meeting);
        $this->em->flush();

        $this->client->loginUser($keeper);
        $this->client->request('GET', '/reuniones/'.$meeting->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Acta anterior');
    }

    /** Añadir a mano a alguien que Peñalara no tiene (Rober en la TIC) marca el grupo para el próximo import. */
    public function testEditingThePeopleOfAGroupFromPenalaraMarksIt(): void
    {
        $admin = $this->admin();
        $carolina = $this->user('Carolina Rodríguez', 'carolina@educa.madrid.org');
        $rober = $this->user('Rober', 'rober@educa.madrid.org');
        $group = $this->group('REUNIÓN TIC', null, $carolina)->setPenalaraKey('REUNIÓN TIC');
        $this->em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/grupos-de-reunion/'.$group->getId().'/editar');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Guardar')->form();
        $values = $form->getPhpValues();
        $values['meeting_group_form']['members'] = [(string) $carolina->getId(), (string) $rober->getId()];
        $values['meeting_group_form']['convener'] = (string) $carolina->getId();
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects('/admin/grupos-de-reunion');
        $this->em->clear();
        $saved = $this->em->getRepository(MeetingGroup::class)->find($group->getId());
        self::assertNotNull($saved);
        self::assertTrue($saved->isEditedSinceImport());
        self::assertSame(Weekday::WEDNESDAY, $saved->getWeekday(), 'el día y la hora se conservan');
        self::assertSame('carolina@educa.madrid.org', $saved->getConvener()?->getEmail());
    }

    /** Solo el convocante y el tipo no son de Peñalara: cambiarlos no hace preguntar al import. */
    public function testSettingOnlyTheConvenerDoesNotMarkIt(): void
    {
        $admin = $this->admin();
        $carolina = $this->user('Carolina Rodríguez', 'carolina2@educa.madrid.org');
        $group = $this->group('CCP', null, $carolina)->setPenalaraKey('CCP');
        $this->em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/grupos-de-reunion/'.$group->getId().'/editar');
        $form = $crawler->selectButton('Guardar')->form();
        $values = $form->getPhpValues();
        $values['meeting_group_form']['convener'] = (string) $carolina->getId();
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects('/admin/grupos-de-reunion');
        $this->em->clear();
        self::assertFalse($this->em->getRepository(MeetingGroup::class)->find($group->getId())?->isEditedSinceImport());
    }

    public function testADayWithoutAnHourIsRejected(): void
    {
        $admin = $this->admin();
        $this->em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/grupos-de-reunion/nuevo');
        $form = $crawler->selectButton('Guardar')->form();
        $values = $form->getPhpValues();
        $values['meeting_group_form']['name'] = 'Tutores 1º';
        $values['meeting_group_form']['weekday'] = (string) Weekday::MONDAY->value;
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'hacen falta el día y la hora');
        self::assertCount(0, $this->em->getRepository(MeetingGroup::class)->findBy(['name' => 'Tutores 1º']));
    }

    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /** Someone with write access to Administración, which is what the /admin catalogues ask for. */
    private function admin(): User
    {
        $role = (new Role())->setCode('admin-series-'.uniqid())->setName('Administración')->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE);
        $this->em->persist($role);

        return $this->user('Admin TIC', 'admin.tic.'.uniqid().'@educa.madrid.org')->addAssignedRole($role);
    }

    private function group(string $name, ?User $convener, User ...$members): MeetingGroup
    {
        $group = (new MeetingGroup())->setName($name)->repeatWeekly(Weekday::WEDNESDAY, 2)->setConvener($convener);
        foreach ($members as $member) {
            $group->addMember($member);
        }
        $this->em->persist($group);

        return $group;
    }

    private function meetingOf(MeetingGroup $group, User $convener, User $attendee, string $when, bool $approval = false): Meeting
    {
        $meeting = (new Meeting($convener, $group->getName(), (new \DateTimeImmutable($when))->setTime(10, 15)))
            ->setMeetingGroup($group)
            ->setMinutesApprovalRequired($approval);
        $meeting->addAttendee($attendee);
        $this->em->persist($meeting);

        return $meeting;
    }
}
