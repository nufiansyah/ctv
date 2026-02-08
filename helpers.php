<?php
declare(strict_types=1);

function generateRequestId(): string {
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) {
        error_log("UUID generation failed: " . $e->getMessage());
        return uniqid('fallback-', true);
    }
}

function buildEmptyVAST(): string {
    $xml = new SimpleXMLElement('<VAST/>');
    $xml->addAttribute('version', '4.0');
    return $xml->asXML();
}

/**
 * Validate creative aspect ratio compatibility with requested dimensions.
 *
 * @param array $bid    Bid object that may include creative dimensions.
 * @param int   $width  Requested player width.
 * @param int   $height Requested player height.
 *
 * @return bool True when dimensions are missing or within 10% ratio tolerance.
 */
function isCreativeCompatible(array $bid, int $width, int $height): bool {
    $creativeWidth = $bid['w'] ?? 0;
    $creativeHeight = $bid['h'] ?? 0;

    if ($creativeWidth <= 0 || $creativeHeight <= 0) {
        return true;
    }

    $requestRatio = $width / $height;
    $creativeRatio = $creativeWidth / $creativeHeight;

    return abs($requestRatio - $creativeRatio) < 0.1;
}

/**
 * Select the best compatible bid from a DSP response.
 *
 * @param array    $dspResponse       Response payload from DSP.
 * @param int      $width             Requested player width.
 * @param int      $height            Requested player height.
 * @param int|null $noBidCode         Optional HTTP code to attach to error cases.
 * @param bool     $requireSeatbid    Whether to fail fast when seatbid is missing.
 *
 * @throws Exception When no compatible bid is found.
 *
 * @return array The highest-priced compatible bid.
 */
function processBids(array $dspResponse, int $width, int $height, ?int $noBidCode = null, bool $requireSeatbid = false): array {
    if ($requireSeatbid && empty($dspResponse['seatbid'])) {
        throw new Exception("No seatbids in DSP response", $noBidCode ?? 0);
    }

    $bestBid = null;
    $highestPrice = 0.0;

    foreach ($dspResponse['seatbid'] ?? [] as $seatbid) {
        foreach ($seatbid['bid'] ?? [] as $bid) {
            if (empty($bid['adm'])) continue;

            $bidPrice = $bid['price'] ?? 0;
            if ($bidPrice >= $highestPrice && isCreativeCompatible($bid, $width, $height)) {
                $bestBid = $bid;
                $highestPrice = $bidPrice;
            }
        }
    }

    if (!$bestBid) {
        throw new Exception("No valid compatible bids received", $noBidCode ?? 0);
    }

    return $bestBid;
}
