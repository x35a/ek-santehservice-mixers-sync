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
    $headers = [
        'Accept: application/xml, text/xml;q=0.9, */*;q=0.8',
        'User-Agent: ek-santehservice-mixers-sync/1.0',
    ];
    [, $xmlBody] = httpGet($url, $headers, 60);
    $products = parseSantehserviceMixersXmlToArray($xmlBody);
    safeLog('info', 'santehservice_xml_fetch_complete', ['total' => count($products)]);
    
    // Dump raw Santehservice products
    dumpData($products, 'fetch_santehservice_mixers', 'santehservice-mixers.json');

    return $products;
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
        safeLog('error', 'santehservice_xml_structure_mismatch', [
            'expected' => 'yml_catalog -> shop -> offers -> offer',
            'actual_root' => $xml->getName(),
            'has_shop' => isset($xml->shop),
            'has_offers' => isset($xml->shop->offers),
            'has_offer' => isset($xml->shop->offers->offer),
        ]);
        
        throw new RuntimeException('XML structure does not match expected format: yml_catalog -> shop -> offers -> offer');
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




