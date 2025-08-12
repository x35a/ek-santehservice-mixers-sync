<?php
declare(strict_types=1);

/**
 * Build a lookup set of Santehservice transformed SKUs for fast membership checks.
 *
 * @param array<int, array<string, mixed>> $santehTransformed
 * @return array<string, bool>
 */
function buildSantehSkuLookup(array $santehTransformed): array
{
    $lookup = [];
    foreach ($santehTransformed as $item) {
        $sku = (string)($item['sku'] ?? '');
        if ($sku !== '') {
            $lookup[$sku] = true;
        }
    }
    return $lookup;
}

/**
 * Build the batch "update" payload for WooCommerce to mark items as out of stock.
 *
 * For each EK product in $ekProducts, if its `sku` is NOT present in $santehSkuLookup,
 * add an update object: { id: <product_id>, stock_status: "outofstock" }.
 *
 * @param array<int, array<string, mixed>> $ekProducts
 * @param array<string, bool> $santehSkuLookup
 * @return array{update: array<int, array{id:int, stock_status:string}>}
 */
function buildOutOfStockUpdatePayload(array $ekProducts, array $santehSkuLookup): array
{
    $update = [];
    foreach ($ekProducts as $product) {
        $id = (int)($product['id'] ?? 0);
        $sku = (string)($product['sku'] ?? '');
        if ($id <= 0 || $sku === '') {
            continue; // skip invalid entries
        }
        // Skip if product is already marked as out of stock in EK dataset
        $stockStatus = strtolower((string)($product['stock_status'] ?? ''));
        if ($stockStatus === 'outofstock') {
            continue;
        }
        if (!isset($santehSkuLookup[$sku])) {
            // Not found in Santeh transformed -> mark out of stock
            if (function_exists('safeLog')) {
                safeLog('info', 'outofstock_mixer_found', [
                    'message' => "mixer out of stock - SKU: {$sku}",
                    'sku' => $sku,
                    'id' => $id,
                ]);
            }
            $update[] = [
                'id' => $id,
                'stock_status' => 'outofstock',
            ];
        }
    }
    return ['update' => $update];
}



/**
 * Execute out-of-stock discovery and dump JSON payload.
 * Returns absolute path of the dumped JSON payload.
 *
 * @param array<int, array<string, mixed>> $ekProducts
 * @param array<int, array<string, mixed>> $santehTransformed
 * @return string
 */
function runFindOutOfStockProducts(array $ekProducts, array $santehTransformed): string
{
    $santehSkuLookup = buildSantehSkuLookup($santehTransformed);
    $payload = buildOutOfStockUpdatePayload($ekProducts, $santehSkuLookup);

    $dumpPath = dumpData($payload, 'outofstock_payload', 'outofstock-mixers-json-payload.json');

    if (function_exists('safeLog')) {
        safeLog('info', 'find_outofstock_mixers_complete', [
            'ek_total' => count($ekProducts),
            'santeh_transformed_total' => count($santehTransformed),
            'outofstock_products' => is_array($payload['update'] ?? null) ? count($payload['update']) : 0,
            'dump_path' => $dumpPath,
        ]);
    }

    return $dumpPath;
}
