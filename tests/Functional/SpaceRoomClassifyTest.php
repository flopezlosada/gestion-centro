<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\Room;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\RoomKind;
use App\Enum\RoomSize;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Clasificar todo el catálogo de espacios en una pantalla.
 *
 * Lo que de verdad se fija aquí es lo que un envío NO debe hacer. La tabla lleva cuarenta filas con dos
 * casillas cada una, y una casilla sin marcar no manda nada: si el guardado leyera «lo que no viene es
 * un no», un envío parcial —un formulario a medio cargar, una petición a mano— cerraría en silencio a
 * reservas los espacios que no se mencionan. De ahí el marcador «presente» y el caso que lo prueba.
 */
final class SpaceRoomClassifyTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * A user logged in, with write access on Espacios when asked for.
     *
     * @param bool $canWriteSpaces whether to give them the area
     */
    private function login(string $email, bool $canWriteSpaces): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        if ($canWriteSpaces) {
            $role = (new Role())->setCode('espacios_test')->setName('Prueba de espacios')
                ->setLevel(Area::ESPACIOS, PermissionLevel::WRITE);
            $this->em->persist($role);
            $user->addAssignedRole($role);
        }
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    /**
     * A catalogue card as the synchroniser leaves it: code for a name, unclassified, both switches on.
     *
     * @param bool $active whether the space is still in use
     */
    private function stub(string $code, bool $active = true): Room
    {
        $room = (new Room())->setCode($code)->setName($code)->setActive($active);
        $this->em->persist($room);

        return $room;
    }

    /**
     * Reads the CSRF token out of the rendered table, which is the only way to get one: asking the token
     * manager outside a request throws, and a previous GET does not help because the client restarts the
     * kernel (see GuardiaPageTest::tokenFrom).
     */
    private function token(): string
    {
        $crawler = $this->client->request('GET', '/espacios/catalogo/clasificar');

        return (string) $crawler->filter('form input[name="_token"]')->attr('value');
    }

    public function testClassifyingNeedsWriteAccessOnSpaces(): void
    {
        $this->login('profe@centro.test', canWriteSpaces: false);

        $this->client->request('GET', '/espacios/catalogo/clasificar');

        self::assertResponseStatusCodeSame(403);
    }

    /** La tabla lista los espacios en uso y deja fuera los retirados, diciendo cuántos son. */
    public function testTheTableListsTheSpacesInUseAndCountsTheRetiredOnes(): void
    {
        $this->stub('2IN5');
        $this->stub('LABQ');
        $this->stub('AULA VIEJA', active: false);
        $this->em->flush();

        $this->login('direccion@centro.test', canWriteSpaces: true);
        $crawler = $this->client->request('GET', '/espacios/catalogo/clasificar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', '2IN5');
        self::assertSelectorTextContains('table', 'LABQ');
        self::assertStringNotContainsString('AULA VIEJA', $crawler->filter('table')->html());
        self::assertSelectorTextContains('body', '1 espacio retirado');
    }

    /** El caso normal: se rellena la tabla entera de un envío. */
    public function testTheWholeTableSavesInOneSubmit(): void
    {
        $classroom = $this->stub('2IN5');
        $lab = $this->stub('LABQ');
        $this->em->flush();

        $this->login('direccion@centro.test', canWriteSpaces: true);
        $this->client->request('POST', '/espacios/catalogo/clasificar', [
            '_token' => $this->token(),
            'presente' => [$classroom->getId() => '1', $lab->getId() => '1'],
            'nombre' => [$classroom->getId() => 'Aula de Inglés 5', $lab->getId() => ''],
            'tipo' => [$classroom->getId() => 'classroom', $lab->getId() => 'lab'],
            'tamano' => [$classroom->getId() => 'one_group', $lab->getId() => ''],
            // El laboratorio queda sin marcar en las dos casillas: el centro no lo abre a reservas ni le
            // manda grupos. Es lo que el envío tiene que poder decir.
            'reservable' => [$classroom->getId() => '1'],
            'asignable' => [$classroom->getId() => '1'],
        ]);

        self::assertResponseRedirects('/espacios/catalogo/clasificar');
        $this->em->clear();
        $rooms = $this->em->getRepository(Room::class);

        $saved = $rooms->findOneBy(['code' => '2IN5']);
        self::assertNotNull($saved);
        self::assertSame('Aula de Inglés 5', $saved->getName());
        self::assertSame(RoomKind::CLASSROOM, $saved->getKind());
        self::assertSame(RoomSize::ONE_GROUP, $saved->getSize());
        self::assertTrue($saved->isReservable());
        self::assertTrue($saved->isAssignable());

        $savedLab = $rooms->findOneBy(['code' => 'LABQ']);
        self::assertNotNull($savedLab);
        self::assertSame(RoomKind::LAB, $savedLab->getKind());
        self::assertNull($savedLab->getSize(), 'sin indicar sigue siendo sin indicar, no un tamaño inventado');
        self::assertFalse($savedLab->isReservable());
        self::assertFalse($savedLab->isAssignable());
        // El nombre en blanco es «llámalo como el horario», no un espacio sin nombre.
        self::assertSame('LABQ', $savedLab->getName());
    }

    /**
     * El caso que justifica el marcador: un envío que no menciona una fila la deja INTACTA. Sin él, las
     * casillas ausentes de esa fila se leerían como «desmarcadas» y el espacio se cerraría a reservas sin
     * que nadie lo haya pedido.
     */
    public function testARowTheRequestDoesNotMentionIsLeftAlone(): void
    {
        $mentioned = $this->stub('2IN5');
        $untouched = $this->stub('S ACTOS');
        $untouched->setKind(RoomKind::ASSEMBLY_HALL)->setSize(RoomSize::MANY_GROUPS);
        $this->em->flush();

        $this->login('direccion@centro.test', canWriteSpaces: true);
        $this->client->request('POST', '/espacios/catalogo/clasificar', [
            '_token' => $this->token(),
            'presente' => [$mentioned->getId() => '1'],
            'nombre' => [$mentioned->getId() => '2IN5'],
            'tipo' => [$mentioned->getId() => 'classroom'],
            'tamano' => [$mentioned->getId() => 'one_group'],
            'reservable' => [$mentioned->getId() => '1'],
            'asignable' => [$mentioned->getId() => '1'],
        ]);

        $this->em->clear();
        $hall = $this->em->getRepository(Room::class)->findOneBy(['code' => 'S ACTOS']);
        self::assertNotNull($hall);
        self::assertSame(RoomKind::ASSEMBLY_HALL, $hall->getKind());
        self::assertSame(RoomSize::MANY_GROUPS, $hall->getSize());
        self::assertTrue($hall->isReservable(), 'la fila no enviada conserva sus dos interruptores');
        self::assertTrue($hall->isAssignable());
    }

    /**
     * Un espacio retirado no se toca desde aquí aunque su id llegue en la petición: la tabla es la lista
     * de lo que se puede clasificar, así que se recorre la base y no lo que manda el navegador.
     */
    public function testARetiredSpaceIsNotTouchedEvenIfItsIdIsSubmitted(): void
    {
        $listed = $this->stub('2IN5');
        $retired = $this->stub('AULA VIEJA', active: false);
        $this->em->flush();

        $this->login('direccion@centro.test', canWriteSpaces: true);
        $this->client->request('POST', '/espacios/catalogo/clasificar', [
            '_token' => $this->token(),
            'presente' => [$listed->getId() => '1', $retired->getId() => '1'],
            'nombre' => [$listed->getId() => '2IN5', $retired->getId() => 'Colada'],
            'tipo' => [$listed->getId() => 'classroom', $retired->getId() => 'lab'],
            'tamano' => [$listed->getId() => 'one_group', $retired->getId() => 'small'],
        ]);

        $this->em->clear();
        $old = $this->em->getRepository(Room::class)->findOneBy(['code' => 'AULA VIEJA']);
        self::assertNotNull($old);
        self::assertSame('AULA VIEJA', $old->getName());
        self::assertSame(RoomKind::OTHER, $old->getKind());
        self::assertNull($old->getSize());
    }

    /**
     * Una fila con un tipo que no existe se rechaza ENTERA y se dice cuál: guardar la mitad que sí se
     * entiende dejaría una ficha que nadie puede distinguir de una completa.
     */
    public function testARowWithAnUnknownKindIsRejectedWholeAndReported(): void
    {
        $bad = $this->stub('2IN5');
        $good = $this->stub('LABQ');
        $this->em->flush();

        $this->login('direccion@centro.test', canWriteSpaces: true);
        $this->client->request('POST', '/espacios/catalogo/clasificar', [
            '_token' => $this->token(),
            'presente' => [$bad->getId() => '1', $good->getId() => '1'],
            'nombre' => [$bad->getId() => 'Nombre nuevo', $good->getId() => 'Laboratorio de Química'],
            'tipo' => [$bad->getId() => 'piscina', $good->getId() => 'lab'],
            'tamano' => [$bad->getId() => 'one_group', $good->getId() => 'one_group'],
        ]);

        $this->em->clear();
        $rooms = $this->em->getRepository(Room::class);

        $rejected = $rooms->findOneBy(['code' => '2IN5']);
        self::assertNotNull($rejected);
        self::assertSame('2IN5', $rejected->getName(), 'ni el nombre se guarda: la fila se rechaza entera');
        self::assertNull($rejected->getSize());

        // Y la fila buena del mismo envío sí se guarda: un dato malo no tira el trabajo de la sentada.
        $saved = $rooms->findOneBy(['code' => 'LABQ']);
        self::assertNotNull($saved);
        self::assertSame(RoomKind::LAB, $saved->getKind());

        // Y se dice cuál se ha quedado fuera, con su código: un «algo ha fallado» obligaría a repasar
        // cuarenta filas para encontrarlo.
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.warning', 'No se guardó 2IN5');
    }

    /** Sin token no se guarda nada: el catálogo decide qué ve el claustro en «Reservas». */
    public function testSavingWithoutAValidTokenIsRefused(): void
    {
        $room = $this->stub('2IN5');
        $this->em->flush();

        $this->login('direccion@centro.test', canWriteSpaces: true);
        $this->client->request('POST', '/espacios/catalogo/clasificar', [
            '_token' => 'malo',
            'presente' => [$room->getId() => '1'],
            'tipo' => [$room->getId() => 'lab'],
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame(RoomKind::OTHER, $this->em->getRepository(Room::class)->findOneBy(['code' => '2IN5'])?->getKind());
    }
}
