# Architecture — WooOps Auditor v0.1

## The one rule

The auditor is **read-only**. It reads WordPress, WooCommerce and the database, and it writes exactly two things: a report file in a protected directory, and one WordPress option holding the metadata of the last run (timestamp, score, file paths). It never touches orders, settings, cron, webhooks, stock, or the Action Scheduler queue.

## Shape

```
StoreGateway (interface)          ← every WordPress/SQL call lives behind this
   ├── WordPressGateway           ← the real store
   └── ArrayGateway               ← fixtures, tests, sample report

CheckInterface                    ← seven independent checks
   run(StoreGateway): {findings, data}

AuditRunner                       ← runs the checks, collects Findings + raw data
   └── AuditResult                ← score, summary, ordering, JSON schema

ReporterInterface
   ├── JsonReporter               ← the stable, versioned contract
   └── HtmlReporter               ← templates/report.php, standalone

Entry points
   ├── WPCLI\AuditCommand         ← wp wooops audit          (primary)
   └── Admin\Page                 ← WooCommerce ▸ WooOps Audit (secondary)
```

## Why the gateway split

The single design decision worth defending: **facts are separated from judgement**.

- The gateway answers "how many failed actions are there, grouped by hook".
- The check answers "83 failed actions concentrated in one hook is a HIGH".

That split buys three things:

1. **Testability without WordPress.** The whole severity and scoring logic is exercised by plain PHPUnit, no WordPress bootstrap, no database, no fixtures directory of SQL. That is why the test suite runs in 25 ms.
2. **A demo that cannot lie about a real store.** `examples/sample-report.html` is generated from `ArrayGateway`, not from anyone's data.
3. **A clean seam for later.** If v0.2 ever needs to audit a store remotely, a second gateway implementation is the entire change.

The cost is one interface. That is the whole abstraction budget of this plugin; there are no factories, no service container, no event system, no plugin API.

## Data flow

1. `AuditCommand` builds a `WordPressGateway` (which captures a single `now()` used by every age calculation, so a slow audit stays internally consistent).
2. `AuditRunner` runs the seven checks in order. A check that throws is caught: the audit continues, the failure is recorded in `errors[]` and surfaced as a finding. An incomplete audit is never presented as a clean one.
3. `AuditResult` holds the findings, the raw per-check data, and derives score/summary/ordering.
4. A reporter serialises it.

## Performance posture

Aggregation happens in SQL: `COUNT`, `SUM`, `GROUP BY`, and `information_schema` for table sizes. The auditor never loads an order set into PHP. Two places read rows rather than aggregates, and both are explicitly bounded:

- past-due median: `LIMIT 1000` (median of a sample, documented as such)
- order age buckets: `LIMIT 5000`
- oldest-order listing: `LIMIT 10`

## Dependencies

Runtime: none. The plugin ships a plain PSR-4 autoloader so it works from a bare checkout; Composer's is used when present. Dev: PHPUnit only.

## What was deliberately not built

No SaaS backend, no REST API, no scheduling of its own, no history/trending, no notifications, no remote reporting, no automatic remediation. v0.1 exists to learn which findings agencies actually care about before any of that is worth designing.
