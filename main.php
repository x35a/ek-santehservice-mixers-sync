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

