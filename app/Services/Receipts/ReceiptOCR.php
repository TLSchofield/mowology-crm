<?php
/**
 * /app/Services/Receipts/ReceiptOCR.php
 * Google Cloud Vision OCR Wrapper — Service Account Auth (JWT/OAuth2)
 *
 * Calls the Vision API DOCUMENT_TEXT_DETECTION endpoint to extract text from a receipt image.
 * Authenticates using a Google Cloud Service Account JSON key file.
 * No Composer/SDK dependency — pure PHP with openssl for JWT signing.
 *
 * Requires:
 *   - GOOGLE_VISION_CREDENTIALS constant pointing to the service account JSON file path
 *   - PHP openssl extension (standard on most hosts)
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
 *   $result = extractTextFromImage('/absolute/path/to/receipt.jpg');
 *   // Returns: ['success' => true, 'text' => 'RAW TEXT...', 'raw_response' => [...]]
 *   // Or:      ['success' => false, 'error' => 'Vision credentials not configured']
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * In-memory token cache to avoid repeated auth calls within the same request.
 */
$_visionTokenCache = ['token' => null, 'expires' => 0];

/**
 * Extract text from an image using Google Cloud Vision TEXT_DETECTION.
 *
 * @param string $filePath Absolute path to the image file
 * @return array ['success' => bool, 'text' => string|null, 'raw_response' => array|null, 'error' => string|null]
 */
function extractTextFromImage(string $filePath): array
{
    // Check credentials path
    if (!defined('GOOGLE_VISION_CREDENTIALS') || empty(GOOGLE_VISION_CREDENTIALS)) {
        return [
            'success'      => false,
            'text'         => null,
            'raw_response' => null,
            'error'        => 'Vision credentials not configured',
        ];
    }

    // Validate credentials file exists
    // On cPanel shared hosting, open_basedir may restrict access to paths outside public_html.
    // The configured path may point to /home/user/app_config/... but PHP can only reach
    // /home/user/public_html/app_config/... — try the basename under PUBLIC_ROOT as a fallback.
    $credPath = GOOGLE_VISION_CREDENTIALS;
    if (!file_exists($credPath) || !is_readable($credPath)) {
        // Fallback: try the same filename under PUBLIC_ROOT/app_config/
        $basename = basename($credPath);
        $altPath = (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : $_SERVER['DOCUMENT_ROOT'])
                 . '/app_config/' . $basename;
        if (file_exists($altPath) && is_readable($altPath)) {
            $credPath = $altPath;
        } else {
            return [
                'success'      => false,
                'text'         => null,
                'raw_response' => null,
                'error'        => 'Vision credentials file not found: ' . GOOGLE_VISION_CREDENTIALS,
            ];
        }
    }

    // Validate image file exists
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return [
            'success'      => false,
            'text'         => null,
            'raw_response' => null,
            'error'        => 'Image file not found or not readable',
        ];
    }

    // Get access token
    $tokenResult = getVisionAccessToken($credPath);
    if (!$tokenResult['success']) {
        return [
            'success'      => false,
            'text'         => null,
            'raw_response' => null,
            'error'        => 'Auth failed: ' . $tokenResult['error'],
        ];
    }
    $accessToken = $tokenResult['token'];

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

    // Build the Vision API request (Bearer token auth, no API key in URL)
    $apiUrl = 'https://vision.googleapis.com/v1/images:annotate';

    $requestBody = json_encode([
        'requests' => [
            [
                'image' => [
                    'content' => $base64Image,
                ],
                'features' => [
                    [
                        'type'       => 'DOCUMENT_TEXT_DETECTION',
                        'maxResults' => 1,
                    ],
                ],
                'imageContext' => [
                    'languageHints' => ['en'],
                ],
            ],
        ],
    ]);

    // Make the API call with Bearer token
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n"
                       . "Authorization: Bearer " . $accessToken . "\r\n",
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


/**
 * Get a Google Cloud OAuth2 access token using a service account JSON key.
 * Generates a JWT, signs it with the private key, exchanges it at Google's token endpoint.
 * Caches the token in-memory for the duration of the PHP request.
 *
 * @param string $credPath Absolute path to the service account JSON file
 * @return array ['success' => bool, 'token' => string|null, 'error' => string|null]
 */
function getVisionAccessToken(string $credPath): array
{
    global $_visionTokenCache;

    // Return cached token if still valid (with 60s buffer)
    if ($_visionTokenCache['token'] && $_visionTokenCache['expires'] > time() + 60) {
        return ['success' => true, 'token' => $_visionTokenCache['token'], 'error' => null];
    }

    // Read the service account JSON
    $jsonData = file_get_contents($credPath);
    if ($jsonData === false) {
        return ['success' => false, 'token' => null, 'error' => 'Cannot read credentials file'];
    }

    $cred = json_decode($jsonData, true);
    if (!$cred) {
        return ['success' => false, 'token' => null, 'error' => 'Invalid credentials JSON'];
    }

    $requiredKeys = ['client_email', 'private_key', 'token_uri'];
    foreach ($requiredKeys as $key) {
        if (empty($cred[$key])) {
            return ['success' => false, 'token' => null, 'error' => 'Missing key in credentials: ' . $key];
        }
    }

    // Build the JWT
    $now = time();
    $expiry = $now + 3600; // 1 hour

    $header = [
        'alg' => 'RS256',
        'typ' => 'JWT',
    ];

    $claimSet = [
        'iss'   => $cred['client_email'],
        'scope' => 'https://www.googleapis.com/auth/cloud-vision',
        'aud'   => $cred['token_uri'],
        'iat'   => $now,
        'exp'   => $expiry,
    ];

    $headerEncoded = base64UrlEncode(json_encode($header));
    $claimEncoded  = base64UrlEncode(json_encode($claimSet));
    $signatureInput = $headerEncoded . '.' . $claimEncoded;

    // Sign with RSA SHA-256
    $privateKey = openssl_pkey_get_private($cred['private_key']);
    if ($privateKey === false) {
        return ['success' => false, 'token' => null, 'error' => 'Invalid private key in credentials'];
    }

    $signature = '';
    $signResult = openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    if (!$signResult) {
        return ['success' => false, 'token' => null, 'error' => 'Failed to sign JWT'];
    }

    $jwt = $signatureInput . '.' . base64UrlEncode($signature);

    // Exchange JWT for access token
    $tokenUrl = $cred['token_uri'];
    $postData = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 15,
        ],
    ]);

    $tokenResponse = @file_get_contents($tokenUrl, false, $context);
    if ($tokenResponse === false) {
        $err = error_get_last();
        return ['success' => false, 'token' => null, 'error' => 'Token exchange failed: ' . ($err['message'] ?? 'Unknown')];
    }

    $tokenData = json_decode($tokenResponse, true);
    if (!$tokenData || empty($tokenData['access_token'])) {
        $errMsg = $tokenData['error_description'] ?? $tokenData['error'] ?? 'No access_token in response';
        return ['success' => false, 'token' => null, 'error' => 'Token exchange error: ' . $errMsg];
    }

    // Cache the token
    $_visionTokenCache['token']   = $tokenData['access_token'];
    $_visionTokenCache['expires'] = $now + ($tokenData['expires_in'] ?? 3600);

    return ['success' => true, 'token' => $tokenData['access_token'], 'error' => null];
}


/**
 * Base64 URL-safe encode (no padding, URL-safe alphabet).
 */
function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
