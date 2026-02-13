<?php
/**
 * /app/Services/Receipts/ReceiptOCR.php
 * Google Cloud Vision OCR Wrapper
 *
 * Calls the Vision API TEXT_DETECTION endpoint to extract text from a receipt image.
 * Uses file_get_contents() + base64 encoding (no Composer/SDK dependency).
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
 *   $result = extractTextFromImage('/absolute/path/to/receipt.jpg');
 *   // Returns: ['success' => true, 'text' => 'RAW TEXT...', 'raw_response' => [...]]
 *   // Or:      ['success' => false, 'error' => 'Vision API key not configured']
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Extract text from an image using Google Cloud Vision TEXT_DETECTION.
 *
 * @param string $filePath Absolute path to the image file
 * @return array ['success' => bool, 'text' => string|null, 'raw_response' => array|null, 'error' => string|null]
 */
function extractTextFromImage(string $filePath): array
{
    // Check API key
    if (!defined('GOOGLE_VISION_API_KEY') || empty(GOOGLE_VISION_API_KEY)) {
        return [
            'success'      => false,
            'text'         => null,
            'raw_response' => null,
            'error'        => 'Vision API key not configured',
        ];
    }

    // Validate file exists
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return [
            'success'      => false,
            'text'         => null,
            'raw_response' => null,
            'error'        => 'Image file not found or not readable',
        ];
    }

    // Read and encode the image
    $imageData = file_get_contents($filePath);
    if ($imageData === false) {
        return [
            'success'      => false,
            'text'         => null,
            'raw_response' => null,
            'error'        => 'Failed to read image file',
        ];
    }

    $base64Image = base64_encode($imageData);

    // Build the Vision API request
    $apiUrl = 'https://vision.googleapis.com/v1/images:annotate?key=' . GOOGLE_VISION_API_KEY;

    $requestBody = json_encode([
        'requests' => [
            [
                'image' => [
                    'content' => $base64Image,
                ],
                'features' => [
                    [
                        'type'       => 'TEXT_DETECTION',
                        'maxResults' => 1,
                    ],
                ],
            ],
        ],
    ]);

    // Make the API call
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $requestBody,
            'timeout' => 30,
        ],
    ]);

    $response = @file_get_contents($apiUrl, false, $context);

    if ($response === false) {
        $err = error_get_last();
        return [
            'success'      => false,
            'text'         => null,
            'raw_response' => null,
            'error'        => 'Vision API request failed: ' . ($err['message'] ?? 'Unknown error'),
        ];
    }

    $decoded = json_decode($response, true);
    if (!$decoded) {
        return [
            'success'      => false,
            'text'         => null,
            'raw_response' => null,
            'error'        => 'Failed to decode Vision API response',
        ];
    }

    // Check for API-level errors
    if (isset($decoded['error'])) {
        return [
            'success'      => false,
            'text'         => null,
            'raw_response' => $decoded,
            'error'        => 'Vision API error: ' . ($decoded['error']['message'] ?? 'Unknown'),
        ];
    }

    // Extract the full text annotation
    $responses = $decoded['responses'] ?? [];
    $firstResponse = $responses[0] ?? [];

    if (isset($firstResponse['error'])) {
        return [
            'success'      => false,
            'text'         => null,
            'raw_response' => $decoded,
            'error'        => 'Vision API annotation error: ' . ($firstResponse['error']['message'] ?? 'Unknown'),
        ];
    }

    $fullTextAnnotation = $firstResponse['fullTextAnnotation']['text']
        ?? $firstResponse['textAnnotations'][0]['description']
        ?? null;

    if (empty($fullTextAnnotation)) {
        return [
            'success'      => true,
            'text'         => '',
            'raw_response' => $decoded,
            'error'        => null,
        ];
    }

    return [
        'success'      => true,
        'text'         => $fullTextAnnotation,
        'raw_response' => $decoded,
        'error'        => null,
    ];
}
