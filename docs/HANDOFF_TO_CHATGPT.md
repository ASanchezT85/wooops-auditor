# Handoff — WooOps Auditor v0.1

Self-contained status document. Assume the reader saw none of the build session.

---

## 1. Executive summary

WooOps Auditor v0.1 is built, tested and documented. It is a WordPress/WooCommerce plugin that runs seven **read-only** operational checks against a store and produces two reports: a versioned JSON document and a standalone HTML report.

It modifies nothing in the store. It makes no outbound network requests. It collects no PII and no secrets.

A demo report for a fictional troubled store is committed at `examples/sample-report.html` (score 54/100: 1 CRITICAL, 2 HIGH, 3 MEDIUM, 4 LOW, 1 INFO).

The tool is ready to be pointed at a real staging store. It has **not** yet been run against one — that is the next step, and the thresholds it ships with are unvalidated heuristics.

## 2. Current status

| | |
|---|---|
| Location | `C:\laragon\www\wooops-auditor` (new standalone git repo) |
| Branch | `feature/wooops-auditor-v0.1` |
| Version | 0.1.0, JSON schema 1.0.0 |
| Tests | 39 tests, 102 assertions, **all passing** |
| Runtime deps | none |
| Dev deps | PHPUnit 10.5 |
| Requirements | PHP 8.1+, WordPress 6.x, WooCommerce; HPOS on or off |
| Definition of Done | every box in the original plan is met (see §12) |

## 3. Repository structure

```
wooops-auditor/
├── wooops-auditor.php          plugin bootstrap, autoloader, HPOS declaration, CLI + admin wiring
├── composer.json / phpunit.xml
├── readme.md
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
├── examples/                   sample-report.html + sample-report.json
└── docs/                       ARCHITECTURE, CHECKS, SECURITY, TESTING, LIMITATIONS, this file
```

## 4. Implemented checks

| # | Key | Emits |
|---|---|---|
| 01 | `environment` | WooCommerce inactive (CRITICAL, stops the check), pending DB schema update, low memory, no HTTPS, `WP_DEBUG` |
| 02 | `cron` | Overdue events (ladder 15 min → 6 h), stale `doing_cron` lock, `DISABLE_WP_CRON` as INFO-only |
| 03 | `action_scheduler_failed` | Failed-action volume (ladder 1/10/50/500) + dominant-hook concentration |
| 04 | `action_scheduler_past_due` | Past-due backlog, severity from the oldest delay (5 min → 6 h ladder) |
| 05 | `pending_orders` | Count, value, age buckets, ten oldest (no PII); severity from the count older than 24 h |
| 06 | `failed_orders` | Last 30 days: volume + gateway concentration; value labelled *attempted*, never *lost* |
| 07 | `database` | Action Scheduler + order table rows/size; custom `$wpdb` prefix handled |

All thresholds are class constants and documented in `docs/CHECKS.md`.

## 5. Architecture decisions

1. **`StoreGateway` separates facts from judgement.** Everything touching WordPress/SQL is behind one interface; the checks contain only the severity logic. This is why the tests need no WordPress and run in ~25 ms, and why the demo report is generated from fixtures rather than anyone's store.
2. **Aggregation in SQL.** `COUNT`/`SUM`/`GROUP BY`/`information_schema`. Row-reading is bounded (`LIMIT 1000` for the past-due median sample, `LIMIT 5000` for age buckets, `LIMIT 10` for the order listing). Nothing loads a full order set.
3. **A single `now()` per run**, captured by the gateway, so a slow audit stays internally consistent.
4. **A failing check does not fail the audit.** It is caught, recorded in `errors[]`, and surfaced as an INFO finding saying the domain is *unknown*, not healthy.
5. **Health score changed from the original plan.** Additive penalties (`-25` per CRITICAL and so on, summed over every finding) floor any store with more than one real problem at 0/100 — the demo store scored 0 and could not be distinguished from a catastrophe. It is now `100 - Σ(worst finding per category)`, same penalty ladder. The plan's own worked example (72/100 with 1 CRITICAL + 2 HIGH + 3 MEDIUM + 1 LOW) is not reproducible under a purely additive rule either.
6. **No new dependencies.** Plain PSR-4 autoloader so the plugin works from a bare checkout.

## 6. Tests

`vendor/bin/phpunit` — 39 tests, 102 assertions, all green. Covered: all seven checks across healthy/degraded/broken states, the false-positive guards (`DISABLE_WP_CRON`, seconds-of-lag, Action Scheduler absent), custom DB prefix, HPOS on/off, JSON schema shape, HTML standalone-ness, HTML XSS escaping, severity ordering, score bounds, and two **semantic** guards that assert the report never calls pending or failed order value "revenue lost".

Not covered: `WordPressGateway`, `AuditCommand`, `Admin\Page` — they need a real WordPress; mocking `wpdb` would only assert the SQL matches itself. See `docs/TESTING.md`.

## 7. Commands

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

## 8. Example outputs

`examples/sample-report.json` and `examples/sample-report.html`. Fictional store, clock pinned so the output is reproducible. JSON top level:

```json
{ "metadata": { "schema_version": "1.0.0", "auditor_version": "0.1.0", "timestamp": 1756209600, "read_only": true },
  "environment": {}, "score": 54, "summary": {}, "findings": [], "checks": {}, "errors": [] }
```

Each finding: `id`, `category`, `severity`, `title`, `summary`, `technical_details`, `why_it_matters`, `recommended_action`, `evidence`.

## 9. Known limitations

Full list in `docs/LIMITATIONS.md`. The ones that matter commercially:

- `DISABLE_WP_CRON = true` does not prove cron is broken (external system cron is invisible to WordPress).
- A pending order proves neither a received nor a lost payment.
- Failed-order value is *attempted* value, not revenue lost.
- Failed actions are historical; a big count can describe an incident fixed months ago.
- `TABLE_ROWS` is an InnoDB estimate.
- **No baseline and no history**, so the auditor cannot say whether today's numbers are normal *for this store*. This is the biggest gap between v0.1 and the monitoring product it is meant to inform.
- Thresholds are unvalidated heuristics until run against real stores.

## 10. Bugs / technical debt

No known bugs. Debt, honestly stated:

- `WordPressGateway` is untested code running real SQL. It is the highest-risk file in the repo and the first thing a staging run will exercise.
- The legacy (non-HPOS) order queries use `postmeta` subqueries for the sample listing; correct, but slower than the HPOS path on large legacy stores.
- The `.htaccess` protecting the report directory is inert on nginx.
- `ArrayGateway`'s override merge has a small special case for list-shaped keys (`cron.overdue`, `tables`) because `array_replace_recursive` merges lists index-wise. It works and is covered by tests, but it is the one piece of fixture code that will surprise someone.
- The admin page runs the audit synchronously in a request.

## 11. Intentionally NOT implemented

Stripe/PayPal APIs, payment reconciliation, webhook or email monitoring, synthetic checkout tests, uptime monitoring, SaaS backend, Next.js/React, accounts, auth, billing, multi-tenant, Redis, external queues, PostgreSQL, mobile, AI/LLM, Slack/Telegram/SMS notifications, cloud monitoring, remote agents, and any automatic fix. Also no history, trending, or scheduling of its own.

## 12. Definition of Done

Installs ✔ · modifies no business data ✔ · seven checks ✔ · HPOS on and off ✔ · legacy orders ✔ · AS failures ✔ · AS overdue ✔ · pending orders ✔ · failed orders ✔ · AS tables ✔ · custom DB prefix ✔ · no huge datasets loaded ✔ · valid JSON ✔ · standalone HTML ✔ · health score documented ✔ · findings explain and recommend ✔ · no PII/secrets ✔ · tests exist ✔ · tests pass ✔ · sample report ✔ · docs ✔ · this handoff ✔

## 13. Files changed

New repository; every file listed in §3 was created in this session. Nothing outside `C:\laragon\www\wooops-auditor` was touched.

## 14. Git status

Branch `feature/wooops-auditor-v0.1`, 12 commits, clean tree, no remote configured, nothing pushed.

```
feat: bootstrap WooOps auditor plugin
feat: add audit result model, severity ladder and health score
feat: add read-only store gateways (WordPress + in-memory)
feat: implement environment and WP-Cron checks
feat: inspect Action Scheduler failed and past-due actions
feat: add pending and failed order health checks
feat: add Action Scheduler table size diagnostics
feat: generate JSON and standalone HTML reports
feat: add wp wooops audit command and minimal admin screen
test: cover core audit scenarios and report guarantees
chore: add generated sample audit report
docs: document WooOps auditor v0.1
```

## 15. Exact next recommended step

**Run it against a real WooCommerce staging store** — nothing else in this project is worth doing first.

1. Copy the plugin into a staging site, activate, run `wp wooops audit`.
2. That single run exercises `WordPressGateway`, the only untested file, against both HPOS and legacy layouts.
3. Then provoke controlled failures — kill cron, fail a batch of scheduled actions, leave orders pending, break a gateway key — and confirm each one is detected at a sensible severity.
4. Record every false positive. Correct the thresholds in the check constants and in `docs/CHECKS.md`.
5. Only then take a report to an agency.

Suggested v0.2, once real-store evidence exists: store the JSON of past runs so the auditor can report *change* rather than absolute numbers (the single most valuable missing capability), and add whichever check the staging exercise proves is missing.
