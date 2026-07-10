<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AuditLog;
use App\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The activity trail must capture inserts and updates of {@see \App\Contract\Auditable} entities
 * automatically, with a field-level before/after diff, without any manual instrumentation.
 */
final class EntityAuditSubscriberTest extends KernelTestCase
{
    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testInsertIsCapturedWithSubjectId(): void
    {
        self::bootKernel();
        $em = $this->entityManager();

        $role = (new Role())->setCode('head_dept')->setName('Jefatura de departamento');
        $em->persist($role);
        $em->flush();

        $entry = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'role.created', 'subjectType' => 'Role']);

        self::assertNotNull($entry, 'the insert should produce a role.created entry');
        self::assertSame((string) $role->getId(), $entry->getSubjectId());
    }

    public function testUpdateIsCapturedWithFieldDiff(): void
    {
        self::bootKernel();
        $em = $this->entityManager();

        $role = (new Role())->setCode('head_dept')->setName('Jefatura de departamento');
        $em->persist($role);
        $em->flush();

        $role->setName('Jefatura de Matemáticas');
        $em->flush();

        $entry = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'role.updated', 'subjectType' => 'Role'], ['id' => 'DESC']);

        self::assertNotNull($entry, 'the update should produce a role.updated entry');
        $changes = $entry->getChanges();
        self::assertIsArray($changes);
        self::assertArrayHasKey('name', $changes);
        self::assertSame('Jefatura de departamento', $changes['name']['old']);
        self::assertSame('Jefatura de Matemáticas', $changes['name']['new']);
    }
}
