<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DueDate\FixedDate;
use App\DueDate\PerTerm;
use App\Entity\AcademicYear;
use App\Entity\Task;
use App\Entity\TaskTemplate;
use App\Enum\TaskType;
use App\Enum\TermBoundary;
use App\Service\TaskGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Generation turns the catalogue into a course's tasks: active templates with a deadline rule become
 * tasks (one per resolved date), templates without a rule are skipped, and a re-run adds nothing new.
 */
final class TaskGeneratorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TaskGenerator $generator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->generator = self::getContainer()->get(TaskGenerator::class);
    }

    private function year(): AcademicYear
    {
        $year = (new AcademicYear())
            ->setSchoolYear('2026-2027')
            ->setTerm1Start(new \DateTimeImmutable('2026-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2026-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2027-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2027-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2027-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2027-06-22'));
        $this->em->persist($year);

        return $year;
    }

    private function template(string $title, bool $active, ?object $rule): TaskTemplate
    {
        $template = (new TaskTemplate())->setTitle($title)->setType(TaskType::SIMPLE)->setActive($active);
        if ($rule instanceof \App\DueDate\DueDateRule) {
            $template->setDueDateRule($rule);
        }
        $this->em->persist($template);

        return $template;
    }

    public function testGeneratesOneTaskPerResolvedDateAndSkipsRuleless(): void
    {
        $year = $this->year();
        $perTerm = $this->template('Acta de reunión', true, new PerTerm(TermBoundary::END));
        $this->template('Tarea sin regla', true, null);
        $this->em->flush();

        $result = $this->generator->generate($year, null);

        self::assertSame(3, $result->created, 'three term-end dates');
        self::assertSame(1, $result->skippedWithoutRule);
        self::assertSame(0, $result->skippedExisting);

        $tasks = $this->em->getRepository(Task::class)->findBy(['schoolYear' => '2026-2027']);
        self::assertCount(3, $tasks);
        foreach ($tasks as $task) {
            self::assertSame($perTerm, $task->getTemplate(), 'linked back to its template');
            self::assertSame('2026-2027', $task->getSchoolYear());
        }
    }

    public function testReRunIsIdempotent(): void
    {
        $year = $this->year();
        $this->template('Memoria', true, new FixedDate(6, 30));
        $this->em->flush();

        $first = $this->generator->generate($year, null);
        self::assertSame(1, $first->created);

        $second = $this->generator->generate($year, null);
        self::assertSame(0, $second->created, 'nothing new on a re-run');
        self::assertSame(1, $second->skippedExisting);
        self::assertCount(1, $this->em->getRepository(Task::class)->findBy(['schoolYear' => '2026-2027']));
    }

    public function testInactiveTemplatesAreNotGenerated(): void
    {
        $year = $this->year();
        $this->template('Retirada', false, new PerTerm(TermBoundary::END));
        $this->em->flush();

        $result = $this->generator->generate($year, null);

        self::assertSame(0, $result->created);
        self::assertCount(0, $this->em->getRepository(Task::class)->findBy(['schoolYear' => '2026-2027']));
    }
}
