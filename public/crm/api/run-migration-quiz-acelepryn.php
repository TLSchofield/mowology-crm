<?php
/**
 * Quiz Seed: Acelepryn — 25 Questions (5 Sections)
 * Category: Pest & Disease ID (id=5)
 *
 * Run once via browser (admin only) or CLI:
 *   /usr/local/bin/php public/crm/api/run-migration-quiz-acelepryn.php
 */

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}

require_once dirname(__DIR__) . '/../loginAuth/auth.php';

// Allow CLI execution
if (php_sapi_name() === 'cli') {
    // Bootstrap DB manually for CLI
    require_once dirname(__DIR__) . '/../app_config/config.php';
} else {
    requireLogin();
    if (getCurrentUser()['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin only']);
        exit;
    }
}

header('Content-Type: application/json');

try {
    $db = getDB();

    // Category 5 = Pest & Disease ID
    $CATEGORY_ID = 5;

    // created_by = first admin user
    $creator = $db->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $CREATED_BY = $creator ? (int)$creator['id'] : 1;

    $questions = [

        // ─── Section 1: Core Understanding ──────────────────────────────────
        [
            'text'    => 'What is Acelepryn primarily used for?',
            'notes'   => "Acelepryn is a systemic insecticide used specifically for grub (larval insect) control in turf. It is NOT a herbicide, fungicide, or soil amendment.\n\nKey fact: Brand name for chlorantraniliprole — the most effective and environmentally preferred grub control registered for use in Canada.",
            'diff'    => 'easy',
            'level'   => 1,
            'months'  => '5,6,7,8',
            'tags'    => 'acelepryn,grub-control,pesticides',
            'options' => [
                ['Weed control', false],
                ['Fungicide', false],
                ['Insect control (grubs)', true],
                ['Soil amendment', false],
            ],
        ],
        [
            'text'    => 'What is the active ingredient in Acelepryn?',
            'notes'   => "Acelepryn\'s active ingredient is chlorantraniliprole — an anthranilic diamide insecticide.\n\nMemory tip: Imidacloprid is a neonicotinoid (Merit/Premise). Glyphosate is Roundup. Urea is fertilizer. Chlorantraniliprole = Acelepryn.",
            'diff'    => 'medium',
            'level'   => 2,
            'months'  => '5,6,7,8',
            'tags'    => 'acelepryn,active-ingredient,pesticides',
            'options' => [
                ['Imidacloprid', false],
                ['Glyphosate', false],
                ['Chlorantraniliprole', true],
                ['Urea', false],
            ],
        ],
        [
            'text'    => 'Acelepryn belongs to which class of insecticides?',
            'notes'   => "Acelepryn is an anthranilic diamide — a newer, highly selective class of insecticides.\n\nClass comparison:\n• Neonicotinoids: imidacloprid (Merit) — bee toxicity concerns\n• Anthranilic diamides: chlorantraniliprole (Acelepryn) — much lower pollinator risk\n• Pyrethroids: permethrin — broad spectrum, nerve toxins\n• Organophosphates: older, higher mammalian toxicity",
            'diff'    => 'hard',
            'level'   => 3,
            'months'  => '5,6,7,8',
            'tags'    => 'acelepryn,chemistry,pesticides',
            'options' => [
                ['Neonicotinoids', false],
                ['Anthranilic diamides', true],
                ['Pyrethroids', false],
                ['Organophosphates', false],
            ],
        ],
        [
            'text'    => 'Why is Acelepryn preferred in Canada?',
            'notes'   => "Acelepryn is the preferred grub control in Canada because:\n• Lower environmental impact vs older products\n• Legal for use on turf in BC (some older chemistries restricted)\n• Reduced risk to pollinators and non-target organisms\n• Effective at low use rates",
            'diff'    => 'easy',
            'level'   => 1,
            'months'  => '5,6,7,8',
            'tags'    => 'acelepryn,regulations,pesticides',
            'options' => [
                ['Cheaper', false],
                ['Faster acting', false],
                ['Lower environmental impact and legal for turf', true],
                ['Kills weeds too', false],
            ],
        ],
        [
            'text'    => 'What pests does Acelepryn target in lawns?',
            'notes'   => "Acelepryn targets soil-dwelling larval insects, primarily:\n• European chafer (Rhizotrogus majalis) — #1 grub pest in Metro Vancouver\n• Japanese beetle larvae\n• June beetle grubs\n• Crane fly larvae (leatherjackets)\n\nIt does NOT control ants, slugs, or moss.",
            'diff'    => 'easy',
            'level'   => 1,
            'months'  => '5,6,7,8',
            'tags'    => 'acelepryn,grubs,chafer,pests',
            'options' => [
                ['Ants', false],
                ['Slugs', false],
                ['Grubs (chafer, beetle larvae)', true],
                ['Moss', false],
            ],
        ],

        // ─── Section 2: Timing Mastery ───────────────────────────────────────
        [
            'text'    => 'Ideal Acelepryn application window in Vancouver is:',
            'notes'   => "Vancouver application window: late June to mid-July.\n\nWhy: European chafer eggs hatch in early-to-mid July. Acelepryn must be present in the soil BEFORE eggs hatch so newly emerged first-instar larvae contact the product immediately.\n\nApril-May = too early (product may degrade before eggs hatch)\nSeptember = too late (grubs are L3, much less susceptible)\nDecember = inactive soil, no grub activity",
            'diff'    => 'medium',
            'level'   => 4,
            'months'  => '6,7',
            'tags'    => 'acelepryn,timing,application,chafer',
            'options' => [
                ['April–May', false],
                ['Late June–Mid July', true],
                ['September', false],
                ['December', false],
            ],
        ],
        [
            'text'    => 'Why is timing critical with Acelepryn?',
            'notes'   => "Timing is critical because Acelepryn targets early larval stage (L1) grubs.\n\n• L1 larvae: tiny, feeding near surface, highly susceptible\n• L2 larvae: moderate susceptibility\n• L3 larvae (late summer/fall): large, deep, much less susceptible\n\nAcelepryn does NOT need rain to activate contact with larvae — it moves into the root zone and larvae must ingest it. Early application catches them when they are most vulnerable.",
            'diff'    => 'medium',
            'level'   => 4,
            'months'  => '6,7',
            'tags'    => 'acelepryn,timing,larval-stage',
            'options' => [
                ['It only works in rain', false],
                ['Targets early larval stage', true],
                ['Only works in cold soil', false],
                ['Only affects adults', false],
            ],
        ],
        [
            'text'    => 'What visual cue helps identify Acelepryn application timing?',
            'notes'   => "Beetle flight at dusk is the key timing indicator for European chafer.\n\nSigns to watch for:\n• June/July evenings: adult chafers fly to trees at dusk in large numbers\n• Crows/starlings digging lawns (eating grubs from prior year)\n• Skunks and raccoons making conical holes overnight\n\nBeetle flight = eggs being laid NOW = apply Acelepryn within 2–3 weeks.",
            'diff'    => 'medium',
            'level'   => 4,
            'months'  => '6,7',
            'tags'    => 'acelepryn,timing,chafer,field-observation',
            'options' => [
                ['Snow melt', false],
                ['Weed growth', false],
                ['Beetle flight at dusk', true],
                ['Mushrooms in lawn', false],
            ],
        ],
        [
            'text'    => 'What happens if Acelepryn is applied too late?',
            'notes'   => "Late application = reduced effectiveness because grubs are larger (L2/L3 stage).\n\nL3 grubs feed deeper in the soil profile and are physiologically less susceptible to chlorantraniliprole. You may still see some control but results will be inconsistent.\n\nPro tip: If you miss the window, document it and advise the client to expect partial control — never guarantee results on late applications.",
            'diff'    => 'medium',
            'level'   => 4,
            'months'  => '6,7,8',
            'tags'    => 'acelepryn,timing,late-application',
            'options' => [
                ['Stronger results', false],
                ['No difference', false],
                ['Reduced effectiveness on larger grubs', true],
                ['Kills grass', false],
            ],
        ],
        [
            'text'    => 'Acelepryn has what timing advantage over older products?',
            'notes'   => "Acelepryn has a wider application window than older grub controls like trichlorfon (Dylox) or chlorpyrifos.\n\nOlder products: narrow window, must hit exact larval stage\nAcelepryn: can be applied preventatively (before eggs even hatch) and still remains effective — residual activity lasts longer in soil.\n\nThis makes it more forgiving and better suited to preventive programs.",
            'diff'    => 'medium',
            'level'   => 4,
            'months'  => '5,6,7,8',
            'tags'    => 'acelepryn,timing,advantages',
            'options' => [
                ['Very short window', false],
                ['No timing needed', false],
                ['Wider application window than older products', true],
                ['Only fall application', false],
            ],
        ],

        // ─── Section 3: Application Technique ───────────────────────────────
        [
            'text'    => 'After applying Acelepryn, you should:',
            'notes'   => "Always water in Acelepryn after application.\n\nChlorantraniliprole binds tightly to organic matter on the surface. Watering moves it down into the root zone (top 2–3 inches) where newly hatched larvae are feeding. Without irrigation, the product stays on the thatch and loses effectiveness.",
            'diff'    => 'easy',
            'level'   => 3,
            'months'  => '6,7',
            'tags'    => 'acelepryn,application,irrigation',
            'options' => [
                ['Leave it dry', false],
                ['Water it in', true],
                ['Mow immediately', false],
                ['Rake', false],
            ],
        ],
        [
            'text'    => 'Why must Acelepryn be watered in after application?',
            'notes'   => "Watering moves Acelepryn into the root zone where grubs feed.\n\nChlorantraniliprole is not water-soluble enough to move on its own in typical rainfall — irrigation physically pushes it past the thatch layer into the top 2–3 inches of soil. This is where early-instar larvae are actively feeding on grass roots.\n\nWithout watering in: product sits on thatch, grubs never contact it, application fails.",
            'diff'    => 'medium',
            'level'   => 3,
            'months'  => '6,7',
            'tags'    => 'acelepryn,irrigation,root-zone',
            'options' => [
                ['Improves color', false],
                ['Moves product into root zone where grubs feed', true],
                ['Prevents weeds', false],
                ['Saves time', false],
            ],
        ],
        [
            'text'    => 'Ideal irrigation amount after Acelepryn application is:',
            'notes'   => "Apply ~0.25 to 0.5 inch of water (6–12 mm) after Acelepryn.\n\n• Too little: product doesn't reach root zone\n• Too much (flooding): product leaches past root zone into soil — wasted and potential runoff\n\nQuick test: place a tuna can on the lawn while irrigating — stop when it has 0.25 to 0.5 inch in it.",
            'diff'    => 'medium',
            'level'   => 3,
            'months'  => '6,7',
            'tags'    => 'acelepryn,irrigation,rate',
            'options' => [
                ['None', false],
                ['Heavy flooding', false],
                ['~0.25–0.5 inch', true],
                ['Just morning dew', false],
            ],
        ],
        [
            'text'    => 'Best soil condition before applying Acelepryn:',
            'notes'   => "Slightly moist soil is ideal before Acelepryn application.\n\n• Bone dry: application water will be absorbed by dry soil before moving product to root zone — reduced distribution\n• Frozen: application impossible, product won't move\n• Slightly moist: soil ready to receive and distribute product uniformly with follow-up irrigation\n• Muddy: runoff risk, product may move laterally off target area",
            'diff'    => 'medium',
            'level'   => 3,
            'months'  => '6,7',
            'tags'    => 'acelepryn,soil-condition,application',
            'options' => [
                ['Bone dry', false],
                ['Frozen', false],
                ['Slightly moist', true],
                ['Muddy', false],
            ],
        ],
        [
            'text'    => 'Applying Acelepryn before heavy rain can:',
            'notes'   => "Heavy rain after Acelepryn application can wash the product too deep — below the root zone where grubs feed.\n\nChlorantraniliprole needs to be in the top 2–3 inches of soil. If >1 inch of rain falls immediately after application, the product may leach past this zone, especially in sandy soils.\n\nBest practice: apply when no heavy rain is forecast for 24–48 hours, then irrigate with a controlled amount.",
            'diff'    => 'medium',
            'level'   => 3,
            'months'  => '6,7',
            'tags'    => 'acelepryn,rain,leaching,application',
            'options' => [
                ['Improve results', false],
                ['Wash product too deep', true],
                ['Kill weeds', false],
                ['Do nothing', false],
            ],
        ],

        // ─── Section 4: How It Works ─────────────────────────────────────────
        [
            'text'    => 'Acelepryn affects insects by:',
            'notes'   => "Acelepryn disrupts muscle function in insects.\n\nChlorantraniliprole activates ryanodine receptors — calcium channels in muscle cells — causing uncontrolled calcium release. This leads to:\n• Uncoordinated muscle contractions\n• Paralysis\n• Cessation of feeding\n• Death within 24–72 hours\n\nThis mechanism is very different from nerve-acting insecticides (organophosphates, pyrethroids).",
            'diff'    => 'hard',
            'level'   => 3,
            'months'  => '1,2,3,4,5,6,7,8,9,10,11,12',
            'tags'    => 'acelepryn,mode-of-action,muscle',
            'options' => [
                ['Burning them', false],
                ['Disrupting muscle function', true],
                ['Blocking sunlight', false],
                ['Drying them out', false],
            ],
        ],
        [
            'text'    => 'The target system in insects for Acelepryn is:',
            'notes'   => "Acelepryn targets ryanodine receptors — calcium channels in muscle cells.\n\nTechnical detail: chlorantraniliprole locks insect ryanodine receptors in the OPEN position, flooding muscle cells with calcium ions. Muscles cannot relax → paralysis → death.\n\nWhy this matters for safety: vertebrate (mammal/bird) ryanodine receptors have different structure → Acelepryn has very low affinity for mammalian receptors → much safer for humans, dogs, birds.",
            'diff'    => 'hard',
            'level'   => 3,
            'months'  => '1,2,3,4,5,6,7,8,9,10,11,12',
            'tags'    => 'acelepryn,ryanodine,calcium,mode-of-action',
            'options' => [
                ['Digestive system', false],
                ['Nervous/muscle calcium channels', true],
                ['Wings', false],
                ['Shell', false],
            ],
        ],
        [
            'text'    => 'Why is Acelepryn safer for mammals than older insecticides?',
            'notes'   => "Acelepryn is safer for mammals because mammalian ryanodine receptors have different structure/sensitivity than insect receptors.\n\nChlorantraniliprole binds strongly to insect ryanodine receptors but has very low binding affinity to mammalian versions. This makes it highly selective.\n\nCompare to organophosphates: inhibit acetylcholinesterase in ALL animals — very dangerous to mammals. Acelepryn exploits a structural difference in the target receptor.",
            'diff'    => 'hard',
            'level'   => 3,
            'months'  => '1,2,3,4,5,6,7,8,9,10,11,12',
            'tags'    => 'acelepryn,safety,mammal,selectivity',
            'options' => [
                ['Doesn\'t enter body', false],
                ['Different receptor sensitivity', true],
                ['Only works in soil', false],
                ['Evaporates instantly', false],
            ],
        ],
        [
            'text'    => 'Acelepryn is considered:',
            'notes'   => "Acelepryn is a highly selective insecticide — it targets specific insects (larvae of beetles/flies) while having low toxicity to most non-target organisms.\n\nSelectivity profile:\n• Highly effective: grubs, caterpillars, some beetle adults\n• Moderate: some other insects\n• Low/no effect: earthworms, most beneficial insects at label rates\n• Very low: mammals, birds, fish\n\nIt is NOT a fertilizer or herbicide.",
            'diff'    => 'medium',
            'level'   => 2,
            'months'  => '1,2,3,4,5,6,7,8,9,10,11,12',
            'tags'    => 'acelepryn,selectivity,classification',
            'options' => [
                ['Broad-spectrum toxic', false],
                ['Highly selective insecticide', true],
                ['Fertilizer', false],
                ['Herbicide', false],
            ],
        ],
        [
            'text'    => 'Compared to older grub control products, Acelepryn has:',
            'notes'   => "Compared to older products (trichlorfon, chlorpyrifos, carbaryl), Acelepryn has significantly lower environmental impact:\n\n• Much lower mammalian toxicity\n• Reduced risk to pollinators (vs neonicotinoids)\n• Lower aquatic toxicity\n• Not classified as a restricted-use pesticide in Canada\n• Longer residual = fewer applications needed\n\nOlder products had shorter windows, higher toxicity, and some are now banned for turf use in Canada.",
            'diff'    => 'medium',
            'level'   => 2,
            'months'  => '1,2,3,4,5,6,7,8,9,10,11,12',
            'tags'    => 'acelepryn,comparison,environmental-impact',
            'options' => [
                ['Higher toxicity', false],
                ['Lower environmental impact', true],
                ['No effectiveness', false],
                ['Faster breakdown only', false],
            ],
        ],

        // ─── Section 5: Professional Turf Strategy ────────────────────────────
        [
            'text'    => 'What is the biggest cause of grub control failure?',
            'notes'   => "Poor timing is the #1 cause of Acelepryn failure.\n\nEven with the correct product, correct rate, and proper watering-in — applying outside the critical window (late June to mid-July in Vancouver) will yield poor results.\n\nOther causes of failure:\n• Not watering in (product stays in thatch)\n• Poor soil contact (extremely dry/compacted soil)\n• Heavy rain immediately after (leaching)\n\nBut timing is by far the most common mistake.",
            'diff'    => 'medium',
            'level'   => 6,
            'months'  => '6,7',
            'tags'    => 'acelepryn,failure,timing,professional',
            'options' => [
                ['Wrong mower', false],
                ['Poor timing', true],
                ['Too much fertilizer', false],
                ['Shade', false],
            ],
        ],
        [
            'text'    => 'Acelepryn works best when applied:',
            'notes'   => "Preventative application — before major grub damage appears — is the professional approach.\n\nCurative (after damage): grubs are L3, deep in soil, less susceptible. Damage already done — dead grass, sod lifting. Results variable.\n\nPreventative (before damage): grubs are L1/L2, near surface, highly susceptible. No visible damage yet. Full season protection.\n\nPro standard: recommend Acelepryn every spring/early summer as a preventative program, not reactive treatment.",
            'diff'    => 'medium',
            'level'   => 6,
            'months'  => '5,6,7',
            'tags'    => 'acelepryn,preventative,professional,strategy',
            'options' => [
                ['After damage appears', false],
                ['Preventatively before major damage', true],
                ['In winter', false],
                ['Only after rain', false],
            ],
        ],
        [
            'text'    => 'A healthy lawn improves Acelepryn results because:',
            'notes'   => "Strong roots tolerate minor grub damage better, giving Acelepryn time to work.\n\nEven with perfect application, some grub feeding occurs before larvae die (24–72 hr). A deep-rooted, well-fertilized lawn can withstand 5–10 grubs/sqft without showing visible damage.\n\nA stressed lawn (drought, low fertility, shallow roots) shows damage at 2–3 grubs/sqft — before the product even finishes working.\n\nIntegrated approach: healthy lawn + preventative Acelepryn = best outcome.",
            'diff'    => 'medium',
            'level'   => 6,
            'months'  => '5,6,7,8',
            'tags'    => 'acelepryn,lawn-health,integrated-pest-management',
            'options' => [
                ['It grows faster', false],
                ['Strong roots tolerate minor damage', true],
                ['It absorbs chemicals better', false],
                ['It kills insects', false],
            ],
        ],
        [
            'text'    => 'What is the key Vancouver grub pest that Acelepryn targets?',
            'notes'   => "European chafer (Rhizotrogus majalis) is the dominant grub pest in Metro Vancouver.\n\nID notes:\n• Adult: tan/brown beetle, 12–14 mm\n• Flies at dusk in June–July to gather on trees\n• Eggs laid July in turf\n• Larvae (grubs): C-shaped, cream-coloured, up to 20mm\n• Raster pattern: V-shaped row of spines (distinguishes from Japanese beetle)\n\nDamage: sod can be rolled back like a carpet in fall/spring.",
            'diff'    => 'easy',
            'level'   => 1,
            'months'  => '6,7,8,9',
            'tags'    => 'acelepryn,chafer,european-chafer,vancouver',
            'options' => [
                ['Armyworms', false],
                ['European chafer', true],
                ['Fire ants', false],
                ['Termites', false],
            ],
        ],
        [
            'text'    => 'Elite crews monitoring for grub pressure track:',
            'notes'   => "Elite monitoring combines three data points:\n\n1. Beetle flights: watch for adult chafers swarming at dusk on warm June/July evenings — confirms eggs being laid NOW\n2. Soil temperatures: grub egg hatch is triggered by soil temp (target treatment when soil reaches 15–18°C at 5cm depth)\n3. Timing windows: track application date relative to historical beetle emergence in your area\n\nThis three-factor approach removes guesswork and maximizes Acelepryn effectiveness.",
            'diff'    => 'hard',
            'level'   => 6,
            'months'  => '6,7',
            'tags'    => 'acelepryn,monitoring,elite,beetle-flight,soil-temperature',
            'options' => [
                ['Only grass color', false],
                ['Only weeds', false],
                ['Beetle flights + soil temps + timing', true],
                ['Nothing', false],
            ],
        ],
    ];

    $results = [];
    $inserted = 0;

    $qStmt = $db->prepare(
        "INSERT INTO quiz_questions
            (category_id, question_text, learn_notes, difficulty, question_type,
             learning_level, seasonal_priority, relevant_months, seasonal_tags,
             is_active, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,1,?)"
    );

    $oStmt = $db->prepare(
        "INSERT INTO quiz_options (question_id, option_text, is_correct) VALUES (?,?,?)"
    );

    foreach ($questions as $i => $q) {
        // Check for duplicate
        $exists = $db->prepare("SELECT id FROM quiz_questions WHERE question_text = ? AND category_id = ?");
        $exists->execute([$q['text'], $CATEGORY_ID]);
        if ($exists->fetchColumn()) {
            $results[] = "SKIP (duplicate): Q" . ($i + 1) . " — {$q['text']}";
            continue;
        }

        $qStmt->execute([
            $CATEGORY_ID,
            $q['text'],
            $q['notes'],
            $q['diff'],
            'multiple_choice',
            $q['level'],
            7,                 // seasonal_priority — high for field-critical knowledge
            $q['months'],
            $q['tags'],
            $CREATED_BY,
        ]);

        $qid = (int)$db->lastInsertId();

        foreach ($q['options'] as $opt) {
            $oStmt->execute([$qid, $opt[0], $opt[1] ? 1 : 0]);
        }

        $inserted++;
        $results[] = "INSERTED: Q" . ($i + 1) . " (id=$qid) — {$q['text']}";
    }

    echo json_encode([
        'success'  => true,
        'inserted' => $inserted,
        'skipped'  => count($questions) - $inserted,
        'total'    => count($questions),
        'detail'   => $results,
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}
