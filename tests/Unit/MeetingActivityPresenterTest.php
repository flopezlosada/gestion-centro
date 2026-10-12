<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AuditLog;
use App\Entity\MeetingType;
use App\Entity\Project;
use App\Entity\User;
use App\Support\MeetingActivityPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * The life of a meeting told as events: one row per audit entry, with the person who did it, and NEVER
 * the text of its acta or its agenda — the trail stores them whole, the history only says they changed.
 * The diffs here have the shape the audit subscriber really stores (ATOM dates with their offset, users
 * and relations by id, collections as added/removed, every field of a creation with a null "old").
 */
final class MeetingActivityPresenterTest extends TestCase
{
    private const string ACTOR = 'natalia.rodriguezmora@educa.madrid.org';

    /**
     * @param array<string, mixed>|null $changes
     */
    private function entry(string $action, ?array $changes, ?string $actor = self::ACTOR): AuditLog
    {
        return new AuditLog($action, $actor, 'Meeting', '8', null, $changes);
    }

    /**
     * An entity manager whose repositories return, per class, the given entities.
     *
     * @param array<class-string, object[]> $byClass
     */
    private function em(array $byClass): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(function (string $class) use ($byClass): EntityRepository {
            $repo = $this->createMock(EntityRepository::class);
            $repo->method('findBy')->willReturn($byClass[$class] ?? []);

            return $repo;
        });

        return $em;
    }

    private function user(int $id, string $fullName, string $email): User
    {
        $user = (new User())->setFullName($fullName)->setEmail($email);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    /**
     * @return array<class-string, object[]>
     */
    private function staff(): array
    {
        return [User::class => [
            $this->user(274, 'Natalia Rodríguez Mora', self::ACTOR),
            $this->user(273, 'María Belén Rodríguez Martínez', 'mb@educa.madrid.org'),
            $this->user(242, 'Pedro Andrés Juez Pérez', 'pedro@educa.madrid.org'),
            $this->user(259, 'Beatriz Moraga Molina', 'beatriz@educa.madrid.org'),
        ]];
    }

    public function testACreationSaysWhoConvenedWhatAndHowMany(): void
    {
        $rows = (new MeetingActivityPresenter($this->em($this->staff())))->present([
            $this->entry('meeting.created', [
                'title' => ['old' => null, 'new' => 'Reunión del equipo directivo'],
                'agenda' => ['old' => null, 'new' => '<ol><li>Punto reservado</li></ol>'],
                'discussion' => ['old' => null, 'new' => null],
                'place' => ['old' => null, 'new' => 'Despacho de Dirección'],
                'onlineUrl' => ['old' => null, 'new' => null],
                'startAt' => ['old' => null, 'new' => '2026-10-09T11:45:00+02:00'],
                'endAt' => ['old' => null, 'new' => '2026-10-09T12:40:00+02:00'],
                'attendanceTakenAt' => ['old' => null, 'new' => null],
                'minutesPublishedAt' => ['old' => null, 'new' => null],
                'convener' => ['old' => null, 'new' => 274],
                'minutesTakenBy' => ['old' => null, 'new' => 259],
                'project' => ['old' => null, 'new' => null],
                'attendees' => ['added' => [274, 273, 242, 259], 'removed' => []],
            ]),
        ], showRemovals: true);

        self::assertCount(1, $rows);
        self::assertSame('Natalia Rodríguez Mora', $rows[0]['actor']);
        self::assertSame([
            'Convocó la reunión para el 09/10/2026 a las 11:45, con 4 personas convocadas',
            'Levanta el acta: Beatriz Moraga Molina',
        ], $rows[0]['events'], 'una creación es UN hecho, no la lista de sus treinta campos');
    }

    public function testChangesToTheConvocationReadAsEventsWithNames(): void
    {
        $rows = (new MeetingActivityPresenter($this->em($this->staff())))->present([
            $this->entry('meeting.updated', [
                'startAt' => ['old' => '2026-10-09T11:45:00+02:00', 'new' => '2026-10-13T10:15:00+02:00'],
                'place' => ['old' => null, 'new' => 'Sala de profesores'],
                'attendees' => ['added' => [273, 242], 'removed' => [259]],
            ]),
        ], showRemovals: true);

        self::assertSame([
            'Cambió el día o la hora: 09/10/2026 a las 11:45 → 13/10/2026 a las 10:15',
            'Cambió el lugar a Sala de profesores',
            'Convocó a María Belén Rodríguez Martínez y Pedro Andrés Juez Pérez',
            'Retiró de la convocatoria a Beatriz Moraga Molina',
        ], $rows[0]['events']);
    }

    public function testOnlyWhoeverManagesTheMeetingReadsWhoWasTakenOffIt(): void
    {
        $presenter = new MeetingActivityPresenter($this->em($this->staff()));
        $trail = [
            $this->entry('meeting.updated', ['attendees' => ['added' => [], 'removed' => [259]]]),
            $this->entry('meeting.updated', ['attendees' => ['added' => [273], 'removed' => [259]]]),
        ];

        $convened = $presenter->present($trail, showRemovals: false);

        // Una retirada sola no deja fila; con más cosas, se cuentan las demás.
        self::assertCount(1, $convened);
        self::assertSame(['Convocó a María Belén Rodríguez Martínez'], $convened[0]['events']);
        self::assertStringNotContainsString('Beatriz', json_encode($convened, \JSON_UNESCAPED_UNICODE) ?: '');
        self::assertCount(2, $presenter->present($trail, showRemovals: true));
    }

    public function testTheActaTextAndTheAgendaAreNeverShown(): void
    {
        $secret = 'Se trata el expediente disciplinario del alumno X';
        $rows = (new MeetingActivityPresenter($this->em($this->staff())))->present([
            $this->entry('meeting.updated', [
                'agenda' => ['old' => '<div>Antes</div>', 'new' => '<div>'.$secret.'</div>'],
                'discussion' => ['old' => null, 'new' => '<div>'.$secret.'</div>'],
                'agreements' => ['old' => null, 'new' => '<div>1. '.$secret.'</div>'],
                'attendanceTakenAt' => ['old' => null, 'new' => '2026-10-09T12:41:00+02:00'],
                'attended' => ['added' => [274, 242], 'removed' => []],
            ]),
        ], showRemovals: true);

        self::assertSame(['Modificó el orden del día', 'Modificó el desarrollo del acta', 'Modificó los acuerdos del acta', 'Pasó lista'], $rows[0]['events']);
        self::assertStringNotContainsString('expediente', json_encode($rows, \JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testARenamedMeetingDoesNotKeepItsOldTitle(): void
    {
        // Quien cambia el título puede estar quitándole el nombre de un alumno: el título viejo no se repite.
        $rows = (new MeetingActivityPresenter($this->em([])))->present([
            $this->entry('meeting.updated', [
                'title' => ['old' => 'Seguimiento disciplinario de Juan P. (3ºB)', 'new' => 'Reunión de tutoría'],
                'place' => ['old' => 'Aula 12', 'new' => null],
            ]),
        ], showRemovals: true);

        self::assertSame(['Cambió el título', 'Quitó el lugar'], $rows[0]['events']);
        self::assertStringNotContainsString('Juan', json_encode($rows, \JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testTimesAreShownAsRecordedWhateverTheOffset(): void
    {
        // En invierno el rastro va en +01:00 y en CI la zona es UTC: la hora que se pinta es la grabada.
        $rows = (new MeetingActivityPresenter($this->em([])))->present([
            $this->entry('meeting.updated', ['startAt' => ['old' => '2026-12-14T09:30:00+01:00', 'new' => '2026-12-14T08:30:00+00:00']]),
        ], showRemovals: true);

        self::assertSame(['Cambió el día o la hora: 14/12/2026 a las 09:30 → 14/12/2026 a las 08:30'], $rows[0]['events']);
    }

    public function testPublishingTellsTheFirstTimeFromACorrectionAndTheApproval(): void
    {
        $rows = (new MeetingActivityPresenter($this->em($this->staff())))->present([
            $this->entry('meeting.updated', ['minutesApprovedAt' => ['old' => null, 'new' => '2026-10-20T10:00:00+02:00'], 'minutesApprovedBy' => ['old' => null, 'new' => 259]]),
            $this->entry('meeting.updated', ['minutesPublishedAt' => ['old' => '2026-10-11T14:11:29+02:00', 'new' => '2026-10-12T09:00:00+02:00']]),
            $this->entry('meeting.updated', [
                'minutesPublishedAt' => ['old' => null, 'new' => '2026-10-11T14:11:29+02:00'],
                'minutesFirstPublishedAt' => ['old' => null, 'new' => '2026-10-11T14:11:29+02:00'],
                'minutesPublishedBy' => ['old' => null, 'new' => 274],
            ]),
            $this->entry('meeting.updated', ['minutesPublishedAt' => ['old' => '2026-10-11T14:11:29+02:00', 'new' => null], 'minutesPath' => ['old' => 'a.pdf', 'new' => 'b.pdf']]),
        ], showRemovals: true);

        self::assertSame(['Dio el acta por aprobada'], $rows[0]['events']);
        self::assertSame(['Publicó una corrección del acta'], $rows[1]['events']);
        self::assertSame(['Publicó el acta'], $rows[2]['events']);
        self::assertSame(['Generó o subió el documento del acta', 'El acta vuelve a borrador'], $rows[3]['events']);
    }

    public function testAnActaInTheCloudTellsItsCreationAndItsPublicationButNeverWhatItSays(): void
    {
        // Las dos entradas tal como las deja la auditoría al crear y publicar un acta en la nube.
        $rows = (new MeetingActivityPresenter($this->em($this->staff())))->present([
            $this->entry('meeting.updated', [
                'minutesCloudPublishedFileId' => ['old' => null, 'new' => '776612791'],
                'minutesPublishedAt' => ['old' => null, 'new' => '2026-10-12T11:01:23+02:00'],
                'minutesFirstPublishedAt' => ['old' => null, 'new' => '2026-10-12T11:01:23+02:00'],
                'minutesPublishedBy' => ['old' => null, 'new' => 274],
            ]),
            $this->entry('meeting.updated', [
                'minutesUploadedAt' => ['old' => null, 'new' => '2026-10-12T10:56:34+02:00'],
                'minutesCloudFileId' => ['old' => null, 'new' => '776611541'],
                'minutesUploadedBy' => ['old' => null, 'new' => 274],
            ]),
        ], showRemovals: true);

        self::assertSame(['Publicó el acta'], $rows[0]['events']);
        self::assertSame(['Creó el acta en la nube de EducaMadrid'], $rows[1]['events']);
    }

    public function testCorrectingTheRollIsNotTakingItAgain(): void
    {
        $rows = (new MeetingActivityPresenter($this->em($this->staff())))->present([
            $this->entry('meeting.updated', ['attendanceTakenAt' => ['old' => '2026-10-11T13:35:30+02:00', 'new' => '2026-10-11T13:42:49+02:00'], 'attended' => ['added' => [242], 'removed' => []]]),
        ], showRemovals: true);

        self::assertSame(['Corrigió la asistencia'], $rows[0]['events']);
    }

    public function testBookkeepingAloneLeavesNoRow(): void
    {
        // Lo que la aplicación apunta para sí (el instante del recordatorio, que se envió, que el PDF quedó
        // viejo) no es un hecho de la reunión.
        $rows = (new MeetingActivityPresenter($this->em([])))->present([
            $this->entry('meeting.updated', ['remindAt' => ['old' => null, 'new' => '2026-10-09T11:35:00+02:00'], 'reminderSentAt' => ['old' => null, 'new' => '2026-10-09T11:35:02+02:00']], null),
            $this->entry('meeting.updated', ['minutesStale' => ['old' => false, 'new' => true]]),
        ], showRemovals: true);

        self::assertSame([], $rows);
    }

    public function testRelationsAndPeopleThatNoLongerExistStillRead(): void
    {
        $type = (new MeetingType())->setName('CCP');
        (new \ReflectionProperty(MeetingType::class, 'id'))->setValue($type, 1);
        $rows = (new MeetingActivityPresenter($this->em([MeetingType::class => [$type], Project::class => [], User::class => []])))->present([
            $this->entry('meeting.updated', [
                'type' => ['old' => null, 'new' => 1],
                'project' => ['old' => 3, 'new' => 9],
                'minutesTakenBy' => ['old' => 274, 'new' => 999],
            ], 'sistema-borrado@centro.test'),
        ], showRemovals: true);

        self::assertSame('Una persona dada de baja', $rows[0]['actor'], 'sin persona detrás no se enseña su correo: es un dato personal');
        self::assertSame([
            'Cambió el tipo de reunión a CCP',
            'La vinculó al proyecto uno ya borrado',
            'Levanta el acta: una persona dada de baja',
        ], $rows[0]['events']);
    }
}
