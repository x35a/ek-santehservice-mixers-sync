<?php
declare(strict_types=1);

/**
 * Build index by SKU for fast lookups.
 *
 * @param array<int, array<string, mixed>> $items
 * @param string $skuKey
 * @return array<string, array<string, mixed>>
 */
function indexBySku(array $items, string $skuKey = 'sku'): array
{
    $idx = [];
    foreach ($items as $item) {
        $sku = (string)($item[$skuKey] ?? '');
        if ($sku === '') { continue; }
        $idx[$sku] = $item;
    }
    return $idx;
}

/**
 * Normalize comparable fields to avoid false positives (trim and collapse spaces).
 */
function normText(?string $s): string
{
    $s = (string)($s ?? '');
    $s = trim($s);
    // Collapse all whitespace sequences to single space
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return $s;
}

/**
 * Compare EK vs Santeh fields for a single product, return update object if differences exist.
 *
 * Rules:
 * - Compare name (ek.name vs santeh.name), use santeh value if differs
 * - Compare price (ek.regular_price vs santeh.price), use santeh price as regular_price if differs
 * - If santeh.available === true and ek.stock_status === 'outofstock', set stock_status to 'instock'
 *
 * @param array<string, mixed> $ek
 * @param array<string, mixed> $santeh
 * @return array<string, mixed>|null  Update object: { id, [regular_price], [name] }
 */
function buildOutdatedUpdateForOne(array $ek, array $santeh): ?array
{
    $id = (int)($ek['id'] ?? 0);
    $sku = (string)($ek['sku'] ?? '');
    if ($id <= 0 || $sku === '') { return null; }

    $ekName = normText((string)($ek['name'] ?? ''));
    $ekPrice = (float)($ek['regular_price'] ?? 0.0);

    $snName = normText((string)($santeh['name'] ?? ''));
    $snPrice = (float)($santeh['price'] ?? 0.0);
    $snAvailable = (bool)($santeh['available'] ?? false);
    $ekStockStatus = (string)($ek['stock_status'] ?? '');

    $update = ['id' => $id];
    $changed = false;

    if ($ekName !== $snName && $snName !== '') {
        $update['name'] = $snName;
        $changed = true;
    }
    // Compare price with 2-decimal rounding to avoid tiny float diffs
    if (round($ekPrice, 2) !== round($snPrice, 2)) {
        $update['regular_price'] = (string)round($snPrice, 2);
        $changed = true;
    }
    // Stock status rule: if santeh says available=true and EK has outofstock -> switch to instock
    if ($snAvailable === true && $ekStockStatus === 'outofstock') {
        $update['stock_status'] = 'instock';
        $changed = true;
    }

    if (!$changed) { return null; }

    if (function_exists('safeLog')) {
        $fields = array_keys($update);
        // remove id from fields list
        $fields = array_values(array_filter($fields, fn($k) => $k !== 'id'));
        safeLog('info', 'outdated_mixer_found', [
            'message' => "mixer outdated - SKU: {$sku}",
            'sku' => $sku,
            'id' => $id,
            'changed_fields' => $fields,
        ]);
    }

    return $update;
}

/**
 * Build the batch update payload for outdated mixers.
 *
 * @param array<int, array<string, mixed>> $ekProducts
 * @param array<int, array<string, mixed>> $santehTransformed
 * @return array{update: array<int, array<string, mixed>>}
 */
function buildOutdatedUpdatePayload(array $ekProducts, array $santehTransformed): array
{
    $ekBySku = indexBySku($ekProducts, 'sku');
    $snBySku = indexBySku($santehTransformed, 'sku');

    $commonSkus = array_values(array_intersect(array_keys($ekBySku), array_keys($snBySku)));

    $update = [];
    foreach ($commonSkus as $sku) {
        $ek = $ekBySku[$sku];
        $sn = $snBySku[$sku];
        $u = buildOutdatedUpdateForOne($ek, $sn);
        if ($u !== null) {
            $update[] = $u;
        }
    }

    return ['update' => $update];
}



/**
 * Execute outdated mixers detection and dump JSON payload.
 * Returns absolute path of the dumped JSON payload.
 *
 * @param array<int, array<string, mixed>> $ekProducts
 * @param array<int, array<string, mixed>> $santehTransformed
 * @return string
 */
function runFindOutdatedMixers(array $ekProducts, array $santehTransformed): string
{
    $payload = buildOutdatedUpdatePayload($ekProducts, $santehTransformed);

    $dumpPath = dumpData($payload, 'outdated_payload', 'update-mixers-json-payload.json');

    if (function_exists('safeLog')) {
        safeLog('info', 'find_outdated_mixers_complete', [
            'ek_total' => count($ekProducts),
            'santeh_transformed_total' => count($santehTransformed),
            'outdated_products' => is_array($payload['update'] ?? null) ? count($payload['update']) : 0,
            'dump_path' => $dumpPath,
        ]);
    }

    return $dumpPath;
}
