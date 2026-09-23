<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Topic;
use App\Entity\User;
use App\Enum\EducationLevel;
use App\Repository\TopicRepository;
use App\Service\TopicCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La lista de temas compartida por materia y nivel, que crece sola: escribir un tema nuevo lo añade al
 * final, y escribir uno que ya está —en otras mayúsculas o sin tilde, como se teclea en un móvil— es ese
 * mismo tema, nunca un segundo.
 */
final class TopicCatalogTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TopicCatalog $catalog;
    private User $teacher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->catalog = self::getContainer()->get(TopicCatalog::class);
        $this->teacher = (new User())->setFullName('Profe Temas')->setEmail('temas@educa.madrid.org');
        $this->em->persist($this->teacher);
        $this->em->flush();
    }

    public function testATypedTopicIsCreatedAtTheEndOfItsList(): void
    {
        $first = $this->resolve('Números racionales');
        $second = $this->resolve('Ecuaciones');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(1, $first->getPosition());
        self::assertSame(2, $second->getPosition());
        self::assertSame('Ecuaciones', $second->getName());
    }

    /** En mayúsculas o con espacios de más es el mismo tema: se compara como compara la base de datos. */
    public function testTheSameNameInOtherCaseOrAccentsIsTheSameTopic(): void
    {
        $topic = $this->resolve('Ecuaciones');
        $this->em->flush();

        self::assertSame($topic, $this->resolve('ECUACIONES'));
        self::assertSame($topic, $this->resolve('  ecuaciones '));
        self::assertCount(1, self::getContainer()->get(TopicRepository::class)->findAll());
    }

    /** Cada materia y nivel tiene su lista: el mismo nombre en otro nivel es otro tema. */
    public function testEachSubjectAndLevelHasItsOwnList(): void
    {
        $eso3 = $this->resolve('Ecuaciones');
        $eso4 = $this->catalog->resolve('Matemáticas', EducationLevel::ESO_4, 'Ecuaciones', $this->teacher);
        $this->em->flush();
        $noLevel = $this->catalog->resolve('Matemáticas', null, 'Ecuaciones', $this->teacher);
        $this->em->flush();

        self::assertNotSame($eso3, $eso4);
        self::assertNotSame($eso3, $noLevel);
        self::assertSame($noLevel, $this->catalog->resolve('Matemáticas', null, 'ecuaciones', $this->teacher), 'la lista sin nivel también se reconoce');
    }

    public function testNothingTypedIsNoTopic(): void
    {
        self::assertNull($this->resolve('   '));
    }

    public function testTheProgressIsThePlaceInTheList(): void
    {
        $this->resolve('Números racionales');
        $this->resolve('Potencias');
        $third = $this->resolve('Ecuaciones');
        $this->em->flush();

        self::assertSame(['position' => 3, 'total' => 3], $third ? $this->catalog->progressOf($third, $this->catalog->offeredFor('Matemáticas', EducationLevel::ESO_3)) : null);
    }

    /**
     * Resolves a typed name in the 3º ESO Matemáticas list and saves it, as the screen does: one topic per
     * request, flushed with the class plan that uses it.
     */
    private function resolve(string $typed): ?Topic
    {
        $topic = $this->catalog->resolve('Matemáticas', EducationLevel::ESO_3, $typed, $this->teacher);
        $this->em->flush();

        return $topic;
    }
}
