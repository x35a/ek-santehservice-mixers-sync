<?php
declare(strict_types=1);

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
            throw new RuntimeException(sprintf(
                'Unexpected response format on page %d. Expected array, got %s. Response: %s',
                $pageNumber,
                gettype($decoded),
                substr($body, 0, 200) . (strlen($body) > 200 ? '...' : '')
            ));
        }
        $count = count($decoded);
        safeLog('info', 'ek_products_page_fetched', ['page' => $pageNumber, 'items' => $count]);
        if ($count > 0) {
            $allProducts = array_merge($allProducts, $decoded);
        }
        $pageNumber++;
    } while ($count === $perPage);

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
    dumpData($filteredProducts, 'fetch_ek_products', 'ek-mixers.json', [
        'total_count' => count($filteredProducts),
        'category_id' => $categoryId,
        'filtered_from' => count($allProducts)
    ]);
    
    return $filteredProducts;
}


