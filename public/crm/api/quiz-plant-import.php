<?php
/**
 * /public/crm/api/quiz-plant-import.php
 * Admin-only endpoint: extract plant data from a photo card (PictureThis, etc.)
 * via free OCR.space API + Wikipedia REST API, then return structured JSON
 * for admin review before saving to quiz_plant_library / quiz_questions.
 *
 * POST multipart/form-data:
 *   image      — plant card photo file
 *   csrf_token — CSRF token
 *
 * Returns JSON: { success, plant, questions[], wikipedia_summary }
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user    = getCurrentUser();
$isAdmin = ($user['role'] ?? '') === 'admin';
session_write_close();

function piOk(array $data): void
{
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function piErr(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if (!$isAdmin) piErr('Admin only', 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') piErr('POST required', 405);
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) piErr('Invalid CSRF token', 403);

// ── 1. Receive & validate image ───────────────────────────────────────────────

$file = $_FILES['image'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) piErr('Image upload failed');

$allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$mimeType = mime_content_type($file['tmp_name']);
if (!in_array($mimeType, $allowed, true)) piErr('Unsupported image type');
if ($file['size'] > 10 * 1024 * 1024) piErr('Image too large — max 10MB');

// ── 2. Run OCR via ocr.space free API ────────────────────────────────────────

if (!defined('OCR_SPACE_API_KEY') || OCR_SPACE_API_KEY === '') {
    piErr('OCR_SPACE_API_KEY is not set in secrets.php');
}

$rawText = runOcr($file['tmp_name'], $mimeType);
if ($rawText === null) piErr('OCR failed — check OCR_SPACE_API_KEY and try again');

// ── 3. Parse the OCR text ─────────────────────────────────────────────────────

$plant = parsePlantCard($rawText);

// ── 4. Wikipedia lookup (free, no key) ───────────────────────────────────────

$wikiSummary = '';
$searchName  = $plant['latin_name'] ?: $plant['common_name'];
if ($searchName) {
    $wikiSummary = fetchWikipediaSummary($searchName);
    // Fallback to common name if scientific name returned no result
    if (!$wikiSummary && $plant['latin_name'] && $plant['common_name']) {
        $wikiSummary = fetchWikipediaSummary($plant['common_name']);
    }
}

// ── 5. Check for existing library entry (duplicate detection) ─────────────────

$existingEntry = null;
if ($plant['common_name'] || $plant['latin_name']) {
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT id, common_name, latin_name, image_path, type
         FROM quiz_plant_library
         WHERE (common_name IS NOT NULL AND LOWER(common_name) = LOWER(?))
            OR (latin_name IS NOT NULL AND latin_name != '' AND LOWER(latin_name) = LOWER(?))
         LIMIT 1"
    );
    $stmt->execute([$plant['common_name'] ?: '', $plant['latin_name'] ?: '']);
    $existingEntry = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── 6. Save uploaded image to /uploads/quiz/ ──────────────────────────────────

$ext      = in_array($mimeType, ['image/jpeg', 'image/jpg']) ? 'jpg'
          : (($mimeType === 'image/png') ? 'png'
          : (($mimeType === 'image/webp') ? 'webp' : 'jpg'));
$filename = 'plant_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destDir  = PUBLIC_ROOT . '/uploads/quiz';
if (!is_dir($destDir)) mkdir($destDir, 0755, true);

$imagePath = null;
if (move_uploaded_file($file['tmp_name'], $destDir . '/' . $filename)) {
    $imagePath = '/uploads/quiz/' . $filename;
}

// ── 6. Build suggested quiz questions ────────────────────────────────────────

$questions = buildSuggestedQuestions($plant, $imagePath, $wikiSummary);

// ── 7. Return to admin for review ────────────────────────────────────────────

piOk([
    'plant'             => $plant,
    'image_path'        => $imagePath,
    'wikipedia_summary' => $wikiSummary,
    'questions'         => $questions,
    'existing_entry'    => $existingEntry,
    'raw_ocr'           => $rawText,   // for debugging
]);


// ═══════════════════════════════════════════════════════════════════════════════
// FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Send image to OCR.space free API and return raw extracted text.
 */
function runOcr(string $tmpPath, string $mimeType): ?string
{
    $apiKey = OCR_SPACE_API_KEY;

    // Encode as base64 to avoid multipart upload issues on shared hosting
    $b64     = base64_encode(file_get_contents($tmpPath));
    $dataUri = 'data:' . $mimeType . ';base64,' . $b64;

    $postData = http_build_query([
        'apikey'              => $apiKey,
        'base64Image'         => $dataUri,
        'language'            => 'eng',
        'isOverlayRequired'   => 'false',
        'detectOrientation'   => 'true',
        'scale'               => 'true',
        'OCREngine'           => '2',  // Engine 2: better for modern card layouts
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                       . "Content-Length: " . strlen($postData) . "\r\n",
            'content' => $postData,
            'timeout' => 30,
        ],
    ]);

    $response = @file_get_contents('https://api.ocr.space/parse/image', false, $ctx);
    if (!$response) return null;

    $json = json_decode($response, true);
    if (!is_array($json) || ($json['IsErroredOnProcessing'] ?? true)) return null;

    $text = '';
    foreach ($json['ParsedResults'] ?? [] as $result) {
        $text .= ($result['ParsedText'] ?? '') . "\n";
    }

    return trim($text) ?: null;
}

/**
 * Parse OCR text from a PictureThis-style plant card.
 * Extracts: common_name, latin_name, sunlight, watering, toxicity, type.
 *
 * PictureThis card layout (approximate):
 *   Botanist in
 *   Your Pocket
 *   Lady's mantle
 *   Alchemilla xanthochlora
 *   Sunlight
 *   Full sun
 *   Water in Mar.
 *   Every 21 days
 *   Toxicity
 *   Non-toxic
 */
function parsePlantCard(string $text): array
{
    $plant = [
        'common_name' => '',
        'latin_name'  => '',
        'sunlight'    => '',
        'watering'    => '',
        'toxicity'    => '',
        'type'        => 'plant',
        'tags'        => '',
    ];

    // Normalize line endings and split
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $lines = array_values(array_filter(array_map('trim', $lines)));

    // --- Latin name: two words, first letter caps, rest lower ---
    // "Alchemilla xanthochlora" or "Taraxacum officinale"
    $latinPattern = '/^[A-Z][a-z]+ [a-z]+(\s+[a-z]+)?\.?$/';
    $latinIdx     = -1;
    foreach ($lines as $i => $line) {
        if (preg_match($latinPattern, $line)) {
            $plant['latin_name'] = $line;
            $latinIdx = $i;
            break;
        }
    }

    // Common name: the non-junk line just before the latin name,
    // or the first non-app-branding line if no latin found.
    $skipPatterns = [
        '/botanist/i', '/your pocket/i', '/picturethis/i',
        '/picture this/i', '/for customized/i', '/care guide/i',
        '/check in/i', '/download/i', '/share/i',
    ];

    if ($latinIdx > 0) {
        for ($i = $latinIdx - 1; $i >= 0; $i--) {
            $skip = false;
            foreach ($skipPatterns as $p) {
                if (preg_match($p, $lines[$i])) { $skip = true; break; }
            }
            if (!$skip && strlen($lines[$i]) > 2) {
                $plant['common_name'] = $lines[$i];
                break;
            }
        }
    } else {
        // No latin name found — take first non-branding line
        foreach ($lines as $line) {
            $skip = false;
            foreach ($skipPatterns as $p) {
                if (preg_match($p, $line)) { $skip = true; break; }
            }
            if (!$skip && strlen($line) > 2) {
                $plant['common_name'] = $line;
                break;
            }
        }
    }

    // --- Sunlight ---
    foreach ($lines as $i => $line) {
        if (stripos($line, 'sunlight') !== false) {
            // Value may be on same line "Sunlight Full sun" or next line
            $inline = trim(preg_replace('/sunlight/i', '', $line));
            if ($inline !== '') {
                $plant['sunlight'] = $inline;
            } elseif (isset($lines[$i + 1])) {
                $plant['sunlight'] = $lines[$i + 1];
            }
            break;
        }
    }

    // --- Watering ---
    foreach ($lines as $i => $line) {
        if (preg_match('/water/i', $line)) {
            // Try same line first: "Water in Mar. Every 21 days"
            $inline = trim(preg_replace('/water\s+in\s+\w+\.?\s*/i', '', $line));
            if ($inline !== '') {
                $plant['watering'] = $inline;
            } elseif (isset($lines[$i + 1])) {
                $plant['watering'] = $lines[$i + 1];
            }
            break;
        }
    }

    // --- Toxicity ---
    foreach ($lines as $i => $line) {
        if (stripos($line, 'toxic') !== false) {
            $inline = trim(preg_replace('/toxicity/i', '', $line));
            if ($inline !== '') {
                $plant['toxicity'] = $inline;
            } elseif (isset($lines[$i + 1])) {
                $plant['toxicity'] = $lines[$i + 1];
            }
            break;
        }
    }

    // --- Guess type from name / tags ---
    $nameLower = strtolower($plant['common_name'] . ' ' . $plant['latin_name']);
    if (preg_match('/grass|ryegrass|fescue|bluegrass|bent/i', $nameLower)) {
        $plant['type'] = 'grass';
    } elseif (preg_match('/weed|dandelion|clover|bindweed|thistle|plantain|crabgrass|spurge|chickweed/i', $nameLower)) {
        $plant['type'] = 'weed';
    } elseif (preg_match('/shrub|hedge|boxwood|juniper|cedar|holly|laurel/i', $nameLower)) {
        $plant['type'] = 'shrub';
    } elseif (preg_match('/tree|maple|oak|pine|spruce|birch|cherry|apple/i', $nameLower)) {
        $plant['type'] = 'tree';
    } elseif (preg_match('/fungus|mushroom|rust|mold|blight|disease/i', $nameLower)) {
        $plant['type'] = 'fungus';
    }

    // --- Auto-tags from extracted data ---
    $tags = [];
    if ($plant['sunlight']) {
        if (stripos($plant['sunlight'], 'full') !== false) $tags[] = 'full-sun';
        if (stripos($plant['sunlight'], 'shade') !== false) $tags[] = 'shade';
        if (stripos($plant['sunlight'], 'partial') !== false) $tags[] = 'partial-sun';
    }
    if ($plant['toxicity']) {
        $tags[] = stripos($plant['toxicity'], 'non') !== false ? 'non-toxic' : 'toxic';
    }
    $plant['tags'] = implode(', ', $tags);

    return $plant;
}

/**
 * Fetch a plain-text Wikipedia summary for a plant name using the free REST API.
 */
function fetchWikipediaSummary(string $name): string
{
    // Replace spaces with underscores for Wikipedia title format
    $title = str_replace(' ', '_', trim($name));
    $url   = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode($title);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: MowologyCRM/1.0 (plant-quiz-import)\r\n"
                       . "Accept: application/json\r\n",
            'timeout' => 10,
        ],
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if (!$response) return '';

    $json = json_decode($response, true);
    if (!is_array($json) || isset($json['type']) && $json['type'] === 'https://mediawiki.org/wiki/HyperSwitch/errors/not_found') {
        return '';
    }

    $summary = $json['extract'] ?? '';
    // Trim to a reasonable length for flashcard notes
    if (mb_strlen($summary) > 600) {
        $summary = mb_substr($summary, 0, 597) . '…';
    }
    return $summary;
}

/**
 * Build a list of suggested quiz questions based on extracted plant data.
 * Each question has: type, question_text, options[], correct_index, learn_notes, difficulty.
 */
function buildSuggestedQuestions(array $plant, ?string $imagePath, string $wikiSummary): array
{
    $questions = [];
    $common    = $plant['common_name'];
    $latin     = $plant['latin_name'];

    if (!$common) return $questions;

    // ── Q1: Photo ID ──────────────────────────────────────────────────────────
    if ($imagePath) {
        $questions[] = [
            'type'          => 'photo_id',
            'question_text' => 'What plant is shown in this image?',
            'options'       => buildPhotoOptions($common),
            'correct_index' => 0,
            'learn_notes'   => ($latin ? "$common ($latin). " : "$common. ") . ($wikiSummary ?: ''),
            'difficulty'    => 'medium',
            'image_path'    => $imagePath,
            'selected'      => true,
        ];
    }

    // ── Q2: Scientific name ───────────────────────────────────────────────────
    if ($latin) {
        $questions[] = [
            'type'          => 'multiple_choice',
            'question_text' => "What is the scientific (Latin) name for $common?",
            'options'       => buildLatinOptions($latin),
            'correct_index' => 0,
            'learn_notes'   => "The scientific name for $common is $latin.",
            'difficulty'    => 'hard',
            'image_path'    => null,
            'selected'      => true,
        ];
    }

    // ── Q3: Sunlight ──────────────────────────────────────────────────────────
    if ($plant['sunlight']) {
        $sun = $plant['sunlight'];
        $questions[] = [
            'type'          => 'multiple_choice',
            'question_text' => "What light conditions does $common prefer?",
            'options'       => buildSunlightOptions($sun),
            'correct_index' => 0,
            'learn_notes'   => "$common prefers $sun.",
            'difficulty'    => 'easy',
            'image_path'    => null,
            'selected'      => true,
        ];
    }

    // ── Q4: Toxicity ──────────────────────────────────────────────────────────
    if ($plant['toxicity']) {
        $isToxic  = stripos($plant['toxicity'], 'non') === false;
        $toxLabel = $plant['toxicity'];
        $questions[] = [
            'type'          => 'multiple_choice',
            'question_text' => "Is $common toxic to humans?",
            'options'       => $isToxic
                ? ['Yes — it is toxic', 'No — it is non-toxic', 'Only mildly irritating', 'Toxic to pets only']
                : ['No — it is non-toxic', 'Yes — it is toxic', 'Only when ingested in large amounts', 'Toxic to pets but not humans'],
            'correct_index' => 0,
            'learn_notes'   => "$common toxicity: $toxLabel.",
            'difficulty'    => 'easy',
            'image_path'    => null,
            'selected'      => false,  // optional — admin can enable
        ];
    }

    // ── Q5: Watering ──────────────────────────────────────────────────────────
    if ($plant['watering']) {
        $water = $plant['watering'];
        $questions[] = [
            'type'          => 'multiple_choice',
            'question_text' => "How often should $common be watered?",
            'options'       => buildWateringOptions($water),
            'correct_index' => 0,
            'learn_notes'   => "$common watering schedule: $water.",
            'difficulty'    => 'medium',
            'image_path'    => null,
            'selected'      => false,
        ];
    }

    return $questions;
}

/** Build photo-ID options — correct answer first, plausible distractors. */
function buildPhotoOptions(string $correct): array
{
    // Generic plausible distractors; admin can edit before saving
    $distractors = ['Alchemilla mollis', 'Geranium sanguineum', 'Astrantia major'];
    return array_merge([$correct], $distractors);
}

/** Build latin name options — correct answer first. */
function buildLatinOptions(string $latin): array
{
    // Scramble the species epithet for distractors
    $parts    = explode(' ', $latin);
    $genus    = $parts[0] ?? $latin;
    $species  = $parts[1] ?? '';
    return [
        $latin,
        $genus . ' vulgaris',
        $genus . ' pratensis',
        ucfirst(strtolower($genus)) . ' sylvestris',
    ];
}

/** Build sunlight options. */
function buildSunlightOptions(string $correct): array
{
    $all = ['Full sun', 'Partial shade', 'Full shade', 'Deep shade'];
    // Put correct first, then others
    $others = array_values(array_filter($all, fn($o) => stripos($o, $correct) === false && stripos($correct, $o) === false));
    return array_merge([$correct], array_slice($others, 0, 3));
}

/** Build watering options. */
function buildWateringOptions(string $correct): array
{
    $all = ['Every 7 days', 'Every 14 days', 'Every 21 days', 'Every 30 days', 'Twice a week', 'Daily'];
    $others = array_values(array_filter($all, fn($o) => $o !== $correct));
    return array_merge([$correct], array_slice($others, 0, 3));
}
