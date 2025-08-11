<?php
// logging.php: Lightweight file logger utilities

declare(strict_types=1);

require_once __DIR__ . '/config.php';

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

    // Keep only last 5 days of logs
    cleanupOldLogs($dir, 5);

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
