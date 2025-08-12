<?php
declare(strict_types=1);

// Global error/exception/shutdown handler that will log and alert
// These should be included early in the application lifecycle

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


/**
 * alert-on-log-levels.php
 * Sends a single summary email at the end of a run if the current log contains WARNING/ERROR.
 *
 * Depends on existing helpers defined elsewhere in the project:
 *  - cfg()
 *  - getCurrentLogContents()
 *  - buildAlertSubject()
 *  - sendAlertEmail()
 */
function alertOnLogLevelsIfNeeded(): void
{
    try {
        // Feature toggle
        $shouldScan = (string)cfg('ALERT_ON_LOG_WARNINGS', '') === '1';
        if (!$shouldScan) {
            return;
        }

        // WARNING (default) => match WARNING or ERROR
        // ERROR => only match ERROR
        $minLevel = strtoupper((string)cfg('ALERT_MIN_LEVEL', 'WARNING'));
        $pattern = $minLevel === 'ERROR'
            ? '/\[(ERROR)\]/'
            : '/\[(WARNING|ERROR)\]/';

        $log = getCurrentLogContents();
        if ($log === '') {
            return;
        }

        if (!preg_match($pattern, $log)) {
            return; // nothing to report
        }

        $subject = buildAlertSubject('Warnings/Errors Detected');
        $body  = "Warnings/Errors were detected in the latest run log.\n\n";
        $body .= "--- Log ---\n" . $log;

        // Best-effort; avoid throwing from here
        @sendAlertEmail($subject, $body);
    } catch (Throwable $e) {
        // Intentionally swallow to avoid affecting the main run
    }
}
