<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\TicIncident;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\IncidentStatus;
use App\Enum\PermissionLevel;
use App\Tests\Support\MintsSessionCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El registro de incidencias TIC. Lo que se comprueba aquí es sobre todo la asimetría del permiso:
 * AVISAR y CERRAR lo puede hacer cualquiera (quien se encuentra la avería es quien tenía clase ahí, y
 * quien la arregla al momento lo dice), y LLEVAR LA COLA (cogerla, reabrirla) solo quien lleva TIC. Y
 * que un equipo de uso individual no arrastra un grupo, que es la contradicción que el centro pidió evitar.
 */
final class TicIncidentTest extends WebTestCase
{
    use MintsSessionCsrfTokens;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email, bool $handlesTic = false): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        if ($handlesTic) {
            $role = (new Role())->setCode('tic_'.uniqid())->setName('Coordinación TIC');
            $role->setLevel(Area::TIC, PermissionLevel::WRITE);
            $this->em->persist($role);
            $user->addAssignedRole($role);
        }
        $this->em->persist($user);

        return $user;
    }

    public function testAnybodyCanReportAnIncident(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/incidencias/nueva');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Registrar la incidencia')->form();
        $form['tic_incident[equipment]'] = 'Proyector';
        $form['tic_incident[description]'] = 'No da señal con ningún portátil.';
        $form['tic_incident[occurredAt]'] = '2026-09-15T11:30';
        $form['tic_incident[priority]'] = 'high';
        $form['tic_incident[groupName]'] = '2ºB';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $incident = $this->em->getRepository(TicIncident::class)->findOneBy(['equipment' => 'Proyector']);
        self::assertNotNull($incident);
        self::assertSame('2ºB', $incident->getGroupName());
        self::assertFalse($incident->isIndividualUse());
        self::assertSame(IncidentStatus::OPEN, $incident->getStatus());
        // El autor sale de la sesión, nunca del formulario.
        self::assertSame('profe@centro.test', $incident->getReportedBy()?->getEmail());
    }

    /**
     * "Si es un equipo individual, no se incorpora el nombre del grupo": aunque el grupo venga en el
     * envío (con el JavaScript apagado el campo sigue ahí), no se guarda.
     */
    public function testIndividualUseDropsTheGroupEvenIfItIsSent(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/incidencias/nueva');
        $form = $crawler->selectButton('Registrar la incidencia')->form();
        $form['tic_incident[equipment]'] = 'Portátil de jefatura';
        $form['tic_incident[description]'] = 'No carga.';
        $form['tic_incident[occurredAt]'] = '2026-09-15T11:30';
        $form['tic_incident[priority]'] = 'low';
        $form['tic_incident[individualUse]'] = '1';
        $form['tic_incident[groupName]'] = '2ºB';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $incident = $this->em->getRepository(TicIncident::class)->findOneBy(['equipment' => 'Portátil de jefatura']);
        self::assertNotNull($incident);
        self::assertTrue($incident->isIndividualUse());
        self::assertNull($incident->getGroupName(), 'un equipo individual no arrastra un grupo');
    }

    /**
     * Cerrarla es de cualquiera: quien lo arregló al momento (no tiene por qué ser quien avisó) o quien la
     * abrió por error, para que TIC no se desplace en balde. Queda su nombre y lo que hizo.
     */
    public function testAnyTeacherCanCloseAnOpenIncident(): void
    {
        $reporter = $this->user('pili@centro.test');
        $fixer = $this->user('juan@centro.test');
        $incident = (new TicIncident())->setEquipment('Proyector')->setDescription('No da señal.')->setReportedBy($reporter);
        $this->em->persist($incident);
        $this->em->flush();
        $id = (int) $incident->getId();

        $this->client->loginUser($fixer);
        $crawler = $this->client->request('GET', '/incidencias/'.$id);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('button[name="estado"][value="resolved"]'));
        self::assertCount(0, $crawler->filter('button[name="estado"][value="in_progress"]'), 'cogerla es de TIC');
        $token = $crawler->filter('form[action$="/estado"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/incidencias/'.$id.'/estado', ['_token' => $token, 'estado' => 'resolved', 'nota' => 'Estaba desenchufado.']);

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(TicIncident::class)->find($id);
        self::assertNotNull($reloaded);
        self::assertSame(IncidentStatus::RESOLVED, $reloaded->getStatus());
        self::assertSame('Estaba desenchufado.', $reloaded->getResolutionNote());
        self::assertSame('juan@centro.test', $reloaded->getResolvedBy()?->getEmail());
    }

    /**
     * Llevar la cola sigue siendo de TIC: cogerla y reabrirla. Con token válido, para que el 403 sea por el
     * permiso y no por el token.
     */
    public function testOnlyTicTakesOnOrReopensAnIncident(): void
    {
        $teacher = $this->user('profe@centro.test');
        $tic = $this->user('tic@centro.test', true);
        $open = (new TicIncident())->setEquipment('Proyector')->setDescription('No da señal.')->setReportedBy($teacher);
        $closed = (new TicIncident())->setEquipment('Pizarra')->setDescription('No toca.')->setReportedBy($teacher)
            ->moveTo(IncidentStatus::RESOLVED, $tic, 'Calibrada.');
        $this->em->persist($open);
        $this->em->persist($closed);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $post = function (TicIncident $incident, string $status): void {
            $id = (int) $incident->getId();
            $token = $this->mintCsrfToken($this->client, '/incidencias/'.$id, 'tic_incident_move'.$id);
            $this->client->request('POST', '/incidencias/'.$id.'/estado', ['_token' => $token, 'estado' => $status, 'nota' => 'otra cosa']);
        };

        $post($open, 'in_progress');
        self::assertResponseStatusCodeSame(403);
        $post($closed, 'open');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/incidencias/'.$closed->getId());
        self::assertSelectorNotExists('form[action$="/estado"]');

        $this->client->loginUser($tic);
        $post($closed, 'open');
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame(IncidentStatus::OPEN, $this->em->getRepository(TicIncident::class)->find($closed->getId())?->getStatus());
    }

    /**
     * Quien no es de TIC también puede cerrar una que TIC ya había cogido (la arregló antes de que llegaran),
     * y TIC cierra las suyas como siempre.
     */
    public function testAnyoneClosesOneInProgressAndTicClosesItsOwn(): void
    {
        $teacher = $this->user('profe@centro.test');
        $tic = $this->user('tic@centro.test', true);
        $taken = (new TicIncident())->setEquipment('Proyector')->setDescription('No da señal.')->setReportedBy($teacher)
            ->moveTo(IncidentStatus::IN_PROGRESS, $tic, '');
        $open = (new TicIncident())->setEquipment('Pizarra')->setDescription('No toca.')->setReportedBy($teacher);
        $this->em->persist($taken);
        $this->em->persist($open);
        $this->em->flush();

        $close = function (User $who, TicIncident $incident, string $note): void {
            $id = (int) $incident->getId();
            $this->client->loginUser($who);
            $token = $this->mintCsrfToken($this->client, '/incidencias/'.$id, 'tic_incident_move'.$id);
            $this->client->request('POST', '/incidencias/'.$id.'/estado', ['_token' => $token, 'estado' => 'resolved', 'nota' => $note]);
            self::assertResponseRedirects();
        };
        $close($teacher, $taken, 'Era el cable; ya va.');
        $close($tic, $open, 'Calibrada.');

        $this->em->clear();
        $repository = $this->em->getRepository(TicIncident::class);
        foreach ([[$taken, 'profe@centro.test', 'Era el cable; ya va.'], [$open, 'tic@centro.test', 'Calibrada.']] as [$incident, $by, $note]) {
            $reloaded = $repository->find($incident->getId());
            self::assertNotNull($reloaded);
            self::assertSame(IncidentStatus::RESOLVED, $reloaded->getStatus());
            self::assertSame($by, $reloaded->getResolvedBy()?->getEmail());
            self::assertSame($note, $reloaded->getResolutionNote());
        }
    }

    /**
     * Con la página vieja abierta, otra persona la cerró mientras tanto: se explica con un aviso en vez de
     * un 403, y el primer cierre se queda como estaba. También si quien reenvía es TIC: su nombre y su nota
     * no deben borrar los del docente que la arregló.
     */
    public function testClosingOneAlreadyClosedFromAStalePageKeepsTheFirstClosure(): void
    {
        $teacher = $this->user('profe@centro.test');
        $tic = $this->user('tic@centro.test', true);
        $closed = (new TicIncident())->setEquipment('Pizarra')->setDescription('No toca.')->setReportedBy($teacher)
            ->moveTo(IncidentStatus::RESOLVED, $tic, 'Calibrada.');
        $this->em->persist($closed);
        $this->em->flush();
        $id = (int) $closed->getId();

        foreach ([$teacher, $this->user('tic2@centro.test', true)] as $who) {
            $this->em->flush();
            $this->client->loginUser($who);
            $token = $this->mintCsrfToken($this->client, '/incidencias/'.$id, 'tic_incident_move'.$id);
            $this->client->request('POST', '/incidencias/'.$id.'/estado', ['_token' => $token, 'estado' => 'resolved', 'nota' => 'Ya va.']);

            self::assertResponseRedirects('/incidencias/'.$id);
            $this->client->followRedirect();
            self::assertSelectorTextContains('.flash', 'Ya estaba cerrada');
        }
        $this->em->clear();
        $reloaded = $this->em->getRepository(TicIncident::class)->find($id);
        self::assertNotNull($reloaded);
        self::assertSame('Calibrada.', $reloaded->getResolutionNote());
        self::assertSame('tic@centro.test', $reloaded->getResolvedBy()?->getEmail());
    }

    /** Cerrar sin decir qué se hizo deja el registro inservible: el servidor lo frena. */
    public function testResolvingNeedsSayingWhatWasDone(): void
    {
        $tic = $this->user('tic@centro.test', true);
        $incident = (new TicIncident())->setEquipment('Proyector')->setDescription('No da señal.')->setReportedBy($tic);
        $this->em->persist($incident);
        $this->em->flush();
        $id = (int) $incident->getId();

        $this->client->loginUser($tic);
        $crawler = $this->client->request('GET', '/incidencias/'.$id);
        $token = $crawler->filter('form[action$="/estado"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/incidencias/'.$id.'/estado', ['_token' => $token, 'estado' => 'resolved', 'nota' => '']);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame(IncidentStatus::OPEN, $this->em->getRepository(TicIncident::class)->find($id)?->getStatus());
    }

    /** La lista enseña por defecto lo que sigue roto; el histórico está a un clic. */
    public function testTheListShowsWhatIsStillOpenByDefault(): void
    {
        $tic = $this->user('tic@centro.test', true);
        $open = (new TicIncident())->setEquipment('Proyector del aula 12')->setDescription('No enciende.')->setReportedBy($tic);
        $done = (new TicIncident())->setEquipment('Altavoces del salón')->setDescription('Zumbaban.')->setReportedBy($tic);
        $done->moveTo(IncidentStatus::RESOLVED, $tic, 'Cambiado el cable.');
        $this->em->persist($open);
        $this->em->persist($done);
        $this->em->flush();

        $this->client->loginUser($tic);
        $this->client->request('GET', '/incidencias');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.incident-list', 'Proyector del aula 12');
        self::assertStringNotContainsString('Altavoces del salón', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/incidencias?ver=todas');
        self::assertStringContainsString('Altavoces del salón', (string) $this->client->getResponse()->getContent());
    }
}
