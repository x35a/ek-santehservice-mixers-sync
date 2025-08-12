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
 * Combines multiple payloads into a single batch update payload.
 * 
 * @param array<string, mixed> $newProductsPayload Payload from runFindNewProducts
 * @param array<string, mixed> $outOfStockPayload Payload from runFindOutOfStockProducts
 * @param array<string, mixed> $outdatedPayload Payload from runFindOutdatedMixers
 * @return array<string, mixed> Combined payload
 */
function runBatchUpdateMixers(array $newProductsPayload, array $outOfStockPayload, array $outdatedPayload): array
{
    safeLog('info', 'batch_update_start', [
        'new_products' => is_array($newProductsPayload['create'] ?? null) ? count($newProductsPayload['create']) : 0,
        'outofstock_products' => is_array($outOfStockPayload['update'] ?? null) ? count($outOfStockPayload['update']) : 0,
        'outdated_products' => is_array($outdatedPayload['update'] ?? null) ? count($outdatedPayload['update']) : 0,
    ]);

    $payload = buildCombinedBatchPayload($newProductsPayload, $outOfStockPayload, $outdatedPayload);
    // Dump for debugging/traceability (non-blocking if write fails inside helper)
    try {
        $dumpPath = dumpData($payload, 'batch_update_merge_payloads', 'batch-update-json-payload.json');
    } catch (Throwable $e) {
        $dumpPath = '';
        safeLog('warning', 'batch_update_payload_dump_failed', ['error' => $e->getMessage()]);
    }

    safeLog('info', 'batch_update_complete', [
        'create_count' => is_array($payload['create'] ?? null) ? count($payload['create']) : 0,
        'update_count' => is_array($payload['update'] ?? null) ? count($payload['update']) : 0,
        'dump_path' => $dumpPath,
    ]);

    return $payload;
}
