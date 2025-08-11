<?php
declare(strict_types=1);

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
        $body  = "Warnings/Errors were detected in the latest run log.\n\n"; // TODO this must be content from log file - getCurrentLogContents()
        $body .= "--- Log ---\n" . $log;

        // Best-effort; avoid throwing from here
        @sendAlertEmail($subject, $body);
    } catch (Throwable $e) {
        // Intentionally swallow to avoid affecting the main run
    }
}
