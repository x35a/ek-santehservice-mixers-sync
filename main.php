<?php
declare(strict_types=1);

require __DIR__ . '/fetch-ek-products.php';

safeLog('info', 'run start');
try {
    $products = fetchSantehserviceMixersProducts();
    $categoryId = (int)cfg('WC_CATEGORY_ID', 121);
    if (empty($products)) {
        safeLog('info', 'run terminated', [
            'reason' => 'no products found for required category',
            'category_id' => $categoryId,
        ]);
        exit(2);
    }
    safeLog('info', 'run complete', ['total' => count($products)]);
    echo json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    safeLog('error', 'run failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

