<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\BreakZone;
use Doctrine\Persistence\ObjectManager;

/**
 * The zones watched during the recreos, as the centre named them (2026-07-30): patio, pasillo,
 * biblioteca, pistas y patio dirigido — the last one new for the coming course.
 *
 * Part of the GOLDEN backbone because a production database needs them from day one: without zones the
 * break duty rota has no columns to fill. They are seeded as data, not code, precisely so the centre can
 * add, rename, re-weight or archive them from the app afterwards.
 *
 * The weights are a STARTING POINT, not a verdict: the centre said the zones do not all cost the same
 * but has not graded them, so these are the obvious reading (the library is the calm one, the yard and
 * the courts the demanding ones) and the screen exists for them to correct it. What matters is that the
 * equitable counter adds up weights rather than counting turns.
 */
final class BreakZoneFixtures extends AbstractGoldenFixture
{
    /**
     * Reference name for the zone with the given name, so other fixtures can wire to it.
     *
     * @param string $name the zone name
     *
     * @return string the fixture reference name
     */
    public static function ref(string $name): string
    {
        return 'break-zone-'.mb_strtolower($name);
    }

    public function load(ObjectManager $manager): void
    {
        // name => [weight, teachers needed each recreo]
        $catalog = [
            'Patio' => [3, 2],
            'Pistas' => [3, 1],
            'Pasillo' => [2, 1],
            'Biblioteca' => [1, 1],
            'Patio dirigido' => [3, 1],
        ];

        $order = 0;
        foreach ($catalog as $name => [$weight, $required]) {
            $zone = (new BreakZone())
                ->setName($name)
                ->setWeight($weight)
                ->setRequiredTeachers($required)
                ->setSortOrder($order++);
            $manager->persist($zone);
            $this->addReference(self::ref($name), $zone);
        }

        $manager->flush();
    }
}
