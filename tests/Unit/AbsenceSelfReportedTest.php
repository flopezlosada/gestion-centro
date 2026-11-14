<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Absence;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Una ausencia es «del propio docente» solo si TODAS sus pasadas las escribió él: es lo que le deja
 * anularla sin pasar por coordinación, así que una sola pasada ajena tiene que bastar para cerrarlo.
 */
final class AbsenceSelfReportedTest extends TestCase
{
    /**
     * A user with an id, as Doctrine would hand it over (the rule compares ids, not object identity).
     */
    private static function user(int $id): User
    {
        $user = (new User())->setFullName('Persona '.$id)->setEmail($id.'@centro.test');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    /**
     * @return iterable<string, array{list<int|null>, bool}>
     */
    public static function passes(): iterable
    {
        // [quién escribe cada pasada, en orden (1 = el docente ausente, null = nadie, p. ej. un comando)]
        yield 'la apunta él' => [[1], true];
        yield 'la apunta y luego la completa él' => [[1, 1], true];
        yield 'la apunta coordinación' => [[2], false];
        yield 'él y luego coordinación' => [[1, 2], false];
        yield 'coordinación y luego él' => [[2, 1], false];
        yield 'la crea un comando' => [[null], false];
    }

    /**
     * @param list<int|null> $reporters who wrote each pass, in order
     */
    #[DataProvider('passes')]
    public function testItIsSelfReportedOnlyWhileEveryPassIsTheTeachers(array $reporters, bool $expected): void
    {
        $absence = (new Absence())->setAbsentTeacher(self::user(1))->setDate(new \DateTimeImmutable('2026-11-16'));
        foreach ($reporters as $i => $reporter) {
            $absence->noteReporter(null === $reporter ? null : self::user($reporter), 0 === $i);
        }

        self::assertSame($expected, $absence->isSelfReported());
    }

    public function testANewAbsenceIsNotSelfReportedUntilSomebodyWritesIt(): void
    {
        self::assertFalse((new Absence())->isSelfReported());
    }
}
