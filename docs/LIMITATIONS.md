# Limitations — what WooOps Auditor v0.1 cannot know

This document exists so that nobody — us included — sells a claim the tool cannot back. Read it before putting a report in front of an agency.

## Things the auditor cannot prove

**`DISABLE_WP_CRON = true` does not prove cron is broken.** It is the recommended production configuration when a system cron calls `wp-cron.php`. WordPress has no way to see that external cron. The auditor reports it as unverifiable and relies on overdue events as the actual evidence.

**Overdue cron events do not prove cron is dead either.** A plugin can schedule an event and then be deactivated, leaving a permanently overdue entry. A single stale hook is not a dead scheduler.

**A pending order does not prove a payment was received.** It does not prove one was lost either. Pending is the normal state of an abandoned checkout and of a bank transfer awaiting settlement. The auditor reports age, not fault.

**Failed-order value is not revenue lost.** It is *attempted* value: the sum of order totals for checkouts that did not complete. Most of those would never have converted. Presenting that figure as lost revenue is wrong and the report is worded to prevent it.

**A large Action Scheduler table is not automatically a fault.** A high-volume store legitimately generates millions of actions. What the auditor detects is accumulation; whether it matters depends on retention policy and hardware.

**Failed actions are historical.** A count of 500 failures whose newest entry is four months old describes a fixed incident, not a current one. Always read the oldest/newest dates alongside the count.

**Row counts are estimates.** `information_schema.TABLE_ROWS` is approximate on InnoDB, sometimes by tens of percent.

**The median past-due delay is a lower bound.** It is computed over the 1000 oldest past-due actions, because pulling an entire backlog into PHP is not safe on a large store.

**No baseline, no trend.** v0.1 keeps no history. It cannot say whether today's 14 failed orders is normal for this store or a tenfold spike. That is the single biggest gap between the auditor and the monitoring product it is meant to inform.

## Things the auditor does not look at

Payment-gateway APIs (Stripe, PayPal), webhook delivery, email deliverability, checkout functionality, uptime, inventory sync, front-end errors, plugin vulnerabilities, file integrity, server-level configuration, and anything requiring an outbound network request. All deliberately out of scope for v0.1.

It also cannot see *why* a scheduled action failed: the message lives in `actionscheduler_logs`, and reading it per action does not scale.

## Environment caveats

- The HTTPS finding reads the configured site URL. A site behind a TLS-terminating proxy may serve HTTPS correctly and still be flagged.
- The memory finding uses the *effective* limit (`max(php memory_limit, WP_MEMORY_LIMIT)`, or unlimited when PHP says `-1`). It still describes web requests; the CLI/cron limit can differ entirely.
- HTTPS is judged from the configured site URL, and downgraded to INFO on local/staging hostnames. A production site on a `.local` domain would therefore be under-reported.
- On nginx, the `.htaccess` protecting the report directory does nothing. Reports there are only as private as the server configuration makes them. Serve them through the admin download link, or write them somewhere outside the web root with `--output`.
- The admin-page run is synchronous. On a store large enough to matter, use WP-CLI.

## Thresholds are still heuristics

Every number in `docs/CHECKS.md` was chosen conservatively from experience. One staging validation has been done (see `docs/TESTING.md`): a clean WooCommerce 11 install scores 100/100 with no false positives, and a store with deliberately provoked failures detects every one of them. That corrected three real false positives but is still a single store. Until the auditor has run against a corpus of real client sites, treat severities as ordering hints rather than verdicts.
