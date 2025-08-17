<?php
declare(strict_types=1);

/**
 * Universal dump function that can handle all types of data dumps.
 * Replaces all individual dump functions with a single unified approach.
 *
 * Examples of usage:
 * 
 * // Basic usage (no additional logging data)
 * dumpData($products, 'ek_products', 'ek-mixers.json');
 * 
 * // With custom type-specific data for enhanced logging
 * dumpData($filteredProducts, 'ek_products', 'ek-mixers.json', [
 *     'total_count' => count($filteredProducts),
 *     'category_id' => $categoryId,
 *     'filtered_from' => count($allProducts)
 * ]);
 * 
 * // For payload data with detailed logging
 * dumpData($payload, 'new_products_payload', 'create-new-mixers-json-payload.json', [
 *     'create_count' => count($payload['create'] ?? []),
 *     'ek_total' => count($ekProducts),
 *     'santeh_transformed_total' => count($santehTransformed)
 * ]);
 *
 * @param array<string, mixed>|array<int, array<string, mixed>> $data Data to dump
 * @param string $type Type of dump (used for logging)
 * @param string $dumpFilename Filename for the dump file
 * @param array<string, mixed>|null $typeSpecificData Optional type-specific data for logging
 * @return string Absolute path to the written file
 */
function dumpData(array $data, string $type, string $dumpFilename, ?array $typeSpecificData = null): string
{
    $dumpDir = __DIR__ . DIRECTORY_SEPARATOR . 'data-example';
    if (!is_dir($dumpDir)) {
        if (!@mkdir($dumpDir, 0777, true)) {
            if (function_exists('safeLog')) {
                safeLog('error', $type . '_dump_failed', [
                    'error' => 'Failed to create directory',
                    'path' => $dumpDir
                ]);
            }
            return '';
        }
    }

    $dumpPath = $dumpDir . DIRECTORY_SEPARATOR . $dumpFilename;

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        if (function_exists('safeLog')) {
            safeLog('error', $type . '_dump_failed', [
                'error' => 'Failed to encode data to JSON'
            ]);
        }
        return '';
    }

    $bytes = @file_put_contents($dumpPath, $json . PHP_EOL);
    if ($bytes === false) {
        if (function_exists('safeLog')) {
            safeLog('error', $type . '_dump_failed', [
                'error' => 'Failed to write to file',
                'path' => $dumpPath
            ]);
        }
        return '';
    }

    // Log success with type-specific information
    if (function_exists('safeLog')) {
        $logData = [
            'path' => $dumpPath,
            'bytes' => $bytes,
        ];

        // Add type-specific additional data if provided
        if ($typeSpecificData !== null) {
            $logData = array_merge($logData, $typeSpecificData);
        }

        // safeLog('info', $type . '_dumped', $logData);
    }

    return $dumpPath;
}
