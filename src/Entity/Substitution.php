<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\SubstitutionRepository;
use App\Util\Excerpt;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quien cubre a una persona de baja larga, y durante cuánto.
 *
 * El centro mete la baja en RAICES a mano y aquí solo da de alta a quien la cubre ATADA a la persona
 * sustituida: nadie teclea un horario, porque el de quien sustituye no sale de ningún sitio explotable
 * (se pica a mano en RAICES). Lo que hace esta fila es autorizar el traspaso — ver
 * {@see \App\Guardia\SubstitutionApplier}.
 *
 * **Abrirla traspasa, cerrarla devuelve.** No hay estado "programada" ni un cron que la active en su
 * fecha: {@see $endedOn} a null ES la sustitución en vigor, y las dos fechas son documentación de
 * cuándo pasó, no disparadores. La alternativa (una vigencia por fechas que el sistema resuelve solo)
 * exigiría que {@see ScheduleEntry} supiera de fechas, y no sabe: la rejilla es semanal
 * ({@see ScheduleEntry::$weekday} + {@see ScheduleEntry::$slotIndex}, sin un solo día del calendario).
 *
 * De ahí el límite honesto de este modelo, que la pantalla dice en voz alta: **la rejilla solo conoce
 * el presente**. Dar de alta hoy una baja que empezó hace dos semanas no reconstruye esas dos semanas
 * de partes. No hace falta que lo haga: el parte materializa profesor, grupo, aula y materia al
 * registrar la ausencia ({@see GuardiaCover}), así que los días ya pasados no se recalculan nunca.
 *
 * **Lo que NO se hereda son los cargos.** {@see User::getAssignedRoles()} no se toca. El encargo del
 * centro decía "asuma todas las funcionalidades de la persona a la que sustituye", y está acotado a
 * propósito: si la baja es de una jefatura de departamento —o de dirección—, quien sustituye da sus
 * clases y sus guardias, pero heredar la colección de roles sería regalar permisos de dirección a
 * alguien que llega en noviembre.
 *
 * Tampoco se desactiva a la persona sustituida ({@see User::$active}): sigue existiendo con su agenda,
 * su histórico y sus tareas, que siguen siendo suyas.
 *
 * Atada a un curso porque el horario lo está: en septiembre se reimporta todo y una sustitución que
 * cruzara de curso no tendría nada que devolver. Una baja que cruce el verano se cierra y se vuelve a
 * abrir sobre el curso nuevo.
 *
 * Auditable: quién dio de alta a quién, cuándo, y quién cerró la sustitución es exactamente lo que se
 * pregunta en junio.
 */
#[ORM\Entity(repositoryClass: SubstitutionRepository::class)]
#[ORM\Table(name: 'substitution')]
#[ORM\Index(name: 'IDX_substitution_year', columns: ['academic_year_id'])]
#[ORM\Index(name: 'IDX_substitution_substituted', columns: ['substituted_teacher_id'])]
#[ORM\Index(name: 'IDX_substitution_substitute', columns: ['substitute_id'])]
class Substitution implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * El curso cuyo horario se traspasa. Lo que se mueve se define por (curso, persona), así que
     * devolverlo al cerrar es determinista sin guardar una lista de filas que un reimport dejaría
     * apuntando a ids que ya no existen.
     */
    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    /** La persona de baja. Conserva su cuenta, su agenda y sus tareas; solo pierde el horario. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'substituted_teacher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $substitutedTeacher;

    /** Quien la cubre. Normalmente un alta nueva, pero puede ser alguien que ya estuvo antes. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'substitute_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $substitute;

    /**
     * Desde cuándo, según el centro. Informativa salvo en un punto: acota hasta dónde hacia atrás se
     * traspasan las guardias ya asignadas ({@see \App\Guardia\SubstitutionApplier}), que nunca bajan de
     * hoy.
     */
    #[ORM\Column(name: 'started_on', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startedOn;

    /**
     * Cuándo se cerró, o null mientras está en vigor. **Null es el estado**, no un dato que falte: se
     * rellena en el mismo gesto que devuelve el horario, así que no puede haber una sustitución con
     * fecha de fin y el horario todavía traspasado.
     */
    #[ORM\Column(name: 'ended_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedOn = null;

    /** Motivo o referencia ("baja por enfermedad", el nº de expediente). Opcional. */
    #[ORM\Column(name: 'note', length: 255, nullable: true)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(AcademicYear $academicYear): static
    {
        $this->academicYear = $academicYear;

        return $this;
    }

    public function getSubstitutedTeacher(): User
    {
        return $this->substitutedTeacher;
    }

    public function setSubstitutedTeacher(User $substitutedTeacher): static
    {
        $this->substitutedTeacher = $substitutedTeacher;

        return $this;
    }

    public function getSubstitute(): User
    {
        return $this->substitute;
    }

    public function setSubstitute(User $substitute): static
    {
        $this->substitute = $substitute;

        return $this;
    }

    public function getStartedOn(): \DateTimeImmutable
    {
        return $this->startedOn;
    }

    public function setStartedOn(\DateTimeImmutable $startedOn): static
    {
        $this->startedOn = $startedOn;

        return $this;
    }

    public function getEndedOn(): ?\DateTimeImmutable
    {
        return $this->endedOn;
    }

    public function setEndedOn(?\DateTimeImmutable $endedOn): static
    {
        $this->endedOn = $endedOn;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * Guarda la nota, normalizando el blanco a null y recortando a lo que cabe en la columna. Recortado
     * aquí y no confiado al formulario, como en {@see GuardiaSupport::setNote()}: un texto de más de 255
     * caracteres saldría como un 500 en vez de como una nota guardada.
     *
     * @param string|null $note el motivo, o null/blanco para ninguno
     */
    public function setNote(?string $note): static
    {
        $clamped = Excerpt::of($note, 255);
        $this->note = '' !== $clamped ? $clamped : null;

        return $this;
    }

    /**
     * Si la sustitución está en vigor, es decir, si el horario está traspasado ahora mismo.
     *
     * @return bool true mientras no se haya cerrado
     */
    public function isOpen(): bool
    {
        return null === $this->endedOn;
    }
}
