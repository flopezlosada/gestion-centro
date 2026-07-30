<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Meeting;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * The invariants of a meeting: who it concerns, how the convened list changes, and the four fields of
 * the acta moving as one (so "there is a file but no name" is never reachable).
 */
final class MeetingTest extends TestCase
{
    private function user(string $name): User
    {
        return (new User())->setFullName($name)->setEmail(strtolower($name).'@centro.test');
    }

    private function meeting(User $convener): Meeting
    {
        return new Meeting($convener, 'Reunión de seguimiento', new \DateTimeImmutable('2026-09-15 14:00'));
    }

    public function testItConcernsTheConvenerAndTheConvenedButNobodyElse(): void
    {
        $convener = $this->user('Coordina');
        $attendee = $this->user('Convocado');
        $stranger = $this->user('Ajeno');
        $meeting = $this->meeting($convener)->addAttendee($attendee);

        self::assertTrue($meeting->concerns($convener), 'quien convoca');
        self::assertTrue($meeting->concerns($attendee), 'quien está convocado');
        self::assertFalse($meeting->concerns($stranger), 'nadie más');
        self::assertFalse($meeting->isAttendee($convener), 'convocar no es estar en la lista de convocados');
    }

    public function testSyncAttendeesReportsOnlyTheNewOnesAndDropsTheRest(): void
    {
        $convener = $this->user('Coordina');
        $stays = $this->user('Sigue');
        $leaves = $this->user('Sale');
        $joins = $this->user('Entra');
        $meeting = $this->meeting($convener)->addAttendee($stays)->addAttendee($leaves);

        $added = $meeting->syncAttendees([$stays, $joins]);

        // Solo el nuevo se anuncia: si se avisara a todos, editar el orden del día volvería a convocar
        // a quien ya lo estaba.
        self::assertSame([$joins], $added);
        self::assertTrue($meeting->isAttendee($stays));
        self::assertTrue($meeting->isAttendee($joins));
        self::assertFalse($meeting->isAttendee($leaves), 'a quien se quita deja de estar convocado');
        self::assertCount(2, $meeting->getAttendees());
    }

    public function testSyncAttendeesWithTheSamePeopleAnnouncesNobody(): void
    {
        $convener = $this->user('Coordina');
        $attendee = $this->user('Convocado');
        $meeting = $this->meeting($convener)->addAttendee($attendee);

        self::assertSame([], $meeting->syncAttendees([$attendee]));
    }

    public function testAttachingMinutesSetsTheWholeRecordAtOnce(): void
    {
        $convener = $this->user('Coordina');
        $meeting = $this->meeting($convener);
        $when = new \DateTimeImmutable('2026-09-15 16:30');

        self::assertFalse($meeting->hasMinutes());
        $replaced = $meeting->attachMinutes('meeting-minutes/uuid-1.pdf', 'acta.pdf', $convener, $when);

        self::assertNull($replaced, 'no había acta previa que borrar');
        self::assertTrue($meeting->hasMinutes());
        self::assertSame('meeting-minutes/uuid-1.pdf', $meeting->getMinutesPath());
        self::assertSame('acta.pdf', $meeting->getMinutesName());
        self::assertSame($convener, $meeting->getMinutesUploadedBy());
        self::assertSame($when, $meeting->getMinutesUploadedAt());
    }

    public function testReplacingMinutesReturnsThePathToDeleteSoNoOrphanIsLeft(): void
    {
        $convener = $this->user('Coordina');
        $other = $this->user('Otra');
        $meeting = $this->meeting($convener);
        $meeting->attachMinutes('meeting-minutes/uuid-1.pdf', 'acta.pdf', $convener, new \DateTimeImmutable('2026-09-15 16:30'));

        $replaced = $meeting->attachMinutes('meeting-minutes/uuid-2.pdf', 'acta-corregida.pdf', $other, new \DateTimeImmutable('2026-09-16 09:00'));

        self::assertSame('meeting-minutes/uuid-1.pdf', $replaced);
        self::assertSame('meeting-minutes/uuid-2.pdf', $meeting->getMinutesPath());
        self::assertSame('acta-corregida.pdf', $meeting->getMinutesName());
        self::assertSame($other, $meeting->getMinutesUploadedBy(), 'firma quien sube la nueva');
    }

    public function testClearingMinutesEmptiesTheWholeRecordAndReturnsTheFileToDelete(): void
    {
        $convener = $this->user('Coordina');
        $meeting = $this->meeting($convener);
        $meeting->attachMinutes('meeting-minutes/uuid-1.pdf', 'acta.pdf', $convener, new \DateTimeImmutable('2026-09-15 16:30'));

        self::assertSame('meeting-minutes/uuid-1.pdf', $meeting->clearMinutes());
        self::assertFalse($meeting->hasMinutes());
        self::assertNull($meeting->getMinutesName());
        self::assertNull($meeting->getMinutesUploadedBy());
        self::assertNull($meeting->getMinutesUploadedAt());
        self::assertNull($meeting->clearMinutes(), 'quitar dos veces no devuelve nada que borrar');
    }

    public function testIsPastComparesAgainstTheStart(): void
    {
        $meeting = $this->meeting($this->user('Coordina'));

        self::assertTrue($meeting->isPast(new \DateTimeImmutable('2026-09-15 14:01')));
        self::assertFalse($meeting->isPast(new \DateTimeImmutable('2026-09-15 13:59')));
    }
}
