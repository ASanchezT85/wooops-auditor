# Security & privacy — WooOps Auditor v0.1

## Read-only, and how that is enforced

Every database statement in the plugin is a `SELECT` (or `SHOW TABLES`). There is no `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `TRUNCATE`, `OPTIMIZE` or `DROP` anywhere in `src/`, and no call to any WooCommerce or WordPress write API (`wc_update_order_status`, `wp_schedule_event`, `wp_unschedule_event`, `as_unschedule_action`, `update_post_meta`, …).

Verify it yourself. This greps for every SQL write and every WordPress/WooCommerce write API, and returns nothing:

```bash
grep -rnE '\$wpdb->(query|insert|update|delete|replace)|update_post_meta|update_user_meta|wp_(schedule|unschedule|clear_scheduled)|as_(schedule|unschedule|enqueue)|->save\(|->set_status\(' src/ templates/
```

A broader keyword grep is noisier and worth knowing about:

```bash
grep -rniE "(insert|update|delete|alter|truncate|drop|optimize|repair)" src/ templates/
```

As of v0.1.1 that returns five hits: one in `DatabaseCheck` and three in `EnvironmentCheck`, all of them prose inside finding text ("Do not delete rows manually…", "WooCommerce database update pending"), plus the docblock in `WordPressGateway` stating this very guarantee. No statement, no API call.

The only writes the plugin performs at all:

1. A report file — **only from WP-CLI, and only when the operator asked for one** (see below). The admin screen writes nothing.
2. One WordPress option, `wooops_last_audit`, holding the timestamp, score and severity counts of the last run. No paths. Written with `autoload = false`.

Neither touches business data.

## How reports are delivered

This changed in the hardening pass, and the change is the point of it.

**From the admin screen, reports are never written to disk.** A download request
re-runs the audit, renders the report into a string, and streams it straight to
the authenticated browser as an attachment. There is no file left behind for a
misconfigured server, a directory listing, or a URL guess to expose.

```
Download report
  → capability check (manage_woocommerce)
  → nonce check (check_admin_referer)
  → audit runs in memory
  → ReportResponse: bytes + headers
  → streamed as an attachment
  → nothing persisted
```

The previous design wrote HTML and JSON into `wp-content/uploads/wooops-audit/`
and protected them with an `.htaccess` deny rule plus an index file. That is not
a control: `.htaccess` is ignored by nginx, and the files sat inside the web
root regardless. **Do not treat `.htaccess` as an access control anywhere in
this project.** The old directory is no longer created; if a store has one from
an earlier version, delete it.

**From WP-CLI, a file is written only because the operator asked for one.**
`wp wooops audit` on its own prints a summary and writes nothing. `--stdout`
streams the report to the terminal. `--format=json|html|both` without `--output`
writes to a private directory under the system temp directory
(`sys_get_temp_dir()/wooops-audit`, created 0700, files 0600) — never under
`wp-content/uploads`. `--output=<path>` writes exactly where the operator said;
choosing a location that the web server does not serve is the operator's
responsibility, and the CLI help says so.

Directory and file modes are POSIX. On Windows they are advisory and the real
protection is the filesystem ACL of the temp directory.

## What is stored between runs

One WordPress option, `wooops_last_audit`, written with `autoload = false`, and
it holds only:

```json
{ "timestamp": 1787768319, "score": 100, "summary": { "CRITICAL": 0, "HIGH": 0, ... } }
```

No file paths, no URLs, no report body, no PII, no secrets. A test asserts the
exact key set, and a second one asserts the serialised value contains no path,
URL or report-body fragment.

Because nothing is stored, the download re-runs the audit. The report you
download therefore reflects the store at download time, and can legitimately
differ from the score shown for the last run. The report carries its own
timestamp; the screen says this in as many words.

## Capabilities and nonces

- Admin page, run action and download action all require `manage_woocommerce`.
- The run action verifies `check_admin_referer('wooops_run_audit')`.
- The download action verifies `check_admin_referer('wooops_download')` and validates `format` with `sanitize_key`.
- WP-CLI inherits the shell's trust boundary, as every WP-CLI command does.

## Output escaping

`templates/report.php` escapes every interpolated value with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`, including hook names and table names, which come from third-party plugins and are therefore untrusted input. A test renders a finding containing `<script>` and an `onerror` payload and asserts neither survives into the output.

The admin page escapes with `esc_html` / `esc_url` / `esc_attr`.

## What is never collected

Not read, not stored, not printed, in any format:

- API keys, tokens, private keys, gateway secrets
- passwords, salts, `AUTH_KEY` and friends
- database credentials
- customer names, emails, phone numbers, addresses, IPs

Order findings carry only: order ID, date, age, amount, currency, payment method title, status. Tests assert that the serialised order findings contain none of `email`, `phone`, `first_name`, `last_name`, `address`, `postcode`, and that the environment payload contains none of `secret`, `password`, `api_key`, `token`, `salt`, `auth_key`.

Order IDs are included because an agency has to be able to look the order up. If a client considers order IDs sensitive, do not share the raw report.

## No outbound anything

WooOps v0.1 makes **no external HTTP requests**: no telemetry, no update check, no phone-home, no error reporting. The HTML report loads no external CSS, JS, fonts, images or CDNs, so it opens on a laptop with no network — and a test asserts that too. Nothing about the audited store leaves the audited WordPress installation unless a human moves the file.

## Threat notes

- The HTML report describes operational weaknesses of a live store. Treat it as confidential; send it the way you would send a pentest report.
- Anyone with `manage_woocommerce` can run the audit and download a report. That is the same group that can already read WooCommerce ▸ Status. Because reports are generated on demand rather than stored, there is no archive of past reports for a later compromise to harvest.
- Report bodies are served as attachments with `X-Content-Type-Options: nosniff`, and the HTML they contain is escaped by the template.
- The audit runs synchronously in a request when triggered from the admin page. On a very large store, prefer WP-CLI.
