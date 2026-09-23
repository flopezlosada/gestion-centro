<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\LessonPlan;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\EducationLevel;
use App\Repository\TopicRepository;
use App\Service\TopicAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lo que la jefatura de departamento hace con una lista de temas: guardar renombrados, reordenados y
 * retirados; crear los que vinieron de un pegado y se mantuvieron; y fusionar un duplicado sin perder las
 * clases ya programadas con él.
 */
final class TopicAdminTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TopicAdmin $admin;
    private TopicRepository $topics;
    private User $head;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->admin = self::getContainer()->get(TopicAdmin::class);
        $this->topics = self::getContainer()->get(TopicRepository::class);
        $this->head = (new User())->setFullName('Jefa Lengua')->setEmail('jefa.temas@educa.madrid.org');
        $this->em->persist($this->head);
        $this->em->flush();
    }

    /**
     * @param list<string> $names topic names, in order
     *
     * @return list<Topic> the persisted topics
     */
    private function seed(array $names): array
    {
        $topics = [];
        foreach ($names as $i => $name) {
            $topic = new Topic('Lengua', EducationLevel::ESO_3, $name, $i + 1, $this->head);
            $this->em->persist($topic);
            $topics[] = $topic;
        }
        $this->em->flush();

        return $topics;
    }

    public function testRenamesReordersAndRetiresExistingTopics(): void
    {
        [$first, $second] = $this->seed(['El Quijote', 'La Celestina']);

        $this->admin->apply('Lengua', EducationLevel::ESO_3, [
            ['id' => $first->getId(), 'name' => 'Don Quijote', 'position' => 2, 'retired' => false],
            ['id' => $second->getId(), 'name' => 'La Celestina', 'position' => 1, 'retired' => true],
        ], $this->head);

        $this->em->clear();
        $list = $this->topics->findListFor('Lengua', EducationLevel::ESO_3);
        self::assertSame('La Celestina', $list[0]->getName());
        self::assertTrue($list[0]->isRetired());
        self::assertSame('Don Quijote', $list[1]->getName());
        self::assertFalse($list[1]->isRetired());
    }

    /** Una fila nueva (sin id, del pegado que se mantuvo) se crea; una en blanco se descarta sin más. */
    public function testCreatesNewRowsAndDropsBlankOnes(): void
    {
        $this->admin->apply('Lengua', EducationLevel::ESO_3, [
            ['id' => null, 'name' => 'Romanticismo', 'position' => 1, 'retired' => false],
            ['id' => null, 'name' => '  ', 'position' => 2, 'retired' => false],
        ], $this->head);

        $list = $this->topics->findListFor('Lengua', EducationLevel::ESO_3);
        self::assertCount(1, $list);
        self::assertSame('Romanticismo', $list[0]->getName());
    }

    /** Una fila cuyo id no es de esta lista (otra materia, o ya no existe) se ignora, no se adopta. */
    public function testIgnoresARowWhoseIdIsNotInThisList(): void
    {
        $foreign = new Topic('Matemáticas', EducationLevel::ESO_3, 'Ecuaciones', 1, $this->head);
        $this->em->persist($foreign);
        $this->em->flush();

        $this->admin->apply('Lengua', EducationLevel::ESO_3, [
            ['id' => $foreign->getId(), 'name' => 'Intruso', 'position' => 1, 'retired' => false],
        ], $this->head);

        self::assertSame([], $this->topics->findListFor('Lengua', EducationLevel::ESO_3));
        $this->em->clear();
        self::assertSame('Ecuaciones', $this->topics->find($foreign->getId())?->getName(), 'la lista ajena no se toca');
    }

    /** Fusionar repunta las clases ya programadas y borra el duplicado; no pierde nada. */
    public function testMergingRepointsPlansAndRemovesTheDuplicate(): void
    {
        [$kept, $duplicate] = $this->seed(['Don Quijote', 'El Quijote']);
        $teacher = (new User())->setFullName('Profe Lengua')->setEmail('profe.temas@educa.madrid.org');
        $this->em->persist($teacher);
        $plan = (new LessonPlan($teacher, new \DateTimeImmutable('2026-02-02'), 3, 'E3A', 'Lengua', EducationLevel::ESO_3))
            ->plan($duplicate, null, null, null);
        $this->em->persist($plan);
        $this->em->flush();

        // El id se lee ANTES de fusionar: tras remove()+flush(), Doctrine anula el identificador del
        // objeto en memoria para una clave autogenerada (UnitOfWork::executeDeletions()), así que
        // llamarlo después lanzaría MissingIdentifierField al construir la consulta de comprobación.
        $duplicateId = $duplicate->getId();
        $this->admin->merge($duplicate, $kept);

        $this->em->clear();
        self::assertNull($this->topics->find($duplicateId), 'el duplicado desaparece');
        $reloaded = $this->em->getRepository(LessonPlan::class)->find($plan->getId());
        self::assertSame($kept->getId(), $reloaded?->getTopic()?->getId(), 'la clase ya programada apunta ahora al que se mantiene');
    }

    public function testCannotMergeATopicIntoItself(): void
    {
        [$topic] = $this->seed(['Don Quijote']);

        $this->expectException(\InvalidArgumentException::class);
        $this->admin->merge($topic, $topic);
    }

    /** Fusionar entre dos listas distintas cambiaría en silencio lo que significa el tema de una clase. */
    public function testCannotMergeAcrossDifferentScopes(): void
    {
        [$fromLengua] = $this->seed(['Don Quijote']);
        $mathsTopic = new Topic('Matemáticas', EducationLevel::ESO_3, 'Ecuaciones', 1, $this->head);
        $this->em->persist($mathsTopic);
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->admin->merge($fromLengua, $mathsTopic);
    }
}
