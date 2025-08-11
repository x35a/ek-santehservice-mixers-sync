<?php
declare(strict_types=1);

/**
 * Batch builder that merges three JSON payloads into a single WooCommerce batch payload.
 *
 * Inputs are paths to JSON files created by:
 * - find-new-mixers.php      -> { create: [...] }
 * - find-outofstock-mixers.php -> { update: [...] }
 * - find-outdated-mixers.php -> { update: [...] }
 *
 * Output shape:
 * {
 *   "create": [...],
 *   "update": [...]
 * }
 */

require_once __DIR__ . '/logging.php';

/**
 * Read JSON file into associative array (best-effort). Returns empty array on error.
 *
 * @return array<string, mixed>
 */
function readJsonAssoc(string $path): array
{
    if ($path === '' || !is_file($path)) {
        safeLog('warning', 'batch_read_json_missing', ['path' => $path]);
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        safeLog('warning', 'batch_read_json_failed', ['path' => $path]);
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        safeLog('warning', 'batch_read_json_decode_error', ['path' => $path]);
        return [];
    }
    // Ensure associative
    return $data;
}

/**
 * Merge two update objects for the same product id. Later fields win.
 *
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 * @return array<string, mixed>
 */
function mergeUpdateObjects(array $a, array $b): array
{
    // Keep id from either (they should be the same)
    $idA = (int)($a['id'] ?? 0);
    $idB = (int)($b['id'] ?? 0);
    $id = $idB > 0 ? $idB : $idA;
    $merged = $a;
    foreach ($b as $k => $v) {
        $merged[$k] = $v;
    }
    if ($id > 0) { $merged['id'] = $id; }
    return $merged;
}

/**
 * Build combined payload from three assoc arrays.
 *
 * @param array<string, mixed> $new
 * @param array<string, mixed> $outOfStock
 * @param array<string, mixed> $outdated
 * @return array{create: array<int, array<string, mixed>>, update: array<int, array<string, mixed>>}
 */
function buildCombinedBatchPayload(array $new, array $outOfStock, array $outdated): array
{
    $create = [];
    if (isset($new['create']) && is_array($new['create'])) {
        // Validate each item is array
        foreach ($new['create'] as $item) {
            if (is_array($item)) { $create[] = $item; }
        }
    }

    // Collect updates from both sources
    $updatesRaw = [];
    if (isset($outOfStock['update']) && is_array($outOfStock['update'])) {
        foreach ($outOfStock['update'] as $u) { if (is_array($u)) { $updatesRaw[] = $u; } }
    }
    if (isset($outdated['update']) && is_array($outdated['update'])) {
        foreach ($outdated['update'] as $u) { if (is_array($u)) { $updatesRaw[] = $u; } }
    }

    // Deduplicate by id and merge fields when same id appears in both
    $updateById = [];
    foreach ($updatesRaw as $u) {
        $id = (int)($u['id'] ?? 0);
        if ($id <= 0) { continue; }
        if (!isset($updateById[$id])) {
            $updateById[$id] = $u;
        } else {
            $updateById[$id] = mergeUpdateObjects($updateById[$id], $u);
        }
    }
    // Reindex to sequential array
    $update = array_values($updateById);

    return [
        'create' => $create,
        'update' => $update,
    ];
}

/**
 * Dump combined batch payload into data-example directory.
 * Returns absolute path.
 *
 * @param array<string, mixed> $payload
 */
function dumpBatchUpdatePayload(array $payload, ?string $dumpFilename = null): string
{
    $dumpDir = __DIR__ . DIRECTORY_SEPARATOR . 'data-example';
    if (!is_dir($dumpDir)) { @mkdir($dumpDir, 0777, true); }

    $filename = $dumpFilename ?? 'batch-update-mixers-json-payload.json';
    $dumpPath = $dumpDir . DIRECTORY_SEPARATOR . $filename;

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
        @file_put_contents($dumpPath, $json . PHP_EOL);
        safeLog('info', 'batch_update_payload_dumped', [
            'path' => $dumpPath,
            'bytes' => strlen($json),
            'create_count' => is_array($payload['create'] ?? null) ? count($payload['create']) : 0,
            'update_count' => is_array($payload['update'] ?? null) ? count($payload['update']) : 0,
        ]);
    }
    return $dumpPath;
}

/**
 * Entry point used by main.php after individual payloads are generated.
 * Returns absolute path to the combined JSON dump.
 */
function runBatchUpdateMixers(string $newProductsJsonPath, string $outOfStockJsonPath, string $outdatedJsonPath): string
{
    safeLog('info', 'batch_update_start', [
        'new_path' => $newProductsJsonPath,
        'outofstock_path' => $outOfStockJsonPath,
        'outdated_path' => $outdatedJsonPath,
    ]);

    $new = readJsonAssoc($newProductsJsonPath);
    $oos = readJsonAssoc($outOfStockJsonPath);
    $odt = readJsonAssoc($outdatedJsonPath);

    $payload = buildCombinedBatchPayload($new, $oos, $odt);
    $dumpPath = dumpBatchUpdatePayload($payload);

    safeLog('info', 'batch_update_complete', [
        'dump_path' => $dumpPath,
        'create_count' => is_array($payload['create'] ?? null) ? count($payload['create']) : 0,
        'update_count' => is_array($payload['update'] ?? null) ? count($payload['update']) : 0,
    ]);

    return $dumpPath;
}

