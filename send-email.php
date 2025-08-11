<?php
declare(strict_types=1);

// Email helpers extracted for reuse across scripts.
// This file intentionally implements its own minimal config loader to avoid
// naming collisions with other modules. It expects a local config.php file
// that returns an associative array (see config-example.php for the format).

/**
 * Load configuration from config.php located next to the scripts.
 * Path is hardcoded as requested.
 *
 * @return array<string, mixed>
 */
function email_get_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Missing config.php file with required configuration.');
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('config.php must return an associative array.');
    }
    return $config = $loaded;
}

/**
 * Safe getter for config values used by email helpers.
 */
function email_cfg(string $key, mixed $default = null): mixed
{
    $conf = email_get_config();
    return array_key_exists($key, $conf) ? $conf[$key] : $default;
}

/**
 * Build a default alert email subject.
 */
function buildAlertSubject(string $kind = 'Error'): string
{
    $host = parse_url((string)email_cfg('WC_SITE_URL', ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = is_string($host) && $host !== '' ? $host : 'localhost';
    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
    return sprintf('[Mixers Sync] %s in %s on %s', $kind, $script, $host);
}

/**
 * Compose and send an alert email. Safe to call from error/shutdown handlers.
 */
function sendAlertEmail(string $subject, string $body): bool
{
    try {
        $to = (string)email_cfg('ALERT_EMAIL_TO', '');
        if ($to === '') {
            return false; // alerts disabled
        }

        $fromName  = (string)email_cfg('ALERT_EMAIL_FROM_NAME', 'EK Santehservice Sync');
        $fromEmail = (string)email_cfg('ALERT_EMAIL_FROM_EMAIL', '');

        if ($fromEmail === '') {
            // Derive a safe default like no-reply@<host>
            $host = parse_url((string)email_cfg('WC_SITE_URL', ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $host = is_string($host) && $host !== '' ? $host : 'localhost';
            $fromEmail = 'no-reply@' . $host;
        }

        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
        ];

        // Best-effort: avoid very long lines in body
        $wrappedBody = wordwrap($body, 998, "\n", true);

        // mail() can fail silently in some environments; return its boolean result
        $result = @mail($to, $subject, $wrappedBody, implode("\r\n", $headers));
        return (bool)$result;
    } catch (Throwable $e) {
        // Avoid throwing from alert sender
        return false;
    }
}

