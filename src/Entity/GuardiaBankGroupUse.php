<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * That a group has already done a task of the bank, kept once the guardia it did it in is archived.
 *
 * The bank skips what a group has done ({@see \App\Repository\GuardiaCoverRepository::bankTasksDoneByGroup()}),
 * and that used to be read from the covers. An archived cover no longer says which group it was, so the
 * pair is copied here first — without the day, which together with the group would say whose class it was.
 *
 * Written only by {@see \App\Guardia\AbsencePurger}. Goes with the task: the bank is per course.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'guardia_bank_group_use')]
#[ORM\UniqueConstraint(name: 'UNIQ_bank_group_use', columns: ['bank_item_id', 'group_name'])]
#[ORM\Index(name: 'IDX_bank_group_use_item', columns: ['bank_item_id'])]
class GuardiaBankGroupUse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GuardiaTaskBankItem::class)]
    #[ORM\JoinColumn(name: 'bank_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private GuardiaTaskBankItem $bankItem;

    /** The group exactly as the cover named it, which is how the bank looks it up. */
    #[ORM\Column(name: 'group_name', length: 255)]
    private string $groupName;

    /** The row id, null until stored. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** The bank task the group did. */
    public function getBankItem(): GuardiaTaskBankItem
    {
        return $this->bankItem;
    }

    /** The group. */
    public function getGroupName(): string
    {
        return $this->groupName;
    }
}
