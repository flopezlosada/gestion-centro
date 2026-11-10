<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\GuardiaTaskBankItem;
use App\Enum\EducationLevel;
use App\Guardia\TaskBankFolder;
use App\Guardia\TaskBankSubjectFolder;
use PHPUnit\Framework\TestCase;

/**
 * The bank listing as folders ({@see TaskBankFolder::group()}): levels in teaching order, subjects
 * inside, the listing's own order kept within each folder, and which folders come unfolded.
 */
final class TaskBankFolderTest extends TestCase
{
    private int $nextId = 1;

    /**
     * A bank task with an id, as the listing hands them over.
     *
     * @param EducationLevel $level   the level
     * @param string         $subject the subject
     * @param string         $title   the title
     *
     * @return GuardiaTaskBankItem the task
     */
    private function task(EducationLevel $level, string $subject, string $title): GuardiaTaskBankItem
    {
        $item = (new GuardiaTaskBankItem())->setLevel($level)->setSubject($subject)->setTitle($title);
        (new \ReflectionProperty(GuardiaTaskBankItem::class, 'id'))->setValue($item, $this->nextId++);

        return $item;
    }

    /**
     * The titles of a subject folder, in order.
     *
     * @param TaskBankSubjectFolder $folder the folder
     *
     * @return list<string> the titles
     */
    private static function titles(TaskBankSubjectFolder $folder): array
    {
        return array_map(static fn (GuardiaTaskBankItem $i): string => $i->getTitle(), $folder->items);
    }

    /**
     * An empty listing yields no folders at all.
     */
    public function testAnEmptyListingHasNoFolders(): void
    {
        self::assertSame([], TaskBankFolder::group([]));
    }

    /**
     * Levels follow teaching order whatever the listing order; subjects and tasks keep the listing order.
     */
    public function testLevelsGoInTeachingOrderAndSubjectsInTheOrderTheyFirstAppear(): void
    {
        // Bachillerato llega antes en la lista (el orden de "menos usadas") y aun así su carpeta va
        // detrás de las de ESO: las carpetas siguen el desplegable de niveles, no el recuento de usos.
        $folders = TaskBankFolder::group([
            $this->task(EducationLevel::BACH_1, 'Filosofía', 'Mito de la caverna'),
            $this->task(EducationLevel::ESO_1, 'Matemáticas', 'Fracciones'),
            $this->task(EducationLevel::ESO_1, 'Lengua', 'Dictado'),
            $this->task(EducationLevel::ESO_1, 'Matemáticas', 'Decimales'),
        ]);

        self::assertSame([EducationLevel::ESO_1, EducationLevel::BACH_1], array_map(static fn (TaskBankFolder $f): EducationLevel => $f->level, $folders));
        self::assertSame(['Matemáticas', 'Lengua'], array_map(static fn (TaskBankSubjectFolder $f): string => $f->subject, $folders[0]->subjects));
        self::assertSame(['Fracciones', 'Decimales'], self::titles($folders[0]->subjects[0]), 'dentro de la carpeta manda el orden del listado');
        self::assertSame(3, $folders[0]->count());
        self::assertSame(1, $folders[1]->count());
    }

    /**
     * With a choice to make at each depth, folders come folded; a lone subject inside a level comes unfolded.
     */
    public function testWithSeveralLevelsAndSubjectsEverythingComesFolded(): void
    {
        $folders = TaskBankFolder::group([
            $this->task(EducationLevel::ESO_1, 'Matemáticas', 'Fracciones'),
            $this->task(EducationLevel::ESO_1, 'Lengua', 'Dictado'),
            $this->task(EducationLevel::ESO_2, 'Matemáticas', 'Ecuaciones'),
        ]);

        self::assertFalse($folders[0]->open);
        self::assertFalse($folders[0]->subjects[0]->open);
        self::assertFalse($folders[0]->subjects[1]->open);
        self::assertFalse($folders[1]->open);
        // Una sola materia en su nivel: abrir el nivel y encontrarse otra carpeta cerrada es un clic de más.
        self::assertTrue($folders[1]->subjects[0]->open);
    }

    /**
     * A listing narrowed to one level shows that level unfolded.
     */
    public function testTheOnlyLevelComesUnfolded(): void
    {
        // Filtrado a un nivel, una carpeta cerrada sola en la pantalla sería un clic sin elección.
        $folders = TaskBankFolder::group([
            $this->task(EducationLevel::ESO_3, 'Matemáticas', 'Fracciones'),
            $this->task(EducationLevel::ESO_3, 'Lengua', 'Dictado'),
        ]);

        self::assertCount(1, $folders);
        self::assertTrue($folders[0]->open);
        self::assertFalse($folders[0]->subjects[0]->open);
    }

    /**
     * The folders holding the task to show come unfolded, and only those.
     */
    public function testTheFoldersHoldingTheSuggestedTaskComeUnfolded(): void
    {
        $suggested = $this->task(EducationLevel::ESO_2, 'Lengua', 'Sugerida');
        $folders = TaskBankFolder::group([
            $this->task(EducationLevel::ESO_1, 'Matemáticas', 'Fracciones'),
            $this->task(EducationLevel::ESO_2, 'Matemáticas', 'Ecuaciones'),
            $suggested,
        ], $suggested->getId());

        self::assertFalse($folders[0]->open);
        self::assertTrue($folders[1]->open);
        self::assertFalse($folders[1]->subjects[0]->open);
        self::assertTrue($folders[1]->subjects[1]->open);
    }

    /**
     * A subject that looks like a number still reaches the folder as a string.
     */
    public function testASubjectNamedWithDigitsStaysAString(): void
    {
        // PHP convierte en entera una clave de array que parece un número: la carpeta tiene que seguir
        // diciendo "2024", no fallar por tipo.
        $folders = TaskBankFolder::group([$this->task(EducationLevel::ESO_1, '2024', 'Proyecto')]);

        self::assertSame('2024', $folders[0]->subjects[0]->subject);
    }
}
