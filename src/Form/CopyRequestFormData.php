<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\CopyRequest;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
     * What to print of each thing, in the order of {@see \App\Entity\GuardiaCover::getPrintableDocuments()}
     * — one entry per file, or a single one when there is no file (or in a standalone order). Each file
     * gets its own number (the exercises go one per pupil, the answer key once) and can be left out.
     *
     * @var array<int, CopyRequestLineData>
     */
    #[Assert\Valid]
    public array $lines;

    public ?string $notes = null;

    /** What the copies are for, as conserjería will read it. */
    #[Assert\NotBlank(message: 'Di para qué son las copias.')]
    #[Assert\Length(max: 255)]
    public string $context = '';

    /** The document to print; only asked for in a standalone order. */
    public ?UploadedFile $document = null;

    public function __construct()
    {
        $this->lines = [new CopyRequestLineData()];
    }

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
        $data->lines = array_map(static fn (): CopyRequestLineData => CopyRequestLineData::printing($copies), range(1, max(1, $count)));

        return $data;
    }

    /**
     * Positions of the lines that go in the order, so the caller only prepares (copies to storage) the files
     * that will actually be printed.
     *
     * @return list<int> zero-based positions, in order
     */
    public function printedPositions(): array
    {
        return array_keys(array_filter(array_values($this->lines), static fn (CopyRequestLineData $line): bool => $line->print));
    }

    /**
     * An order with every file left out is no order: at least one has to be ticked.
     *
     * @param ExecutionContextInterface $context the validation context
     */
    #[Assert\Callback]
    public function validateSomethingToPrint(ExecutionContextInterface $context): void
    {
        if ([] === $this->printedPositions()) {
            $context->buildViolation('Marca al menos un archivo para imprimir.')->atPath('lines')->addViolation();
        }
    }

    /**
     * Writes the submitted values onto the order, one line per ticked entry of {@see $lines}, each with the
     * file at the same position, if any. Lines left out are skipped. Call only on valid data.
     *
     * @param CopyRequest                                             $order the order to fill in
     * @param array<int, array{path: string|null, name: string|null}> $files the files to print, keyed by their
     *                                                                       position in {@see $lines}; missing
     *                                                                       when a line carries no file
     *
     * @throws \LogicException when applied to data that did not pass validation
     */
    public function applyTo(CopyRequest $order, array $files = []): void
    {
        $lines = array_values($this->lines);
        foreach ($this->printedPositions() as $i) {
            $copies = $lines[$i]->copies;
            if (null === $copies) {
                throw new \LogicException('El encargo se aplica solo tras validarse: falta el número de copias.');
            }
            $order->addLine($copies, $files[$i]['path'] ?? null, $files[$i]['name'] ?? null);
        }

        $order->setNotes($this->notes)->setContext($this->context);
    }
}
