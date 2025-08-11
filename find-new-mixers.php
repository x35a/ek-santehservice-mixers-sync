<?php
declare(strict_types=1);
/**
 * Build a lookup set of existing EK product SKUs for fast membership checks.
 *
 * @param array<int, array<string, mixed>> $ekProducts
 * @return array<string, bool>
 */
function buildEkSkuLookup(array $ekProducts): array
{
    $lookup = [];
    foreach ($ekProducts as $product) {
        $sku = (string)($product['sku'] ?? '');
        if ($sku !== '') {
            $lookup[$sku] = true;
        }
    }
    return $lookup;
}

/**
 * Map Santehservice transformed product to WooCommerce create payload shape.
 *
 * @param array<string, mixed> $santehProduct
 * @param int $categoryId
 * @return array<string, mixed>
 */
function mapSantehToWooCreate(array $santehProduct, int $categoryId): array
{
    $name = (string)($santehProduct['name'] ?? '');
    $description = (string)($santehProduct['description'] ?? '');
    $sku = (string)($santehProduct['sku'] ?? '');
    $price = (float)($santehProduct['price'] ?? 0.0);

    $pictures = [];
    if (isset($santehProduct['pictures']) && is_array($santehProduct['pictures'])) {
        foreach ($santehProduct['pictures'] as $p) {
            $src = trim((string)$p);
            if ($src !== '') {
                $pictures[] = ['src' => $src];
            }
        }
    }

    $attributes = [];
    if (isset($santehProduct['params']) && is_array($santehProduct['params'])) {
        foreach ($santehProduct['params'] as $idx => $param) {
            $paramName = trim((string)($param['name'] ?? ''));
            $paramValue = trim((string)($param['value'] ?? ''));
            if ($paramName === '' || $paramValue === '') {
                continue;
            }
            $attributes[] = [
                'name' => $paramName,
                'options' => [$paramValue],
                'visible' => true,
                'variation' => false,
            ];
        }
    }

    $payload = [
        'name' => $name,
        'type' => 'simple',
        'regular_price' => (string)$price,
        'description' => $description,
    ];
    if ($sku !== '') {
        // Ensure SKU appears immediately after description in the JSON order
        $payload['sku'] = $sku;
    }
    $payload['categories'] = [
        ['id' => $categoryId],
    ];
    if (!empty($pictures)) {
        $payload['images'] = $pictures;
    }
    if (!empty($attributes)) {
        $payload['attributes'] = $attributes;
    }

    return $payload;
}

/**
 * Build the batch "create" payload for WooCommerce from Santehservice transformed array,
 * including only items whose SKU is not present in EK products.
 *
 * @param array<int, array<string, mixed>> $santehTransformed
 * @param array<string, bool> $ekSkuLookup
 * @param int $categoryId
 * @return array{create: array<int, array<string, mixed>>}
 */
function buildWooBatchCreatePayload(array $santehTransformed, array $ekSkuLookup, int $categoryId): array
{
    $create = [];
    foreach ($santehTransformed as $sProd) {
        $sku = (string)($sProd['sku'] ?? '');
        if ($sku === '') {
            continue; // skip items without SKU
        }
        if (isset($ekSkuLookup[$sku])) {
            continue; // already exists on EK
        }
        // Log each newly discovered mixer by SKU
        if (function_exists('safeLog')) {
            safeLog('info', 'find_new_mixers_new_mixer_found', [
                'message' => "new mixer found - SKU: {$sku}",
                'sku' => $sku,
            ]);
        }
        $create[] = mapSantehToWooCreate($sProd, $categoryId);
    }
    return ['create' => $create];
}

/**
 * Dump payload JSON into data-example directory.
 * Returns absolute path to the written file.
 *
 * @param array<string, mixed> $payload
 * @param string|null $dumpFilename
 * @return string
 */
function dumpNewProductsPayload(array $payload, ?string $dumpFilename = null): string
{
    $dumpDir = __DIR__ . DIRECTORY_SEPARATOR . 'data-example';
    if (!is_dir($dumpDir)) {
        @mkdir($dumpDir, 0777, true);
    }

    $filename = $dumpFilename ?? 'create-new-products-json-payload.json';
    $dumpPath = $dumpDir . DIRECTORY_SEPARATOR . $filename;

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
        @file_put_contents($dumpPath, $json . PHP_EOL);
        if (function_exists('safeLog')) {
            safeLog('info', 'find_new_mixers_new_products_payload_dumped', [
                'path' => $dumpPath,
                'bytes' => strlen($json),
                'create_count' => is_array($payload['create'] ?? null) ? count($payload['create']) : 0,
            ]);
        }
    }

    return $dumpPath;
}

/**
 * Execute new-products discovery using provided Santehservice transformed array.
 * Returns absolute path of the dumped JSON payload.
 *
 * @param array<int, array<string, mixed>> $santehTransformed
 * @return string
 */
function runFindNewProducts(array $santehTransformed, array $ekProducts): string
{
    $categoryId = (int)cfg('WC_CATEGORY_ID', 121);

    $ekSkuLookup = buildEkSkuLookup($ekProducts);
    $payload = buildWooBatchCreatePayload($santehTransformed, $ekSkuLookup, $categoryId);

    $dumpPath = dumpNewProductsPayload($payload);

    if (function_exists('safeLog')) {
        safeLog('info', 'find_new_mixers_complete', [
            'ek_total' => count($ekProducts),
            'santeh_transformed_total' => count($santehTransformed),
            'new_products' => is_array($payload['create'] ?? null) ? count($payload['create']) : 0,
            'dump_path' => $dumpPath,
        ]);
    }

    return $dumpPath;
}




