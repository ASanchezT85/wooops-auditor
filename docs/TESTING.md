# Testing — WooOps Auditor v0.1

## Running

```bash
composer install
vendor/bin/phpunit
```

Requires PHP 8.1+. No WordPress, no database, no WP test suite: everything under test sits behind `StoreGateway`, so the tests drive `ArrayGateway` directly. The suite runs in well under a second.

Regenerate the demo report:

```bash
php bin/generate-sample.php
```

## What is covered (39 tests, 102 assertions)

**Environment** — WooCommerce active and healthy; WooCommerce missing (CRITICAL, and the check stops rather than emitting misleading follow-ups); HPOS enabled and disabled; outdated DB schema; low memory; missing HTTPS; no secrets in the payload.

**Cron** — no overdue events; `DISABLE_WP_CRON` alone stays INFO (the central false-positive guard); hours of overdue events → CRITICAL; seconds of lag → nothing reported; stale `doing_cron` lock.

**Action Scheduler** — zero failed; a few failed (LOW, no concentration finding); large volume (HIGH + the dominant hook named); huge volume (CRITICAL); Action Scheduler absent reported as unknown rather than healthy; the full past-due severity ladder; empty queue; backlog severity taken from the oldest delay.

**Orders** — no pending; recent pending (INFO only); stale pending escalating to MEDIUM and HIGH; failed orders reporting *attempted value*; gateway concentration; and two semantic guards: the pending finding must never contain "revenue lost", and the failed finding must carry the "NOT revenue lost" sentence and no `revenue_lost` key. Plus: no PII anywhere in order findings.

**Database** — normal tables pass; a 1M-row log table → CRITICAL and a 312k-row actions table → HIGH; a custom `$wpdb` prefix (`shop7x_`) is handled.

**Reporting** — healthy store scores 100 with no actionable findings; troubled store scores below 100 and never below 0; worst-per-category scoring; findings ordered by severity; all seven checks present in the output; a check that throws does not lose the rest of the audit; JSON structure, schema version and per-finding keys; HTML is standalone (no external `script`/`link`/`img`, no CDN, no remote fonts); HTML escapes hostile finding content.

## What is not covered, and why

`WordPressGateway` has no tests. It is the one class that requires a real WordPress and a real database, and mocking `wpdb` would only assert that the SQL strings match themselves. It is verified by running the plugin against a real store — which is the next phase of the project, not something a unit test can stand in for.

The admin page and the WP-CLI command are also untested for the same reason: they are thin wiring over tested code.

## Fixtures

`tests/Fixtures.php` builds two stores from `ArrayGateway`:

- `healthy()` — defaults, scores 100.
- `troubledStore()` — the demo store: 43 failed actions, 12 past-due actions, 7 pending orders ($742.81), 3 failed orders, a 1,072,113-row Action Scheduler log table at 1.31 GB, `WP_DEBUG` on, `DISABLE_WP_CRON` on. Scores **54/100**.

The clock is pinned (`Fixtures::NOW`) so reports are byte-reproducible. No real store data and no real people appear anywhere in the fixtures.
