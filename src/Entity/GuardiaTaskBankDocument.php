<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One file of a {@see GuardiaTaskBankItem}. A bank task is often more than one sheet — the exercises
 * and their answer key, a reading and its questions — and the department uploads them together so the
 * covering teacher finds the whole task in one place.
 *
 * Immutable once stored: replacing a file is removing it and uploading another, so a copy order that
 * snapshotted it ({@see CopyRequestLine}) never has to wonder which version it got. {@see $position}
 * keeps them in the order they were uploaded, which is the order the copy room receives them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'guardia_task_bank_document')]
#[ORM\Index(name: 'IDX_bank_document_item', columns: ['item_id'])]
class GuardiaTaskBankDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GuardiaTaskBankItem::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private GuardiaTaskBankItem $item;

    /** Storage-relative path, as returned by {@see \App\Service\FileUploader}. */
    #[ORM\Column(length: 255)]
    private string $path;

    /** Original client filename, so the download and the attachment keep a recognisable name. */
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    /**
     * @param GuardiaTaskBankItem $item     the task the file belongs to
     * @param string              $path     the storage-relative path of the stored file
     * @param string              $name     the original filename
     * @param int                 $position its place among the task's files
     */
    public function __construct(GuardiaTaskBankItem $item, string $path, string $name, int $position)
    {
        $this->item = $item;
        $this->path = $path;
        $this->name = $name;
        $this->position = $position;
    }

    /** The row id, null until persisted. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** The bank task this file belongs to. */
    public function getItem(): GuardiaTaskBankItem
    {
        return $this->item;
    }

    /** The storage-relative path of the file. */
    public function getPath(): string
    {
        return $this->path;
    }

    /** The original filename. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Its place among its siblings, first is 0. */
    public function getPosition(): int
    {
        return $this->position;
    }
}
