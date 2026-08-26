# Handoff — WooOps Auditor v0.1

Self-contained status document. Assume the reader saw none of the build session.
Last updated: 2026-08-26.

---

## 1. Executive summary

WooOps Auditor v0.1 is **built, tested, validated against a real store, and documented**. It is a WordPress/WooCommerce plugin that runs seven **read-only** operational checks and produces two reports: a versioned JSON document and a standalone HTML report.

It modifies nothing in the store. It makes no outbound network requests. It collects no PII and no secrets.

It has been run against a real WooCommerce 11.0.1 staging store (§7). That run found and fixed **three false positives**, confirmed the HPOS and legacy SQL paths return identical numbers, and verified the monetary figures by hand. The staging store was deleted afterwards at the owner's request; the evidence and reproduction steps live in `docs/TESTING.md`.

The repository is on GitHub: `https://github.com/ASanchezT85/wooops-auditor.git`.

## 2. Current status

| | |
|---|---|
| Location | `C:\laragon\www\wooops-auditor` |
| Remote | `https://github.com/ASanchezT85/wooops-auditor.git` |
| Branch | `feature/wooops-auditor-v0.1` (also the remote default branch) |
| Commits | 17, all pushed |
| Version | 0.1.0, JSON schema 1.0.0 |
| Tests | 45 tests, 111 assertions, **all passing** (~35 ms) |
| Staging validation | Done 2026-08-26 against WooCommerce 11.0.1; store since deleted |
| Runtime deps | none |
| Dev deps | PHPUnit 10.5 |
| Requirements | PHP 8.1+, WordPress 6.x, WooCommerce; HPOS on or off |
| Definition of Done | every box in the original plan is met (§13) |

## 3. Repository structure

```
wooops-auditor/
├── wooops-auditor.php          plugin bootstrap, autoloader, HPOS declaration, CLI + admin wiring
├── composer.json / phpunit.xml
├── readme.md                   agency-facing pitch + install/use
├── src/
│   ├── Audit/                  Severity, Finding, AuditResult (JSON schema), HealthScore, AuditRunner
│   ├── Checks/                 CheckInterface + the seven checks
│   ├── Store/                  StoreGateway (interface), WordPressGateway (real), ArrayGateway (fixtures)
│   ├── Report/                 ReporterInterface, JsonReporter, HtmlReporter
│   ├── Support/                Format (durations/bytes/money), ReportWriter (protected output dir)
│   ├── WPCLI/AuditCommand.php
│   └── Admin/Page.php
├── templates/report.php        the standalone HTML report
├── tests/                      bootstrap, Fixtures, ChecksTest, ReportingTest
├── bin/generate-sample.php
├── examples/                   sample-report.html + sample-report.json (54/100 demo store)
└── docs/                       ARCHITECTURE, CHECKS, SECURITY, TESTING, LIMITATIONS, this file
```

## 4. Implemented checks

| # | Key | Emits |
|---|---|---|
| 01 | `environment` | WooCommerce inactive (CRITICAL, stops the check), pending DB schema update, low **effective** memory limit, no HTTPS (INFO on local/staging hostnames), `WP_DEBUG` |
| 02 | `cron` | Overdue events (ladder 15 min → 6 h), stale `doing_cron` lock, `DISABLE_WP_CRON` as INFO-only |
| 03 | `action_scheduler_failed` | Failed-action volume (ladder 1/10/50/500) + dominant-hook concentration |
| 04 | `action_scheduler_past_due` | Past-due backlog by oldest delay, and whether it is a real backlog or a few stuck actions |
| 05 | `pending_orders` | Count, value, age buckets, ten oldest (no PII); severity from the count older than 24 h |
| 06 | `failed_orders` | Last 30 days: volume + gateway concentration; value labelled *attempted*, never *lost* |
| 07 | `database` | Action Scheduler + order table rows/size; custom `$wpdb` prefix handled |

All thresholds are class constants and documented in `docs/CHECKS.md`.

## 5. Architecture decisions

1. **`StoreGateway` separates facts from judgement.** Everything touching WordPress/SQL is behind one interface; the checks contain only the severity logic. This is why the tests need no WordPress and run in ~35 ms, and why the demo report is generated from fixtures rather than anyone's store.
2. **Aggregation in SQL.** `COUNT`/`SUM`/`GROUP BY`/`information_schema`. Row-reading is bounded (`LIMIT 1000` for the past-due median sample, `LIMIT 5000` for age buckets, `LIMIT 10` for the order listing). Nothing loads a full order set.
3. **A single `now()` per run**, captured by the gateway, so a slow audit stays internally consistent.
4. **A failing check does not fail the audit.** It is caught, recorded in `errors[]`, and surfaced as an INFO finding saying the domain is *unknown*, not healthy.
5. **Health score changed from the original plan.** Additive penalties (`-25` per CRITICAL and so on, summed over every finding) floor any store with more than one real problem at 0/100 — the demo store scored 0 and could not be distinguished from a catastrophe. It is now `100 - Σ(worst finding per category)`, same penalty ladder. The plan's own worked example (72/100 with 1 CRITICAL + 2 HIGH + 3 MEDIUM + 1 LOW) is not reproducible under a purely additive rule either.
6. **No new dependencies.** Plain PSR-4 autoloader so the plugin works from a bare checkout.

## 6. Tests

`vendor/bin/phpunit` — **45 tests, 111 assertions, all green.**

Covered: all seven checks across healthy/degraded/broken states; the false-positive guards (`DISABLE_WP_CRON` alone, seconds-of-lag, Action Scheduler absent, default memory limit, local hostnames, straggler vs backlog); custom DB prefix; HPOS on/off; JSON schema shape; HTML standalone-ness; HTML XSS escaping; severity ordering; score bounds; and two **semantic** guards that fail the build if the report ever calls pending or failed order value "revenue lost".

Not unit-tested: `WordPressGateway`, `AuditCommand`, `Admin\Page` — they need a real WordPress; mocking `wpdb` would only assert the SQL matches itself. They are covered by the staging run instead (§7).

## 7. Staging validation (2026-08-26)

Purpose-built store: WordPress + **WooCommerce 11.0.1**, MySQL 8.4, PHP 8.3, custom table prefix `shop7x_`, plugin symlinked in so the real code ran.

**Clean install → 100/100**, zero actionable findings — after fixing three false positives the first run exposed:

| Found | Cause | Fix |
|---|---|---|
| `environment.memory.low` HIGH on a healthy store | WordPress defaults `WP_MEMORY_LIMIT` to 40M and only ever *raises* the ini limit, never lowers it. The check judged the constant alone while PHP was set to `-1` (unlimited) | Judge the **effective** limit: `max(php, wp)`, unlimited when PHP reports `-1` |
| `environment.https.missing` HIGH on `http://wooops-staging.test` | No notion of local/staging hostnames | INFO for `localhost`, `127.0.0.1`, `::1`, `.test`, `.local`, `.localhost`, `.example`, `.invalid` |
| The auditor listed *itself* under "WooCommerce plugins" | The name filter matches `woo` | Skip the plugin's own directory |

Each now has a regression test.

**Seeded-failure store → 33/100.** 18 orders, 43 failed actions, 12 past-due actions, every cron event backdated 8 h 20 m, Action Scheduler log table inflated to 2.74 M rows / 298 MB. Every seeded failure was detected:

```
CRITICAL  cron.overdue.critical                 12 events overdue, worst 8.4 h late
CRITICAL  database.actionscheduler_logs.bloat   2,741,404 rows, 298.02 MB
HIGH      action_scheduler.past_due.backlog     32 past due, oldest 1.2 h (median 3 min)
MEDIUM    action_scheduler.failed.volume        43 failed, oldest 13.8 days
MEDIUM    action_scheduler.failed.concentration 24 of 43 (56%) in one hook
MEDIUM    orders.failed.volume                  10 failed in 30 days, 1,420.18 USD attempted
MEDIUM    orders.failed.gateway_concentration   9 of 10 (90%) Stripe
LOW       orders.pending.volume                 7 pending, 821.75 USD, 4 older than 24 h
```

- Money figures verified by hand against the seed data: `821.75` and `1,420.18` are exact.
- The 30-day window correctly excluded an order seeded 40 days back.
- **HPOS vs legacy**: the run was repeated with HPOS enabled and the orders backfilled into `shop7x_wc_orders`. Identical counts and totals. Two separate SQL implementations, same answers.
- **Performance**: 1.3–1.4 s for the full seven-check audit against the 2.74 M-row table.
- **Privacy**: the generated JSON was grepped for `email|phone|first_name|last_name|billing_address|postcode|auth_key|password|secret` — two hits, both the word "emails" inside explanatory prose.

That run also produced one precision improvement: 32 past-due actions with a **median lag of 3 minutes** but an oldest of 1.2 hours is one stuck action, not a stalled queue. The finding now says so explicitly when the median is small relative to the oldest.

The staging store and its database were deleted afterwards at the owner's request. Reproduction steps are in `docs/TESTING.md`.

## 8. Commands

```bash
composer install
vendor/bin/phpunit                 # tests
php bin/generate-sample.php        # regenerate examples/

wp wooops audit                    # terminal summary
wp wooops audit --format=json|html|both
wp wooops audit --format=html --output=/tmp/report.html
wp wooops audit --format=json --stdout
```

Default output directory: `wp-content/uploads/wooops-audit/` (created 0750, with `.htaccess` deny + `index.html`).

## 9. Example outputs

`examples/sample-report.json` and `examples/sample-report.html` — fictional store, clock pinned, **54/100**, 10 findings (9 actionable). Generated from `tests/Fixtures.php`, deliberately *not* from the staging store, so the committed sample stays byte-reproducible.

JSON top level:

```json
{ "metadata": { "schema_version": "1.0.0", "auditor_version": "0.1.0", "timestamp": 1787745600, "read_only": true },
  "environment": {}, "score": 54, "summary": {}, "findings": [], "checks": {}, "errors": [] }
```

Each finding: `id`, `category`, `severity`, `title`, `summary`, `technical_details`, `why_it_matters`, `recommended_action`, `evidence`.

## 10. Known limitations

Full list in `docs/LIMITATIONS.md`. The ones that matter commercially:

- `DISABLE_WP_CRON = true` does not prove cron is broken (an external system cron is invisible to WordPress).
- A pending order proves neither a received nor a lost payment.
- Failed-order value is *attempted* value, not revenue lost.
- Failed actions are historical; a big count can describe an incident fixed months ago.
- `TABLE_ROWS` is an InnoDB estimate.
- **No baseline and no history**, so the auditor cannot say whether today's numbers are normal *for this store*. This is the biggest gap between v0.1 and the monitoring product it is meant to inform.
- Thresholds are heuristics validated against exactly **one** store.

## 11. Bugs, debt, and one published error that was corrected

No known bugs in the code.

**Corrected error (2):** the demo fixture's pinned clock was `1756209600`, which is **2025**-08-26, not 2026 as its own comment claimed. Every generated sample report was therefore dated a year in the past — spotted while rendering the readme screenshot. Fixed to `1787745600`; the sample was regenerated.

**Corrected error (1):** `docs/SECURITY.md` claimed the broad write-keyword grep returned "exactly two hits". It returns **five** — the original number came from a filtered count and was wrong as published (commit `ac8cd28`). Fixed in `ed110d1`: the docs now name all five (four are prose inside finding text, the fifth is the docblock asserting the guarantee), and both the readme and SECURITY.md now lead with a *strict* grep covering real SQL writes and WordPress/WooCommerce write APIs, which returns nothing. All three grep variants were executed and confirmed before publishing.

Debt:

- `WordPressGateway` has no unit tests. It is exercised end-to-end against a real store, which is the meaningful check, but a regression there would not be caught by CI — and there is no CI.
- The legacy (non-HPOS) order queries use `postmeta` subqueries for the sample listing; correct, but slower than the HPOS path on large legacy stores.
- The `.htaccess` protecting the report directory is inert on nginx.
- `ArrayGateway`'s override merge has a special case for list-shaped keys (`cron.overdue`, `tables`) because `array_replace_recursive` merges lists index-wise. Works, tested, but it is the one piece of fixture code that will surprise someone.
- The admin page runs the audit synchronously in a request.

## 12. Intentionally NOT implemented

Stripe/PayPal APIs, payment reconciliation, webhook or email monitoring, synthetic checkout tests, uptime monitoring, SaaS backend, Next.js/React, accounts, auth, billing, multi-tenant, Redis, external queues, PostgreSQL, mobile, AI/LLM, Slack/Telegram/SMS notifications, cloud monitoring, remote agents, and any automatic fix. Also no history, trending, or scheduling of its own.

## 13. Definition of Done

Installs ✔ · modifies no business data ✔ · seven checks ✔ · HPOS on and off ✔ (verified identical on a real store) · legacy orders ✔ · AS failures ✔ · AS overdue ✔ · pending orders ✔ · failed orders ✔ · AS tables ✔ · custom DB prefix ✔ (`shop7x_`, on a real store) · no huge datasets loaded ✔ · valid JSON ✔ · standalone HTML ✔ · health score documented ✔ · findings explain and recommend ✔ · no PII/secrets ✔ (grepped on real output) · tests exist ✔ · tests pass ✔ · sample report ✔ · docs ✔ · this handoff ✔

## 14. What is still missing

Nothing blocks using the tool. These are the open items:

**Repository hygiene**
- No `v0.1.0` tag.
- The repository is **private**. Fine for now; it has to change before agencies can see it.
- The remote default branch is `feature/wooops-auditor-v0.1`. If this repo is going to be shown to agencies, it should have a `main`.

**Product validation (the real gap)**
- Run against **two or three real client stores** with different plugin stacks. All three false positives so far came from one *clean* install; a store with forty plugins will surface more.
- Record which findings a human calls noise, and correct the thresholds in the check constants and `docs/CHECKS.md`.
- Then put a report in front of an agency and watch **which finding they read first**. That, not the code, decides the v0.2 scope.

**Known v0.2 candidate**
- Persisting past runs so the auditor can report *change* rather than absolute numbers. It is the single most valuable missing capability and the thing that turns this from an audit into monitoring.

## 15. Git

Branch `feature/wooops-auditor-v0.1`, 14 commits, clean working tree, 1 commit ahead of `origin`.

```
66f65e3 feat: bootstrap WooOps auditor plugin
611eec9 feat: add audit result model, severity ladder and health score
9e48700 feat: add read-only store gateways (WordPress + in-memory)
fb418ce feat: implement environment and WP-Cron checks
c865a4b feat: inspect Action Scheduler failed and past-due actions
f34bd1d feat: add pending and failed order health checks
5f58987 feat: add Action Scheduler table size diagnostics
68a3a61 feat: generate JSON and standalone HTML reports
c719a05 feat: add wp wooops audit command and minimal admin screen
d56009c test: cover core audit scenarios and report guarantees
9d37b6e chore: add generated sample audit report
ac8cd28 docs: document WooOps auditor v0.1
7da8d8e fix: remove three false positives found on a real WooCommerce store
ed110d1 docs: rewrite readme as an agency-facing pitch   ← not pushed
```

## 16. Exact next recommended step

1. Confirm the CI workflow actually runs (it is registered and Actions is enabled, but no run had appeared at the time of writing), tag `v0.1.0`, and decide on `main` + repo visibility.
2. Then the only step that matters: **run it against real client stores** and collect false positives. The tool is finished enough; what it lacks is evidence about which of its findings agencies are willing to pay to be told about.
