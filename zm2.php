<?php
/**
 * VAST Endpoint with Enhanced Security and Functionality
 */

declare(strict_types=1);

// ==================== Configuration ====================
const DSP_ENDPOINT = 'http://rtb.houseofpubs.live/?pid=4ebbffecff9c400cd569802d6eb7f49f';
const DEFAULT_TIMEOUT_MS = 2000;
const MAX_RETRIES = 2;
const CACHE_TTL = 300; // 5 minutes

// ==================== Error Handling ====================
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_errors.log');

// ==================== Helper Functions ====================

/**
 * Generate secure UUID
 */
function generateRequestId(): string {
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) {
        error_log("UUID generation failed: " . $e->getMessage());
        return uniqid('fallback-', true);
    }
}

/**
 * Build empty VAST response
 */
function buildEmptyVAST(): string {
    $xml = new SimpleXMLElement('<VAST/>');
    $xml->addAttribute('version', '4.0');
    return $xml->asXML();
}

/**
 * Validate and sanitize input parameters
 */
function sanitizeInput(array $input): array {
    $filtered = [
        'width' => filter_var($input['width'] ?? 1920, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 3840, 'default' => 1920]
        ]),
        'height' => filter_var($input['height'] ?? 1080, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2160, 'default' => 1080]
        ]),
        'sid' => preg_replace('/[^a-zA-Z0-9_-]/', '', $input['sid'] ?? 'default_app'),
        'ua' => substr(strip_tags($input['ua'] ?? 'Mozilla/5.0'), 0, 255),
        'uip' => filter_var($input['uip'] ?? '127.0.0.1', FILTER_VALIDATE_IP) ?: '127.0.0.1',
        'app_name' => substr(strip_tags($input['app_name'] ?? 'CTV App'), 0, 255),
        'app_bundle' => preg_replace('/[^a-zA-Z0-9._-]/', '', $input['app_bundle'] ?? 'com.example.ctv')
    ];

    return $filtered;
}

/**
 * Make request to DSP with retry logic
 */
function makeDspRequest(array $ortbRequest, int $timeout = DEFAULT_TIMEOUT_MS): array {
    $retryCount = 0;
    $lastError = null;
    
    do {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => DSP_ENDPOINT,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($ortbRequest),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Forwarded-For: ' . ($ortbRequest['device']['ip'] ?? ''),
                    'User-Agent: ' . ($ortbRequest['device']['ua'] ?? ''),
                    'x-openrtb-version: 2.5',
                    'Accept: application/json'
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FAILONERROR => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    curl_close($ch);
                    return $data;
                }
            }
            
            $lastError = "HTTP $httpCode: " . curl_error($ch);
            curl_close($ch);
            
            // Exponential backoff
            if ($retryCount < MAX_RETRIES) {
                usleep(100000 * (2 ** $retryCount)); // 100ms, 200ms, etc.
            }
        } catch (Exception $e) {
            $lastError = $e->getMessage();
        }
    } while ($retryCount++ < MAX_RETRIES);

    throw new Exception("DSP request failed after $retryCount attempts: $lastError");
}

/**
 * Process DSP response and select best bid
 */
function processBids(array $dspResponse, int $width, int $height): array {
    $bestBid = null;
    $highestPrice = 0;

    foreach ($dspResponse['seatbid'] ?? [] as $seatbid) {
        foreach ($seatbid['bid'] ?? [] as $bid) {
            if (!empty($bid['adm']) && ($bid['price'] ?? 0) >= $highestPrice) {
                // Additional checks for creative compatibility
                if (isCreativeCompatible($bid, $width, $height)) {
                    $bestBid = $bid;
                    $highestPrice = $bid['price'];
                }
            }
        }
    }

    if (!$bestBid) {
        throw new Exception("No valid compatible bids received");
    }

    return $bestBid;
}

/**
 * Check if creative is compatible with requested dimensions
 */
function isCreativeCompatible(array $bid, int $width, int $height): bool {
    // Extract creative dimensions from bid (implementation depends on DSP response structure)
    $creativeWidth = $bid['w'] ?? 0;
    $creativeHeight = $bid['h'] ?? 0;
    
    // Basic aspect ratio check
    if ($creativeWidth > 0 && $creativeHeight > 0) {
        $requestRatio = $width / $height;
        $creativeRatio = $creativeWidth / $creativeHeight;
        return abs($requestRatio - $creativeRatio) < 0.1; // 10% tolerance
    }
    
    return true; // Assume compatible if dimensions not specified
}

/**
 * Inject auction price macro into VAST XML
 */
function injectAuctionPrice(string $vastXml, float $price): string {
    try {
        // Convert price to string with 4 decimal places (common in advertising)
        $priceStr = number_format($price, 4, '.', '');
        
        // Simple string replacement for the macro
        $updatedVast = str_replace('${AUCTION_PRICE}', $priceStr, $vastXml);
        
        // If the VAST is more complex, we could parse it with SimpleXML and modify it
        return $updatedVast;
    } catch (Exception $e) {
        error_log("Error injecting auction price: " . $e->getMessage());
        return $vastXml; // Return original if modification fails
    }
}

// ==================== Main Execution ====================

// Set headers
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/xml; charset=utf-8');
header('X-Request-ID: ' . generateRequestId());

try {
    // 1. Sanitize input
    $params = sanitizeInput($_GET);
    
    // 2. Build OpenRTB request
    $ortbRequest = [
        'id' => generateRequestId(),
        'imp' => [[
            'id' => generateRequestId(),
            'video' => [
                'w' => $params['width'],
                'h' => $params['height'],
                'mimes' => ['video/mp4', 'video/webm'],
                'linearity' => 1,
                'minduration' => 5,
                'maxduration' => 30,
                'protocols' => [2, 3, 5, 6, 7, 8],
                'startdelay' => 0,
                'api' => [1, 2]
            ],
            'bidfloor' => 3,
            'bidfloorcur' => 'USD'
        ]],
        'app' => [
            'id' => $params['sid'],
            'name' => $params['app_name'],
            'bundle' => $params['app_bundle'],
            'publisher' => ['id' => $params['sid']]
        ],
        'device' => [
            'ua' => $params['ua'],
            'ip' => $params['uip'],
            'devicetype' => 3
        ],
        'user' => [
            'id' => generateRequestId()
        ],
        'at' => 1,
        'tmax' => 1500
    ];

    // 3. Send to DSP with retry logic
    $dspResponse = makeDspRequest($ortbRequest);

    // 4. Process bids and select best one
    $winningBid = processBids($dspResponse, $params['width'], $params['height']);

    // 5. Inject auction price macro if present in the VAST
    $vastXml = $winningBid['adm'];
    if (isset($winningBid['price']) && strpos($vastXml, '${AUCTION_PRICE}') !== false) {
        $vastXml = injectAuctionPrice($vastXml, $winningBid['price']);
    }

    // 6. Output VAST
    echo $vastXml;
    exit(0);

} catch (Exception $e) {
    error_log("VAST endpoint error [" . ($_SERVER['HTTP_X_REQUEST_ID'] ?? '') . "]: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo buildEmptyVAST();
    exit(1);
}