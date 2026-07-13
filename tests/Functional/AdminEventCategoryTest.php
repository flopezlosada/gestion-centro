<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EventCategory;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\CategoryColor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The admin catalogue of event categories: an admin can create and delete them; a plain teacher
 * cannot reach the section at all.
 */
final class AdminEventCategoryTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function admin(): User
    {
        $adminRole = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        $this->em->persist($adminRole);
        $user = (new User())->setFullName('Directora Test')->setEmail('director@centro.test')->addAssignedRole($adminRole);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testAdminCanCreateACategory(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/categorias-evento/nueva');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Guardar')->form();
        $form['event_category[name]'] = 'Claustro';
        $form['event_category[color]'] = 'blue';
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/categorias-evento');
        $this->em->clear();
        $category = $this->em->getRepository(EventCategory::class)->findOneBy(['name' => 'Claustro']);
        self::assertNotNull($category);
        self::assertSame(CategoryColor::BLUE, $category->getColor());
    }

    public function testAdminCanDeleteACategory(): void
    {
        $this->client->loginUser($this->admin());
        $category = (new EventCategory())->setName('Temporal')->setColor(CategoryColor::RED);
        $this->em->persist($category);
        $this->em->flush();
        $id = (int) $category->getId();

        $crawler = $this->client->request('GET', '/admin/categorias-evento');
        $this->client->submit($crawler->filter('form[action="/admin/categorias-evento/'.$id.'/borrar"]')->form());

        self::assertResponseRedirects('/admin/categorias-evento');
        $this->em->clear();
        self::assertNull($this->em->getRepository(EventCategory::class)->find($id));
    }

    public function testDuplicateCategoryNameIsRejected(): void
    {
        // A name outside the seeded catalogue (the migration seeds General/Docencia/Reunión/Tutoría/
        // Personal, present in the test DB), so this test owns the only "Claustro".
        $this->client->loginUser($this->admin());
        $this->em->persist((new EventCategory())->setName('Claustro')->setColor(CategoryColor::TEAL));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/categorias-evento/nueva');
        $form = $crawler->selectButton('Guardar')->form();
        $form['event_category[name]'] = 'Claustro';
        $form['event_category[color]'] = 'blue';
        $this->client->submit($form);

        // The unique-name constraint rejects it: the form is redisplayed (422) and no second row is created.
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Ya existe una categoría', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $this->em->getRepository(EventCategory::class)->findBy(['name' => 'Claustro']));
    }

    public function testTeacherCannotReachTheCategoriesAdmin(): void
    {
        $teacher = (new User())->setFullName('Docente Test')->setEmail('profe@centro.test');
        $this->em->persist($teacher);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/admin/categorias-evento');

        self::assertResponseStatusCodeSame(403);
    }
}
