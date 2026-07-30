<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\CopyRequest;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form-backing object for a {@see CopyRequest}. A DTO, like the rest of the project's forms: an empty
 * "número de copias" has to come back as "indica cuántas copias hacen falta" next to the field, not as
 * a type error against an entity that only knows how to hold a real number.
 *
 * {@see $context} is filled by the controller when the order comes from a guardia (group, room, day
 * and period are already known) and typed by the user in a standalone order; either way it is never
 * blank by the time it is validated. The document is handled by the controller against the shared
 * {@see \App\Support\DocumentUpload} policy.
 */
final class CopyRequestFormData
{
    #[Assert\NotNull(message: 'Indica cuántas copias hacen falta.')]
    #[Assert\Range(min: 1, max: 500, notInRangeMessage: 'El número de copias debe estar entre {{ min }} y {{ max }}.')]
    public ?int $copies = null;

    public ?string $notes = null;

    /** What the copies are for, as conserjería will read it. */
    #[Assert\NotBlank(message: 'Di para qué son las copias.')]
    #[Assert\Length(max: 255)]
    public string $context = '';

    /** The document to print; only asked for in a standalone order. */
    public ?UploadedFile $document = null;

    /**
     * Writes the submitted values onto the order. Call only on valid data.
     *
     * @param CopyRequest $order the order to fill in
     *
     * @throws \LogicException when applied to data that did not pass validation
     */
    public function applyTo(CopyRequest $order): void
    {
        if (null === $this->copies) {
            throw new \LogicException('El encargo se aplica solo tras validarse: falta el número de copias.');
        }

        $order->setCopies($this->copies)
            ->setNotes($this->notes)
            ->setContext($this->context);
    }
}
