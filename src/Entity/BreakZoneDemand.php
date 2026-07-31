<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\BreakPeriod;
use App\Enum\Weekday;
use App\Repository\BreakZoneDemandRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An EXCEPTION to how many people a zone needs, for one weekday and one recreo.
 *
 * The centre asked for two things the flat {@see BreakZone::$requiredTeachers} cannot say: "en los
 * recreos cortos no hay patios dirigidos" (varies by recreo) and "los patios dirigidos los organiza por
 * días el equipo directivo" (varies by weekday). Both are answered by a figure per cell.
 *
 * Only exceptions are stored, and that is the point. The ordinary case — a zone that needs the same
 * people every day at both recreos — stays a single number on the zone, and the fifty rows a full grid
 * would need never exist. A row here means somebody deliberately said "this cell is different", and
 * a zero means "nobody here at this recreo", which is exactly how the patio dirigido disappears from the
 * short one.
 *
 * Change tracking is automatic: the entity is {@see Auditable}. Who took a zone out of a recreo, and
 * when, is the kind of thing that gets asked in June.
 */
#[ORM\Entity(repositoryClass: BreakZoneDemandRepository::class)]
#[ORM\Table(name: 'break_zone_demand')]
#[ORM\UniqueConstraint(name: 'UNIQ_break_demand_cell', columns: ['zone_id', 'weekday', 'period'])]
class BreakZoneDemand implements Auditable
{
    /**
     * El máximo que se puede pedir en una casilla. No es cosmético: esta cifra la EXPANDE el motor en una
     * plaza por persona ({@see \App\Guardia\BreakRotaPlanner::places()}), así que un número absurdo
     * tecleado por error construiría un array enorme en cada carga del cuadrante. El `max` del formulario
     * no protege al servidor; esto sí.
     */
    public const MAX_REQUIRED = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The zone this exception is about. */
    #[ORM\ManyToOne(targetEntity: BreakZone::class)]
    #[ORM\JoinColumn(name: 'zone_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private BreakZone $zone;

    /** The weekday it applies to, ISO-8601 (Monday = 1). */
    #[ORM\Column(name: 'weekday', type: Types::SMALLINT, enumType: Weekday::class)]
    private Weekday $weekday;

    /** Which of the day's two recreos it applies to. */
    #[ORM\Column(name: 'period', length: 8, enumType: BreakPeriod::class)]
    private BreakPeriod $period;

    /** How many people this cell needs. Zero means the zone is not watched at that recreo. */
    #[ORM\Column(name: 'required_teachers', type: Types::SMALLINT)]
    private int $requiredTeachers = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getZone(): BreakZone
    {
        return $this->zone;
    }

    public function setZone(BreakZone $zone): static
    {
        $this->zone = $zone;

        return $this;
    }

    public function getWeekday(): Weekday
    {
        return $this->weekday;
    }

    public function setWeekday(Weekday $weekday): static
    {
        $this->weekday = $weekday;

        return $this;
    }

    public function getPeriod(): BreakPeriod
    {
        return $this->period;
    }

    public function setPeriod(BreakPeriod $period): static
    {
        $this->period = $period;

        return $this;
    }

    public function getRequiredTeachers(): int
    {
        return $this->requiredTeachers;
    }

    /**
     * Sets how many people the cell needs, clamped to 0..{@see self::MAX_REQUIRED}: a negative demand is
     * not a thing and would make every shortfall calculation lie, and an absurd one is expanded into that
     * many places by the engine.
     *
     * @param int $requiredTeachers the people needed
     */
    public function setRequiredTeachers(int $requiredTeachers): static
    {
        $this->requiredTeachers = max(0, min(self::MAX_REQUIRED, $requiredTeachers));

        return $this;
    }
}
