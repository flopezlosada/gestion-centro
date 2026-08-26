<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmittedEffectRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un efecto que ya se ha producido y NO debe producirse dos veces.
 *
 * Es deliberadamente genérica: no habla de correos ni de tareas concretas. Un efecto es cualquier
 * cosa irreversible hacia fuera del sistema — un email, un aviso push, un fichero depositado, una
 * llamada a una API que factura por operación. Lo único que el sistema necesita saber es «esto ya está
 * hecho», y para eso basta con una clave.
 *
 * La clave tiene tres partes, y el índice único sobre ellas ES el mecanismo:
 *
 * - `kind`: qué clase de efecto ("meeting_reminder", "copy_request"…).
 * - `reference`: sobre qué o quién ("user-76", "meeting-2026-09-01"…). Cuando el efecto es único por
 *   ejecución y no por destinatario, vale una referencia fija.
 * - `occurredOn`: la fecha de negocio a la que corresponde, no el instante en que se emitió. Es lo que
 *   hace que «el recordatorio de la reunión del 8 de septiembre» sea distinto del de la semana
 *   siguiente.
 *
 * POR QUÉ NO BASTA UN SELLO POR TAREA Y DÍA. Si el envío de cuarenta avisos se cae en el tercero, un
 * sello puesto al principio deja a treinta y siete personas sin aviso para siempre, y puesto al final
 * hace que el reintento repita los tres primeros. La granularidad tiene que ser la del efecto, no la
 * de la tarea.
 *
 * Y por qué un índice único y no una comprobación en código: los `if` pierden las carreras. Dos
 * procesos pueden comprobar a la vez que un aviso no se ha mandado y mandarlo los dos; contra el
 * índice, el segundo choca.
 *
 * NINGUNA TAREA DE ESTA APLICACIÓN LO USA TODAVÍA, y es correcto: los cinco barridos de avisos ya
 * llevan su propio sello en su propia tabla (`Notification`, el `remindedAt` del evento, el sello de
 * RAICES de la cobertura), que es idempotencia por estado y cumple el mismo contrato. Esto está aquí
 * para la tarea SIGUIENTE, la que produzca un efecto que no tenga dónde apuntarse solo — y para que
 * quien la escriba no tenga que inventarse el mecanismo otra vez ({@see \App\Service\Cron\EffectLedger},
 * {@see \App\Command\AbstractCronCommand::emitOnce()}).
 */
#[ORM\Entity(repositoryClass: EmittedEffectRepository::class)]
#[ORM\Table(name: 'emitted_effect')]
#[ORM\UniqueConstraint(name: 'UNIQ_emitted_effect_key', columns: ['kind', 'reference', 'occurred_on'])]
// Sirve a la purga por antigüedad.
#[ORM\Index(name: 'IDX_emitted_effect_emitted_at', columns: ['emitted_at'])]
class EmittedEffect
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Clase de efecto. La declara quien lo emite; el guardián no interpreta el valor. */
    #[ORM\Column(length: 60)]
    private string $kind = '';

    /** A qué o a quién se refiere el efecto. */
    #[ORM\Column(length: 100)]
    private string $reference = '';

    /**
     * Fecha de negocio del efecto (el día de la reunión avisada, el día del encargo…), NO el instante
     * de emisión: ése es {@see self::$emittedAt}.
     */
    #[ORM\Column(name: 'occurred_on', type: 'date_immutable')]
    private \DateTimeImmutable $occurredOn;

    /**
     * Destino concreto, si lo hay: una dirección de correo, una URL. No forma parte de la clave —el
     * mismo efecto no debe repetirse aunque cambie el destino— y sirve para auditar después («¿a qué
     * dirección se mandó?»).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $target = null;

    /** Cuándo se apuntó el efecto. Con este dato se purga la tabla por antigüedad. */
    #[ORM\Column(name: 'emitted_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $emittedAt;

    public function __construct()
    {
        $this->occurredOn = new \DateTimeImmutable('today');
        $this->emittedAt = new \DateTimeImmutable();
    }

    /**
     * @return int|null identificador autogenerado
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string clase de efecto
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @param string $kind clase de efecto
     */
    public function setKind(string $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    /**
     * @return string referencia del efecto
     */
    public function getReference(): string
    {
        return $this->reference;
    }

    /**
     * @param string $reference referencia del efecto
     */
    public function setReference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    /**
     * @return \DateTimeImmutable fecha de negocio del efecto
     */
    public function getOccurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }

    /**
     * @param \DateTimeImmutable $occurredOn fecha de negocio del efecto
     */
    public function setOccurredOn(\DateTimeImmutable $occurredOn): self
    {
        $this->occurredOn = $occurredOn;

        return $this;
    }

    /**
     * @return string|null destino del efecto, o null
     */
    public function getTarget(): ?string
    {
        return $this->target;
    }

    /**
     * Guarda el destino recortado al largo de la columna: una dirección larga o un valor inesperado no
     * deben reventar el INSERT y con él el envío.
     *
     * @param string|null $target destino del efecto
     */
    public function setTarget(?string $target): self
    {
        $this->target = null === $target ? null : mb_substr(trim($target), 0, 255);

        return $this;
    }

    /**
     * @return \DateTimeImmutable instante en que se apuntó el efecto
     */
    public function getEmittedAt(): \DateTimeImmutable
    {
        return $this->emittedAt;
    }

    /**
     * @param \DateTimeImmutable $emittedAt instante de emisión
     */
    public function setEmittedAt(\DateTimeImmutable $emittedAt): self
    {
        $this->emittedAt = $emittedAt;

        return $this;
    }
}
