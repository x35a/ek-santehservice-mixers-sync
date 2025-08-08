<?php
declare(strict_types=1);

require __DIR__ . '/fetch-ek-products.php';

// Simple self-test for email delivery using current config

// Allow optional query params when run via web server
$subjectParam   = isset($_GET['subject']) ? (string)$_GET['subject'] : null;
$includeLogFlag = isset($_GET['include_log']) ? (string)$_GET['include_log'] : '1';
$includeLog     = $includeLogFlag !== '0';

// Build subject and body
$subject = $subjectParam !== null && $subjectParam !== ''
    ? $subjectParam
    : buildAlertSubject('Test Alert');

safeLog('info', 'test_email_invoked');

$to        = (string)cfg('ALERT_EMAIL_TO', '');
$fromName  = (string)cfg('ALERT_EMAIL_FROM_NAME', 'EK Santehservice Sync');
$fromEmail = (string)cfg('ALERT_EMAIL_FROM_EMAIL', '');
if ($fromEmail === '') {
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

$lines = [];
$lines[] = 'This is a test alert email for ek-santehservice-mixers-sync.';
$lines[] = 'Date: ' . date('c');
$lines[] = 'Host: ' . ((string)(parse_url((string)cfg('WC_SITE_URL', ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost')));
$lines[] = 'Script: ' . basename(__FILE__);
$lines[] = '';
if ($includeLog) {
    $log = getCurrentLogContents();
    $lines[] = '--- Log (current run) ---';
    $lines[] = $log !== '' ? $log : '(no log available)';
}

$body = implode("\n", $lines);
$body = wordwrap($body, 998, "\n", true);

$result = false;
$error  = null;
try {
    if ($to === '') {
        throw new RuntimeException('ALERT_EMAIL_TO is empty in config.php');
    }
    // Use the same helper as production paths for full parity
    $result = sendAlertEmail($subject, $body);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$output = [
    'sent' => (bool)$result,
    'to' => $to,
    'from_name' => $fromName,
    'from_email' => $fromEmail,
    'subject' => $subject,
    'error' => $error,
];

// If run via CLI, print plain text; otherwise JSON
if (PHP_SAPI === 'cli') {
    echo ($output['sent'] ? 'OK' : 'FAIL') . PHP_EOL;
    if ($error !== null) {
        echo 'Error: ' . $error . PHP_EOL;
    }
    echo 'To: ' . $to . PHP_EOL;
    echo 'From: ' . $fromName . ' <' . $fromEmail . '>' . PHP_EOL;
    echo 'Subject: ' . $subject . PHP_EOL;
    exit($result ? 0 : 1);
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "\n";


