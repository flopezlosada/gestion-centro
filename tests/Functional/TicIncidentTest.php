<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\TicIncident;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\IncidentStatus;
use App\Enum\PermissionLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El registro de incidencias TIC. Lo que se comprueba aquí es sobre todo la asimetría del permiso:
 * AVISAR lo puede hacer cualquiera (quien se encuentra la avería es quien tenía clase ahí) y RESOLVER
 * solo quien lleva TIC. Y que un equipo de uso individual no arrastra un grupo, que es la contradicción
 * que el centro pidió evitar.
 */
final class TicIncidentTest extends WebTestCase
{
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

    public function testOnlyTicCanResolveAnIncident(): void
    {
        $teacher = $this->user('profe@centro.test');
        $tic = $this->user('tic@centro.test', true);
        $incident = (new TicIncident())->setEquipment('Proyector')->setDescription('No da señal.')->setReportedBy($teacher);
        $this->em->persist($incident);
        $this->em->flush();
        $id = (int) $incident->getId();

        // Quien avisó no ve el bloque de gestionar…
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/incidencias/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/estado"]');

        // …y si lo intenta a pelo, tampoco.
        $this->client->request('POST', '/incidencias/'.$id.'/estado', ['estado' => 'resolved', 'nota' => 'ya está']);
        self::assertResponseStatusCodeSame(403);

        // Coordinación TIC sí.
        $this->client->loginUser($tic);
        $crawler = $this->client->request('GET', '/incidencias/'.$id);
        $token = $crawler->filter('form[action$="/estado"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/incidencias/'.$id.'/estado', [
            '_token' => $token,
            'estado' => 'resolved',
            'nota' => 'Cambiado el cable HDMI.',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(TicIncident::class)->find($id);
        self::assertNotNull($reloaded);
        self::assertSame(IncidentStatus::RESOLVED, $reloaded->getStatus());
        self::assertSame('Cambiado el cable HDMI.', $reloaded->getResolutionNote());
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
