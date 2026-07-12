<?php

declare(strict_types=1);

namespace App\DataFixtures;

/**
 * Fixture group names, centralised so they cannot drift between fixtures. Mirrors the pattern used in
 * the sibling ISO project: a stable "golden" backbone that seeds the eventual production database,
 * and a volatile "demo" layer of sample records to create and modify freely while testing.
 *
 * GOLDEN is the structural backbone with NO real-data source: the role catalog and the recurring-task
 * template catalog. Everything the roster ETL imports (people, departments — and it upserts the roles
 * idempotently) is DEMO or real, never seeded twice. Term structure, holidays and the sample plan are
 * DEMO (in production the centre sets its own from the UI / the roster).
 *
 * A GOLDEN fixture must never depend on a DEMO one, or `--group=golden` would fail to resolve.
 *
 * Loading (all synthetic, safe for git):
 * - `doctrine:fixtures:load`                → everything (GOLDEN ∪ DEMO).
 * - `doctrine:fixtures:load --group=demo`   → backbone + sample data (DEMO ⊇ GOLDEN).
 * - `doctrine:fixtures:load --group=golden` → only the production baseline (roles + task catalog).
 *
 * A realistic local instance with real people = `--group=golden` + `app:import-roster`, with no
 * duplicates (the roster upserts the golden roles by code). The real centre data (PII) is NEVER
 * seeded here: it lives under the git-ignored /fixtures/real/ and is loaded by the import command.
 */
final class FixtureGroups
{
    /** Stable backbone that would seed production. Also part of {@see DEMO}. */
    public const string GOLDEN = 'golden';

    /** Sample data for local testing. Includes the whole {@see GOLDEN} backbone. */
    public const string DEMO = 'demo';
}
