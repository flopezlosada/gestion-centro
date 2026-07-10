<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Returns the most recent audit entries, newest first.
     *
     * @param int $limit maximum number of entries to return
     *
     * @return AuditLog[] the latest entries
     */
    public function findLatest(int $limit = 100): array
    {
        return $this->findBy([], ['occurredAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /**
     * Audit entries about one subject, newest first, optionally narrowed to a single action.
     * Used to show the security/admin trail of a given entity without a parallel data model.
     *
     * @param string      $subjectType the logged subject type (e.g. 'User', 'Role')
     * @param string      $subjectId   the subject id, as stored (string)
     * @param string|null $action      restrict to this action, or null for every action
     * @param int         $limit       maximum number of entries to return
     *
     * @return AuditLog[] the matching entries, newest first
     */
    public function findForSubject(string $subjectType, string $subjectId, ?string $action = null, int $limit = 50): array
    {
        $criteria = ['subjectType' => $subjectType, 'subjectId' => $subjectId];
        if (null !== $action) {
            $criteria['action'] = $action;
        }

        return $this->findBy($criteria, ['occurredAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
