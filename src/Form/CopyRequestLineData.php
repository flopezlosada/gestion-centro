<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One file of a copy order as the form holds it: whether to print it and how many copies.
 *
 * Both go in the same object so "print this file" and "this many copies" cannot drift apart by position.
 * A file left unticked is not printed, and whatever number its box holds is ignored: a task from the bank
 * can carry the answer key or a picture the teacher does not want on paper.
 */
final class CopyRequestLineData
{
    public const int MAX_COPIES = 500;

    /** Whether this file goes in the order. Ticked by default: most of the time everything is printed. */
    public bool $print = true;

    /** How many copies; required only when {@see $print} is ticked. */
    public ?int $copies = null;

    /**
     * Builds a line to print with a prefilled number of copies.
     *
     * @param int|null $copies the number to prefill, or null to leave it blank
     *
     * @return self the line
     */
    public static function printing(?int $copies): self
    {
        $line = new self();
        $line->copies = $copies;

        return $line;
    }

    /**
     * Requires a number in range, only for a file that is going to be printed.
     *
     * @param ExecutionContextInterface $context the validation context
     */
    #[Assert\Callback]
    public function validateCopies(ExecutionContextInterface $context): void
    {
        if (!$this->print) {
            return;
        }
        if (null === $this->copies) {
            $context->buildViolation('Indica cuántas copias hacen falta.')->atPath('copies')->addViolation();

            return;
        }
        if ($this->copies < 1 || $this->copies > self::MAX_COPIES) {
            $context->buildViolation(sprintf('El número de copias debe estar entre 1 y %d.', self::MAX_COPIES))->atPath('copies')->addViolation();
        }
    }
}
