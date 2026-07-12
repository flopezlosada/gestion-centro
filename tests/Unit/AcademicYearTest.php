<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AcademicYear;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * The six term dates must stay strictly ordered, and the derived accessors (year start/end, per-term
 * boundaries) must read from the right field. The validation context is stubbed so these stay pure.
 */
final class AcademicYearTest extends TestCase
{
    /**
     * Builds a well-formed course: three non-overlapping terms in chronological order.
     */
    private function validYear(): AcademicYear
    {
        return (new AcademicYear())
            ->setSchoolYear('2026-2027')
            ->setTerm1Start(new \DateTimeImmutable('2026-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2026-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2027-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2027-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2027-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2027-06-22'));
    }

    public function testAccessorsReadTheRightBoundary(): void
    {
        $year = $this->validYear();

        self::assertEquals(new \DateTimeImmutable('2026-09-15'), $year->getTermStart(1));
        self::assertEquals(new \DateTimeImmutable('2026-12-22'), $year->getTermEnd(1));
        self::assertEquals(new \DateTimeImmutable('2027-01-08'), $year->getTermStart(2));
        self::assertEquals(new \DateTimeImmutable('2027-06-22'), $year->getTermEnd(3));

        // Year bounds are the outermost term boundaries.
        self::assertEquals($year->getTermStart(1), $year->getYearStart());
        self::assertEquals($year->getTermEnd(3), $year->getYearEnd());
    }

    public function testInvalidTermNumberThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validYear()->getTermStart(4);
    }

    public function testWellOrderedYearReportsNoViolation(): void
    {
        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())->method('buildViolation');

        $this->validYear()->validateTermOrder($context);
    }

    /**
     * A term whose end precedes its own start is rejected, and the violation is attached to the
     * offending end field.
     */
    public function testTermEndingBeforeItStartsIsRejected(): void
    {
        $year = $this->validYear()->setTerm1End(new \DateTimeImmutable('2026-09-01')); // before term1Start

        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->method('atPath')->with('term1End')->willReturnSelf();
        $builder->expects(self::once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::once())->method('buildViolation')->willReturn($builder);

        $year->validateTermOrder($context);
    }

    /**
     * Overlapping terms (the second starting before the first ends) are rejected on the next term's
     * start field.
     */
    public function testOverlappingTermsAreRejected(): void
    {
        $year = $this->validYear()->setTerm2Start(new \DateTimeImmutable('2026-12-01')); // before term1End

        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->method('atPath')->with('term2Start')->willReturnSelf();
        $builder->expects(self::once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::once())->method('buildViolation')->willReturn($builder);

        $year->validateTermOrder($context);
    }
}
