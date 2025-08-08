<?php
declare(strict_types=1);

// Optional tiny .env loader (no external libs). Only KEY=VALUE lines, ignores comments and quotes.
// Values loaded only if corresponding env var not already set.
function loadEnvFileIfPresent(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
            continue;
        }
        $pos = strpos($trimmed, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($trimmed, 0, $pos));
        $value = trim(substr($trimmed, $pos + 1));
        // Remove surrounding quotes if present
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

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

// Public function to fetch WooCommerce products based on env config
function fetchSantehserviceMixersProducts(): array
{
    loadEnvFileIfPresent(__DIR__ . '/.env');

    $siteUrl = $_ENV['WC_SITE_URL'] ?? getenv('WC_SITE_URL') ?: '';
    $username = $_ENV['WC_API_USERNAME'] ?? getenv('WC_API_USERNAME') ?: '';
    $password = $_ENV['WC_API_PASSWORD'] ?? getenv('WC_API_PASSWORD') ?: '';
    $perPage  = (int)(($_ENV['WC_PER_PAGE'] ?? getenv('WC_PER_PAGE') ?: 100));
    if ($perPage <= 0 || $perPage > 100) {
        $perPage = 100;
    }
    if ($siteUrl === '' || $username === '' || $password === '') {
        throw new RuntimeException('Missing required env vars. Please set WC_SITE_URL, WC_API_USERNAME, WC_API_PASSWORD (and optionally WC_PER_PAGE).');
    }

    $parsedUrl = parse_url($siteUrl) ?: [];
    $scheme = isset($parsedUrl['scheme']) ? strtolower((string)$parsedUrl['scheme']) : '';
    $isHttps = ($scheme === 'https');
    $envForceQueryAuth = ($_ENV['WC_QUERY_STRING_AUTH'] ?? getenv('WC_QUERY_STRING_AUTH') ?? '') === '1';
    $useQueryAuth = (!$isHttps) || $envForceQueryAuth;

    $apiBase = rtrim($siteUrl, '/') . '/wp-json/wc/v3/products';

    $pageNumber = 1;
    $allProducts = [];

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

        [, $body] = httpGet($url, $headers, 60);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected response format');
        }
        $count = count($decoded);
        if ($count > 0) {
            $allProducts = array_merge($allProducts, $decoded);
        }
        $pageNumber++;
    } while ($count === $perPage);

    return $allProducts;
}


