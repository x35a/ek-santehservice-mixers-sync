<?php
declare(strict_types=1);

require __DIR__ . '/logging.php';
require __DIR__ . '/fetch-ek-products.php';
require __DIR__ . '/fetch-santehservice-mixers.php';
require __DIR__ . '/transform-santehservice-mixers.php';
require __DIR__ . '/find-new-mixers.php';
require __DIR__ . '/find-outofstock-mixers.php';

safeLog('info', 'run start');
try {
    $ekMixers = fetchEkProducts();
    $categoryId = (int)cfg('WC_CATEGORY_ID', 121);
    if (empty($ekMixers)) {
        safeLog('info', 'run terminated', [
            'reason' => 'no products found for required category',
            'category_id' => $categoryId,
        ]);
        // Alert on non-standard termination (no products)
        $subject = buildAlertSubject('Non-standard Termination');
        $body = "The sync run terminated without products for the required category.\n\n" . json_encode([
            'reason' => 'no products found for required category',
            'category_id' => $categoryId,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        $log = getCurrentLogContents();
        if ($log !== '') {
            $body .= "--- Log ---\n" . $log;
        }
        sendAlertEmail($subject, $body);
        exit(2);
    }

    // After EK WooCommerce products are fetched, also fetch Santehservice XML feed
    $santehMixers = fetchSantehserviceMixersProductsFromXml();
    safeLog('info', 'santehservice_products_loaded', ['total' => count($santehMixers)]);
    if (empty($santehMixers)) {
        safeLog('error', 'santehservice_xml_empty', [
            'reason' => 'no offers returned',
            'url' => (string)cfg('SANTEHSERVICE_XML_URL', ''),
        ]);
        $subject = buildAlertSubject('Santehservice XML Empty');
        $body = "The Santehservice XML feed returned no offers.\n\n" . json_encode([
            'reason' => 'no offers returned',
            'url' => (string)cfg('SANTEHSERVICE_XML_URL', ''),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        $log = getCurrentLogContents();
        if ($log !== '') {
            $body .= "--- Log ---\n" . $log;
        }
        sendAlertEmail($subject, $body);
        exit(3);
    }

    // Transform Santehservice products and dump transformed result
    $santehMixersTransformed = transformSantehserviceMixersProducts($santehMixers);
    // Limit transformed array to at most 3 items
    $santehMixersTransformed = array_slice($santehMixersTransformed, 0, 3); // TODO remove later
    
    safeLog('info', 'santehservice_products_transformed', [
        'before' => count($santehMixers),
        'after' => count($santehMixersTransformed),
    ]);
    try {
        $transformedPath = dumpSantehserviceTransformedProducts($santehMixersTransformed);
        // Optional debug log already emitted inside dump helper; keep a small confirmation here
        safeLog('info', 'santehservice_products_transformed_path', ['path' => $transformedPath]);
    } catch (Throwable $e) {
        safeLog('error', 'santehservice_products_transformed_dump_failed', ['error' => $e->getMessage()]);
    }

    // take $santehMixersTransformed array and use as input and run find-new-mixers.php
    try {
        $newProductsJsonPath = runFindNewProducts($santehMixersTransformed, $ekMixers);
        safeLog('info', 'new_products_json_generated', ['path' => $newProductsJsonPath]);
    } catch (Throwable $e) {
        safeLog('error', 'runFindNewProducts_failed', ['error' => $e->getMessage()]);
    }
    // After finding new products, find out-of-stock mixers and dump JSON payload
    try {
        $outOfStockJsonPath = runFindOutOfStockProducts($ekMixers, $santehMixersTransformed);
        safeLog('info', 'outofstock_products_json_generated', ['path' => $outOfStockJsonPath]);
    } catch (Throwable $e) {
        safeLog('error', 'runFindOutOfStockProducts_failed', ['error' => $e->getMessage()]);
    }

    safeLog('info', 'run complete', [
        'ek_total' => count($ekMixers),
        'santeh_total' => isset($santehMixers) ? count($santehMixers) : 0,
        'santeh_transformed_total' => isset($santehMixersTransformed) ? count($santehMixersTransformed) : 0,
    ]);

} catch (Throwable $e) {
    safeLog('error', 'run failed', ['error' => $e->getMessage()]);
    // Send alert email on exception
    $subject = buildAlertSubject('Run Failed');
    $body = "The sync run failed with an exception.\n\n" . json_encode([
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    $log = getCurrentLogContents();
    if ($log !== '') {
        $body .= "--- Log ---\n" . $log;
    }
    sendAlertEmail($subject, $body);
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

