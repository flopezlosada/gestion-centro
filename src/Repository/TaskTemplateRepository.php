<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaskTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskTemplate>
 */
class TaskTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskTemplate::class);
    }

    /**
     * Active templates (those still instantiated each course), by title.
     *
     * @return TaskTemplate[] the active templates
     */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['title' => 'ASC']);
    }
}
