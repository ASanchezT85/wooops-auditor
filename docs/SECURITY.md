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

As of v0.1.0 that returns five hits: one in `DatabaseCheck` and three in `EnvironmentCheck`, all of them prose inside finding text ("Do not delete rows manually…", "WooCommerce database update pending"), plus the docblock in `WordPressGateway` stating this very guarantee. No statement, no API call.

The only writes the plugin performs at all:

1. The report file, into a protected directory (below).
2. One WordPress option, `wooops_last_audit`, holding the timestamp, score, severity counts and file paths of the last run. Written with `autoload = false`.

Neither touches business data.

## Where reports go

`ReportWriter::default()` writes to `wp-content/uploads/wooops-audit/`, creating it with mode `0750` and dropping in:

- `.htaccess` with `Deny from all`
- an empty `index.html`

Report files are written `0640`. On nginx the `.htaccess` does nothing, so the directory is **not** guaranteed private there — see LIMITATIONS.md. Reports are served through `admin-post.php` with a capability check and a nonce, never by linking to the uploads URL, and the download handler refuses any path that is not inside the plugin's own report directory.

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
- Anyone with `manage_woocommerce` can run the audit and read past reports. That is the same group that can already read WooCommerce ▸ Status.
- The audit runs synchronously in a request when triggered from the admin page. On a very large store, prefer WP-CLI.
