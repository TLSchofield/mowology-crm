<?php
/**
 * /app/Services/Receipts/ReceiptParser.php
 * Receipt Text Parser
 *
 * Extracts structured fields from raw OCR text using regex patterns.
 * Designed for Canadian receipts (GST 5%, PST, CAD dollar format).
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
 *   $parsed = parseReceiptText($ocrText);
 *   // Returns: [
 *   //   'total' => '145.67', 'tax' => '6.93', 'subtotal' => '138.74',
 *   //   'date' => '2026-02-13', 'vendor_hint' => 'HOME DEPOT',
 *   //   'card_last4' => '1234', 'payment_method' => 'credit_card',
 *   // ]
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Parse raw OCR text and extract structured receipt fields.
 *
 * @param string $ocrText Raw text from OCR
 * @return array Parsed fields with values (null if not found)
 */
function parseReceiptText(string $ocrText): array
{
    $result = [
        'total'          => null,
        'tax'            => null,
        'subtotal'       => null,
        'date'           => null,
        'vendor_hint'    => null,
        'card_last4'     => null,
        'payment_method' => null,
    ];

    $lines = preg_split('/\r?\n/', $ocrText);
    if (empty($lines)) {
        return $result;
    }

    // Vendor hint: typically first non-empty line or first line with letters
    $result['vendor_hint'] = extractVendorHint($lines);

    // Total
    $result['total'] = extractTotal($ocrText, $lines);

    // Tax (GST, PST, HST)
    $result['tax'] = extractTax($ocrText);

    // Subtotal
    $result['subtotal'] = extractSubtotal($ocrText);

    // Date
    $result['date'] = extractDate($ocrText);

    // Payment method + card last 4
    $paymentInfo = extractPaymentInfo($ocrText);
    $result['card_last4']     = $paymentInfo['card_last4'];
    $result['payment_method'] = $paymentInfo['payment_method'];

    // If we found subtotal and tax but no total, calculate it
    if ($result['total'] === null && $result['subtotal'] !== null && $result['tax'] !== null) {
        $result['total'] = number_format(
            (float)$result['subtotal'] + (float)$result['tax'],
            2,
            '.',
            ''
        );
    }

    return $result;
}

/**
 * Extract vendor hint from first meaningful lines.
 */
function extractVendorHint(array $lines): ?string
{
    // First 3 non-empty lines are candidates — vendor is usually first
    $candidates = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (strlen($trimmed) >= 3 && preg_match('/[A-Za-z]/', $trimmed)) {
            $candidates[] = $trimmed;
            if (count($candidates) >= 3) break;
        }
    }

    if (empty($candidates)) {
        return null;
    }

    // Return the first candidate that looks like a store name
    // Skip lines that are mostly numbers/symbols or look like addresses
    foreach ($candidates as $candidate) {
        // Skip lines with dates, phone numbers, addresses
        if (preg_match('/^\d{3}[\-\.]\d{3}[\-\.]\d{4}/', $candidate)) continue;
        if (preg_match('/^\d+\s+(st|ave|blvd|rd|dr|hwy|street|avenue)/i', $candidate)) continue;

        return $candidate;
    }

    return $candidates[0] ?? null;
}

/**
 * Extract total amount from OCR text.
 * Looks for patterns like "TOTAL $145.67", "TOTAL: 145.67", "AMOUNT DUE: $145.67"
 */
function extractTotal(string $text, array $lines): ?string
{
    // Pattern: TOTAL, AMOUNT DUE, BALANCE DUE, etc. followed by dollar amount
    $totalPatterns = [
        '/(?:TOTAL\s*(?:DUE)?|AMOUNT\s*DUE|BALANCE\s*DUE|GRAND\s*TOTAL|PURCHASE\s*TOTAL)\s*:?\s*\$?\s*(\d{1,6}[.,]\d{2})/i',
        // "TOTAL" at start of line, value at end
        '/^.*TOTAL[^$\d]*\$?\s*(\d{1,6}[.,]\d{2})\s*$/im',
    ];

    foreach ($totalPatterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches)) {
            // Take the last match (usually the grand total appears after subtotals)
            $amount = end($matches[1]);
            $amount = str_replace(',', '.', $amount);
            return $amount;
        }
    }

    // Fallback: find the largest dollar amount on a line with "TOTAL"
    $maxAmount = null;
    foreach ($lines as $line) {
        if (stripos($line, 'total') !== false) {
            if (preg_match('/\$?\s*(\d{1,6}[.,]\d{2})/', $line, $m)) {
                $val = (float)str_replace(',', '.', $m[1]);
                if ($maxAmount === null || $val > $maxAmount) {
                    $maxAmount = $val;
                }
            }
        }
    }

    if ($maxAmount !== null) {
        return number_format($maxAmount, 2, '.', '');
    }

    return null;
}

/**
 * Extract tax amount (GST, PST, HST, TAX).
 */
function extractTax(string $text): ?string
{
    $taxPatterns = [
        // GST 5%, PST, HST
        '/(?:GST|PST|HST|TAX)\s*(?:\d+%?)?\s*:?\s*\$?\s*(\d{1,6}[.,]\d{2})/i',
    ];

    $totalTax = 0;
    $found = false;

    foreach ($taxPatterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[1] as $amount) {
                $totalTax += (float)str_replace(',', '.', $amount);
                $found = true;
            }
        }
    }

    return $found ? number_format($totalTax, 2, '.', '') : null;
}

/**
 * Extract subtotal amount.
 */
function extractSubtotal(string $text): ?string
{
    $pattern = '/(?:SUB\s*TOTAL|SUBTOTAL)\s*:?\s*\$?\s*(\d{1,6}[.,]\d{2})/i';
    if (preg_match($pattern, $text, $m)) {
        return str_replace(',', '.', $m[1]);
    }
    return null;
}

/**
 * Extract date from receipt text.
 * Supports: MM/DD/YYYY, DD/MM/YYYY, YYYY-MM-DD, MMM DD YYYY, etc.
 *
 * @return string|null Date in YYYY-MM-DD format
 */
function extractDate(string $text): ?string
{
    $patterns = [
        // YYYY-MM-DD
        '/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/' => function ($m) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        },
        // MM/DD/YYYY or DD/MM/YYYY
        '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/' => function ($m) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            $y = (int)$m[3];
            // If first number > 12, it's DD/MM/YYYY
            if ($a > 12) {
                return sprintf('%04d-%02d-%02d', $y, $b, $a);
            }
            // Default: MM/DD/YYYY (North American convention)
            return sprintf('%04d-%02d-%02d', $y, $a, $b);
        },
        // MM/DD/YY or DD/MM/YY
        '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})(?!\d)/' => function ($m) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            $y = (int)$m[3] + 2000;
            if ($a > 12) {
                return sprintf('%04d-%02d-%02d', $y, $b, $a);
            }
            return sprintf('%04d-%02d-%02d', $y, $a, $b);
        },
        // Month DD, YYYY (e.g., "Feb 13, 2026", "February 13, 2026")
        '/(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{1,2}),?\s+(\d{4})/i' => function ($m) {
            $parsed = strtotime($m[0]);
            return $parsed ? date('Y-m-d', $parsed) : null;
        },
    ];

    foreach ($patterns as $regex => $formatter) {
        if (preg_match($regex, $text, $matches)) {
            $date = $formatter($matches);
            if ($date) {
                // Basic validation
                $parts = explode('-', $date);
                if (count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                    return $date;
                }
            }
        }
    }

    return null;
}

/**
 * Extract payment method and card last 4 digits.
 */
function extractPaymentInfo(string $text): array
{
    $result = ['card_last4' => null, 'payment_method' => null];

    // Card last 4 digits: ****1234, XXXX1234, *1234, ending in 1234
    $cardPatterns = [
        '/(?:\*{2,4}|X{2,4})\s*(\d{4})/i',
        '/ending\s+(?:in\s+)?(\d{4})/i',
        '/card\s*#?\s*\*+(\d{4})/i',
    ];

    foreach ($cardPatterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $result['card_last4'] = $m[1];
            break;
        }
    }

    // Payment method detection
    $textLower = strtolower($text);
    if (strpos($textLower, 'visa') !== false) {
        $result['payment_method'] = 'credit_card';
    } elseif (strpos($textLower, 'mastercard') !== false || strpos($textLower, 'master card') !== false) {
        $result['payment_method'] = 'credit_card';
    } elseif (strpos($textLower, 'amex') !== false || strpos($textLower, 'american express') !== false) {
        $result['payment_method'] = 'credit_card';
    } elseif (strpos($textLower, 'debit') !== false || strpos($textLower, 'interac') !== false) {
        $result['payment_method'] = 'debit';
    } elseif (strpos($textLower, 'cash') !== false) {
        $result['payment_method'] = 'cash';
    } elseif (strpos($textLower, 'e-transfer') !== false || strpos($textLower, 'etransfer') !== false) {
        $result['payment_method'] = 'etransfer';
    } elseif ($result['card_last4']) {
        // If we found card digits but no specific brand
        $result['payment_method'] = 'credit_card';
    }

    return $result;
}
