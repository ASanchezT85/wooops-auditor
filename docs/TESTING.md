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

## The pre-push hook (this project's CI)

GitHub Actions cannot run for this project: the account it lives under is locked
for billing, so every triggered run fails with "The job was not started because
your account is locked due to a billing issue" — public repository or not. The
checks run locally instead, before the commits leave the machine:

```bash
git config core.hooksPath bin/hooks
git config wooops.php /path/to/php8    # only if `php` on PATH is older than 8.1
```

`bin/hooks/pre-push` then runs the full suite and verifies that
`examples/sample-report.*` still regenerates identically from the fixtures — the
sample is what gets shown to clients, so it must not drift from what the code
produces. `git push --no-verify` skips it.

`.github/workflows/tests.yml` is kept and correct (PHP 8.1/8.2/8.3 matrix, same
two checks) but set to `workflow_dispatch` only, so a public repository is not
decorated with a red X on every commit. A manual dispatch confirmed the file
parses and reaches the runner; only the billing lock stops it. Restore the push
trigger once that is resolved.

## What is covered (58 tests, 148 assertions)

**Environment** — WooCommerce active and healthy; default `WP_MEMORY_LIMIT` against an unlimited PHP limit (regression from the staging run); effective limit as the higher of the two; HTTP on a local/staging hostname stays INFO while HTTP on a public hostname stays HIGH; WooCommerce missing (CRITICAL, and the check stops rather than emitting misleading follow-ups); HPOS enabled and disabled; outdated DB schema; low memory; missing HTTPS; no secrets in the payload.

**Cron** — no overdue events; `DISABLE_WP_CRON` alone stays INFO (the central false-positive guard); hours of overdue events → CRITICAL; seconds of lag → nothing reported; stale `doing_cron` lock.

**Action Scheduler** — straggler vs real backlog (regression from the staging run); zero failed; a few failed (LOW, no concentration finding); large volume (HIGH + the dominant hook named); huge volume (CRITICAL); Action Scheduler absent reported as unknown rather than healthy; the full past-due severity ladder; empty queue; backlog severity taken from the oldest delay.

**Orders** — no pending; recent pending (INFO only); stale pending escalating to MEDIUM and HIGH; failed orders reporting *attempted value*; gateway concentration; and two semantic guards: the pending finding must never contain "revenue lost", and the failed finding must carry the "NOT revenue lost" sentence and no `revenue_lost` key. Plus: no PII anywhere in order findings.

**Database** — normal tables pass; a 1M-row log table → CRITICAL and a 312k-row actions table → HIGH; a custom `$wpdb` prefix (`shop7x_`) is handled.

**Admin hardening** (`tests/AdminSecurityTest.php`) — download and run both refused without `manage_woocommerce`; both refused with the capability but an invalid nonce (capability alone is not enough, and neither is a nonce alone); an unknown `format` refused; HTML and JSON downloads stream the report with the right `Content-Type`, `Content-Disposition` attachment filename, `Content-Length` and `X-Content-Type-Options: nosniff`; the response is marked no-cache; the whole admin flow creates no files anywhere; `wooops_last_audit` holds exactly `timestamp`, `score`, `summary` and its serialised value contains no path, URL or report body; and a static assertion that `src/Admin/Page.php` never mentions `ReportWriter`, `file_put_contents`, `fopen`, `readfile` or `wp_upload_dir`.

These run against small WordPress function stubs (`tests/WordPressStubs.php`) driving a subclass that captures `header()`, `echo` and `exit`. The stubs do not test WordPress; they let the plugin's *use* of capability, nonce and header APIs be asserted without a WordPress installation. The real thing was then exercised on a live install — see below.

**Reporting** — healthy store scores 100 with no actionable findings; troubled store scores below 100 and never below 0; worst-per-category scoring; findings ordered by severity; all seven checks present in the output; a check that throws does not lose the rest of the audit; JSON structure, schema version and per-finding keys; HTML is standalone (no external `script`/`link`/`img`, no CDN, no remote fonts); HTML escapes hostile finding content.

## What is not covered, and why

`WordPressGateway` has no unit tests. It is the one class that requires a real WordPress and a real database, and mocking `wpdb` would only assert that the SQL strings match themselves. It is verified instead by running the plugin against a real store — see the next section, which is where the three real bugs were found.

The admin page and the WP-CLI command are also untested for the same reason: they are thin wiring over tested code.

## Validation against a real store

`vendor/bin/phpunit` proves the logic. The following proves the SQL. On 2026-08-26 the auditor was run against a purpose-built staging store: WordPress 6.x + **WooCommerce 11.0.1**, MySQL 8.4, PHP 8.3, custom table prefix `shop7x_`, at `C:\laragon\www\wooops-staging`.

**Clean install.** `wp wooops audit` → **100/100**, 0 actionable findings, 2 INFO, 5 PASS. Zero false positives — after fixing the three the first run exposed:

| Found | Cause | Fix |
|---|---|---|
| `environment.memory.low` HIGH on a healthy store | WordPress defaults `WP_MEMORY_LIMIT` to 40M and only ever *raises* the ini limit; the check judged the constant alone, while PHP was set to `-1` (unlimited) | Judge the **effective** limit: `max(php, wp)`, unlimited when PHP says `-1` |
| `environment.https.missing` HIGH on `http://wooops-staging.test` | No notion of local/staging hostnames | INFO for `localhost`, `127.0.0.1`, `::1`, `.test`, `.local`, `.localhost`, `.example`, `.invalid` |
| The auditor listed *itself* under "WooCommerce plugins" | The name filter matches `woo` | Skip the plugin's own directory |

Each one now has a regression test.

**With deliberately provoked failures** (`wooops-staging/seed-failures.php` — 18 orders, 43 failed actions, 12 past-due actions, every cron event backdated 8 h 20 m, and the Action Scheduler log table inflated to 2.74 M rows / 298 MB):

```
WooOps Audit — health score 33/100
CRITICAL  cron.overdue.critical                 12 events overdue, worst 8.4 h late
CRITICAL  database.actionscheduler_logs.bloat   2,741,404 rows, 298.02 MB
HIGH      action_scheduler.past_due.backlog     32 past due, oldest 1.2 h (median 3 min)
MEDIUM    action_scheduler.failed.volume        43 failed, oldest 13.8 days
MEDIUM    action_scheduler.failed.concentration 24 of 43 (56%) in one hook
MEDIUM    orders.failed.volume                  10 failed in 30 days, 1,420.18 USD attempted
MEDIUM    orders.failed.gateway_concentration   9 of 10 (90%) Stripe
LOW       orders.pending.volume                 7 pending, 821.75 USD, 4 older than 24 h
```

Every seeded failure was detected. The money figures were verified by hand against the seed data (`821.75` and `1,420.18` are exact), and the 30-day window correctly excluded the order seeded 40 days back.

**Both storage layouts.** The run above was repeated with HPOS enabled and the orders backfilled into `shop7x_wc_orders`. The HPOS and legacy code paths produced **identical** counts and totals — which is the real point of that test, since they are two entirely separate SQL queries.

**Performance.** 1.3–1.4 s wall clock for the full seven-check audit against the 2.74 M-row table. Nothing loads a full result set.

**Privacy.** The generated JSON was grepped for `email|phone|first_name|last_name|billing_address|postcode|auth_key|password|secret`: two hits, both the word "emails" inside explanatory prose. No PII, no secrets. The HTML report contains zero external references.

### Hardening validation (2026-08-26)

A second staging store — WordPress + WooCommerce 11.0.1, prefix `shop7x_` — was
built to drive the admin handlers through *real* WordPress: real users, real
`current_user_can`, real `wp_create_nonce`/`check_admin_referer`, real
filesystem. Results:

```
anonymous download refused ....... OK
subscriber download refused ...... OK   (real user, no manage_woocommerce)
admin + invalid nonce refused .... OK
admin HTML download .............. OK
    Content-Type: text/html; charset=utf-8
    Content-Disposition: attachment; filename="wooops-audit-2026-08-26-181839.html"
    Content-Length: 20278
    X-Content-Type-Options: nosniff
admin JSON download ............. OK
    Content-Type: application/json; charset=utf-8
stored option keys .............. timestamp, score, summary
stored option payload ........... {"timestamp":1787768319,"score":100,"summary":{...}}
no new files under uploads ...... OK (7 files, unchanged)
no uploads/wooops-audit dir ..... OK
```

After the admin flow *and* every CLI invocation, `wp-content/uploads` contained
only WooCommerce's own placeholder images and its `woocommerce_uploads`
directory — nothing from WooOps. The option in the database was verified
directly with SQL and holds metadata only.

The authorization tests were also mutation-checked: removing the capability and
nonce checks from `Page::authorize()` turns four of them red, and restoring the
checks turns them green again. An authorization test that still passes without
authorization is worthless, so this was verified rather than assumed.

**Reproducing it.** `wooops-staging/seed-failures.php` writes; the auditor never does. To rebuild the staging store: install WordPress + WooCommerce, symlink the plugin into `wp-content/plugins/`, `wp plugin activate wooops-auditor`, `wp eval-file seed-failures.php`, `wp wooops audit`. The admin hardening was driven by a second script, `validate-admin.php`, which subclasses `Admin\Page` to capture `header()`/`echo`/`exit` and then asserts the filesystem and the stored option. Neither script ships with the plugin: they write, and the auditor never does.

## Fixtures

`tests/Fixtures.php` builds two stores from `ArrayGateway`:

- `healthy()` — defaults, scores 100.
- `troubledStore()` — the demo store: 43 failed actions, 12 past-due actions, 7 pending orders ($742.81), 3 failed orders, a 1,072,113-row Action Scheduler log table at 1.31 GB, `WP_DEBUG` on, `DISABLE_WP_CRON` on. Scores **54/100**.

The demo fixture is deliberately *not* the staging store: it stays fixture-driven so the committed sample report is byte-reproducible.

The clock is pinned (`Fixtures::NOW`) so reports are byte-reproducible. No real store data and no real people appear anywhere in the fixtures.
