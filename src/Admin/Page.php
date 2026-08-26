<?php
declare(strict_types=1);

namespace WooOps\Auditor\Admin;

use WooOps\Auditor\Audit\AuditRunner;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Report\HtmlReporter;
use WooOps\Auditor\Report\JsonReporter;
use WooOps\Auditor\Store\WordPressGateway;
use WooOps\Auditor\Support\ReportWriter;

/**
 * Minimal admin screen: run the audit, see the score, download the reports.
 *
 * Deliberately thin. WP-CLI is the primary interface in v0.1; this exists so
 * an agency can hand the screen to someone who does not have shell access.
 * Reports are served through admin-post with a capability check, never from a
 * public uploads URL.
 */
final class Page
{
    private const CAPABILITY = 'manage_woocommerce';
    private const SLUG = 'wooops-audit';
    private const OPTION = 'wooops_last_audit';

    public static function register(): void
    {
        $page = new self();
        add_action('admin_menu', [$page, 'addMenu']);
        add_action('admin_post_wooops_run_audit', [$page, 'handleRun']);
        add_action('admin_post_wooops_download', [$page, 'handleDownload']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('WooOps Audit', 'wooops-auditor'),
            __('WooOps Audit', 'wooops-auditor'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to view this page.', 'wooops-auditor'));
        }

        $last = get_option(self::OPTION, []);
        $url = admin_url('admin-post.php');

        echo '<div class="wrap"><h1>' . esc_html__('WooOps Audit', 'wooops-auditor') . '</h1>';
        echo '<p>' . esc_html__('Runs a read-only diagnostic of this store. It never modifies orders, settings, cron or the database.', 'wooops-auditor') . '</p>';

        echo '<form method="post" action="' . esc_url($url) . '">';
        wp_nonce_field('wooops_run_audit');
        echo '<input type="hidden" name="action" value="wooops_run_audit">';
        submit_button(__('Run Read-Only Audit', 'wooops-auditor'));
        echo '</form>';

        if (!is_array($last) || $last === []) {
            echo '<p>' . esc_html__('No audit has been run yet.', 'wooops-auditor') . '</p></div>';

            return;
        }

        echo '<h2>' . esc_html__('Last audit', 'wooops-auditor') . '</h2>';
        echo '<p><strong>' . esc_html(gmdate('Y-m-d H:i', (int) $last['timestamp'])) . ' UTC</strong> — '
            . esc_html__('Health score', 'wooops-auditor') . ': <strong>' . (int) $last['score'] . '/100</strong></p>';

        echo '<ul>';
        foreach (Severity::ORDER as $level) {
            $count = (int) ($last['summary'][$level] ?? 0);
            if ($count > 0) {
                echo '<li>' . esc_html($level) . ': ' . $count . '</li>';
            }
        }
        echo '</ul><p>';

        foreach (['html', 'json'] as $format) {
            if (empty($last['files'][$format])) {
                continue;
            }
            $link = wp_nonce_url(
                add_query_arg(['action' => 'wooops_download', 'format' => $format], $url),
                'wooops_download'
            );
            echo '<a class="button" href="' . esc_url($link) . '">'
                . esc_html(sprintf(__('Download %s', 'wooops-auditor'), strtoupper($format)))
                . '</a> ';
        }

        echo '</p></div>';
    }

    public function handleRun(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'wooops-auditor'));
        }
        check_admin_referer('wooops_run_audit');

        $result = (new AuditRunner())->run(WordPressGateway::create());
        $writer = ReportWriter::default();
        $stamp = gmdate('Y-m-d-His', $result->timestamp);

        $files = [
            'json' => $writer->write("wooops-audit-{$stamp}.json", (new JsonReporter())->render($result)),
            'html' => $writer->write("wooops-audit-{$stamp}.html", (new HtmlReporter())->render($result)),
        ];

        update_option(self::OPTION, [
            'timestamp' => $result->timestamp,
            'score' => $result->score(),
            'summary' => $result->summary(),
            'files' => $files,
        ], false);

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    public function handleDownload(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'wooops-auditor'));
        }
        check_admin_referer('wooops_download');

        $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : '';
        $last = get_option(self::OPTION, []);
        $path = $last['files'][$format] ?? null;

        // Only ever serve a path this plugin wrote itself, inside its own directory.
        $base = ReportWriter::default()->directory();
        if (!is_string($path) || !str_starts_with($path, $base) || !is_readable($path)) {
            wp_die(esc_html__('Report not available.', 'wooops-auditor'));
        }

        nocache_headers();
        header('Content-Type: ' . ($format === 'json' ? 'application/json' : 'text/html') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
