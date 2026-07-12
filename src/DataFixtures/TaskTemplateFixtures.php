<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\DueDate\PerTerm;
use App\DueDate\RelativeToAnchor;
use App\Entity\Role;
use App\Entity\TaskTemplate;
use App\Enum\CalendarAnchor;
use App\Enum\TaskType;
use App\Enum\TermBoundary;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * The recurring-task template catalog: the tasks that repeat every course, each with its deadline
 * rule so the yearly generation can stamp dates automatically. Part of the GOLDEN backbone — the
 * catalog is the structural core of the product (in production the centre grows it from the UI).
 * Two illustrative entries; depends on {@see RoleFixtures} for the responsible role.
 */
final class TaskTemplateFixtures extends AbstractGoldenFixture implements DependentFixtureInterface
{
    public const string REPORT = 'template-report';
    public const string MEETING = 'template-meeting';

    public function load(ObjectManager $manager): void
    {
        $headDept = $this->getReference(RoleFixtures::ref('head_dept'), Role::class);

        // The department report is due at the end of the course; the meeting minutes recur at the end
        // of every term.
        $report = (new TaskTemplate())->setTitle('Memoria del departamento')->setType(TaskType::WITH_DELIVERABLE)->setResponsibleRole($headDept)->setRequiresDocument(true)
            ->setDueDateRule(new RelativeToAnchor(CalendarAnchor::YEAR_END, 0));
        $meeting = (new TaskTemplate())->setTitle('Acta de reunión de departamento')->setType(TaskType::SIMPLE)->setResponsibleRole($headDept)
            ->setDueDateRule(new PerTerm(TermBoundary::END));

        $manager->persist($report);
        $manager->persist($meeting);
        $this->addReference(self::REPORT, $report);
        $this->addReference(self::MEETING, $meeting);

        $manager->flush();
    }

    /**
     * @return list<class-string>
     */
    public function getDependencies(): array
    {
        return [RoleFixtures::class];
    }
}
