<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\Material;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Reservar espacios y material. Lo que de verdad importa aquí es que dos personas no puedan tener la
 * misma cosa a la misma hora — y que quien la tenga sea quien la pidió primero, no quien recargue la
 * pantalla más rápido.
 */
final class BookingTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    private function material(string $name): Material
    {
        $material = (new Material())->setName($name)->setKeptAt('Conserjería');
        $this->em->persist($material);

        return $material;
    }

    /**
     * Sends the booking form for a day and period.
     *
     * @param string $resource the resource key, e.g. "material:3"
     */
    private function book(string $resource, string $day, int $slot, string $purpose = 'Grabación del podcast'): void
    {
        $crawler = $this->client->request('GET', '/reservas?fecha='.$day);
        $token = (string) $crawler->filter('form[action="/reservas/nueva"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/reservas/nueva', [
            '_token' => $token,
            'fecha' => $day,
            'tramo' => (string) $slot,
            'recurso' => $resource,
            'motivo' => $purpose,
        ]);
    }

    public function testAnybodyCanBookMaterialAndItShowsOnTheDay(): void
    {
        $teacher = $this->user('profe@centro.test');
        $radio = $this->material('Radio');
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->book('material:'.$radio->getId(), '2026-09-15', 2);

        self::assertResponseRedirects('/reservas?fecha=2026-09-15');
        $this->em->clear();
        $stored = $this->em->getRepository(Booking::class)->findOneBy(['purpose' => 'Grabación del podcast']);
        self::assertNotNull($stored);
        self::assertSame(2, $stored->getSlotIndex());
        self::assertSame('Radio', $stored->resourceName());
        self::assertSame('profe@centro.test', $stored->getBookedBy()?->getEmail());

        $this->client->request('GET', '/reservas?fecha=2026-09-15');
        self::assertSelectorTextContains('.incident-list', 'Radio');
    }

    /**
     * El choque lo impide la base de datos, no una comprobación previa: con un check-then-insert habría
     * una rendija en la que dos personas pidiendo lo mismo a la vez ganarían las dos.
     */
    public function testTheSameThingCannotBeBookedTwiceForTheSamePeriod(): void
    {
        $first = $this->user('primera@centro.test');
        $second = $this->user('segunda@centro.test');
        $radio = $this->material('Radio');
        $this->em->flush();
        $key = 'material:'.$radio->getId();

        $this->client->loginUser($first);
        $this->book($key, '2026-09-15', 2, 'Podcast');
        self::assertResponseRedirects();

        $this->client->loginUser($second);
        $this->book($key, '2026-09-15', 2, 'Otra cosa');
        self::assertResponseRedirects();

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Booking::class)->findAll(), 'solo la primera reserva existe');
        self::assertNull($this->em->getRepository(Booking::class)->findOneBy(['purpose' => 'Otra cosa']));

        // Y la segunda persona lee por qué, en vez de encontrarse la pantalla igual sin explicación.
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'lo ha reservado otra persona');
    }

    /** La misma cosa a OTRA hora sí: el choque es por tramo, no por día. */
    public function testTheSameThingCanBeBookedForAnotherPeriod(): void
    {
        $teacher = $this->user('profe@centro.test');
        $radio = $this->material('Radio');
        $this->em->flush();
        $key = 'material:'.$radio->getId();

        $this->client->loginUser($teacher);
        $this->book($key, '2026-09-15', 2, 'Podcast a segunda');
        $this->book($key, '2026-09-15', 3, 'Podcast a tercera');

        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(Booking::class)->findAll());
    }

    public function testOnlyTheOwnerCancelsTheirBooking(): void
    {
        $owner = $this->user('duena@centro.test');
        $other = $this->user('otra@centro.test');
        $radio = $this->material('Radio');
        $this->em->flush();

        $booking = Booking::forMaterial($owner, $radio, new \DateTimeImmutable('2026-09-15'), 2, 'Podcast');
        $this->em->persist($booking);
        $this->em->flush();
        $id = (int) $booking->getId();

        // Una persona ajena no ve el botón y, si lo intenta a pelo, tampoco.
        $this->client->loginUser($other);
        $this->client->request('GET', '/reservas?fecha=2026-09-15');
        self::assertSelectorNotExists('form[action$="/anular"]');
        $this->client->request('POST', '/reservas/'.$id.'/anular', ['_token' => 'malo']);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Booking::class)->find($id));
    }
}
