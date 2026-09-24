<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\Meeting;
use App\Entity\MeetingGroup;
use App\Entity\MeetingType;
use App\Entity\NonLectiveDay;
use App\Entity\Notification;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\EventReminderOffset;
use App\Enum\TimeSlotKind;
use App\Enum\Weekday;
use App\Repository\MeetingRepository;
use App\Service\RecurringMeetingGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Las reuniones semanales que se crean solas: una por semana a la hora del marco horario, solo en días
 * lectivos, nunca dos veces, y sin resucitar la que quien convoca borró.
 *
 * Fecha FIJA (un jueves del curso 2026-2027): el resultado depende del día de la semana y del
 * calendario escolar, y anclado al reloj del runner contaría otra cosa cada día.
 */
final class RecurringMeetingGeneratorTest extends KernelTestCase
{
    /** Jueves; el lunes siguiente es el 12 de octubre (se registra como no lectivo en un test). */
    private const TODAY = '2026-10-08';

    private EntityManagerInterface $em;
    private RecurringMeetingGenerator $generator;
    private AcademicYear $year;
    private User $convener;
    private User $member;
    private MeetingGroup $group;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->generator = self::getContainer()->get(RecurringMeetingGenerator::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2026-2027')
            ->setTerm1Start(new \DateTimeImmutable('2026-09-08'))
            ->setTerm1End(new \DateTimeImmutable('2026-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2027-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2027-03-19'))
            ->setTerm3Start(new \DateTimeImmutable('2027-03-29'))
            ->setTerm3End(new \DateTimeImmutable('2027-06-23'));
        $this->em->persist($this->year);
        // La 6ª hora del centro: índice 7, porque el 3 y el 6 son recreos.
        $this->em->persist((new TimeSlot())
            ->setAcademicYear($this->year)
            ->setSlotIndex(7)
            ->setStartsAt(new \DateTimeImmutable('13:35'))
            ->setEndsAt(new \DateTimeImmutable('14:30'))
            ->setKind(TimeSlotKind::LECTIVE));

        $this->convener = $this->person('Jefa de Estudios', 'jefa@educa.madrid.org');
        $this->member = $this->person('Tutor de 3º', 'tutor3@educa.madrid.org');
        $kind = (new MeetingType())->setName('Tutores')->setMinutesApprovalRequired(true);
        $this->em->persist($kind);

        $this->group = (new MeetingGroup())
            ->setName('TUTORES/AS 3º ESO')
            ->repeatWeekly(Weekday::MONDAY, 7)
            ->setConvener($this->convener)
            ->setType($kind)
            ->addMember($this->convener)
            ->addMember($this->member);
        $this->em->persist($this->group);
        $this->em->flush();
    }

    public function testCreatesNextWeeksMeetingAtTheTimetablesHourForTheGroupsPeople(): void
    {
        $result = $this->generator->generate(new \DateTimeImmutable(self::TODAY));

        self::assertSame(1, $result['created']);
        $meeting = $this->onlyMeeting();
        self::assertSame('2026-10-12 13:35', $meeting->getStartAt()->format('Y-m-d H:i'));
        self::assertSame('2026-10-12 14:30', $meeting->getEndAt()?->format('Y-m-d H:i'));
        self::assertSame('TUTORES/AS 3º ESO', $meeting->getTitle());
        self::assertSame($this->convener, $meeting->getConvener());
        self::assertSame($this->group, $meeting->getMeetingGroup());
        self::assertTrue($meeting->minutesApprovalRequired(), 'lo decide el tipo del grupo');
        self::assertSame([$this->member], array_values($meeting->getAttendees()->toArray()), 'quien convoca no se convoca a sí mismo');
    }

    /**
     * La reunión hereda el lugar y el aviso del grupo, y SOLO quien convoca recibe un aviso: el de que le
     * falta el orden del día. Los convocados no, que la reunión ya está fija en su horario.
     */
    public function testInheritsPlaceAndReminderAndTellsOnlyTheConvenerToWriteTheAgenda(): void
    {
        $this->group->setPlace('Sala de profesores')->setReminder(EventReminderOffset::FIFTEEN_MINUTES);
        $this->em->flush();

        $this->generator->generate(new \DateTimeImmutable(self::TODAY));

        $meeting = $this->onlyMeeting();
        self::assertSame('Sala de profesores', $meeting->getPlace());
        self::assertSame(EventReminderOffset::FIFTEEN_MINUTES, $meeting->getReminder());
        self::assertSame('2026-10-12 13:20', $meeting->getRemindAt()?->format('Y-m-d H:i'));

        $notices = $this->em->getRepository(Notification::class)->findBy(['kind' => 'meeting.generated']);
        self::assertCount(1, $notices);
        self::assertSame($this->convener, $notices[0]->getRecipient());
        self::assertCount(0, $this->em->getRepository(Notification::class)->findBy(['recipient' => $this->member]));
    }

    public function testRunningItAgainTheSameDayCreatesNothing(): void
    {
        $this->generator->generate(new \DateTimeImmutable(self::TODAY));
        $again = $this->generator->generate(new \DateTimeImmutable(self::TODAY));

        self::assertSame(0, $again['created']);
        self::assertCount(1, $this->meetings());
    }

    /** Si quien convoca borra una semana (un festivo, una semana sin reunión), no vuelve a aparecer. */
    public function testADeletedMeetingIsNeverBroughtBack(): void
    {
        $this->generator->generate(new \DateTimeImmutable(self::TODAY));
        $this->em->remove($this->onlyMeeting());
        $this->em->flush();

        $this->generator->generate(new \DateTimeImmutable('2026-10-09'));

        self::assertCount(0, $this->meetings());
    }

    public function testSkipsANonTeachingDay(): void
    {
        $this->em->persist((new NonLectiveDay())->setDate(new \DateTimeImmutable('2026-10-12'))->setDescription('Fiesta Nacional'));
        $this->em->flush();

        $result = $this->generator->generate(new \DateTimeImmutable(self::TODAY));

        self::assertSame(0, $result['created']);
        self::assertSame([], $result['stuck'], 'un festivo no es un problema: simplemente no hay reunión');
    }

    public function testAGroupWithNobodyToConveneGeneratesNothing(): void
    {
        $this->group->setConvener(null);
        $this->em->flush();

        self::assertSame(0, $this->generator->generate(new \DateTimeImmutable(self::TODAY))['created']);
    }

    /**
     * Sin esa hora en el marco horario (el horario del curso aún no se ha importado), el grupo se queda
     * atascado y lo dice, pero SIN dar el día por hecho: en cuanto el horario llega, se genera.
     */
    public function testAMissingPeriodIsRetriedOnceTheTimetableArrives(): void
    {
        $this->group->repeatWeekly(Weekday::MONDAY, 5);
        $this->em->flush();

        $stuck = $this->generator->generate(new \DateTimeImmutable(self::TODAY));
        self::assertSame(['TUTORES/AS 3º ESO'], $stuck['stuck']);
        self::assertCount(0, $this->meetings());

        $this->em->persist((new TimeSlot())
            ->setAcademicYear($this->year)
            ->setSlotIndex(5)
            ->setStartsAt(new \DateTimeImmutable('12:30'))
            ->setEndsAt(new \DateTimeImmutable('13:25'))
            ->setKind(TimeSlotKind::LECTIVE));
        $this->em->flush();

        self::assertSame(1, $this->generator->generate(new \DateTimeImmutable(self::TODAY))['created']);
        self::assertSame('12:30', $this->onlyMeeting()->getStartAt()->format('H:i'));
    }

    /** Activado un lunes después de la hora de la reunión, esa no se crea: ya no se va a celebrar. */
    public function testAMeetingWhoseHourHasAlreadyGoneByIsNotCreated(): void
    {
        $result = $this->generator->generate(new \DateTimeImmutable('2026-10-19 15:00'));

        self::assertSame(1, $result['created'], 'solo la del lunes siguiente');
        self::assertSame('2026-10-26', $this->onlyMeeting()->getStartAt()->format('Y-m-d'));
    }

    /**
     * El lunes 7 de septiembre ya es del curso 2026-2027 pero sus clases empiezan el 8: no hay reunión, y
     * no es un atasco (el calendario de festivos no sabe cuándo empieza el curso; esto sí).
     */
    public function testNothingBeforeTheCourseStarts(): void
    {
        $result = $this->generator->generate(new \DateTimeImmutable('2026-09-03'));

        self::assertSame(0, $result['created']);
        self::assertSame([], $result['stuck']);
    }

    /**
     * @return list<Meeting> the meetings of the group
     */
    private function meetings(): array
    {
        return self::getContainer()->get(MeetingRepository::class)->findBy(['meetingGroup' => $this->group]);
    }

    /** Un cambio del grupo llega a la reunión ya creada, salvo en lo que quien convoca cambió a mano en ella. */
    public function testAnEditReachesTheUpcomingMeetingExceptWhatWasChangedThere(): void
    {
        $this->generator->generate(new \DateTimeImmutable(self::TODAY));
        $before = $this->group->seriesShape();
        $this->group->setName('TUTORÍA 3º ESO')->setPlace('Sala A');
        $this->em->flush();

        self::assertSame(1, $this->generator->applySeriesChange($this->group, $before, new \DateTimeImmutable(self::TODAY)));
        $meeting = $this->onlyMeeting();
        self::assertSame('TUTORÍA 3º ESO', $meeting->getTitle());
        self::assertSame('Sala A', $meeting->getPlace());
        self::assertSame(0, $this->noticesTo($this->member, 'meeting.rescheduled'), 'poner lugar donde no lo había no mueve a nadie');

        $meeting->setPlace('Biblioteca');
        $before = $this->group->seriesShape();
        $this->group->setPlace('Sala B');
        $this->em->flush();
        $this->generator->applySeriesChange($this->group, $before, new \DateTimeImmutable(self::TODAY));

        self::assertSame('Biblioteca', $this->onlyMeeting()->getPlace(), 'el lugar de esa semana lo decidió quien convoca');
    }

    /** Otro día: la reunión se mueve dentro de su semana y se avisa a los convocados. */
    public function testANewDayMovesTheMeetingWithinItsWeekAndWarns(): void
    {
        $this->generator->generate(new \DateTimeImmutable(self::TODAY));
        $before = $this->group->seriesShape();
        $this->group->repeatWeekly(Weekday::WEDNESDAY, 7);
        $this->em->flush();

        $this->generator->applySeriesChange($this->group, $before, new \DateTimeImmutable(self::TODAY));

        self::assertSame('2026-10-14 13:35', $this->onlyMeeting()->getStartAt()->format('Y-m-d H:i'));
        self::assertSame(1, $this->noticesTo($this->member, 'meeting.rescheduled'));
        self::assertNull($this->group->getGeneratedThrough(), 'el horario nuevo se vuelve a repasar desde hoy');
    }

    /** Si en esa semana la nueva hora ya pasó, la reunión de esa semana se cancela, con aviso. */
    public function testANewHourAlreadyGoneByCancelsThatWeeksMeeting(): void
    {
        $this->em->persist((new TimeSlot())->setAcademicYear($this->year)->setSlotIndex(0)
            ->setStartsAt(new \DateTimeImmutable('08:25'))->setEndsAt(new \DateTimeImmutable('09:20'))->setKind(TimeSlotKind::LECTIVE));
        $this->em->flush();
        $this->generator->generate(new \DateTimeImmutable(self::TODAY));
        $before = $this->group->seriesShape();
        $this->group->repeatWeekly(Weekday::MONDAY, 0);
        $this->em->flush();

        $this->generator->applySeriesChange($this->group, $before, new \DateTimeImmutable('2026-10-12 09:00'));

        self::assertCount(0, $this->meetings());
        self::assertSame(1, $this->noticesTo($this->member, 'meeting.cancelled'));
    }

    /** Quien convoca ahora se queda las reuniones ya creadas y recibe el aviso del orden del día. */
    public function testANewConvenerTakesTheUpcomingMeetingAndIsToldAboutTheAgenda(): void
    {
        $this->generator->generate(new \DateTimeImmutable(self::TODAY));
        $before = $this->group->seriesShape();
        $this->group->setConvener($this->member);
        $this->em->flush();

        $this->generator->applySeriesChange($this->group, $before, new \DateTimeImmutable(self::TODAY));

        $meeting = $this->onlyMeeting();
        self::assertSame($this->member, $meeting->getConvener());
        self::assertSame($this->member, $meeting->getMinutesTakenBy());
        self::assertSame([$this->convener], array_values($meeting->getAttendees()->toArray()), 'quien convocaba sigue en el grupo: pasa a convocado');
        self::assertSame(1, $this->noticesTo($this->member, 'meeting.generated'));
    }

    /**
     * Una reunión no deja cambiar su convocante a mano: si no coincide con el del grupo es que se quedó
     * atrás, y el siguiente guardado del grupo la pone al día aunque ese guardado no toque el convocante.
     */
    public function testAConvenerLeftBehindCatchesUpOnTheNextSave(): void
    {
        $this->generator->generate(new \DateTimeImmutable(self::TODAY));
        $this->group->setConvener($this->member);
        $this->em->flush();
        $before = $this->group->seriesShape();

        $this->generator->applySeriesChange($this->group, $before, new \DateTimeImmutable(self::TODAY));

        self::assertSame($this->member, $this->onlyMeeting()->getConvener());
    }

    /** Dejar de repetirse cancela las reuniones que aún no se han celebrado. */
    public function testStoppingTheSeriesCancelsTheUpcomingMeetings(): void
    {
        $this->generator->generate(new \DateTimeImmutable(self::TODAY));
        $before = $this->group->seriesShape();
        $this->group->stopRepeating();
        $this->em->flush();

        $this->generator->applySeriesChange($this->group, $before, new \DateTimeImmutable(self::TODAY));

        self::assertCount(0, $this->meetings());
        self::assertSame(1, $this->noticesTo($this->member, 'meeting.cancelled'));
    }

    /** También la que su convocante movió a mano: el grupo ya no existe como serie, así que no queda huérfana. */
    public function testStoppingTheSeriesAlsoCancelsAMeetingMovedByHand(): void
    {
        $this->generator->generate(new \DateTimeImmutable(self::TODAY));
        $this->onlyMeeting()->setStartAt(new \DateTimeImmutable('2026-10-13 10:00'));
        $this->em->flush();
        $before = $this->group->seriesShape();
        $this->group->setActive(false);
        $this->em->flush();

        $this->generator->applySeriesChange($this->group, $before, new \DateTimeImmutable(self::TODAY));

        self::assertCount(0, $this->meetings());
        self::assertSame(1, $this->noticesTo($this->member, 'meeting.cancelled'));
    }

    private function noticesTo(User $person, string $kind): int
    {
        return \count($this->em->getRepository(Notification::class)->findBy(['recipient' => $person, 'kind' => $kind]));
    }

    private function onlyMeeting(): Meeting
    {
        $meetings = $this->meetings();
        self::assertCount(1, $meetings);

        return $meetings[0];
    }

    private function person(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }
}
