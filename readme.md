# WooOps Auditor v0.1

A **read-only** diagnostic for WooCommerce stores. It inspects the store and produces a structured operational report. It never modifies anything: no fixes, no retries, no cleanups, no status changes.

Built for agencies that maintain several WooCommerce sites and want to find operational failures before the client does.

## Install

Drop the directory into `wp-content/plugins/` and activate, or:

```bash
composer install --no-dev
```

Requires WordPress 6.x, PHP 8.1+, and WooCommerce (current supported releases). Works with HPOS on or off.

## Use

```bash
wp wooops audit                                    # summary table in the terminal
wp wooops audit --format=json                      # writes a JSON report
wp wooops audit --format=html                      # writes a standalone HTML report
wp wooops audit --format=both                      # writes both
wp wooops audit --format=html --output=/tmp/x.html # explicit destination
wp wooops audit --format=json --stdout             # pipe it somewhere
```

Without `--output`, reports land in `wp-content/uploads/wooops-audit/`, which the plugin creates with an `.htaccess` deny rule and an index file.

There is also a minimal screen at **WooCommerce ▸ WooOps Audit** (run the audit, see the score, download the reports). WP-CLI is the primary interface.

## The seven checks

| # | Check | Finds |
|---|---|---|
| 01 | Environment | WooCommerce missing/inactive, pending DB update, low memory, no HTTPS, `WP_DEBUG` |
| 02 | WP-Cron | Overdue events, stale cron lock, unverifiable external cron |
| 03 | Action Scheduler — failed | Volume and concentration of failed background jobs |
| 04 | Action Scheduler — past due | A queue that is behind, with a severity ladder by delay |
| 05 | Pending orders | Orders ageing in "pending payment", bucketed by age |
| 06 | Failed orders | Failed checkouts in the last 30 days, concentration by gateway |
| 07 | Database | Action Scheduler and order table accumulation |

Full rules, thresholds and known false positives: [docs/CHECKS.md](docs/CHECKS.md).

## Reports

- **JSON** — versioned, stable schema (`schema_version`, `auditor_version`, `timestamp`). This is the contract external monitoring will consume later.
- **HTML** — one standalone file. No external CSS, JS, fonts or CDNs; opens offline and prints cleanly.

Example: [`examples/sample-report.html`](examples/sample-report.html) and [`examples/sample-report.json`](examples/sample-report.json) — a fictional store scoring 54/100. Generated from fixtures; no real store data.

## Health score

`100 - Σ (penalty of the worst finding in each category)`, floored at 0. CRITICAL 25, HIGH 12, MEDIUM 5, LOW 2, INFO/PASS 0. It is a headline indicator; the report always shows every finding regardless of the number.

## Reading the report honestly

- A pending order is **not** proof of a lost payment.
- Failed-order value is **attempted** value, not revenue lost.
- `DISABLE_WP_CRON = true` is **not** proof that cron is broken.

[docs/LIMITATIONS.md](docs/LIMITATIONS.md) lists everything v0.1 cannot know. Read it before showing a report to a client.

## Docs

[Architecture](docs/ARCHITECTURE.md) · [Checks](docs/CHECKS.md) · [Security & privacy](docs/SECURITY.md) · [Testing](docs/TESTING.md) · [Limitations](docs/LIMITATIONS.md) · [Handoff](docs/HANDOFF_TO_CHATGPT.md)
