<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\ArchivedBreakDutyGap;
use App\Entity\ArchivedGuardiaCover;
use App\Entity\AuditLog;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakDutyGap;
use App\Entity\BreakZone;
use App\Entity\CopyRequest;
use App\Entity\CopyRequestLine;
use App\Entity\Department;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaGrouping;
use App\Entity\GuardiaTaskBankItem;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\AbsenceRetention;
use App\Enum\BreakPeriod;
use App\Enum\EducationLevel;
use App\Enum\Weekday;
use App\Guardia\AbsencePurger;
use App\Repository\BreakDutyGapRepository;
use App\Repository\GuardiaCoverRepository;
use App\Service\BreakDutyGapNotifier;
use App\Service\BreakRotaAnnouncer;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Past the plazo, who was away is forgotten: the absences go, their covers become archived guardias
 * that only say who did them, and the groupings, task documents, guardia notices and trail go with them.
 * The fair split and the statistics must read the same before and after, and the day the plazo starts
 * on, and everything after it, must be left exactly as it was.
 */
final class AbsencePurgerTest extends KernelTestCase
{
    /** With a week of plazo, the 15th is the first day kept and the 14th the last one forgotten. */
    private const string TODAY = '2026-10-22';

    private EntityManagerInterface $em;
    private FileUploader $uploader;
    private AbsencePurger $purger;
    private GuardiaCoverRepository $covers;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->uploader = self::getContainer()->get(FileUploader::class);
        $this->purger = self::getContainer()->get(AbsencePurger::class);
        $this->covers = self::getContainer()->get(GuardiaCoverRepository::class);
    }

    private function user(string $name): User
    {
        $user = (new User())->setFullName($name)->setEmail(strtolower($name).'-'.uniqid().'@centro.test');
        $this->em->persist($user);

        return $user;
    }

    /**
     * A cover of that teacher on that day, with the absence it hangs from (one per teacher and day).
     */
    private function cover(User $absent, string $day, int $slot, ?User $guardia, ?string $group = 'E4D'): GuardiaCover
    {
        $date = new \DateTimeImmutable($day);
        $absence = $this->em->getRepository(Absence::class)->findOneBy(['absentTeacher' => $absent, 'date' => $date]);
        if (null === $absence) {
            $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date);
            $this->em->persist($absence);
        }
        $absence->addSlotIndexes([$slot]);
        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate($date)
            ->setSlotIndex($slot)
            ->setAbsentTeacher($absent)
            ->setGroupName($group)
            ->setRoomName('A12')
            ->setSubjectName('Matemáticas')
            ->setAssignedGuardia($guardia);
        $this->em->persist($cover);
        $this->em->flush();

        return $cover;
    }

    private function academicYear(): AcademicYear
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

    private function bankItem(): GuardiaTaskBankItem
    {
        $year = $this->academicYear();
        $department = (new Department())->setCode('mates-'.uniqid())->setName('Matemáticas');
        $item = (new GuardiaTaskBankItem())
            ->setAcademicYear($year)
            ->setDepartment($department)
            ->setLevel(EducationLevel::ESO_4)
            ->setTitle('Ecuaciones')
            ->setSubject('Matemáticas');
        $this->em->persist($department);
        $this->em->persist($item);

        return $item;
    }

    /**
     * A row whose creation time cannot be set from outside, moved back to that moment.
     */
    private function dated(object $row, string $property, string $at): void
    {
        (new \ReflectionProperty($row::class, $property))->setValue($row, new \DateTimeImmutable($at));
    }

    public function testForgetsWhoWasAwayButKeepsWhoCoveredAndTheFigures(): void
    {
        $ana = $this->user('Ana');
        $bruno = $this->user('Bruno');
        $lucia = $this->user('Lucía');
        $mario = $this->user('Mario');
        $bank = $this->bankItem();

        // El 8: dos clases juntas en la biblioteca (una guardia para Ana), y una hora que acabó en incidencia.
        $grouping = (new GuardiaGrouping())->setDate(new \DateTimeImmutable('2026-10-08'))->setSlotIndex(1)->setRoomName('Biblioteca')->setNote('Con el grupo de Lucía');
        $this->em->persist($grouping);
        $withTask = $this->cover($lucia, '2026-10-08', 1, $ana)
            ->setGrouping($grouping)
            ->setBankItem($bank)
            ->setTaskDocumentPath($this->uploader->store('%PDF-1.4 tarea', 'guardia-tasks', 'pdf'))
            ->setTaskDocumentName('Examen adaptado de Pablo.pdf')
            ->setTaskDescription('Página 40')
            ->setIncidentNote('El proyector no iba');
        $this->cover($mario, '2026-10-08', 1, $ana, '2DIVA, 2DIVB')->setGrouping($grouping);
        $this->cover($lucia, '2026-10-08', 2, $bruno)->setNotCovered(true);
        // El 14, último día que sale del plazo; el 15, el primero que se queda.
        $this->cover($lucia, '2026-10-14', 3, $bruno, null);
        $kept = $this->cover($lucia, '2026-10-15', 1, $ana);
        $this->em->flush();
        $document = (string) $withTask->getTaskDocumentPath();

        $orphanTrail = new AuditLog('guardia_cover.deleted', 'coordinacion@centro.test', 'GuardiaCover', '999999', null, ['groupName' => 'E4D']);
        $recentOrphanTrail = new AuditLog('guardia_cover.deleted', 'coordinacion@centro.test', 'GuardiaCover', '999998');
        $this->dated($orphanTrail, 'occurredAt', '2026-10-01 10:00');
        $this->dated($recentOrphanTrail, 'occurredAt', '2026-10-20 10:00');
        $oldNotice = new Notification($ana, 'guardia.assigned', 'Cubres a Lucía (grupo E4D)');
        $recentNotice = new Notification($ana, 'guardia.assigned', 'Cubres a Lucía (grupo E4D)');
        $oldTaskNotice = new Notification($ana, 'task.reminder', 'Entrega la programación');
        $this->dated($oldNotice, 'createdAt', '2026-10-08 08:00');
        $this->dated($recentNotice, 'createdAt', '2026-10-20 08:00');
        $this->dated($oldTaskNotice, 'createdAt', '2026-10-01 08:00');
        foreach ([$orphanTrail, $recentOrphanTrail, $oldNotice, $recentNotice, $oldTaskNotice] as $row) {
            $this->em->persist($row);
        }
        $this->em->flush();

        $loadBefore = $this->covers->totalLoad();
        $summaryBefore = $this->covers->coverageSummary();
        $byGroupBefore = $this->covers->periodsByGroup();
        $keptId = (int) $kept->getId();
        $this->em->clear();

        $purged = $this->purger->purge(AbsenceRetention::ONE_WEEK, new \DateTimeImmutable(self::TODAY));

        self::assertSame(['absences' => 3, 'covers' => 4, 'breakGaps' => 0, 'unremoved' => []], $purged, 'Lucía el 8 y el 14 y Mario el 8; cuatro horas');
        self::assertSame($loadBefore, $this->covers->totalLoad(), 'el reparto no cambia porque un día pase del plazo');
        self::assertSame($summaryBefore, $this->covers->coverageSummary());
        self::assertSame($byGroupBefore, $this->covers->periodsByGroup(), 'las horas por grupo siguen sumando lo archivado');
        self::assertSame(
            ['Ana' => 2, 'Bruno' => 1],
            array_column(array_map(static fn (array $r): array => ['name' => $r['teacher']->getFullName(), 'total' => $r['total']], $this->covers->coveredTotalsByTeacher()), 'total', 'name'),
            'Ana tiene una archivada y otra viva y sale una sola vez, con las dos',
        );
        self::assertSame(2, $this->covers->countCoveredForTeacher($this->em->getRepository(User::class)->find($ana->getId())));
        self::assertSame(['E4D' => 3, '2DIVA' => 1, '2DIVB' => 1], array_column($this->covers->periodsByGroup(), 'total', 'name'));

        $live = $this->em->getRepository(GuardiaCover::class)->findAll();
        self::assertSame([$keptId], array_map(static fn (GuardiaCover $c): ?int => $c->getId(), $live), 'el 15 se queda tal cual');
        self::assertCount(1, $this->em->getRepository(Absence::class)->findAll());
        self::assertCount(0, $this->em->getRepository(GuardiaGrouping::class)->findAll(), 'la agrupación decía dónde estaban esos grupos');

        $archived = $this->em->getRepository(ArchivedGuardiaCover::class)->findBy([], ['date' => 'ASC', 'slotIndex' => 'ASC']);
        self::assertSame(
            [['2026-10-08', 1, 'Ana', false, true], ['2026-10-08', 1, 'Ana', false, true], ['2026-10-08', 2, 'Bruno', true, false], ['2026-10-14', 3, 'Bruno', false, false]],
            array_map(static fn (ArchivedGuardiaCover $a): array => [$a->getDate()->format('Y-m-d'), $a->getSlotIndex(), $a->getAssignedGuardia()?->getFullName(), $a->isNotCovered(), $a->isGrouped()], $archived),
        );

        self::assertFalse(is_file($this->uploader->absolutePath($document)), 'la tarea que dejó la ausente se borra del disco');
        self::assertSame([null], array_values($this->covers->bankTasksDoneByGroup('E4D', 0)), 'el grupo ya hizo la tarea, sin decir cuándo');
        self::assertSame([], $this->covers->bankTasksDoneByGroup('2DIVA, 2DIVB', 0), 'ese grupo no tenía tarea del banco');

        $notices = array_map(static fn (Notification $n): string => $n->getKind().' '.$n->getCreatedAt()->format('d'), $this->em->getRepository(Notification::class)->findBy(['recipient' => $ana]));
        sort($notices);
        self::assertSame(['guardia.assigned 20', 'task.reminder 01'], $notices, 'solo se van los avisos de guardia pasados del plazo');

        $trail = $this->em->getRepository(AuditLog::class)->findBy(['subjectType' => ['GuardiaCover', 'Absence', 'GuardiaGrouping']]);
        $subjects = array_unique(array_map(static fn (AuditLog $l): string => $l->getSubjectType().' '.$l->getSubjectId(), $trail));
        sort($subjects);
        $keptAbsence = $this->em->getRepository(Absence::class)->findAll()[0];
        self::assertSame(['Absence '.$keptAbsence->getId(), 'GuardiaCover '.$keptId, 'GuardiaCover 999998'], $subjects, 'del rastro queda lo vivo y lo borrado a mano dentro del plazo');
    }

    public function testCopyOrdersForAForgottenGuardiaNoLongerSayWhatForNorWhoAskedButStayCounted(): void
    {
        $lucia = $this->user('Lucía');
        $ana = $this->user('Ana');
        $order = fn (?GuardiaCover $cover, string $context): CopyRequest => (new CopyRequest())
            ->setCover($cover)
            ->setContext($context)
            ->setNotes('A doble cara')
            ->setRequestedBy($lucia)
            ->setRecipient('conserjeria@centro.test')
            ->addLine(28);
        $forgotten = $order($this->cover($lucia, '2026-10-08', 1, $ana), '4º de ESO · E4D · aula A12 · 08/10, 1ª hora')
            ->addLine(1, $this->uploader->store('%PDF-1.4 tarea', 'copy-requests', 'pdf'), 'Examen adaptado de Pablo.pdf');
        $document = (string) $forgotten->getDocumentLines()[0]->getPath();
        $kept = $order($this->cover($lucia, '2026-10-15', 1, $ana), '4º de ESO · E4D · aula A12 · 15/10, 1ª hora');
        // Uno suelto, sin ausencia detrás: no habla de nadie que faltara, así que no es cosa de este plazo.
        $standalone = $order(null, 'Circular para las familias');
        $this->dated($standalone, 'requestedAt', '2026-10-01 09:00');
        foreach ([$forgotten, $kept, $standalone] as $row) {
            $this->em->persist($row);
        }
        $this->em->flush();
        $ids = [(int) $forgotten->getId(), (int) $kept->getId(), (int) $standalone->getId()];
        $this->em->clear();

        $this->purger->purge(AbsenceRetention::ONE_WEEK, new \DateTimeImmutable(self::TODAY));

        $orders = $this->em->getRepository(CopyRequest::class);
        $read = static fn (CopyRequest $o): array => [$o->getContext(), $o->getNotes(), $o->getRequestedBy()?->getFullName(), null !== $o->getCover(), $o->getTotalCopies()];
        $order = $orders->find($ids[0]);
        self::assertSame([AbsencePurger::FORGOTTEN_ORDER_CONTEXT, null, null, false, 29], $read($order), 'quedan las copias que se encargaron ese día, no para quién ni quién');
        // Sin enviar no lo habría borrado la purga de documentos: ya no apunta a su guardia y lo fecharía por el encargo.
        self::assertSame([[null, null], [null, null]], array_map(static fn (CopyRequestLine $l): array => [$l->getPath(), $l->getName()], $order->getLines()));
        self::assertNotNull($order->getDocumentPurgedAt(), 'el listado dice que hubo documento y no ofrece reenviarlo');
        self::assertFalse(is_file($this->uploader->absolutePath($document)));
        self::assertSame(['4º de ESO · E4D · aula A12 · 15/10, 1ª hora', 'A doble cara', 'Lucía', true, 28], $read($orders->find($ids[1])));
        self::assertSame(['Circular para las familias', 'A doble cara', 'Lucía', false, 28], $read($orders->find($ids[2])));
    }

    public function testDoesNothingWhenEverythingIsWithinThePlazo(): void
    {
        $ana = $this->user('Ana');
        $this->cover($this->user('Lucía'), '2026-10-15', 1, $ana);
        $this->em->clear();

        self::assertSame(['absences' => 0, 'covers' => 0, 'breakGaps' => 0, 'unremoved' => []], $this->purger->purge(AbsenceRetention::ONE_WEEK, new \DateTimeImmutable(self::TODAY)));
        self::assertCount(1, $this->em->getRepository(GuardiaCover::class)->findAll());
    }

    public function testALongerPlazoKeepsMore(): void
    {
        $this->cover($this->user('Lucía'), '2026-10-08', 1, $this->user('Ana'));
        $this->em->clear();

        self::assertSame(0, $this->purger->purge(AbsenceRetention::TWO_WEEKS, new \DateTimeImmutable(self::TODAY))['covers'], 'con dos semanas el 8 aún se queda');
        self::assertSame(1, $this->purger->purge(AbsenceRetention::ONE_WEEK, new \DateTimeImmutable(self::TODAY))['covers']);
    }

    public function testASecondRunAddsToTheSameMonth(): void
    {
        $this->cover($this->user('Lucía'), '2026-10-01', 1, $this->user('Ana'));
        $this->em->clear();
        $this->purger->purge(AbsenceRetention::ONE_WEEK, new \DateTimeImmutable('2026-10-09'));

        $this->cover($this->user('Mario'), '2026-10-05', 2, $this->user('Bruno'));
        $this->em->clear();
        $this->purger->purge(AbsenceRetention::ONE_WEEK, new \DateTimeImmutable(self::TODAY));

        self::assertSame([['name' => 'E4D', 'total' => 2]], $this->covers->periodsByGroup());
    }

    /**
     * A recreo left unwatched says who was away twice over: the teacher on the rota line, and the zone and
     * recreo of a fixed rota with the day. Past the plazo only the course, the day and who stepped in stay,
     * and the course goes on counting it.
     */
    public function testAnUnwatchedRecreoKeepsTheDayAndWhoSteppedInButNotTheRotaLine(): void
    {
        $year = $this->academicYear();
        $patio = (new BreakZone())->setName('Patio')->setWeight(3);
        $this->em->persist($patio);
        $ana = $this->user('Ana');
        $duty = (new BreakDutyAssignment())
            ->setAcademicYear($year)
            ->setTeacher($this->user('Lucía'))
            ->setWeekday(Weekday::MONDAY)
            ->setZone($patio)
            ->setPeriod(BreakPeriod::FIRST);
        $this->em->persist($duty);
        $gap = fn (string $day, ?User $volunteer, ?string $note): BreakDutyGap => (new BreakDutyGap())
            ->setAssignment($duty)
            ->setDate(new \DateTimeImmutable($day))
            ->setVolunteer($volunteer)
            ->setNote($note);
        $this->em->persist($gap('2026-10-05', null, null));
        $this->em->persist($gap('2026-10-12', $ana, 'Lucía tenía médico'));
        $kept = $gap('2026-10-19', null, 'Sin patio, llueve');
        $this->em->persist($kept);
        $oldNotice = new Notification($ana, BreakDutyGapNotifier::KIND, 'Patio sin vigilar: falta Lucía');
        $recentNotice = new Notification($ana, BreakDutyGapNotifier::KIND, 'Patio sin vigilar: falta Lucía');
        $this->dated($oldNotice, 'createdAt', '2026-10-12 08:00');
        $this->dated($recentNotice, 'createdAt', '2026-10-19 08:00');
        // El reparto del cuadrante no habla de nadie que faltara: no es cosa de este plazo.
        $announced = new Notification($ana, BreakRotaAnnouncer::KIND, 'Ya está el cuadrante de recreo');
        $this->dated($announced, 'createdAt', '2026-10-01 08:00');
        $orphanTrail = new AuditLog('break_duty_gap.deleted', 'coordinacion@centro.test', 'BreakDutyGap', '999999', null, ['note' => 'Lucía tenía médico']);
        $this->dated($orphanTrail, 'occurredAt', '2026-10-01 10:00');
        foreach ([$oldNotice, $recentNotice, $announced, $orphanTrail] as $row) {
            $this->em->persist($row);
        }
        $this->em->flush();
        $gaps = self::getContainer()->get(BreakDutyGapRepository::class);
        $summaryBefore = $gaps->summary($year);
        $keptId = (int) $kept->getId();
        $this->em->clear();

        $purged = $this->purger->purge(AbsenceRetention::ONE_WEEK, new \DateTimeImmutable(self::TODAY));

        self::assertSame(2, $purged['breakGaps']);
        $year = $this->em->getRepository(AcademicYear::class)->find($year->getId());
        self::assertInstanceOf(AcademicYear::class, $year);
        self::assertSame(['total' => 3, 'covered' => 1], $summaryBefore);
        self::assertSame($summaryBefore, $gaps->summary($year), 'el curso sigue contando los recreos que se quedaron sin vigilar');
        self::assertSame([$keptId], array_map(static fn (BreakDutyGap $g): ?int => $g->getId(), $this->em->getRepository(BreakDutyGap::class)->findAll()), 'el 19 se queda tal cual');
        self::assertSame('Sin patio, llueve', $this->em->getRepository(BreakDutyGap::class)->find($keptId)?->getNote());

        $archived = $this->em->getRepository(ArchivedBreakDutyGap::class)->findBy([], ['date' => 'ASC']);
        self::assertSame(
            [['2026-10-05', null], ['2026-10-12', 'Ana']],
            array_map(static fn (ArchivedBreakDutyGap $a): array => [$a->getDate()->format('Y-m-d'), $a->getVolunteer()?->getFullName()], $archived),
        );

        $notices = array_map(static fn (Notification $n): string => $n->getKind().' '.$n->getCreatedAt()->format('d'), $this->em->getRepository(Notification::class)->findBy(['recipient' => $ana]));
        sort($notices);
        self::assertSame([BreakRotaAnnouncer::KIND.' 01', BreakDutyGapNotifier::KIND.' 19'], $notices, 'el aviso del 12 nombraba a quien faltó');

        $trail = $this->em->getRepository(AuditLog::class)->findBy(['subjectType' => 'BreakDutyGap']);
        self::assertNotEmpty($trail, 'guardar los huecos deja rastro, o el test no prueba nada');
        self::assertSame([(string) $keptId], array_values(array_unique(array_map(static fn (AuditLog $l): string => (string) $l->getSubjectId(), $trail))), 'del rastro solo queda el del 19, ni siquiera el de uno borrado a mano');
    }
}
