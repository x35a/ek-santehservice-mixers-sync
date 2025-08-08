<?php
declare(strict_types=1);

require __DIR__ . '/fetch-ek-products.php';

// This script intentionally triggers an uncaught exception to test global handlers and email alerts

safeLog('info', 'test_trigger_exception_start');

throw new RuntimeException('Intentional test exception from test-trigger-exception.php');


