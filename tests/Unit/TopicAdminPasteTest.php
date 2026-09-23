<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Topic;
use App\Entity\User;
use App\Enum\EducationLevel;
use App\Repository\LessonPlanRepository;
use App\Repository\TopicRepository;
use App\Service\TopicAdmin;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pegar una programación: limpia la numeración habitual, quita líneas vacías y duplicadas —dentro del
 * pegado y contra lo que ya hay en la lista— y no escribe nada. Con dobles de {@see TopicRepository} y
 * {@see EntityManagerInterface}: lo que se comprueba es el análisis del texto, no la persistencia.
 */
final class TopicAdminPasteTest extends TestCase
{
    /**
     * @param list<Topic> $existing the list's current topics
     */
    private function admin(array $existing = []): TopicAdmin
    {
        $topics = $this->createMock(TopicRepository::class);
        $topics->method('findListFor')->willReturn($existing);

        return new TopicAdmin($topics, $this->createMock(LessonPlanRepository::class), $this->createMock(EntityManagerInterface::class));
    }

    private function topic(string $name): Topic
    {
        return new Topic('Matemáticas', EducationLevel::ESO_3, $name, 1, null);
    }

    public function testStripsCommonNumberingPrefixes(): void
    {
        $raw = "Tema 1. Números racionales\nUD 2 - Potencias y raíces\n3) Polinomios\n4 - Ecuaciones\nTema5: Funciones";

        self::assertSame(
            ['Números racionales', 'Potencias y raíces', 'Polinomios', 'Ecuaciones', 'Funciones'],
            $this->admin()->parsePaste('Matemáticas', EducationLevel::ESO_3, $raw),
        );
    }

    public function testDropsBlankLinesAndDuplicatesWithinThePaste(): void
    {
        $raw = "Ecuaciones\n\n   \nECUACIONES\nPolinomios";

        self::assertSame(['Ecuaciones', 'Polinomios'], $this->admin()->parsePaste('Matemáticas', EducationLevel::ESO_3, $raw));
    }

    /** Un nombre que ya está en la lista (aunque venga con otras mayúsculas) no se propone otra vez. */
    public function testDropsNamesAlreadyInTheList(): void
    {
        $raw = "ecuaciones\nPolinomios";

        self::assertSame(
            ['Polinomios'],
            $this->admin([$this->topic('Ecuaciones')])->parsePaste('Matemáticas', EducationLevel::ESO_3, $raw),
        );
    }

    public function testCapsTheNumberOfLinesAccepted(): void
    {
        $raw = implode("\n", array_map(static fn (int $i): string => "Tema $i", range(1, TopicAdmin::MAX_PASTED_LINES + 10)));

        self::assertCount(TopicAdmin::MAX_PASTED_LINES, $this->admin()->parsePaste('Matemáticas', EducationLevel::ESO_3, $raw));
    }

    public function testCapsTheLengthOfALine(): void
    {
        $long = str_repeat('a', Topic::MAX_NAME + 50);

        self::assertSame([str_repeat('a', Topic::MAX_NAME)], $this->admin()->parsePaste('Matemáticas', EducationLevel::ESO_3, $long));
    }

    public function testAnEmptyPasteYieldsNoTopics(): void
    {
        self::assertSame([], $this->admin()->parsePaste('Matemáticas', EducationLevel::ESO_3, "\n  \n"));
    }
}
