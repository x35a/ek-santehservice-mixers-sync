<?php
declare(strict_types=1);

/**
 * Transformation utilities for Santehservice mixers products.
 *
 * Contains configurable variables and pure transformation + dump helpers.
 */

// --- Configurable variables (edit values as needed) ---

/**
 * Additive markup to add to each product price.
 */
$SANTEH_MARKUP = 300.0;

/**
 * Allowed inclusive price range. Products outside this range are filtered out.
 * Keys: 'min' and 'max'.
 * @var array{min: float, max: float}
 */
$SANTEH_PRICE_RANGE = [
    'min' => 1.0,
    'max' => 1000.0,
];

/**
 * List of SKU codes to exclude from the final array.
 * @var array<int, string>
 */
$SANTEH_MIXER_EXCLUDE_LIST = [
    'V47183', // wrong category
    'V47310', // wrong category
    'V47335', // wrong category
    'V47286', // wrong category
    'V47209', // wrong category
    'V38123', // wrong category
    'V38324', // no tubes
    'V38255', // no tubes
    'V38194', // no tubes
    'V38121', // no tubes
    'V38058', // no tubes
    'V38053', // no tubes
    'V38061', // no tubes
    'V38330', // no tubes
    'V38251', // no tubes
    'V05387', // watermarks
    'V05400', // watermarks
    'V05386', // watermarks
];

/**
 * Transform Santehservice products by filtering and applying markup.
 *
 * Processing order:
 * 1) Filter out products where available !== true
 * 2) Filter out by price range (inclusive)
 * 3) Filter out by SKU in exclude list
 * 4) Add additive markup to price
 *
 * @param array<int, array<string, mixed>> $products
 * @param float|null $markupOverride Optional markup to override default variable
 * @param array{min: float, max: float}|null $priceRangeOverride Optional price range override
 * @param array<int, string>|null $excludeListOverride Optional exclude list override
 * @return array<int, array<string, mixed>>
 */
function transformSantehserviceMixersProducts(
    array $products,
    ?float $markupOverride = null,
    ?array $priceRangeOverride = null,
    ?array $excludeListOverride = null
): array {
    // Use configured globals unless overrides are provided
    /** @var float $markup */
    $markup = is_float($markupOverride) ? $markupOverride : (float)($GLOBALS['SANTEH_MARKUP'] ?? 0.0);

    /** @var array{min: float, max: float} $priceRange */
    $priceRange = is_array($priceRangeOverride) ? $priceRangeOverride : (array)($GLOBALS['SANTEH_PRICE_RANGE'] ?? ['min' => 0.0, 'max' => INF]);

    /** @var array<int, string> $excludeList */
    $excludeList = is_array($excludeListOverride) ? $excludeListOverride : (array)($GLOBALS['SANTEH_MIXER_EXCLUDE_LIST'] ?? []);

    $excludeLookup = [];
    foreach ($excludeList as $sku) {
        if ($sku !== '') {
            $excludeLookup[$sku] = true;
        }
    }

    $minPrice = (float)($priceRange['min'] ?? 0.0);
    $maxPrice = (float)($priceRange['max'] ?? INF);

    $result = [];
    foreach ($products as $product) {
        // 1) availability must be true
        $available = (bool)($product['available'] ?? false);
        if ($available !== true) {
            continue;
        }

        // 2) price range filter (inclusive)
        $price = (float)($product['price'] ?? 0.0);
        if (!is_finite($price) || $price < $minPrice || $price > $maxPrice) {
            continue;
        }

        // 3) exclude by SKU
        $sku = (string)($product['sku'] ?? '');
        if ($sku !== '' && isset($excludeLookup[$sku])) {
            continue;
        }

        // 4) apply additive markup
        $product['price'] = $price + $markup;

        $result[] = $product;
    }

    return $result;
}

/**
 * Dump transformed products to data-example directory.
 * Returns absolute path to the written file.
 *
 * @param array<int, array<string, mixed>> $transformedProducts
 * @param string|null $dumpFilename Optional custom filename (defaults to santehservice-products-transformed.json)
 * @return string
 */
function dumpSantehserviceTransformedProducts(array $transformedProducts, ?string $dumpFilename = null): string
{
    $dumpDir = __DIR__ . DIRECTORY_SEPARATOR . 'data-example';
    if (!is_dir($dumpDir)) {
        @mkdir($dumpDir, 0777, true);
    }

    $filename = $dumpFilename ?? 'santehservice-products-transformed.json';
    $dumpPath = $dumpDir . DIRECTORY_SEPARATOR . $filename;

    $json = json_encode($transformedProducts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
        @file_put_contents($dumpPath, $json . PHP_EOL);
        if (function_exists('safeLog')) {
            safeLog('info', 'santehservice_products_transformed_dumped', [
                'path' => $dumpPath,
                'bytes' => strlen($json),
            ]);
        }
    }

    return $dumpPath;
}


