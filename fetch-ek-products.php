<?php
declare(strict_types=1);

// Configuration loader from local config.php
function getConfig(): array
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

function cfg(string $key, mixed $default = null): mixed
{
    $conf = getConfig();
    return array_key_exists($key, $conf) ? $conf[$key] : $default;
}

// Lightweight file logger. Writes ISO8601 timestamps, level, message, and JSON context.
function cleanupOldLogs(string $dir, int $maxAgeDays): void
{
    if (!is_dir($dir)) {
        return;
    }
    $threshold = time() - ($maxAgeDays * 86400);
    $entries = @scandir($dir) ?: [];
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            continue;
        }
        if (!str_ends_with($name, '.log')) {
            continue;
        }
        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime < $threshold) {
            @unlink($path);
        }
    }
}

function getLogPath(): string
{
    static $cachedPath = null;
    if ($cachedPath !== null) {
        return $cachedPath;
    }

    $custom = cfg('WC_LOG_FILE');
    if (is_string($custom) && $custom !== '') {
        return $cachedPath = $custom;
    }

    $dir = cfg('WC_LOG_DIR');
    if (!is_string($dir) || $dir === '') {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        // Fallback to script directory if logs/ is not writable
        $dir = __DIR__;
    }

    // Keep only last 7 days of logs
    cleanupOldLogs($dir, 7);

    $filename = date('Y-m-d-H-i-s') . '-' . str_replace('.', '', uniqid('', true)) . '.log';
    $cachedPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    return $cachedPath;
}

function safeLog(string $level, string $message, array $context = []): void
{
    try {
        $includeRunId = ((string)cfg('WC_LOG_INCLUDE_RUN_ID', '') === '1');
        $includeScript = ((string)cfg('WC_LOG_INCLUDE_SCRIPT', '') === '1');

        static $runId = null;
        if ($includeRunId && $runId === null) {
            $runId = str_replace('.', '', uniqid('', true));
        }

        $script = $includeScript ? basename($_SERVER['SCRIPT_FILENAME'] ?? __FILE__) : null;

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $trace[1] ?? [];
        $callerFunction = null;
        if (isset($caller['class'], $caller['type'], $caller['function'])) {
            $callerFunction = $caller['class'] . $caller['type'] . $caller['function'];
        } else {
            $callerFunction = $caller['function'] ?? null;
        }
        $callerFile = $caller['file'] ?? null;
        $callerLine = $caller['line'] ?? null;

        $relativePath = null;
        if (is_string($callerFile) && $callerFile !== '') {
            $prefix = __DIR__ . DIRECTORY_SEPARATOR;
            if (str_starts_with($callerFile, $prefix)) {
                $relativePath = substr($callerFile, strlen($prefix));
            } else {
                $relativePath = basename($callerFile);
            }
        }

        $autoContext = [];
        if ($includeRunId && $runId !== null) {
            $autoContext['run_id'] = $runId;
        }
        if ($includeScript && $script !== null) {
            $autoContext['script'] = $script;
        }
        if ($relativePath !== null) {
            $autoContext['file'] = $relativePath;
        }
        if ($callerLine !== null) {
            $autoContext['line'] = $callerLine;
        }
        if ($callerFunction !== null) {
            $autoContext['func'] = $callerFunction;
        }

        // Merge user context, user-supplied keys win
        foreach ($context as $k => $v) {
            $autoContext[$k] = $v;
        }

        $line = sprintf('%s [%s] %s', date('c'), strtoupper($level), $message);
        if (!empty($autoContext)) {
            $line .= ' ' . json_encode($autoContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $line .= PHP_EOL;
        @file_put_contents(getLogPath(), $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // Intentionally ignore logging failures to avoid breaking runtime on restrictive hosts
    }
}

// Compose and send an alert email. Safe to call from error/shutdown handlers.
function sendAlertEmail(string $subject, string $body): bool
{
    try {
        $to = (string)cfg('ALERT_EMAIL_TO', '');
        if ($to === '') {
            return false; // alerts disabled
        }

        $fromName  = (string)cfg('ALERT_EMAIL_FROM_NAME', 'EK Santehservice Sync');
        $fromEmail = (string)cfg('ALERT_EMAIL_FROM_EMAIL', '');

        if ($fromEmail === '') {
            // Derive a safe default like no-reply@<host>
            $host = parse_url((string)cfg('WC_SITE_URL', ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
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

// Build a default alert email subject
function buildAlertSubject(string $kind = 'Error'): string
{
    $host = parse_url((string)cfg('WC_SITE_URL', ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = is_string($host) && $host !== '' ? $host : 'localhost';
    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
    return sprintf('[Mixers Sync] %s in %s on %s', $kind, $script, $host);
}

// Collect latest log content for this run (best-effort)
function getCurrentLogContents(): string
{
    try {
        $path = getLogPath();
        if (is_file($path)) {
            $size = filesize($path);
            if ($size === false) {
                return '';
            }
            // Read up to last 100 KB to keep emails reasonable
            $max = 100 * 1024;
            if ($size > $max) {
                $fp = @fopen($path, 'rb');
                if ($fp) {
                    fseek($fp, -$max, SEEK_END);
                    $data = stream_get_contents($fp) ?: '';
                    fclose($fp);
                    return "(truncated to last 100KB)\n\n" . $data;
                }
            }
            return (string)file_get_contents($path);
        }
    } catch (Throwable $e) {
    }
    return '';
}

// Register global error/exception/shutdown handlers that will log and alert
set_error_handler(static function (int $severity, string $message, ?string $file = null, ?int $line = null): bool {
    // Respect error_reporting level: if @ operator used, error_reporting() returns 0
    if (!(error_reporting() & $severity)) {
        return false; // let PHP handle
    }
    $context = ['severity' => $severity];
    if ($file !== null) {
        $context['file'] = $file;
    }
    if ($line !== null) {
        $context['line'] = $line;
    }
    safeLog('error', 'php_error', $context);
    $subject = buildAlertSubject('PHP Error');
    $body = "A PHP error occurred.\n\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    $log = getCurrentLogContents();
    if ($log !== '') {
        $body .= "--- Log ---\n" . $log;
    }
    sendAlertEmail($subject, $body);
    return true; // handled
});

set_exception_handler(static function (Throwable $e): void {
    $context = [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
    safeLog('error', 'uncaught_exception', $context);
    $subject = buildAlertSubject('Unhandled Exception');
    $body = "An unhandled exception occurred.\n\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    $log = getCurrentLogContents();
    if ($log !== '') {
        $body .= "--- Log ---\n" . $log;
    }
    sendAlertEmail($subject, $body);
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $context = [
            'type' => $err['type'],
            'message' => $err['message'] ?? '',
            'file' => $err['file'] ?? '',
            'line' => $err['line'] ?? 0,
        ];
        safeLog('error', 'fatal_shutdown', $context);
        $subject = buildAlertSubject('Fatal Error');
        $body = "A fatal error occurred during shutdown.\n\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        $log = getCurrentLogContents();
        if ($log !== '') {
            $body .= "--- Log ---\n" . $log;
        }
        sendAlertEmail($subject, $body);
    }
});

// HTTP GET helper using cURL, with fallback to streams if cURL missing
function httpGet(string $url, array $headers = [], int $timeoutSeconds = 30): array
{
    $headers = array_values($headers);
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($ch, CURLOPT_USERAGENT, 'ek-santehservice-mixers-sync/1.0');
        $body = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err   = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($errNo !== 0) {
            throw new RuntimeException('cURL error: ' . $err);
        }
        if ($status >= 400) {
            throw new RuntimeException('HTTP error: ' . $status . ' for ' . $url . ' body: ' . (is_string($body) ? $body : ''));
        }
        return [$status, (string) $body];
    }
    // Streams fallback
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
    }
    if ($body === false) {
        throw new RuntimeException('HTTP request failed for ' . $url);
    }
    if ($status >= 400) {
        throw new RuntimeException('HTTP error: ' . $status . ' for ' . $url . ' body: ' . $body);
    }
    return [$status, (string) $body];
}

/**
 * Determine whether a product contains the specified WooCommerce category id.
 */
function productHasCategoryId(array $product, int $categoryId): bool
{
    if (!isset($product['categories']) || !is_array($product['categories'])) {
        return false;
    }
    foreach ($product['categories'] as $category) {
        if (is_array($category) && (int)($category['id'] ?? 0) === $categoryId) {
            return true;
        }
    }
    return false;
}

/**
 * Dump EK products to data-example directory.
 * Returns absolute path to the written file.
 *
 * @param array<int, array<string, mixed>> $ekProducts
 * @param string|null $dumpFilename Optional custom filename (defaults to ek-products.json)
 * @return string
 */
function dumpEkProducts(array $ekProducts, ?string $dumpFilename = null): string
{
    $dumpDir = __DIR__ . DIRECTORY_SEPARATOR . 'data-example';
    if (!is_dir($dumpDir)) {
        @mkdir($dumpDir, 0777, true);
    }

    $filename = $dumpFilename ?? 'ek-products.json';
    $dumpPath = $dumpDir . DIRECTORY_SEPARATOR . $filename;

    $json = json_encode($ekProducts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
        @file_put_contents($dumpPath, $json . PHP_EOL);
        if (function_exists('safeLog')) {
            safeLog('info', 'ek_products_dumped', [
                'path' => $dumpPath,
                'bytes' => strlen($json),
            ]);
        }
    }

    return $dumpPath;
}

// Public function to fetch WooCommerce products based on env config
function fetchEkProducts(): array
{
    $siteUrl = (string)cfg('WC_SITE_URL', '');
    $username = (string)cfg('WC_API_USERNAME', '');
    $password = (string)cfg('WC_API_PASSWORD', '');
    $perPage  = (int)cfg('WC_PER_PAGE', 100);
    if ($perPage <= 0 || $perPage > 100) {
        $perPage = 100;
    }
    if ($siteUrl === '' || $username === '' || $password === '') {
        throw new RuntimeException('Missing required config values. Please set WC_SITE_URL, WC_API_USERNAME, WC_API_PASSWORD (and optionally WC_PER_PAGE).');
    }

    $parsedUrl = parse_url($siteUrl) ?: [];
    $scheme = isset($parsedUrl['scheme']) ? strtolower((string)$parsedUrl['scheme']) : '';
    $isHttps = ($scheme === 'https');
    $envForceQueryAuth = ((string)cfg('WC_QUERY_STRING_AUTH', '') === '1');
    $useQueryAuth = (!$isHttps) || $envForceQueryAuth;

    $apiBase = rtrim($siteUrl, '/') . '/wp-json/wc/v3/products';

    safeLog('info', 'ek_products_fetch_start', ['per_page' => $perPage, 'query_auth' => $useQueryAuth ? 1 : 0]);

    $pageNumber = 1;
    $allProducts = [];

    try {
        do {
            $query = [
                'per_page' => $perPage,
                'page' => $pageNumber,
            ];
            if ($useQueryAuth) {
                $query['consumer_key'] = $username;
                $query['consumer_secret'] = $password;
            }
            $url = $apiBase . '?' . http_build_query($query);

            $headers = [
                'Accept: application/json',
            ];
            if (!$useQueryAuth) {
                $headers[] = 'Authorization: Basic ' . base64_encode($username . ':' . $password);
            }

            safeLog('info', 'ek_products_request', ['page' => $pageNumber]);
            [, $body] = httpGet($url, $headers, 60);
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Unexpected response format');
            }
            $count = count($decoded);
            safeLog('info', 'ek_products_page_fetched', ['page' => $pageNumber, 'items' => $count]);
            if ($count > 0) {
                $allProducts = array_merge($allProducts, $decoded);
            }
            $pageNumber++;
        } while ($count === $perPage);
    } catch (Throwable $e) {
        safeLog('error', 'fetch failed', ['page' => $pageNumber, 'error' => $e->getMessage()]);
        throw $e;
    }

    safeLog('info', 'ek_products_fetch_complete', ['total' => count($allProducts)]);

    // Keep only products from the desired category (default: 121)
    $categoryId = (int)cfg('WC_CATEGORY_ID', 121);
    $filteredProducts = array_values(array_filter(
        $allProducts,
        static function (array $product) use ($categoryId): bool {
            return productHasCategoryId($product, $categoryId);
        }
    ));

    safeLog('info', 'ek_products_filter_applied', [
        'category_id' => $categoryId,
        'before' => count($allProducts),
        'after' => count($filteredProducts),
    ]);
    
    // Dump processed EK products for debugging/inspection
    try {
        $ekDumpPath = dumpEkProducts($filteredProducts);
        safeLog('info', 'ek_products_dump_path', ['path' => $ekDumpPath]);
    } catch (Throwable $e) {
        safeLog('error', 'ek_products_dump_failed', ['error' => $e->getMessage()]);
    }
    
    return $filteredProducts;
}


