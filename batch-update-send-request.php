<?php
declare(strict_types=1);

// Sends the combined batch JSON payload to WooCommerce products/batch endpoint
// and dumps server response to data-example/batch-update-server-response.json

require_once __DIR__ . '/logging.php';

/**
 * Minimal HTTP POST JSON helper. Uses cURL when available, falls back to streams.
 * Returns array [statusCode, bodyString]. Never throws; network/transport failures
 * are represented as status 0 with an error message in the body where possible.
 *
 * @param string $url
 * @param string $jsonBody
 * @param string[] $headers
 * @param int $timeoutSeconds
 * @return array{0:int,1:string}
 */
function httpPostJson(string $url, string $jsonBody, array $headers = [], int $timeoutSeconds = 60): array
{
    $headers = array_values($headers);

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
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
            return [0, 'cURL error: ' . $err];
        }
        return [$status, (string)($body === false ? '' : $body)];
    }

    // Streams fallback
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $jsonBody,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true, // fetch body even on 4xx/5xx
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
        return [0, 'HTTP POST failed for ' . $url];
    }
    return [$status, (string)$body];
}



/**
 * Entry point called from main.php after runBatchUpdateMixers().
 * Accepts the batch payload array and returns path to server response dump.
 */
function runBatchUpdateSendRequest(array $batchPayload): string
{
    $siteUrl = (string)cfg('WC_SITE_URL', '');
    $username = (string)cfg('WC_API_USERNAME', '');
    $password = (string)cfg('WC_API_PASSWORD', '');
    if ($siteUrl === '' || $username === '' || $password === '') {
        throw new RuntimeException('Missing required config values for WooCommerce API.');
    }
    // Encode payload
    $json = json_encode($batchPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException('Failed to encode batch payload to JSON.');
    }

    // Determine auth method (query params on HTTP or when forced by config)
    $parsedUrl = parse_url($siteUrl) ?: [];
    $scheme = isset($parsedUrl['scheme']) ? strtolower((string)$parsedUrl['scheme']) : '';
    $isHttps = ($scheme === 'https');
    $envForceQueryAuth = ((string)cfg('WC_QUERY_STRING_AUTH', '') === '1');
    $useQueryAuth = (!$isHttps) || $envForceQueryAuth;

    $endpoint = rtrim($siteUrl, '/') . '/wp-json/wc/v3/products/batch';
    if ($useQueryAuth) {
        $qs = http_build_query([
            'consumer_key' => $username,
            'consumer_secret' => $password,
        ]);
        $endpoint .= (str_contains($endpoint, '?') ? '&' : '?') . $qs;
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    if (!$useQueryAuth) {
        $headers[] = 'Authorization: Basic ' . base64_encode($username . ':' . $password);
    }

    safeLog('info', 'batch_update_send_start', [
        'endpoint' => $endpoint,
        'query_auth' => $useQueryAuth ? 1 : 0,
        'payload_bytes' => strlen($json),
        'create_count' => is_array($batchPayload['create'] ?? null) ? count($batchPayload['create']) : 0,
        'update_count' => is_array($batchPayload['update'] ?? null) ? count($batchPayload['update']) : 0,
    ]);

    // Perform request
    [$status, $body] = httpPostJson($endpoint, $json, $headers, 120);

    // Dump server response regardless of status
    try {
        $dumpPath = dumpData(['response' => (string)$body], 'batch_update_server_response', 'batch-update-server-response.json');
    } catch (Throwable $e) {
        $dumpPath = '';
        safeLog('error', 'batch_update_response_dump_failed', ['error' => $e->getMessage()]);
    }

    if ($status === 0) {
        safeLog('error', 'batch_update_send_transport_error', [
            'status' => $status,
            'error' => $body,
        ]);
        // Also alert via email as it indicates connectivity issue
        $subject = buildAlertSubject('Batch Send Transport Error');
        $msg = [
            'endpoint' => $endpoint,
            'status' => $status,
            'error' => $body,
        ];
        $log = getCurrentLogContents();
        $bodyEmail = "Transport error while sending batch update.\n\n" . json_encode($msg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        if ($log !== '') { $bodyEmail .= "--- Log ---\n" . $log; }
        sendAlertEmail($subject, $bodyEmail);
        // Still return dump path (may be empty string if dump failed)
        return $dumpPath;
    }

    if ($status >= 400) {
        safeLog('error', 'batch_update_send_http_error', [
            'status' => $status,
            // Body may include WooCommerce error details
        ]);
        $subject = buildAlertSubject('Batch Send HTTP Error');
        $msg = [
            'endpoint' => $endpoint,
            'status' => $status,
        ];
        $log = getCurrentLogContents();
        $bodyEmail = "HTTP error while sending batch update.\n\n" . json_encode($msg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        if ($log !== '') { $bodyEmail .= "--- Log ---\n" . $log; }
        sendAlertEmail($subject, $bodyEmail);
        return $dumpPath;
    }

    // Success
    safeLog('info', 'batch_update_send_success', [
        'status' => $status,
        'response_bytes' => strlen((string)$body),
        'response_dump_path' => $dumpPath,
    ]);
    return $dumpPath;
}

