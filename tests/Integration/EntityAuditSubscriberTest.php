<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AuditLog;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The activity trail must capture inserts, updates, deletes and collection (role assignment) changes
 * of {@see \App\Contract\Auditable} entities automatically, with a field-level diff, without any
 * manual instrumentation.
 */
final class EntityAuditSubscriberTest extends KernelTestCase
{
    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function makeRole(EntityManagerInterface $em, string $code = 'head_dept', string $name = 'Jefatura de departamento'): Role
    {
        $role = (new Role())->setCode($code)->setName($name);
        $em->persist($role);
        $em->flush();

        return $role;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestChanges(EntityManagerInterface $em, string $action, string $subjectType): ?array
    {
        $entry = $em->getRepository(AuditLog::class)->findOneBy(['action' => $action, 'subjectType' => $subjectType], ['id' => 'DESC']);
        self::assertNotNull($entry, sprintf('expected a %s entry for %s', $action, $subjectType));

        return $entry->getChanges();
    }

    public function testInsertIsCapturedWithSubjectId(): void
    {
        self::bootKernel();
        $em = $this->entityManager();

        $role = $this->makeRole($em);

        $entry = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'role.created', 'subjectType' => 'Role']);
        self::assertNotNull($entry, 'the insert should produce a role.created entry');
        self::assertSame((string) $role->getId(), $entry->getSubjectId());
    }

    public function testUpdateIsCapturedWithFieldDiff(): void
    {
        self::bootKernel();
        $em = $this->entityManager();

        $role = $this->makeRole($em);
        $role->setName('Jefatura de Matemáticas');
        $em->flush();

        $changes = $this->latestChanges($em, 'role.updated', 'Role');
        self::assertIsArray($changes);
        self::assertArrayHasKey('name', $changes);
        self::assertSame('Jefatura de departamento', $changes['name']['old']);
        self::assertSame('Jefatura de Matemáticas', $changes['name']['new']);
    }

    public function testDeleteIsCapturedWithSubjectId(): void
    {
        self::bootKernel();
        $em = $this->entityManager();

        $role = $this->makeRole($em);
        $id = $role->getId();

        $em->remove($role);
        $em->flush();

        $entry = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'role.deleted', 'subjectType' => 'Role']);
        self::assertNotNull($entry, 'the delete should produce a role.deleted entry');
        self::assertSame((string) $id, $entry->getSubjectId(), 'the subject id must survive the deletion');
    }

    public function testJsonPermissionsChangeIsCaptured(): void
    {
        self::bootKernel();
        $em = $this->entityManager();

        $role = $this->makeRole($em);
        $role->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE);
        $em->flush();

        $changes = $this->latestChanges($em, 'role.updated', 'Role');
        self::assertIsArray($changes);
        self::assertArrayHasKey('permissions', $changes);
        self::assertSame([], $changes['permissions']['old']);
        self::assertSame(['administration' => 'write'], $changes['permissions']['new']);
    }

    public function testRoleAssignmentCollectionChangeIsCaptured(): void
    {
        self::bootKernel();
        $em = $this->entityManager();

        $role = $this->makeRole($em);
        $user = (new User())->setFullName('Ana Ruiz')->setEmail('ana@example.test');
        $em->persist($user);
        $em->flush();

        $user->addAssignedRole($role);
        $em->flush();

        $changes = $this->latestChanges($em, 'user.updated', 'User');
        self::assertIsArray($changes);
        self::assertArrayHasKey('assignedRoles', $changes, 'assigning a role must be tracked (sensitive action)');
        self::assertContains($role->getId(), $changes['assignedRoles']['added']);
        self::assertSame([], $changes['assignedRoles']['removed']);
    }
}
