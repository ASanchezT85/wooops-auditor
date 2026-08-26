# WooOps Auditor

**Find out what is quietly broken in a WooCommerce store — in about five minutes, without touching anything.**

Read-only operational diagnostics for WooCommerce. Run one command, get a report you can hand to a client.

---

## The problem

The failures that cost a WooCommerce store money are rarely the ones that throw a 500. They are silent:

- Cron stopped running three weeks ago, so order emails, subscription renewals and stock syncs quietly stopped with it.
- 800 Action Scheduler jobs are sitting in `failed`, and nobody has looked at that table since the store launched.
- Orders have been stuck in `pending` for eleven days because a gateway callback never arrives.
- The `actionscheduler_logs` table is 1.3 GB and every admin screen has been getting slower for months.

None of that shows up on an uptime monitor. It shows up when the client emails you asking why a customer never got their order.

## What this does

Seven checks. One command. A report.

| # | Check | What it surfaces |
|---|---|---|
| 01 | Environment | WooCommerce inactive, pending DB update, low effective memory, no HTTPS, `WP_DEBUG` left on |
| 02 | WP-Cron | Events overdue by hours, stale cron locks — without falsely blaming an external system cron |
| 03 | Action Scheduler — failed | How many jobs failed, how old, and which hook (usually: which plugin) is responsible |
| 04 | Action Scheduler — past due | A queue that is behind, and whether it is a real backlog or one stuck action |
| 05 | Pending orders | Orders ageing in "pending payment", bucketed by age, with the oldest listed |
| 06 | Failed orders | Failed checkouts in the last 30 days, and whether they concentrate in one gateway |
| 07 | Database | Action Scheduler and order tables accumulating without bound |

Full rules, thresholds and known false positives: **[docs/CHECKS.md](docs/CHECKS.md)**.

## What it looks like

```
$ wp wooops audit

WooOps Audit — health score 33/100
CRITICAL: 2   HIGH: 1   MEDIUM: 4   LOW: 1   INFO: 1   PASS: 0

severity  id                                     summary
CRITICAL  cron.overdue.critical                  12 scheduled event(s) are overdue; the worst is 8.4 hours late.
CRITICAL  database.actionscheduler_logs.bloat    The table shop7x_actionscheduler_logs holds 2,741,404 rows and occupies 298.02 MB.
HIGH      action_scheduler.past_due.backlog      32 pending action(s) are past their scheduled time. The oldest is 1.2 hours late (median lag 3 minutes).
MEDIUM    action_scheduler.failed.volume         43 scheduled action(s) are currently marked as failed. The oldest failure is 13.8 days old.
MEDIUM    action_scheduler.failed.concentration  24 of 43 failures (56%) come from the hook "wc_update_product_lookup_tables".
MEDIUM    orders.failed.volume                   10 order(s) failed in the last 30 days, for a total attempted value of 1,420.18 USD.
MEDIUM    orders.failed.gateway_concentration    9 of 10 failed orders (90%) used "Credit Card (Stripe)".
LOW       orders.pending.volume                  7 order(s) are in the pending payment status, totalling 821.75 USD. 4 of them are more than 24 hours old.
```

Every finding carries a plain-language **summary**, **why it matters**, a **recommended action**, and the **evidence** behind it — so the report is something you can forward, not something you have to translate first.

Client-ready output: **[`examples/sample-report.html`](examples/sample-report.html)** (clone and open it in a browser — one self-contained file, no network needed) and **[`examples/sample-report.json`](examples/sample-report.json)**.

---

## It cannot change your client's store

This is the part worth reading twice before you install anything on a live site.

WooOps Auditor **only reads**. There is no `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `TRUNCATE` or `OPTIMIZE` anywhere in the source, and no call to any WooCommerce or WordPress write API. It does not retry a failed job, cancel an action, change an order status, prune a table, or edit a setting. If it finds something wrong, it says so and stops.

It also makes **no outbound network requests**: no telemetry, no phone-home, no update check. Nothing about the audited store leaves the audited store unless a human moves the file.

And it collects **no personal data and no secrets** — no customer names, emails, phones or addresses; no API keys, tokens, passwords or salts. Order findings carry order ID, date, age, amount, currency, payment method and status. That is all.

Verify it yourself in one line — this greps for every SQL write and every WordPress/WooCommerce write API, and returns nothing:

```bash
grep -rnE '\$wpdb->(query|insert|update|delete|replace)|update_post_meta|wp_(schedule|unschedule)|as_(schedule|unschedule)|->save\(|->set_status\(' src/ templates/
```

The plugin writes exactly two things, neither of them business data: the report file, and one WordPress option (`wooops_last_audit`) holding the timestamp, score and file paths of the last run.

Details and the threat notes: **[docs/SECURITY.md](docs/SECURITY.md)**.

---

## Install

```bash
cd wp-content/plugins
git clone https://github.com/ASanchezT85/wooops-auditor.git
wp plugin activate wooops-auditor
```

Requires WordPress 6.x, PHP 8.1+, and a currently supported WooCommerce. Works with HPOS on or off — both paths are exercised and produce identical results. No runtime dependencies.

## Use

```bash
wp wooops audit                                     # summary table in the terminal
wp wooops audit --format=html                       # standalone HTML report
wp wooops audit --format=json                       # versioned JSON
wp wooops audit --format=both                       # both
wp wooops audit --format=html --output=/tmp/x.html  # explicit destination
wp wooops audit --format=json --stdout              # pipe it somewhere
```

Without `--output`, reports land in `wp-content/uploads/wooops-audit/`, created with an `.htaccess` deny rule and an index file. There is also a minimal screen at **WooCommerce ▸ WooOps Audit** for sites where you would rather not hand someone a shell. WP-CLI is the primary interface.

The full seven-check audit takes **1.3 seconds** against a store with a 2.7-million-row Action Scheduler table. Everything is aggregated in SQL; no result set is ever loaded into memory.

## The health score

`100 - Σ (penalty of the worst finding in each category)`, floored at 0 — CRITICAL 25, HIGH 12, MEDIUM 5, LOW 2, INFO/PASS 0.

It is a headline number for the top of a report, nothing more. The findings are the deliverable, and they are always listed in full regardless of the score.

---

## What it will not claim

An audit tool that overstates is worse than no audit tool, because you only get to hand a client a wrong number once.

- **A pending order is not a lost payment.** It is also not a received one. Abandoned checkouts are pending. Bank transfers are pending for days. The report says *how old*, not *whose fault*.
- **Failed-order value is attempted value, not revenue lost.** Most failed orders are declined cards that were never going to convert. The report will never call that figure lost revenue — there is a test that fails the build if the wording drifts.
- **`DISABLE_WP_CRON = true` does not mean cron is broken.** It is the recommended production setup when a system cron calls `wp-cron.php`, and WordPress cannot see that system cron. The report says so and relies on overdue events as the real evidence.
- **Failed actions are historical.** 500 failures whose newest entry is four months old describes an incident that was already fixed.

Everything v0.1 cannot know: **[docs/LIMITATIONS.md](docs/LIMITATIONS.md)**. Read it before putting a report in front of a client.

## How much of this is proven

- **45 unit tests, 111 assertions**, covering every check across healthy, degraded and broken states — including the false-positive guards and the two semantic guards above.
- **Validated against a real store**: WooCommerce 11.0.1, MySQL 8.4, PHP 8.3, custom table prefix. A clean install scores 100/100 with zero false positives; a store with deliberately provoked failures detects every one of them, with the monetary figures verified by hand. That run found and fixed three real false positives. HPOS and legacy order storage return identical numbers.
- **Not yet proven**: thresholds are heuristics validated against one store. A busy site with forty plugins will surface noise this has not seen. If you run it and something reads as nonsense, that feedback is worth more to this project than a feature request.

Details: **[docs/TESTING.md](docs/TESTING.md)**.

## Status

**v0.1** — deliberately small. Seven checks, two report formats, one command. No SaaS, no dashboard, no accounts, no notifications, no history, and no automatic fixes.

The point of this version is to learn which findings agencies actually act on before building anything larger around them. If you maintain WooCommerce stores and run this against one, tell us which finding you looked at first.

## Docs

[Architecture](docs/ARCHITECTURE.md) · [Checks](docs/CHECKS.md) · [Security & privacy](docs/SECURITY.md) · [Testing](docs/TESTING.md) · [Limitations](docs/LIMITATIONS.md) · [Handoff](docs/HANDOFF_TO_CHATGPT.md)
