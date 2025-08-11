<?php
declare(strict_types=1);

require __DIR__ . '/logging.php';
require __DIR__ . '/send-email.php';
require __DIR__ . '/fetch-ek-products.php';
require __DIR__ . '/fetch-santehservice-mixers.php';
require __DIR__ . '/transform-santehservice-mixers.php';
require __DIR__ . '/find-new-mixers.php';
require __DIR__ . '/find-outofstock-mixers.php';
require __DIR__ . '/find-outdated-mixers.php';
require __DIR__ . '/batch-update-merge-payloads.php';
require __DIR__ . '/batch-update-send-request.php';

safeLog('info', 'run start');
try {
    $ekMixers = fetchEkProducts();
    $categoryId = (int)cfg('WC_CATEGORY_ID', 121);
    // if (empty($ekMixers)) {
    //     safeLog('info', 'run terminated', [
    //         'reason' => 'no products found for required category',
    //         'category_id' => $categoryId,
    //     ]);
    //     // Alert on non-standard termination (no products)
    //     $subject = buildAlertSubject('Non-standard Termination');
    //     $body = "The sync run terminated without products for the required category.\n\n" . json_encode([
    //         'reason' => 'no products found for required category',
    //         'category_id' => $categoryId,
    //     ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    //     $log = getCurrentLogContents();
    //     if ($log !== '') {
    //         $body .= "--- Log ---\n" . $log;
    //     }
    //     sendAlertEmail($subject, $body);
    //     exit(2);
    // }

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

    // After out-of-stock, find outdated mixers (name/description/price diffs) and dump JSON payload
    try {
        $outdatedJsonPath = runFindOutdatedMixers($ekMixers, $santehMixersTransformed);
        safeLog('info', 'outdated_products_json_generated', ['path' => $outdatedJsonPath]);
    } catch (Throwable $e) {
        safeLog('error', 'runFindOutdatedMixers_failed', ['error' => $e->getMessage()]);
    }

    // Build combined batch payload and dump it
    try {
        $batchPayload = runBatchUpdateMixers(
            $newProductsJsonPath ?? '',
            $outOfStockJsonPath ?? '',
            $outdatedJsonPath ?? ''
        );
        safeLog('info', 'batch_update_json_generated', [
            'create_count' => is_array($batchPayload['create'] ?? null) ? count($batchPayload['create']) : 0,
            'update_count' => is_array($batchPayload['update'] ?? null) ? count($batchPayload['update']) : 0,
        ]);
    } catch (Throwable $e) {
        safeLog('error', 'runBatchUpdateMixers_failed', ['error' => $e->getMessage()]);
    }

    // Send the batch request to WooCommerce and dump server response
    try {
        if (isset($batchPayload) && is_array($batchPayload)) {
            $create = $batchPayload['create'] ?? [];
            $update = $batchPayload['update'] ?? [];
            $hasNonEmpty = (is_array($create) && count($create) > 0) || (is_array($update) && count($update) > 0);

            if (!$hasNonEmpty) {
                safeLog('info', 'batch_update_send_skipped', [
                    'reason' => 'batch has only empty arrays',
                    'create_count' => is_array($create) ? count($create) : 0,
                    'update_count' => is_array($update) ? count($update) : 0,
                ]);
            } else {
                $serverResponsePath = runBatchUpdateSendRequest($batchPayload);
                if ($serverResponsePath !== '') {
                    safeLog('info', 'batch_update_server_response_dump_path', ['path' => $serverResponsePath]);
                }
            }
        } else {
            safeLog('warning', 'batch_update_send_skipped', ['reason' => 'no batch payload available']);
        }
    } catch (Throwable $e) {
        safeLog('error', 'runBatchUpdateSendRequest_failed', ['error' => $e->getMessage()]);
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

