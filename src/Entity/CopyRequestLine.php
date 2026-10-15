<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing to print in a {@see CopyRequest}, with its own number of copies: a task's exercises go one
 * per pupil while its answer key goes once, so a single number per order would be wrong for one of them.
 *
 * Usually a file ({@see $path}), snapshotted when the order is placed. A line without one is an order
 * described only in words (a guardia whose task is just a description), which still has to say how many
 * copies — or one whose file was purged once its day passed ({@see CopyRequest::getDocumentPurgedAt()}).
 */
#[ORM\Entity]
#[ORM\Table(name: 'copy_request_line')]
#[ORM\Index(name: 'IDX_copy_line_order', columns: ['order_id'])]
class CopyRequestLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CopyRequest::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CopyRequest $order;

    /** How many copies of this line, validated on the way in ({@see \App\Form\CopyRequestFormData}). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $copies;

    /** Storage-relative path of the order's own copy of the file, or null (see the class docblock). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $path;

    /** Original filename, used in the e-mail and the listing. Goes with the file when it is purged. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    /**
     * @param CopyRequest $order    the order the line belongs to
     * @param int         $copies   how many copies to print
     * @param string|null $path     the storage-relative path of the file, or null for a line without one
     * @param string|null $name     the original filename
     * @param int         $position its place in the order
     */
    public function __construct(CopyRequest $order, int $copies, ?string $path, ?string $name, int $position)
    {
        $this->order = $order;
        $this->copies = $copies;
        $this->path = $path;
        $this->name = null !== $path ? $name : null;
        $this->position = $position;
    }

    /** The row id, null until persisted. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** The order this line belongs to. */
    public function getOrder(): CopyRequest
    {
        return $this->order;
    }

    /** How many copies to print. */
    public function getCopies(): int
    {
        return $this->copies;
    }

    /** The storage-relative path of the file, or null when the line has none (or it was purged). */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /** The original filename, or null when the line has none (or it was purged). */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Whether the line carries a file to print.
     *
     * @return bool true when there is a file
     */
    public function hasDocument(): bool
    {
        return null !== $this->path;
    }

    /** Its place among its siblings, first is 0. */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Forgets the file, name included (a filename can be personal data too).
     *
     * @return string|null the storage-relative path of the file to delete, or null if there was none
     */
    public function purge(): ?string
    {
        $path = $this->path;
        $this->path = null;
        $this->name = null;

        return $path;
    }
}
