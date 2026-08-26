<?php
declare(strict_types=1);

namespace WooOps\Auditor\Tests {

use RuntimeException;

/**
 * The handful of WordPress functions the admin screen calls, and a mutable
 * state object the tests drive them with.
 *
 * This is not a WordPress test suite and does not pretend to be one. It exists
 * so the *authorization and delivery path* — capability, nonce, headers, and
 * the guarantee that nothing is written to disk — can be asserted in ordinary
 * PHPUnit. The real WordPress behaviour of these functions is not under test;
 * the way the plugin uses them is.
 */
final class WordPressState
{
    public static ?self $current = null;

    public bool $userCan = true;
    public bool $nonceValid = true;

    /** @var array<string,mixed> */
    public array $options = [];

    /** @var list<string> */
    public array $headers = [];

    public string $output = '';
    public bool $terminated = false;
    public ?string $redirect = null;

    public static function reset(): self
    {
        self::$current = new self();

        return self::$current;
    }

    public static function get(): self
    {
        return self::$current ??= new self();
    }
}

/** Thrown by the wp_die() stub so a denial is observable in a test. */
final class WordPressDied extends RuntimeException
{
}

}

namespace {

    use WooOps\Auditor\Tests\WordPressDied;
    use WooOps\Auditor\Tests\WordPressState;

    if (!function_exists('current_user_can')) {
        function current_user_can(string $capability): bool
        {
            return WordPressState::get()->userCan;
        }
    }

    if (!function_exists('check_admin_referer')) {
        function check_admin_referer(string $action, string $queryArg = '_wpnonce'): bool
        {
            // WordPress calls wp_nonce_ays() and dies on failure. So do we.
            if (!WordPressState::get()->nonceValid) {
                throw new WordPressDied('Nonce check failed: ' . $action);
            }

            return true;
        }
    }

    if (!function_exists('wp_die')) {
        function wp_die($message = '', $title = '', $args = []): void
        {
            throw new WordPressDied(is_string($message) ? $message : 'wp_die');
        }
    }

    if (!function_exists('get_option')) {
        function get_option(string $name, $default = false)
        {
            return WordPressState::get()->options[$name] ?? $default;
        }
    }

    if (!function_exists('update_option')) {
        function update_option(string $name, $value, $autoload = null): bool
        {
            WordPressState::get()->options[$name] = $value;

            return true;
        }
    }

    if (!function_exists('sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key) ?? '');
        }
    }

    if (!function_exists('wp_unslash')) {
        function wp_unslash($value)
        {
            return is_string($value) ? stripslashes($value) : $value;
        }
    }

    if (!function_exists('nocache_headers')) {
        function nocache_headers(): void
        {
            WordPressState::get()->headers[] = 'Cache-Control: no-cache, must-revalidate, max-age=0';
        }
    }

    if (!function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . ltrim($path, '/');
        }
    }

    if (!function_exists('wp_safe_redirect')) {
        function wp_safe_redirect(string $location, int $status = 302): bool
        {
            WordPressState::get()->redirect = $location;

            return true;
        }
    }

    if (!function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }
}
