<?php
declare(strict_types=1);

namespace WooOps\Auditor\Admin;

use WooOps\Auditor\Audit\AuditResult;
use WooOps\Auditor\Audit\AuditRunner;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Report\ReportResponse;
use WooOps\Auditor\Store\StoreGateway;
use WooOps\Auditor\Store\WordPressGateway;

/**
 * Minimal admin screen: run the audit, see the score, download the reports.
 *
 * Reports are **never written to disk from the admin screen**. A download
 * request re-runs the audit and streams the result straight to the browser, so
 * there is no report file left behind under wp-content/uploads for a
 * misconfigured server to hand out. The only thing that persists is the
 * metadata in self::OPTION — timestamp, score and severity counts — which
 * contains no paths, no report body, no PII and no secrets.
 *
 * Deliberately thin. WP-CLI is the primary interface in v0.1; this exists so
 * an agency can hand the screen to someone who does not have shell access.
 */
class Page
{
    public const CAPABILITY = 'manage_woocommerce';
    public const SLUG = 'wooops-audit';
    public const OPTION = 'wooops_last_audit';
    public const RUN_ACTION = 'wooops_run_audit';
    public const DOWNLOAD_ACTION = 'wooops_download';

    /** Injectable for tests; production always builds from the live $wpdb. */
    private ?StoreGateway $gateway;

    public function __construct(?StoreGateway $gateway = null)
    {
        $this->gateway = $gateway;
    }

    public static function register(): void
    {
        $page = new self();
        add_action('admin_menu', [$page, 'addMenu']);
        add_action('admin_post_' . self::RUN_ACTION, [$page, 'handleRun']);
        add_action('admin_post_' . self::DOWNLOAD_ACTION, [$page, 'handleDownload']);
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
        wp_nonce_field(self::RUN_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::RUN_ACTION) . '">';
        submit_button(__('Run Read-Only Audit', 'wooops-auditor'));
        echo '</form>';

        if (!is_array($last) || $last === []) {
            echo '<p>' . esc_html__('No audit has been run yet.', 'wooops-auditor') . '</p></div>';

            return;
        }

        echo '<h2>' . esc_html__('Last audit', 'wooops-auditor') . '</h2>';
        echo '<p><strong>' . esc_html(gmdate('Y-m-d H:i', (int) ($last['timestamp'] ?? 0))) . ' UTC</strong> — '
            . esc_html__('Health score', 'wooops-auditor') . ': <strong>' . (int) ($last['score'] ?? 0) . '/100</strong></p>';

        echo '<ul>';
        foreach (Severity::ORDER as $level) {
            $count = (int) ($last['summary'][$level] ?? 0);
            if ($count > 0) {
                echo '<li>' . esc_html($level) . ': ' . $count . '</li>';
            }
        }
        echo '</ul>';

        echo '<p>';
        foreach (ReportResponse::FORMATS as $format) {
            $link = wp_nonce_url(
                add_query_arg(
                    ['action' => self::DOWNLOAD_ACTION, 'format' => $format],
                    $url
                ),
                self::DOWNLOAD_ACTION
            );
            echo '<a class="button" href="' . esc_url($link) . '">'
                . esc_html(sprintf(__('Download %s', 'wooops-auditor'), strtoupper($format)))
                . '</a> ';
        }
        echo '</p>';

        echo '<p class="description">'
            . esc_html__('Reports are generated when you download them and are never stored on the server, so a download re-runs the audit and reflects the store as it is right now.', 'wooops-auditor')
            . '</p></div>';
    }

    public function handleRun(): void
    {
        $this->authorize(self::RUN_ACTION);

        $result = $this->audit();

        // Metadata only. No file paths, no URLs, no report body: whatever is
        // stored here is readable by anything that can read wp_options.
        update_option(self::OPTION, [
            'timestamp' => $result->timestamp,
            'score' => $result->score(),
            'summary' => $result->summary(),
        ], false);

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        $this->terminate();
    }

    public function handleDownload(): void
    {
        $this->authorize(self::DOWNLOAD_ACTION);

        $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : '';
        if (!in_array($format, ReportResponse::FORMATS, true)) {
            $this->deny(__('Unknown report format.', 'wooops-auditor'));

            return;
        }

        // Generated here, streamed, and gone. Nothing touches the filesystem.
        $response = ReportResponse::create($format, $this->audit());

        nocache_headers();
        foreach ($response->headers() as $header) {
            $this->sendHeader($header);
        }
        $this->emit($response->body);
        $this->terminate();
    }

    /**
     * Capability first, then nonce. Both are required; neither is sufficient.
     */
    protected function authorize(string $action): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            $this->deny(__('Insufficient permissions.', 'wooops-auditor'));

            return;
        }

        check_admin_referer($action);
    }

    protected function audit(): AuditResult
    {
        return (new AuditRunner())->run($this->gateway ?? WordPressGateway::create());
    }

    protected function deny(string $message): void
    {
        wp_die(esc_html($message), '', ['response' => 403]);
    }

    /** Seams, so the delivery path can be exercised by tests. */
    protected function sendHeader(string $header): void
    {
        header($header);
    }

    protected function emit(string $body): void
    {
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- report bodies are escaped by their reporter.
    }

    protected function terminate(): void
    {
        exit;
    }
}
