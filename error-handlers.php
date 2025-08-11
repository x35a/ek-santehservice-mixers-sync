<?php
declare(strict_types=1);

// Global error/exception/shutdown handlers that will log and alert
// These should be included early in the application lifecycle

// Register global error handler for PHP errors (E_WARNING, E_NOTICE, etc.)
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

// Register global exception handler for uncaught exceptions
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

// Register shutdown function for fatal errors
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
