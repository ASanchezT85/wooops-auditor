<?php
/**
 * Standalone HTML report template.
 *
 * @var \WooOps\Auditor\Audit\AuditResult $result
 */

use WooOps\Auditor\Audit\Severity;

if (!function_exists('wooops_e')) {
    function wooops_e(mixed $value): string
    {
        if (is_bool($value)) {
            $value = $value ? 'yes' : 'no';
        }
        if ($value === null) {
            $value = '—';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('wooops_value')) {
    /** Raw numbers stay raw in the JSON; the HTML shows them the way a human reads them. */
    function wooops_value(string $key, mixed $value): string
    {
        if (is_int($value)) {
            if (str_ends_with($key, '_size') || str_ends_with($key, '_bytes')) {
                return \WooOps\Auditor\Support\Format::bytes($value) . ' (' . number_format($value) . ' B)';
            }
            if (str_ends_with($key, '_seconds') || $key === 'delay' || $key === 'age') {
                return \WooOps\Auditor\Support\Format::duration($value);
            }
            if (in_array($key, ['oldest', 'newest', 'timestamp'], true) && $value > 1000000000) {
                return gmdate('Y-m-d H:i', $value) . ' UTC';
            }
            if ($value >= 10000) {
                return number_format($value);
            }
        }

        return (string) wooops_e($value);
    }
}

if (!function_exists('wooops_evidence')) {
    function wooops_evidence(mixed $value, int $depth = 0, string $key = ''): string
    {
        if (!is_array($value)) {
            return '<span class="v">' . wooops_value($key, $value) . '</span>';
        }
        if ($value === []) {
            return '<span class="v">—</span>';
        }

        $out = '<table class="ev">';
        foreach ($value as $k => $v) {
            $label = is_int($k) ? '#' . ($k + 1) : (string) $k;
            $out .= '<tr><th>' . wooops_e(str_replace('_', ' ', $label)) . '</th><td>'
                . ($depth < 2
                    ? wooops_evidence($v, $depth + 1, is_int($k) ? $key : (string) $k)
                    : '<span class="v">' . wooops_e(is_array($v) ? json_encode($v) : $v) . '</span>')
                . '</td></tr>';
        }

        return $out . '</table>';
    }
}

$summary = $result->summary();
$environment = $result->environment;
$score = $result->score();
$scoreClass = $score >= 85 ? 'good' : ($score >= 60 ? 'warn' : 'bad');
$actionable = $result->actionable();
$passing = array_values(array_filter(
    $result->sortedFindings(),
    static fn ($f) => Severity::penalty($f->severity) === 0
));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WooOps Audit — <?= wooops_e($environment['site_url'] ?? 'WooCommerce store') ?></title>
<style>
:root{--ink:#1c1e21;--muted:#63676c;--line:#e2e5e9;--bg:#f6f7f9;--card:#fff;
--crit:#a01b25;--high:#c25908;--med:#9a7c05;--low:#3a6ea5;--info:#63676c;--pass:#1f7a45;}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.wrap{max-width:900px;margin:0 auto;padding:32px 20px 64px}
header{border-bottom:3px solid var(--ink);padding-bottom:16px;margin-bottom:28px}
h1{font-size:22px;margin:0 0 4px;letter-spacing:-.01em}
h1 span{color:var(--muted);font-weight:400}
h2{font-size:15px;text-transform:uppercase;letter-spacing:.08em;margin:38px 0 14px;padding-bottom:6px;border-bottom:1px solid var(--line)}
.meta{color:var(--muted);font-size:13px}
.meta b{color:var(--ink);font-weight:600}
.score{display:flex;gap:24px;align-items:center;background:var(--card);border:1px solid var(--line);border-radius:8px;padding:20px 24px;margin-bottom:8px}
.score .n{font-size:44px;font-weight:700;line-height:1}
.score .n small{font-size:16px;color:var(--muted);font-weight:400}
.score.good .n{color:var(--pass)}.score.warn .n{color:var(--med)}.score.bad .n{color:var(--crit)}
.counts{display:flex;flex-wrap:wrap;gap:8px}
.pill{display:inline-block;border-radius:999px;padding:3px 11px;font-size:12px;font-weight:600;color:#fff;letter-spacing:.03em}
.pill.CRITICAL{background:var(--crit)}.pill.HIGH{background:var(--high)}.pill.MEDIUM{background:var(--med)}
.pill.LOW{background:var(--low)}.pill.INFO{background:var(--info)}.pill.PASS{background:var(--pass)}
.pill.z{background:#c8ccd1;color:#444}
.f{background:var(--card);border:1px solid var(--line);border-left:5px solid var(--info);border-radius:6px;padding:16px 18px;margin:0 0 14px}
.f.CRITICAL{border-left-color:var(--crit)}.f.HIGH{border-left-color:var(--high)}
.f.MEDIUM{border-left-color:var(--med)}.f.LOW{border-left-color:var(--low)}.f.PASS{border-left-color:var(--pass)}
.f h3{margin:0 0 6px;font-size:16px;display:flex;gap:10px;align-items:baseline;flex-wrap:wrap}
.f .id{font:11px/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--muted)}
.f p{margin:8px 0}
.f .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);display:block;margin-top:12px}
details{margin-top:10px}
summary{cursor:pointer;font-size:13px;color:var(--low)}
table{border-collapse:collapse;width:100%;font-size:13px;background:var(--card)}
th,td{text-align:left;padding:6px 10px;border:1px solid var(--line);vertical-align:top}
th{background:#f0f2f4;font-weight:600;width:34%}
table.ev{margin:6px 0 0}
table.ev th{width:38%;font-weight:500;font-size:12px}
.v{font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;word-break:break-word}
.note{color:var(--muted);font-size:12px;margin-top:8px}
footer{margin-top:44px;padding-top:14px;border-top:1px solid var(--line);color:var(--muted);font-size:12px}
@media print{body{background:#fff}.wrap{max-width:none;padding:0}.f{break-inside:avoid}details{display:none}}
@media (max-width:600px){.score{flex-direction:column;align-items:flex-start;gap:14px}th{width:auto}}
</style>
</head>
<body>
<div class="wrap">

<header>
  <h1>WooOps Audit <span>— read-only diagnostic</span></h1>
  <p class="meta">
    Store <b><?= wooops_e($environment['site_url'] ?? 'unknown') ?></b><br>
    Audit date <b><?= wooops_e(gmdate('Y-m-d H:i', $result->timestamp)) ?> UTC</b><br>
    WordPress <b><?= wooops_e($environment['wordpress_version'] ?? '?') ?></b> ·
    WooCommerce <b><?= wooops_e($environment['woocommerce_version'] ?? 'not detected') ?></b> ·
    PHP <b><?= wooops_e($environment['php_version'] ?? '?') ?></b> ·
    HPOS <b><?= wooops_e($environment['hpos_enabled'] ?? false) ?></b>
  </p>
</header>

<div class="score <?= $scoreClass ?>">
  <div class="n"><?= (int) $score ?><small>/100</small></div>
  <div>
    <div class="counts">
      <?php foreach (Severity::ORDER as $level) : ?>
        <span class="pill <?= $summary[$level] > 0 ? wooops_e($level) : 'z' ?>"><?= wooops_e($level) ?> <?= (int) $summary[$level] ?></span>
      <?php endforeach; ?>
    </div>
    <p class="note">Health score is a headline indicator only: 100 minus a fixed penalty for the worst finding in each category. Read the findings, not the number.</p>
  </div>
</div>

<?php if ($result->errors !== []) : ?>
<p class="note"><strong>Incomplete audit:</strong> <?= count($result->errors) ?> check(s) failed to run. This report does not cover those domains.</p>
<?php endif; ?>

<h2>Findings</h2>
<?php if ($actionable === []) : ?>
  <p>No actionable findings. Every check either passed or returned informational data only.</p>
<?php endif; ?>
<?php foreach ($actionable as $f) : ?>
  <article class="f <?= wooops_e($f->severity) ?>">
    <h3><span class="pill <?= wooops_e($f->severity) ?>"><?= wooops_e($f->severity) ?></span> <?= wooops_e($f->title) ?>
      <span class="id"><?= wooops_e($f->id) ?></span></h3>
    <p><?= wooops_e($f->summary) ?></p>
    <?php if ($f->whyItMatters !== '') : ?>
      <span class="lbl">Why it matters</span><p><?= wooops_e($f->whyItMatters) ?></p>
    <?php endif; ?>
    <?php if ($f->recommendedAction !== '') : ?>
      <span class="lbl">Recommended action</span><p><?= wooops_e($f->recommendedAction) ?></p>
    <?php endif; ?>
    <?php if ($f->technicalDetails !== '') : ?>
      <p class="note"><?= wooops_e($f->technicalDetails) ?></p>
    <?php endif; ?>
    <?php if ($f->evidence !== []) : ?>
      <details open><summary>Evidence</summary><?= wooops_evidence($f->evidence) ?></details>
    <?php endif; ?>
  </article>
<?php endforeach; ?>

<h2>Checks that passed</h2>
<?php if ($passing === []) : ?>
  <p class="note">None.</p>
<?php else : ?>
  <?php foreach ($passing as $f) : ?>
    <article class="f <?= wooops_e($f->severity) ?>">
      <h3><span class="pill <?= wooops_e($f->severity) ?>"><?= wooops_e($f->severity) ?></span> <?= wooops_e($f->title) ?>
        <span class="id"><?= wooops_e($f->id) ?></span></h3>
      <p><?= wooops_e($f->summary) ?></p>
    </article>
  <?php endforeach; ?>
<?php endif; ?>

<h2>Environment</h2>
<table>
  <?php foreach ($environment as $key => $value) : ?>
    <tr>
      <th><?= wooops_e(str_replace('_', ' ', (string) $key)) ?></th>
      <td class="v"><?= wooops_e(is_array($value) ? implode(', ', $value) : $value) ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<p class="note">Secrets, keys, salts and credentials are never collected. Order data is aggregated and contains no customer names, addresses, emails or phone numbers.</p>

<h2>Appendix — raw check data</h2>
<?php foreach ($result->checks as $key => $data) : ?>
  <details><summary><?= wooops_e($key) ?></summary><?= wooops_evidence($data) ?></details>
<?php endforeach; ?>

<footer>
  Generated by WooOps Auditor <?= wooops_e($result->auditorVersion) ?> (schema <?= wooops_e(\WooOps\Auditor\Audit\AuditResult::SCHEMA_VERSION) ?>).
  This tool is strictly read-only: it inspects and reports, and never modifies the store.
</footer>

</div>
</body>
</html>
