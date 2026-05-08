<?php
/**
 * run-migration-quiz-images.php
 * Bulk-seeds open-source Wikimedia Commons images for quiz questions.
 *
 * For every active quiz question that has no image yet, this script:
 *   1. Looks up the correct answer text (e.g. "Dandelion")
 *   2. Calls the Wikipedia pageimages API to get the canonical article image
 *   3. Inserts the image URL into quiz_question_images (and sets image_path on the question)
 *
 * Images come from Wikimedia Commons (CC BY-SA / public domain).
 * Attribution: "Wikimedia Commons (CC BY-SA)" — display alongside images.
 *
 * Run once (idempotent — skips questions that already have images).
 * POST to this URL while logged in as admin to trigger.
 *
 * Dry-run mode: add ?dry=1 to the URL to preview without writing to DB.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}
session_write_close();

require_once CRM_INCLUDES . '/functions.php';
$db    = getDB();
$dry   = isset($_GET['dry']);
$log   = [];
$stats = ['found' => 0, 'seeded' => 0, 'skipped' => 0, 'failed' => 0, 'no_image' => 0];

function imgLog(string $msg): void { global $log; $log[] = $msg; }

// ── Fetch questions that have no image and have a visual category ──────────────
// We join quiz_options to get the correct answer text (the species/object name).
// We skip questions that already have an image in quiz_question_images or image_path.
$stmt = $db->prepare("
    SELECT
        q.id,
        q.question_text,
        q.image_path,
        c.name  AS category_name,
        opt.option_text AS correct_answer
    FROM quiz_questions q
    JOIN quiz_categories c ON c.id = q.category_id
    JOIN quiz_options opt ON opt.question_id = q.id AND opt.is_correct = 1
    WHERE q.is_active = 1
      AND (q.image_path IS NULL OR q.image_path = '')
      AND NOT EXISTS (
          SELECT 1 FROM quiz_question_images qi WHERE qi.question_id = q.id
      )
    ORDER BY c.name, q.id
");
$stmt->execute();
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stats['found'] = count($questions);

imgLog("Found {$stats['found']} questions needing images");

// ── Wikimedia Wikipedia pageimages lookup ────────────────────────────────────

function fetchWikipediaImage(string $query): ?string
{
    $url = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action'        => 'query',
        'titles'        => $query,
        'prop'          => 'pageimages',
        'pithumbsize'   => 600,
        'pilimit'       => 1,
        'redirects'     => 1,
        'format'        => 'json',
        'formatversion' => 2,
    ]);

    $ctx = stream_context_create([
        'http' => [
            'timeout'      => 8,
            'user_agent'   => 'MowologyCRM/1.0 (https://mowology.ca; admin@mowology.ca)',
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;

    $data  = json_decode($raw, true);
    $pages = $data['query']['pages'] ?? [];
    $page  = reset($pages);

    if (!$page || isset($page['missing'])) return null;

    return $page['thumbnail']['source'] ?? null;
}

// Some correct answers need a Wikipedia search hint for better results.
// Key: exact option_text (lowercase) → better Wikipedia article title.
$wikiHints = [
    // Grasses
    'kentucky bluegrass'        => 'Kentucky bluegrass',
    'perennial ryegrass'        => 'Lolium perenne',
    'creeping red fescue'       => 'Festuca rubra',
    'tall fescue'               => 'Festuca arundinacea',
    'creeping bentgrass'        => 'Agrostis stolonifera',
    'annual bluegrass'          => 'Poa annua',
    'crabgrass'                 => 'Digitaria',
    'quackgrass'                => 'Elymus repens',
    // Weeds
    'dandelion'                 => 'Taraxacum officinale',
    'creeping buttercup'        => 'Ranunculus repens',
    'canada thistle'            => 'Cirsium arvense',
    'broadleaf plantain'        => 'Plantago major',
    'white clover'              => 'Trifolium repens',
    'common chickweed'          => 'Stellaria media',
    'ground ivy'                => 'Glechoma hederacea',
    'wood sorrel'               => 'Oxalis',
    'hairy bittercress'         => 'Cardamine hirsuta',
    'japanese knotweed'         => 'Reynoutria japonica',
    'english ivy'               => 'Hedera helix',
    'himalayan blackberry'      => 'Rubus armeniacus',
    'field horsetail'           => 'Equisetum arvense',
    'common yarrow'             => 'Achillea millefolium',
    'common groundsel'          => 'Senecio vulgaris',
    'herb robert'               => 'Geranium robertianum',
    'stinging nettle'           => 'Urtica dioica',
    "shepherd's purse"          => 'Capsella bursa-pastoris',
    'creeping jenny'            => 'Lysimachia nummularia',
    'moss'                      => 'Moss',
    'bird\'s-eye speedwell'     => 'Veronica persica',
    // Trees & shrubs
    'english laurel'            => 'Prunus laurocerasus',
    'japanese maple'            => 'Acer palmatum',
    'rhododendron'              => 'Rhododendron',
    'hydrangea'                 => 'Hydrangea',
    'lavender'                  => 'Lavandula',
    'hosta'                     => 'Hosta',
    'kinnikinnick'              => 'Arctostaphylos uva-ursi',
    'sword fern'                => 'Polystichum munitum',
    'red osier dogwood'         => 'Cornus sericea',
    'forsythia'                 => 'Forsythia',
    'ornamental cherry'         => 'Prunus serrulata',
    'yew'                       => 'Taxus',
    'english holly'             => 'Ilex aquifolium',
    'daylily'                   => 'Hemerocallis',
    'bleeding heart'            => 'Lamprocapnos spectabilis',
    'astilbe'                   => 'Astilbe',
    'miscanthus'                => 'Miscanthus sinensis',
    'arbutus'                   => 'Arbutus menziesii',
    'western red cedar'         => 'Thuja plicata',
    'boxwood'                   => 'Buxus',
    // Equipment
    'string trimmer'            => 'String trimmer',
    'lawn mower'                => 'Lawn mower',
    'leaf blower'               => 'Leaf blower',
    'hedge trimmer'             => 'Hedge trimmer',
    'backpack sprayer'          => 'Sprayer',
    'aerator'                   => 'Lawn aerator',
    'dethatching rake'          => 'Thatch (lawn)',
    'broadcast spreader'        => 'Spreader (agriculture)',
    'pressure washer'           => 'Pressure washing',
];

// ── Process each question ─────────────────────────────────────────────────────

$insertImg  = $db->prepare("
    INSERT INTO quiz_question_images (question_id, image_path, sort_order, caption)
    VALUES (?, ?, 0, ?)
    ON DUPLICATE KEY UPDATE image_path = VALUES(image_path), caption = VALUES(caption)
");
$updateQ = $db->prepare("UPDATE quiz_questions SET image_path = ? WHERE id = ?");

foreach ($questions as $q) {
    $answer   = trim($q['correct_answer']);
    $answerLc = strtolower($answer);
    $wikiQ    = $wikiHints[$answerLc] ?? $answer;

    imgLog("Q#{$q['id']} [{$q['category_name']}] correct={$answer} → searching: {$wikiQ}");

    $imgUrl = fetchWikipediaImage($wikiQ);

    // If no result with the hint, try the raw answer text
    if (!$imgUrl && $wikiQ !== $answer) {
        imgLog("  → no result for hint, trying raw answer: {$answer}");
        $imgUrl = fetchWikipediaImage($answer);
    }

    if (!$imgUrl) {
        imgLog("  → NO IMAGE FOUND");
        $stats['no_image']++;
        continue;
    }

    imgLog("  → {$imgUrl}");

    if ($dry) {
        $stats['seeded']++;
        continue;
    }

    try {
        $caption = "Source: Wikimedia Commons (CC BY-SA)";
        $insertImg->execute([$q['id'], $imgUrl, $caption]);
        $updateQ->execute([$imgUrl, $q['id']]);
        $stats['seeded']++;
    } catch (Throwable $e) {
        imgLog("  → DB ERROR: " . $e->getMessage());
        $stats['failed']++;
    }

    // Be polite to the Wikimedia API
    usleep(200_000); // 200ms between requests
}

imgLog("=== Done. found={$stats['found']} seeded={$stats['seeded']} no_image={$stats['no_image']} failed={$stats['failed']} dry=" . ($dry ? 'YES' : 'NO') . " ===");

echo json_encode([
    'success' => true,
    'dry_run' => $dry,
    'stats'   => $stats,
    'log'     => $log,
]);
