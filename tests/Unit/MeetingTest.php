<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Meeting;
use App\Entity\User;
use App\Enum\EventReminderOffset;
use App\Enum\MeetingScope;
use PHPUnit\Framework\TestCase;

/**
 * The invariants of a meeting: who it concerns, how the convened list changes, the four fields of the acta
 * moving as one (so "there is a file but no name" is never reachable) and the acta being written in a
 * single operation (so "half an acta saved" is not reachable either).
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

    public function testAMeetingWithoutAReminderHasNoInstantToFireAt(): void
    {
        self::assertNull($this->meeting($this->user('Coordina'))->getRemindAt());
    }

    public function testTheReminderInstantIsDerivedFromTheStart(): void
    {
        $meeting = $this->meeting($this->user('Coordina'))->setReminder(EventReminderOffset::TEN_MINUTES);

        self::assertSame('2026-09-15 13:50', $meeting->getRemindAt()?->format('Y-m-d H:i'));
    }

    public function testMovingTheMeetingMovesTheReminderAndReArmsIt(): void
    {
        $meeting = $this->meeting($this->user('Coordina'))->setReminder(EventReminderOffset::TEN_MINUTES);
        $meeting->markReminderSent(new \DateTimeImmutable('2026-09-15 13:50'));

        $meeting->setStartAt(new \DateTimeImmutable('2026-09-15 17:00'));

        self::assertSame('2026-09-15 16:50', $meeting->getRemindAt()?->format('Y-m-d H:i'));
        self::assertNull($meeting->getReminderSentAt(), 'la reunión se movió: hay que volver a avisar');
    }

    public function testRewritingTheSameScheduleDoesNotAnnounceItAgain(): void
    {
        // El formulario de edición reescribe SIEMPRE hora y antelación, aunque solo cambies el orden del
        // día. Si eso re-armara el aviso, tocar una coma volvería a pitarle el móvil a todo el mundo.
        $meeting = $this->meeting($this->user('Coordina'))->setReminder(EventReminderOffset::TEN_MINUTES);
        $meeting->markReminderSent(new \DateTimeImmutable('2026-09-15 13:50'));

        $meeting->setStartAt(new \DateTimeImmutable('2026-09-15 14:00'))->setReminder(EventReminderOffset::TEN_MINUTES);

        self::assertNotNull($meeting->getReminderSentAt());
    }

    public function testClearingTheReminderClearsTheInstant(): void
    {
        $meeting = $this->meeting($this->user('Coordina'))->setReminder(EventReminderOffset::ONE_HOUR)->setReminder(null);

        self::assertNull($meeting->getRemindAt());
    }

    public function testThePeopleExpectedAreTheConvenedPlusTheConvener(): void
    {
        $convener = $this->user('Coordina');
        $attendee = $this->user('Convocado');
        $meeting = $this->meeting($convener)->addAttendee($attendee);

        self::assertSame([$attendee, $convener], $meeting->people());
    }

    public function testTheConvenerIsNotCountedTwiceWhenAlsoConvened(): void
    {
        $convener = $this->user('Coordina');
        $meeting = $this->meeting($convener)->addAttendee($convener);

        self::assertSame([$convener], $meeting->people());
    }

    public function testTheRollKeepsOnlyThePeopleExpectedAndDerivesTheAbsentees(): void
    {
        $convener = $this->user('Coordina');
        $came = $this->user('Vino');
        $missed = $this->user('Falto');
        $stranger = $this->user('Ajeno');
        $meeting = $this->meeting($convener)->addAttendee($came)->addAttendee($missed);

        $meeting->recordSession(null, null, [$came, $stranger], new \DateTimeImmutable('2026-09-15 15:00'));

        self::assertTrue($meeting->isAttendanceTaken());
        // El de fuera se descarta aunque venga en el POST, y el orden es el de la convocatoria.
        self::assertSame([$came], $meeting->getAttended()->toArray());
        self::assertSame([$missed, $convener], $meeting->absentees());
    }

    public function testTheRollCanBeCorrectedToNobody(): void
    {
        // Lista vacía es una respuesta VÁLIDA ("no vino nadie"), distinta de no haber pasado lista; y al
        // corregirla hay que retirar de verdad a quien ya estaba apuntado.
        $convener = $this->user('Coordina');
        $came = $this->user('Vino');
        $meeting = $this->meeting($convener)->addAttendee($came);
        $meeting->recordSession(null, null, [$came], new \DateTimeImmutable('2026-09-15 15:00'));

        $meeting->recordSession(null, null, [], new \DateTimeImmutable('2026-09-15 15:10'));

        self::assertCount(0, $meeting->getAttended());
        self::assertTrue($meeting->isAttendanceTaken(), 'sigue constando que se pasó lista');
        self::assertSame([$came, $convener], $meeting->absentees());
    }

    public function testTheWholeActaIsWrittenInOneGo(): void
    {
        // El arreglo del fallo que traía la pantalla: el desarrollo, los acuerdos y la lista se guardan de
        // una vez, así que "guardé la asistencia y perdí lo escrito" no es un estado alcanzable.
        $convener = $this->user('Coordina');
        $came = $this->user('Vino');
        $meeting = $this->meeting($convener)->addAttendee($came);
        $when = new \DateTimeImmutable('2026-09-15 15:00');

        $meeting->recordSession('Se abre la sesión.', '1. Se aprueba.', [$came], $when);

        self::assertSame('Se abre la sesión.', $meeting->getDiscussion());
        self::assertSame('1. Se aprueba.', $meeting->getAgreements());
        self::assertSame([$came], $meeting->getAttended()->toArray());
        self::assertSame($when, $meeting->getRecordUpdatedAt());
        self::assertTrue($meeting->isAttendanceTaken());
    }

    public function testAMeetingWithFamiliesKeepsTheRollButNoText(): void
    {
        // Con familias no hay acta AQUÍ (va a RAICES) pero sí quién vino: la entidad se queda con la lista
        // y tira el texto, de modo que un POST a pelo no puede dejar media acta colgando de una cita.
        $convener = $this->user('Coordina');
        $came = $this->user('Familia');
        $meeting = $this->meeting($convener)->addAttendee($came);
        $meeting->setScope(MeetingScope::FAMILIES);

        $meeting->recordSession('Lo que me apetezca.', 'Y esto.', [$came], new \DateTimeImmutable('2026-09-15 15:00'));

        self::assertNull($meeting->getDiscussion(), 'sin acta aquí no hay desarrollo que guardar');
        self::assertNull($meeting->getAgreements());
        self::assertSame([$came], $meeting->getAttended()->toArray(), 'la lista sí: es un dato de la cita');
        self::assertTrue($meeting->isAttendanceTaken());
    }

    public function testTheAgendaIsAlsoRefusedOnAMeetingThatKeepsNoMinutes(): void
    {
        $meeting = $this->meeting($this->user('Coordina'));
        $meeting->setScope(MeetingScope::STUDENTS);

        $meeting->setAgenda('Puntos a tratar');

        self::assertNull($meeting->getAgenda());
    }

    public function testTheFileIsOutdatedOnceTheActaIsWrittenAgain(): void
    {
        $convener = $this->user('Coordina');
        $meeting = $this->meeting($convener);

        self::assertFalse($meeting->minutesOutdated(), 'sin fichero no hay nada desfasado');

        $meeting->recordSession('Primera versión.', null, [], new \DateTimeImmutable('2026-09-15 15:00'));
        $meeting->attachMinutes('meeting-minutes/uuid-1.pdf', 'acta.pdf', $convener, new \DateTimeImmutable('2026-09-15 15:05'));

        self::assertFalse($meeting->minutesOutdated(), 'el PDF se generó DESPUÉS de escribirla: está al día');

        $meeting->recordSession('Corregida.', null, [], new \DateTimeImmutable('2026-09-15 16:00'));

        self::assertTrue($meeting->minutesOutdated(), 'el PDF que hay ya no dice lo que dice el acta');
    }

    public function testAnActaNobodyWroteIsNotReportedAsOutdated(): void
    {
        // El caso de las actas que ya existían cuando se añadió el sello: sin `recordUpdatedAt` no hay nada
        // que comparar, y marcarlas todas como desfasadas sería un aviso falso en cada reunión vieja.
        $convener = $this->user('Coordina');
        $meeting = $this->meeting($convener);
        $meeting->attachMinutes('meeting-minutes/uuid-1.pdf', 'acta.pdf', $convener, new \DateTimeImmutable('2026-09-15 16:30'));

        self::assertFalse($meeting->minutesOutdated());
    }

    public function testIsPastComparesAgainstTheStart(): void
    {
        $meeting = $this->meeting($this->user('Coordina'));

        self::assertTrue($meeting->isPast(new \DateTimeImmutable('2026-09-15 14:01')));
        self::assertFalse($meeting->isPast(new \DateTimeImmutable('2026-09-15 13:59')));
    }
}
