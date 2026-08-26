# Checks — WooOps Auditor v0.1

Every threshold in this document is a **heuristic**, chosen to be conservative and adjusted once we have run the auditor against real stores. They are constants at the top of each check class, in one place, on purpose.

Severity ladder and health-score penalty:

| Severity | Penalty | Meaning |
|---|---|---|
| PASS | 0 | Checked, nothing wrong |
| INFO | 0 | Worth knowing, not a problem |
| LOW | 2 | Worth fixing eventually |
| MEDIUM | 5 | Worth scheduling |
| HIGH | 12 | Worth this week |
| CRITICAL | 25 | Worth today |

**Health score** = `100 - Σ (penalty of the worst finding in each category)`, floored at 0. Worst-per-category rather than a sum over all findings, because one broken extension can emit several findings and a purely additive score bottoms out at 0 for any store with more than one problem — which makes it useless for telling "bad" from "catastrophic". The score is a headline only; the report always lists every finding regardless of it.

---

## 01 — WooCommerce Environment (`environment`)

**Purpose.** Establish what we are looking at, and catch environment problems that would make every other result misleading.

**Data inspected.** WordPress/WooCommerce/PHP/database versions, `woocommerce_db_version`, HPOS on/off, `WP_MEMORY_LIMIT` and PHP `memory_limit`, `WP_DEBUG`, `DISABLE_WP_CRON`, site URL and scheme, timezone, active theme, active plugin count, names of WooCommerce-related plugins, `$wpdb` prefix.

**Algorithm / severity rules.**

| Condition | Severity |
|---|---|
| WooCommerce not active | CRITICAL — and the check stops; everything else would be noise |
| `woocommerce_db_version` < plugin version | HIGH |
| Effective memory limit < 128M | HIGH |
| Effective memory limit < 256M | LOW |
| Site URL not HTTPS, public hostname | HIGH |
| Site URL not HTTPS, local/staging hostname | INFO |
| `WP_DEBUG` true | LOW |
| none of the above | PASS |

**Effective memory limit** = `php memory_limit` if it is unlimited (`-1`), otherwise `max(php memory_limit, WP_MEMORY_LIMIT)`. WordPress only ever *raises* the ini limit and never lowers it, so judging `WP_MEMORY_LIMIT` alone is wrong. This was found by running the auditor against a real store: WordPress defaults `WP_MEMORY_LIMIT` to 40M, and the first version reported HIGH on a healthy default install with an unlimited PHP limit.

**Local hostnames.** `localhost`, `127.0.0.1`, `::1` and the `.test` / `.local` / `.localhost` / `.example` / `.invalid` suffixes downgrade the HTTPS finding to INFO. Plain HTTP is expected there, and a staging report full of red is a report nobody reads.

**False positives.** A site behind a reverse proxy terminating TLS can have an `http://` site URL and still serve HTTPS to customers — the HTTPS finding is then wrong. The effective memory limit describes web requests; the CLI limit used by cron can differ.

**Limitations.** Not a security scanner. It does not check file permissions, plugin vulnerabilities, or WordPress core integrity, and it deliberately collects nothing that could be a secret.

---

## 02 — WP-Cron Health (`cron`)

**Purpose.** Decide whether background work is actually being triggered.

**Data inspected.** `_get_cron_array()` (registered events, their timestamps), `DISABLE_WP_CRON`, `ALTERNATE_WP_CRON`, the `doing_cron` transient.

**Algorithm.** The signal is *overdue events*, not configuration. The worst delay among overdue events sets the severity:

| Worst overdue delay | Severity |
|---|---|
| ≥ 6 h | CRITICAL |
| 1–6 h | HIGH |
| 15–60 min | MEDIUM |
| < 15 min | not reported |

Plus: a `doing_cron` lock held longer than 10 minutes → HIGH.

**`DISABLE_WP_CRON` is never treated as breakage.** It is the recommended production setup when a system cron calls `wp-cron.php`, and WordPress cannot see that system cron. It is reported as INFO ("external cron may be configured, cannot be verified from WordPress alone"), escalating to MEDIUM only when events are *also* badly overdue — at which point the overdue finding is doing the real work anyway.

**False positives.** A low-traffic site with default WP-Cron and no visitors will legitimately show minutes of lateness; that is why nothing under 15 minutes is reported. A cron event scheduled by a plugin that was later deactivated stays in the array forever and will show as permanently overdue.

---

## 03 — Action Scheduler, failed actions (`action_scheduler_failed`)

**Purpose.** Find background work that silently did not happen.

**Data inspected.** `{prefix}actionscheduler_actions` where `status = 'failed'`: total, oldest, newest, counts grouped by hook and by group. Aggregated in SQL; the rows are never loaded.

**Severity by volume.**

| Failed actions | Severity |
|---|---|
| ≥ 500 | CRITICAL |
| ≥ 50 | HIGH |
| ≥ 10 | MEDIUM |
| 1–9 | LOW |
| 0 | PASS |

**Concentration.** With ≥ 10 failures, if one hook owns ≥ 50 % of them, a second MEDIUM finding names that hook — that is usually one broken extension, which is far cheaper to fix than a general scheduler problem.

**False positives.** Failed actions accumulate historically: a store that was broken six months ago and fixed still shows the old failures until something prunes them. That is why the report shows the *oldest* and *newest* failure dates — a large count whose newest entry is months old is history, not an incident.

**Limitations.** No retry, no cancel, no delete. The auditor cannot tell you *why* an action failed beyond the hook name; the log message lives in `actionscheduler_logs` and reading it per action does not scale.

---

## 04 — Action Scheduler, past-due actions (`action_scheduler_past_due`)

**Purpose.** Detect a queue that is behind, as opposed to one that is failing.

**Data inspected.** Pending actions with `scheduled_date_gmt < now`: count, oldest delay, median delay over a bounded sample of 1000, grouping by hook.

**Thresholds** (severity from the *oldest* delay):

| Delay | Severity |
|---|---|
| < 5 min | INFO |
| 5–15 min | LOW |
| 15–60 min | MEDIUM |
| 1–6 h | HIGH |
| > 6 h | CRITICAL |

A queue that is seconds behind is normal and is never called a fault.

**Straggler vs backlog.** When the median lag is under 5 minutes while the oldest action is at least 15 minutes late, the finding says so explicitly: the queue is draining and the delay sits in a few stuck actions rather than the whole queue. Observed on the staging store (32 past-due actions, median 3 minutes, oldest 1.2 hours) — without that sentence the finding reads as a stalled queue when it is not.

**False positives.** Some plugins intentionally schedule actions in the past to make them run on the next queue pass; those appear as small, permanent lag. A store mid-import will have a large legitimate backlog that drains on its own.

**Limitations.** The median is over the 1000 oldest past-due actions, not the whole backlog. On a very large backlog it is a lower bound on the true median, and the report says so.

---

## 05 — Pending Orders (`pending_orders`)

**Purpose.** Surface orders waiting for payment that are old enough that nobody is going to pay them.

**Data inspected.** Orders with status `wc-pending`: count, summed total, currency, counts by payment method, counts by age bucket (`<1h`, `1-6h`, `6-24h`, `1-7d`, `>7d`), and the ten oldest with **no personal data** — order ID, date, age, amount, currency, payment method, status.

**Severity** from the number older than 24 hours:

| Stale (> 24 h) | Severity |
|---|---|
| ≥ 20 | HIGH |
| ≥ 5 | MEDIUM |
| ≥ 1 | LOW |
| 0 (but pending orders exist) | INFO |

**Semantics that must not slip.** *Pending does not mean a lost payment.* Abandoned checkouts are pending. Bank transfers are legitimately pending for days. The report says the value figure "is not revenue, and it is not money that was lost", and a test asserts that wording stays.

**False positives.** Stores using bank transfer or cash on delivery routinely carry old pending orders by design. Any store with a checkout that creates the order before payment (the WooCommerce default) accumulates pending orders in proportion to its traffic.

---

## 06 — Failed Orders (`failed_orders`)

**Purpose.** Detect a payment path that is failing more than normal.

**Data inspected.** Orders with status `wc-failed` in the last **30 days**: count, summed total, counts by payment method and by age bucket, ten samples with no personal data.

**Severity by volume:** ≥ 50 HIGH, ≥ 10 MEDIUM, 1–9 LOW, 0 PASS.

**Concentration.** With ≥ 5 failures, if one payment method owns ≥ 70 % of them → MEDIUM. That pattern usually means store-side misconfiguration (keys, webhook, currency) rather than customer behaviour.

**Semantics that must not slip.** The money figure is **attempted value**, not revenue lost. Most failed orders are declined cards that were never going to convert. The finding carries that sentence in `technical_details`, and a test asserts it.

**False positives.** Card testing / fraud attempts inflate failed-order counts without indicating any store problem. Some gateways mark 3-D Secure abandonment as failed.

**Limitations.** Without a baseline the auditor cannot say whether 14 failures is high *for this store*. v0.1 reports absolute volume and concentration only; trend detection needs history, which v0.1 does not keep.

---

## 07 — Database / Action Scheduler tables (`database`)

**Purpose.** Detect bookkeeping that is accumulating without bound. Not a general database profiler.

**Data inspected.** From `information_schema` (`TABLE_ROWS`, `DATA_LENGTH`, `INDEX_LENGTH`) for: the four `actionscheduler_*` tables, `wc_orders`/`wc_orders_meta`/`wc_order_addresses`, `posts`, `postmeta`, `options`. The `$wpdb` prefix is always read at runtime — `wp_` is never assumed, and a test covers a custom prefix.

**Severity for Action Scheduler tables** (worst of the two ladders):

| Rows | Severity | | Total size | Severity |
|---|---|---|---|---|
| ≥ 1,000,000 | CRITICAL | | ≥ 1 GB | CRITICAL |
| ≥ 250,000 | HIGH | | ≥ 256 MB | HIGH |
| ≥ 50,000 | MEDIUM | | | |

Any other inspected table over 1 GB is reported once as MEDIUM, for context only, with no cleanup recommendation.

**False positives.** `TABLE_ROWS` is an estimate on InnoDB and can be off by a wide margin; the report states this. A large `actionscheduler_actions` table on a high-volume store may be entirely healthy and simply reflect throughput.

**Limitations.** No `OPTIMIZE`, no `ANALYZE`, no row deletion, ever. The auditor reports size and stops.
