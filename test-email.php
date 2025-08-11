<?php
declare(strict_types=1);

require __DIR__ . '/send-email.php';

$subject = buildAlertSubject('Test Email');
$body = "This is a test email sent at " . date('c');

$result = sendAlertEmail($subject, $body);
echo "sendAlertEmail returned: " . var_export($result, true) . PHP_EOL;