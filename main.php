<?php
declare(strict_types=1);

require __DIR__ . '/fetch-ek-products.php';
require __DIR__ . '/fetch-santehservice-mixers.php';

safeLog('info', 'run start');
try {
    $products = fetchSantehserviceMixersProducts();
    $categoryId = (int)cfg('WC_CATEGORY_ID', 121);
    if (empty($products)) {
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
    $santehProducts = fetchSantehserviceMixersProductsFromXml();
    safeLog('info', 'santehservice_products_loaded', ['total' => count($santehProducts)]);
    if (empty($santehProducts)) {
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

    // Dump processed Santehservice products for debugging/inspection
    try {
        $dumpDir = __DIR__ . DIRECTORY_SEPARATOR . 'data-example';
        if (!is_dir($dumpDir)) {
            @mkdir($dumpDir, 0777, true);
        }
        $dumpPath = $dumpDir . DIRECTORY_SEPARATOR . 'santehservice-products.json';
        $json = json_encode($santehProducts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($dumpPath, $json . PHP_EOL);
            safeLog('info', 'santehservice_products_dumped', ['path' => $dumpPath, 'bytes' => strlen($json)]);
        }
    } catch (Throwable $e) {
        safeLog('error', 'santehservice_products_dump_failed', ['error' => $e->getMessage()]);
    }
    
    safeLog('info', 'run complete', [
        'ek_total' => count($products),
        'santeh_total' => isset($santehProducts) ? count($santehProducts) : 0,
    ]);
    // echo json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
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

