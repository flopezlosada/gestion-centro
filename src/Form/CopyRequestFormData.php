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
    /**
     * How many copies of each thing to print, in the order of {@see \App\Entity\GuardiaCover::getPrintableDocuments()}
     * — one entry per file, or a single one when there is no file (or in a standalone order). Each file
     * gets its own number: the exercises go one per pupil, the answer key once.
     *
     * @var array<int, int|null>
     */
    #[Assert\All([
        new Assert\NotNull(message: 'Indica cuántas copias hacen falta.'),
        new Assert\Range(min: 1, max: 500, notInRangeMessage: 'El número de copias debe estar entre {{ min }} y {{ max }}.'),
    ])]
    public array $copies = [null];

    public ?string $notes = null;

    /** What the copies are for, as conserjería will read it. */
    #[Assert\NotBlank(message: 'Di para qué son las copias.')]
    #[Assert\Length(max: 255)]
    public string $context = '';

    /** The document to print; only asked for in a standalone order. */
    public ?UploadedFile $document = null;

    /**
     * Prefills the same number of copies for each of the things to print.
     *
     * @param int      $count  how many things there are to print (at least one)
     * @param int|null $copies the number to prefill, or null to leave them blank
     *
     * @return self the form data
     */
    public static function forCount(int $count, ?int $copies): self
    {
        $data = new self();
        $data->copies = array_fill(0, max(1, $count), $copies);

        return $data;
    }

    /**
     * Writes the submitted values onto the order, one line per entry of {@see $copies}, each with the file
     * at the same position, if any. Call only on valid data.
     *
     * @param CopyRequest                                       $order the order to fill in
     * @param list<array{path: string|null, name: string|null}> $files the files to print, in the order of
     *                                                                 {@see $copies}; shorter (even empty)
     *                                                                 when some lines carry no file
     *
     * @throws \LogicException when applied to data that did not pass validation
     */
    public function applyTo(CopyRequest $order, array $files = []): void
    {
        foreach (array_values($this->copies) as $i => $copies) {
            if (null === $copies) {
                throw new \LogicException('El encargo se aplica solo tras validarse: falta el número de copias.');
            }
            $order->addLine($copies, $files[$i]['path'] ?? null, $files[$i]['name'] ?? null);
        }

        $order->setNotes($this->notes)->setContext($this->context);
    }
}
