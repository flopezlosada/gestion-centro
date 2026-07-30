<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Department;
use App\Entity\GuardiaTaskBankItem;
use App\Enum\EducationLevel;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form-backing object for a {@see GuardiaTaskBankItem}. A DTO on purpose, like the rest of the
 * project's forms: a half-filled screen would otherwise mean a half-built entity with no level and no
 * department, which the entity refuses to represent (and the database refuses to store). Here a
 * missing choice is simply null, and validation turns it into a message next to the field.
 *
 * The document is part of the form but not of this mapping: storing an upload can fail on its own
 * terms, so the controller does it against the shared {@see \App\Support\DocumentUpload} policy and
 * writes the path onto the entity only when it succeeded.
 */
final class GuardiaTaskBankFormData
{
    #[Assert\NotNull(message: 'Elige el nivel al que va dirigida la tarea.')]
    public ?EducationLevel $level = null;

    /**
     * The subject the task is for, picked from the ones the timetable actually has: the group works on
     * the subject it was going to have, so this has to match a real class, not a hand-typed variant.
     */
    #[Assert\NotBlank(message: 'Elige la materia: la tarea tiene que ser de la asignatura que le tocaba al grupo.')]
    #[Assert\Length(max: 128)]
    public ?string $subject = null;

    /**
     * Section letters the task is limited to ("A" or "A, C"), or blank for the whole level + subject.
     * Optional on purpose: an optional subject mixes pupils of several sections, or of none.
     */
    #[Assert\Length(max: 64)]
    public ?string $sections = null;

    #[Assert\NotNull(message: 'Elige el departamento que aporta la tarea.')]
    public ?Department $department = null;

    #[Assert\NotBlank(message: 'Ponle un título a la tarea.')]
    #[Assert\Length(max: 160)]
    public string $title = '';

    public ?string $description = null;

    /** The uploaded document, handled by the controller (see the class docblock). */
    public ?UploadedFile $document = null;

    /** Ticked to drop the document the task already had. */
    public bool $removeDocument = false;

    #[Assert\Range(min: 1, max: 500, notInRangeMessage: 'El número de copias debe estar entre {{ min }} y {{ max }}.')]
    public ?int $suggestedCopies = null;

    public bool $active = true;

    /**
     * Prefills the form from an existing bank task (for editing).
     *
     * @param GuardiaTaskBankItem $item the task to edit
     *
     * @return self the prefilled form data
     */
    public static function fromItem(GuardiaTaskBankItem $item): self
    {
        $data = new self();
        $data->level = $item->getLevel();
        $data->subject = $item->getSubject();
        $data->sections = $item->getSectionsText();
        $data->department = $item->getDepartment();
        $data->title = $item->getTitle();
        $data->description = $item->getDescription();
        $data->suggestedCopies = $item->getSuggestedCopies();
        $data->active = $item->isActive();

        return $data;
    }

    /**
     * Writes the submitted values onto the task. Call only on valid data: level and department are
     * required, and a null here would mean the submit was applied without being validated.
     *
     * @param GuardiaTaskBankItem $item the task to fill in
     *
     * @throws \LogicException when applied to data that did not pass validation
     */
    public function applyTo(GuardiaTaskBankItem $item): void
    {
        if (null === $this->level || null === $this->department) {
            throw new \LogicException('Los datos del banco se aplican solo tras validarse: falta el nivel o el departamento.');
        }

        $item->setLevel($this->level)
            ->setSubject((string) $this->subject)
            ->setSections($this->sections)
            ->setDepartment($this->department)
            ->setTitle($this->title)
            ->setDescription($this->description)
            ->setSuggestedCopies($this->suggestedCopies)
            ->setActive($this->active);
    }
}
