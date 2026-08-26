# Handoff — WooOps Auditor v0.1.1

Self-contained status document. Assume the reader saw none of the build session.
Last updated: 2026-08-26, after the report-security hardening was merged and
released as `v0.1.1`.

---

## 1. Executive summary

WooOps Auditor v0.1 is a WordPress/WooCommerce plugin that runs seven **read-only** operational checks against a store and produces a versioned JSON document and a standalone HTML report. It modifies nothing, makes no outbound requests, and collects no PII or secrets.

This pass was **security hardening only**. No new product, no v0.2, no change to the seven checks or their thresholds.

The issue fixed: audit reports used to be written into `wp-content/uploads/wooops-audit/` and "protected" by an `.htaccess` deny rule. That is not a control — nginx ignores `.htaccess`, and the files sat inside the web root either way. Reports describe exactly how a live store is failing, so leaving them there was the most serious weakness in v0.1.

Now the admin screen never writes a report at all: a download re-runs the audit, renders it in memory, and streams it to the authenticated browser. WP-CLI writes a file only when the operator asks for one, and its default location moved out of the web root.

Verified by 13 new unit tests (58 total, all green) **and** by driving the real admin handlers through a live WordPress install with real users, real capabilities and real nonces.

**This work is merged and released.** PR #1 was merged into `main` and the result is tagged **`v0.1.1`**. The hardening branch is gone; `main` is the only branch.

## 2. Security issue corrected

| | |
|---|---|
| **Issue** | Audit reports persisted under `wp-content/uploads/wooops-audit/`, inside the web root, guarded only by an `.htaccess` deny rule and an empty `index.html`. |
| **Why it mattered** | `.htaccess` is ignored by nginx entirely. Even on Apache, the report — which enumerates a store's operational failures, order IDs, amounts and table sizes — was one server misconfiguration or one guessed filename away from being public. |
| **Severity** | Information disclosure. No authentication bypass: the *admin download link* was always capability- and nonce-checked. The exposure was the file left behind, not the endpoint. |
| **Fix** | Admin reports are generated in memory and streamed; nothing is persisted. The CLI default moved to a private directory outside the web root. `.htaccess` is no longer claimed as an access control anywhere. |

## 3. Before / after

**Before**

```
Admin: Run Audit  → render HTML + JSON → write both into wp-content/uploads/wooops-audit/
                  → store file paths in the wooops_last_audit option
       Download   → capability + nonce → readfile() the stored path
CLI:   --format   → write into wp-content/uploads/wooops-audit/ by default
```

**After**

```
Admin: Run Audit  → audit in memory → store timestamp/score/summary only (no paths)
       Download   → capability + nonce → audit runs again in memory
                  → ReportResponse (bytes + headers) → streamed as an attachment
                  → nothing written to disk, ever
CLI:   (no flags) → terminal summary, writes nothing
       --stdout   → report to STDOUT
       --format   → private dir under sys_get_temp_dir() (0700 dir / 0600 file)
       --output   → exactly where the operator said
```

**Breaking change:** the CLI default output directory moved from
`wp-content/uploads/wooops-audit/` to `sys_get_temp_dir()/wooops-audit`. Any
script that assumed the old path must pass `--output` explicitly. Files left in
the old directory by earlier versions are **not** cleaned up — a read-only
auditor should not delete things — so remove them by hand.

**Behavioural consequence, by design:** because nothing is stored, an admin
download re-runs the audit. The downloaded report reflects the store at download
time and can differ from the score shown for the last run. Each report carries
its own timestamp, and the admin screen says so.

## 4. Files changed

| File | Change |
|---|---|
| `src/Admin/Page.php` | Rewritten delivery path: capability → nonce → in-memory audit → stream. Stores metadata only. `header()`, `echo` and `exit` moved behind overridable seams (`sendHeader`/`emit`/`terminate`) so the path is testable. Class is no longer `final`. |
| `src/Report/ReportResponse.php` | **New.** Value object: filename, content type, body, plus `headers()` including `X-Content-Type-Options: nosniff`. Rejects any format but `html`/`json`. |
| `src/Support/ReportWriter.php` | Default directory no longer consults `wp_upload_dir()`; uses `sys_get_temp_dir()/wooops-audit` at 0700, files at 0600. Dropped the `.htaccess`/`index.html` theatre. |
| `src/WPCLI/AuditCommand.php` | Behaviour unchanged; documentation corrected to describe the new default and the operator's responsibility for `--output`. |
| `tests/AdminSecurityTest.php` | **New**, 13 tests (see §5). |
| `tests/WordPressStubs.php` | **New.** Minimal stubs for the WordPress functions the admin path calls, plus a mutable state object. |
| `tests/bootstrap.php` | Loads the stubs. |
| `docs/SECURITY.md` | "Where reports go" replaced by "How reports are delivered" + "What is stored between runs". All `.htaccess`-as-protection claims removed. |
| `docs/ARCHITECTURE.md` | New section: rendering is separate from persisting. |
| `docs/TESTING.md` | New coverage list, the hardening validation transcript, the mutation check. |
| `docs/LIMITATIONS.md` | Replaced the nginx/`.htaccess` caveat with the re-run trade-off, the CLI persistence rule, POSIX modes on Windows, and the orphaned legacy directory. |
| `readme.md` | Corrected the "reports land in wp-content/uploads" line and the writes list; test counts updated. |
| `wooops-auditor.php`, `src/Audit/AuditRunner.php` | Version bumped to 0.1.1 (plugin header, `WOOOPS_AUDITOR_VERSION`, `AuditRunner::VERSION`). |
| `examples/sample-report.json` | Regenerated: it embeds `auditor_version`. |
| `.gitattributes` | **New.** Line endings normalised to LF. See §17. |

Untouched, deliberately: the seven checks, their thresholds, `AuditRunner`, `AuditResult`, `HealthScore`, both reporters, `templates/report.php`, and the sample artifacts.

## 5. New tests

`tests/AdminSecurityTest.php` — 13 tests:

*Authorization*: download refused without `manage_woocommerce`; run refused without it (and stores nothing); download refused with the capability but an invalid nonce; run refused likewise. Capability alone is not enough; a nonce alone is not enough.

*Input*: an unknown `format` (`pdf`) is refused.

*Delivery*: HTML download streams the report with `Content-Type: text/html; charset=utf-8`, an `attachment` `Content-Disposition` whose filename matches `wooops-audit-YYYY-MM-DD-HHMMSS.html`, a correct `Content-Length`, and `X-Content-Type-Options: nosniff`; JSON download streams valid JSON carrying the schema version with the JSON content type; the response is marked no-cache.

*Persistence*: the full admin flow (run + both downloads) leaves the filesystem byte-identical; `wooops_last_audit` has exactly the keys `timestamp`, `score`, `summary` and its serialised value contains none of `path`, `file`, `http`, `uploads`, `.html`, `.json`, `doctype`; and a static assertion that `src/Admin/Page.php` mentions none of `ReportWriter`, `file_put_contents`, `fopen`, `readfile`, `wp_upload_dir`.

*Response object*: filename derives from the audit timestamp; anything but HTML/JSON throws.

**Mutation-checked.** Deleting the capability and nonce checks from
`Page::authorize()` turns four of these tests red; restoring them turns them
green. An authorization test that passes without authorization is worthless, so
this was verified rather than assumed.

## 6. Test totals

```
Before this pass:  45 tests, 111 assertions
After this pass:   58 tests, 148 assertions
```

All passing, ~40 ms. Every pre-existing guarantee still holds: valid JSON, standalone HTML, XSS escaping, severity ordering, health score bounds, and the two semantic guards ("pending is not a lost payment", "attempted value is not revenue lost").

## 7. Manual WordPress validation

A staging store was built for this: WordPress + **WooCommerce 11.0.1**, MySQL 8.4, PHP 8.3, custom prefix `shop7x_`, plugin symlinked in. `validate-admin.php` drove the real handlers through real WordPress — real `wp_set_current_user`, real `current_user_can`, real `wp_create_nonce`/`check_admin_referer`:

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
    Content-Disposition: attachment; filename="wooops-audit-2026-08-26-181839.json"
stored option keys .............. timestamp, score, summary
stored option payload ........... {"timestamp":1787768319,"score":100,"summary":{"CRITICAL":0,"HIGH":0,"MEDIUM":0,"LOW":0,"INFO":2,"PASS":5}}
no new files under uploads ...... OK (7 files, unchanged)
no uploads/wooops-audit dir ..... OK
```

Not done through a browser: no clicking, no login. The handlers were invoked directly inside WordPress with the current user set, which exercises the same capability and nonce code paths. The visual admin screen itself (buttons, layout) has not been eyeballed since the change.

## 8. Filesystem validation

After the admin flow **and** all five CLI invocations, `wp-content/uploads` contained only WooCommerce's own files:

```
wp-content/uploads/woocommerce-placeholder*.webp   (5 files)
wp-content/uploads/woocommerce_uploads/.htaccess
wp-content/uploads/woocommerce_uploads/index.html
```

No `wooops-audit` directory. The stored option was read straight out of MySQL and holds `timestamp`, `score`, `summary` and nothing else. CLI reports landed in `C:\Users\...\Temp\wooops-audit\`, outside the web root.

## 9. WP-CLI validation

| Command | Result |
|---|---|
| `wp wooops audit` | Terminal summary, 100/100, writes nothing |
| `wp wooops audit --format=json --stdout` | Valid JSON to STDOUT |
| `wp wooops audit --format=html` | Written to the private temp directory |
| `wp wooops audit --format=html --output=<path>` | Written exactly there |
| `wp wooops audit --format=both` | Both files, private temp directory |

No CLI behaviour was removed. Only the default destination changed.

## 10. CI status

**The CI workflow exists and is technically correct** — `.github/workflows/tests.yml`, PHP 8.1/8.2/8.3 matrix, `composer install`, `vendor/bin/phpunit`, plus a sample-report reproducibility check.

**It does not run.** The GitHub account is locked for billing: a manual dispatch reached the runner and returned *"The job was not started because your account is locked due to a billing issue."* That is account-level, so making the repository public did not lift it. The workflow is therefore left on `workflow_dispatch` only, so a public repo is not stamped with a red X on every commit. **Automatic CI is not working; do not claim otherwise.**

**The active gate is local**: `bin/hooks/pre-push` (`git config core.hooksPath bin/hooks`) runs the full suite and the sample-report reproducibility check before any push leaves the machine. It ran and passed on every push in this pass.

## 11. Branch

The work was done on `fix/v0.1-report-security-hardening`, branched from `main`,
and delivered through **[PR #1 — "Stop persisting audit reports in the web
root"](https://github.com/ASanchezT85/wooops-auditor/pull/1)** (17 files,
+908/−258), merged on 2026-08-26 with a merge commit. The branch was deleted
locally and on the remote afterwards. `main` is now the only branch.

## 12. Commits

On the branch, in order:

```
8b4cfee  fix: stop persisting admin audit reports in public uploads
9e4d9d5  test: cover authenticated report delivery
6c8937a  docs: document hardened report handling
bd7826a  docs: stop pinning the docs commit hash in the handoff
94406f0  chore: bump version to 0.1.1
```

Then on `main`:

```
5322454  Merge pull request #1 from ASanchezT85/fix/v0.1-report-security-hardening
5c32c01  chore: normalise line endings to LF
```

Two notes on the history, since both were process deviations:

- `bd7826a` exists because an earlier attempt to pin the docs commit's own hash
  in this document required amending and a `--force-with-lease` push, which the
  task brief prohibited. It was on the feature branch, seconds after the
  original push. It was corrected with an ordinary commit rather than more
  rewriting, and no history has been rewritten since.
- `94406f0` (version bump) and `5c32c01` (line endings) were not in the original
  brief. Both are explained in §17.


## 13. Git status

Working tree clean, everything pushed. Repository:
`https://github.com/ASanchezT85/wooops-auditor` — public, GPL-2.0-or-later,
single branch `main` at `5c32c01`, 58 tests green, pre-push hook passing.

Tags:

| Tag | Points at | What it is |
|---|---|---|
| `v0.1.0` | `1d1819d` | The original v0.1, before the hardening |
| `v0.1.1` | `70e8d16` (on `main`) | This hardening release |

Both are annotated tags carrying a summary of the release in their message.

## 14. Known limitations

- An admin download **re-runs the audit**, so it can differ from the score displayed for the last run. That is the cost of storing nothing.
- `--output` writes wherever it is told. Keeping that path out of the web root is the operator's responsibility.
- Directory/file modes 0700/0600 are POSIX. On Windows they are advisory; the temp directory ACL is the real protection.
- Files left in `wp-content/uploads/wooops-audit/` by earlier versions are not removed. The plugin no longer creates or reads that directory; clean it up by hand.
- The admin screen still runs the audit synchronously in a request. On a very large store, prefer WP-CLI.
- The WordPress stubs in `tests/` are not a WordPress test suite. They assert how the plugin *uses* capability/nonce/header APIs, not how WordPress implements them; the live-install validation in §7 covers the rest.
- Everything in `docs/LIMITATIONS.md` still applies: thresholds are heuristics validated against one store.

## 15. Bugs discovered

None. No defect was found in the seven checks during this pass, and none of their logic or thresholds was touched.

## 16. Exact next recommended step

That step is done: PR #1 is merged and `v0.1.1` is tagged. **There is no engineering work left planned for v0.1.**

Stop building and go get evidence: run the auditor against **2–3 real client stores** with different plugin stacks, record every finding a human would call noise, and correct the thresholds. What agencies read first in that report decides the scope of v0.2 — not this codebase.

## 17. Two things done beyond the brief

Both were judgement calls made during the pass. Neither touches the seven
checks, and both are visible in the history.

**Version bumped to 0.1.1** (`94406f0`). Tagging `v0.1.1` while the code still
reported `0.1.0` would have shipped reports whose `auditor_version` field lied
about which build produced them — and that field is precisely what external
monitoring will key on later. The plugin header, `WOOOPS_AUDITOR_VERSION` and
`AuditRunner::VERSION` all moved together, and the sample report was
regenerated because it embeds the value. **`schema_version` stays `1.0.0`**: no
field was added, removed or renamed, so consumers need no change.

**Line endings normalised to LF** (`5c32c01`, `.gitattributes`). On a Windows
checkout with `core.autocrlf=true`, git wrote CRLF while
`bin/generate-sample.php` writes LF, so `examples/sample-report.*` appeared
modified the instant they were regenerated. The pre-push hook asserts the sample
still regenerates identically, so on someone else's machine that gate would have
failed for no real reason — a false alarm in the project's only working CI
substitute. `git add --renormalize .` reported **no content differences**: the
files were already stored with LF, and the commit contains nothing but the
`.gitattributes` file. The local working tree was then refreshed so 26 tracked
files that still held CRLF on disk match the repository.

Verified by the failure mode rather than by the commit existing: running
`php bin/generate-sample.php` now leaves `git status` empty, where before it
left `examples/sample-report.json` modified.

---

## Appendix — the project before this pass

Unchanged by the hardening, repeated here so this document stands alone.

**The seven checks.** `environment` (WooCommerce inactive, pending DB schema update, low *effective* memory limit, no HTTPS — INFO on local/staging hostnames, `WP_DEBUG`); `cron` (overdue events on a 15 min → 6 h ladder, stale `doing_cron` lock, `DISABLE_WP_CRON` as INFO only); `action_scheduler_failed` (volume ladder 1/10/50/500 + dominant-hook concentration); `action_scheduler_past_due` (severity from the oldest delay, and whether it is a backlog or a few stuck actions); `pending_orders` (count, value, age buckets, ten oldest without PII); `failed_orders` (last 30 days, volume + gateway concentration, value labelled *attempted*); `database` (Action Scheduler and order table rows/size, custom `$wpdb` prefix handled). Thresholds are class constants, documented in `docs/CHECKS.md`.

**Architecture.** `StoreGateway` separates facts from judgement: everything touching WordPress/SQL sits behind it (`WordPressGateway` for the real store, `ArrayGateway` for fixtures), and the checks hold only the severity logic. That is why the suite needs no WordPress. Aggregation happens in SQL, with every row-reading query explicitly bounded. A check that throws is caught and reported as *unknown*, never as healthy.

**Health score.** `100 - Σ(penalty of the worst finding in each category)`, floored at 0; CRITICAL 25, HIGH 12, MEDIUM 5, LOW 2, INFO/PASS 0. Changed from the original plan's purely additive rule, which floored any store with more than one real problem at 0/100.

**Earlier staging validation (also 2026-08-26).** Against WooCommerce 11.0.1: a clean install scored 100/100 with zero false positives — after fixing three the first run exposed (WP_MEMORY_LIMIT judged alone, HTTPS on local hostnames, the auditor listing itself). A store with seeded failures scored 33/100 with every seeded failure detected, money figures exact by hand, and the HPOS and legacy order paths returning identical numbers. 1.3 s against a 2.74 M-row Action Scheduler table.

**Deliberately not implemented**: history, baselines, trending, scheduled monitoring, multi-store dashboard, remote agent, SaaS backend, accounts, billing, payment APIs, webhook/email monitoring, uptime checks, notifications, AI, and any automatic fix. v0.1 exists to learn which findings agencies act on before any of that is worth designing.
