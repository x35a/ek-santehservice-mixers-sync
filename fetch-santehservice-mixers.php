<?php
declare(strict_types=1);

/**
 * Fetch and parse Santehservice mixers XML feed into a normalized PHP array.
 *
 * Requires helper functions defined in `fetch-ek-products.php` (cfg, httpGet, safeLog, etc.).
 */

/**
 * Public entry: fetch Santehservice feed and return array of products.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchSantehserviceMixersProductsFromXml(): array
{
    $url = (string)cfg('SANTEHSERVICE_XML_URL', '');
    if ($url === '') {
        throw new RuntimeException('Missing SANTEHSERVICE_XML_URL in config.php');
    }

    safeLog('info', 'santehservice_xml_fetch_start', ['url' => $url]);
    try {
        $headers = [
            'Accept: application/xml, text/xml;q=0.9, */*;q=0.8',
            'User-Agent: ek-santehservice-mixers-sync/1.0',
        ];
        [, $xmlBody] = httpGet($url, $headers, 60);
        $products = parseSantehserviceMixersXmlToArray($xmlBody);
        safeLog('info', 'santehservice_xml_fetch_complete', ['total' => count($products)]);
        // Dump raw Santehservice products for debugging/inspection (analogous to EK dump)
        try {
            $dumpPath = dumpSantehserviceProducts($products);
            safeLog('info', 'santehservice_products_dump_path', ['path' => $dumpPath]);
        } catch (Throwable $e) {
            safeLog('error', 'santehservice_products_dump_failed', ['error' => $e->getMessage()]);
        }
        return $products;
    } catch (Throwable $e) {
        safeLog('error', 'santehservice_xml_fetch_failed', [
            'error' => $e->getMessage(),
            'url' => $url,
        ]);
        throw $e;
    }
}

/**
 * Parse Santehservice XML body into an array of offer arrays.
 *
 * @param string $xmlBody
 * @return array<int, array<string, mixed>>
 */
function parseSantehserviceMixersXmlToArray(string $xmlBody): array
{
    $xml = @simplexml_load_string($xmlBody, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
    if ($xml === false) {
        throw new RuntimeException('Failed to parse XML');
    }

    // Expect structure: yml_catalog -> shop -> offers -> offer
    if (!isset($xml->shop->offers->offer)) {
        return [];
    }

    $result = [];
    foreach ($xml->shop->offers->offer as $offer) {
        $result[] = santehserviceOfferToArray($offer);
    }
    return $result;
}

/**
 * Convert a single <offer> node to a normalized array.
 *
 * @param SimpleXMLElement $offer
 * @return array<string, mixed>
 */
function santehserviceOfferToArray(SimpleXMLElement $offer): array
{
    $attrId = (string)($offer['id'] ?? '');
    $attrAvailable = (string)($offer['available'] ?? '');

    $pictures = [];
    if (isset($offer->picture)) {
        foreach ($offer->picture as $pic) {
            $url = trim((string)$pic);
            if ($url !== '') {
                $pictures[] = $url;
            }
        }
    }

    $params = [];
    if (isset($offer->param)) {
        foreach ($offer->param as $param) {
            $name = trim((string)$param['name']);
            $value = trim((string)$param);
            if ($name !== '') {
                $params[] = ['name' => $name, 'value' => $value];
            }
        }
    }

    $data = [
        'id' => $attrId,
        'available' => parseBooleanString($attrAvailable),
        'url' => trim(getSimpleXmlString($offer->url ?? null)),
        'price' => parseDecimalString(getSimpleXmlString($offer->price ?? null)),
        'old_price' => parseDecimalString(getSimpleXmlString($offer->oldprice ?? null)),
        'currency' => trim(getSimpleXmlString($offer->currencyId ?? null)),
        'category_id' => trim(getSimpleXmlString($offer->categoryId ?? null)),
        'pictures' => $pictures,
        'delivery' => parseBooleanString(getSimpleXmlString($offer->delivery ?? null)),
        'name' => trim(getSimpleXmlString($offer->name ?? null)),
        'vendor' => trim(getSimpleXmlString($offer->vendor ?? null)),
        'vendor_code' => trim(getSimpleXmlString($offer->vendorCode ?? null)),
        'model' => trim(getSimpleXmlString($offer->model ?? null)),
        'sku' => trim(getSimpleXmlString($offer->kod ?? null)),
        'description' => trim(getSimpleXmlString($offer->description ?? null)),
        'params' => $params,
    ];

    return $data;
}

/**
 * Helper: safe get string from SimpleXMLElement|null.
 */
function getSimpleXmlString(?SimpleXMLElement $node): string
{
    if ($node === null) {
        return '';
    }
    return (string)$node;
}

/**
 * Helper: parse human-friendly booleans like "true", "1", "yes".
 */
function parseBooleanString(string $value): bool
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return false;
    }
    return in_array($normalized, ['1', 'true', 'yes', 'y'], true);
}

/**
 * Helper: parse decimal string using dot or comma as decimal separator.
 */
function parseDecimalString(string $value): float
{
    $value = trim($value);
    if ($value === '') {
        return 0.0;
    }
    // Replace comma with dot if present, but only when comma appears as decimal separator
    $normalized = str_replace([' ', '\u00A0', "\xC2\xA0"], '', $value);
    $normalized = str_replace(',', '.', $normalized);
    return (float)$normalized;
}


/**
 * Dump raw Santehservice products to data-example directory.
 * Returns absolute path to the written file.
 *
 * @param array<int, array<string, mixed>> $products
 * @param string|null $dumpFilename Optional custom filename (defaults to santehservice-products.json)
 * @return string
 */
function dumpSantehserviceProducts(array $products, ?string $dumpFilename = null): string
{
    $dumpDir = __DIR__ . DIRECTORY_SEPARATOR . 'data-example';
    if (!is_dir($dumpDir)) {
        @mkdir($dumpDir, 0777, true);
    }

    $filename = $dumpFilename ?? 'santehservice-products.json';
    $dumpPath = $dumpDir . DIRECTORY_SEPARATOR . $filename;

    $json = json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
        @file_put_contents($dumpPath, $json . PHP_EOL);
        if (function_exists('safeLog')) {
            safeLog('info', 'santehservice_products_dumped', [
                'path' => $dumpPath,
                'bytes' => strlen($json),
            ]);
        }
    }

    return $dumpPath;
}

