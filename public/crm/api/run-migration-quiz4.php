<?php
/**
 * /public/crm/api/run-migration-quiz4.php
 * Seeds 100 framework training questions from the Claude Framework Direction Prompt.
 * Covers 19 knowledge areas across 7 categories (adds Customer Service cat if missing).
 * Assigns question_type and learning_level from the Part 1 framework schema.
 * Idempotent — skips questions already in DB (matched on text + category).
 * Run once as admin, then this file can be left in place (re-running is safe).
 */
declare(strict_types=1);
header('Content-Type: application/json');

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
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

session_write_close();

$db = getDB();
$steps  = [];
$errors = [];

// ── 1. Ensure Customer Service category exists ────────────────────────────────
try {
    $exists = $db->prepare("SELECT id FROM quiz_categories WHERE name = ? LIMIT 1");
    $exists->execute(['Customer Service']);
    if (!$exists->fetch()) {
        $db->prepare(
            "INSERT INTO quiz_categories (name, description, icon, colour, is_active, sort_order)
             VALUES (?, ?, ?, ?, 1, ?)"
        )->execute([
            'Customer Service',
            'Explain services to clients, sales knowledge, and professional standards.',
            'users',
            '#0077b6',
            7,
        ]);
        $steps[] = '✅ Created Customer Service category';
    } else {
        $steps[] = '✅ Customer Service category already exists';
    }
} catch (PDOException $e) {
    $errors[] = 'Category create error: ' . $e->getMessage();
}

// ── 2. Load category IDs ──────────────────────────────────────────────────────
$catRows  = $db->query("SELECT id, name FROM quiz_categories")->fetchAll(PDO::FETCH_KEY_PAIR);
$byName   = array_flip($catRows);

$weedCat  = (int)($byName['Weed Identification'] ?? 0);
$plantCat = (int)($byName['Plant & Grass ID']    ?? 0);
$equipCat = (int)($byName['Equipment & Tools']   ?? 0);
$pestCat  = (int)($byName['Pest & Disease ID']   ?? 0);
$turfCat  = (int)($byName['Turf & Soil']         ?? 0);
$svcCat   = (int)($byName['Customer Service']    ?? 0);

$missing = [];
foreach ([
    'Weed Identification' => $weedCat,
    'Plant & Grass ID'    => $plantCat,
    'Equipment & Tools'   => $equipCat,
    'Pest & Disease ID'   => $pestCat,
    'Turf & Soil'         => $turfCat,
    'Customer Service'    => $svcCat,
] as $n => $id) {
    if (!$id) $missing[] = $n;
}
if ($missing) {
    echo json_encode(['error' => 'Missing categories — run run-migration-quiz.php first', 'missing' => $missing]);
    exit;
}

// ── 3. Question data ──────────────────────────────────────────────────────────
// [$catId, $question_text, $difficulty, $learn_notes, $question_type, $learning_level, $correct, [$wrong1,$wrong2,$wrong3]]

$questions = [

    // ═══════════════════════════════════════════════════════════════════════
    // TURF KNOWLEDGE (10) — Turf & Soil cat, learning levels 1–3
    // ═══════════════════════════════════════════════════════════════════════
    [$turfCat,
        'What does lawn aeration primarily improve?', 'easy',
        "Aeration removes plugs of soil to relieve compaction.\nBenefits: better oxygen flow to roots, improved water infiltration, reduced thatch buildup.\nBest time in Vancouver: spring (April–May) or fall (Sept–Oct).\nFor heavily compacted clay soil, consider double-passing with the aerator.",
        'multiple_choice', 1,
        'Soil compaction', ['Weed growth', 'Soil colour', 'Grass height']],

    [$turfCat,
        'What grass type grows best in Vancouver\'s cool, wet climate?', 'easy',
        "Perennial ryegrass thrives in Vancouver's mild winters and moist summers.\nIt germinates quickly (5–7 days), establishes fast, and stays green year-round.\nOther cool-season grasses used: creeping red fescue (shade), Kentucky bluegrass (sunny).\nAvoid warm-season grasses (Bermuda, Zoysia, St. Augustine) — they go dormant in BC winters.",
        'multiple_choice', 1,
        'Perennial ryegrass', ['Bermuda grass', 'Zoysia', 'St. Augustine']],

    [$turfCat,
        'What does moss in a lawn usually indicate?', 'easy',
        "Moss thrives where grass struggles — common causes:\n• Shade (tree canopy, fences, structures)\n• Poor drainage / compacted soil\n• Low soil pH\n• Low fertility\nCorrection: improve drainage, overseed with shade-tolerant fescue, apply lime to raise pH, reduce shade if possible.",
        'multiple_choice', 2,
        'Shade or poor drainage', ['Too much fertilizer', 'Too much sun', 'Too much aeration']],

    [$turfCat,
        'When should lawns be aerated in Vancouver?', 'easy',
        "Best aeration timing in BC:\n• Spring (April–May): grass is actively growing and recovers quickly.\n• Fall (September–October): soil is still warm, cooler air reduces stress, roots develop through winter.\nAvoid: mid-summer heat stress or when soil is frozen/waterlogged.\nFor clay soils with heavy compaction, annual aeration is recommended.",
        'multiple_choice', 4,
        'Spring or fall', ['Mid-winter', 'Mid-summer heat', 'During snow']],

    [$turfCat,
        'What causes lawn scalping?', 'easy',
        "Scalping = cutting grass too short, exposing the crown and stems.\nResults in brown, stressed, weakened turf that invites weeds and disease.\nRule of thumb: never remove more than 1/3 of the blade length in one cut.\nHealthy mowing height for Vancouver lawns: 2.5–3.5 inches (6–9 cm).\nScalping commonly happens after skipping mows, then cutting low to 'catch up'.",
        'multiple_choice', 1,
        'Cutting too short', ['Too much fertilizer', 'Overwatering', 'Clay soil']],

    [$turfCat,
        'What encourages deeper grass root growth?', 'medium',
        "Deep, infrequent watering trains roots to grow downward seeking moisture.\nTarget: 25mm (1 inch) per week, applied in 2–3 sessions rather than daily.\nShallow daily watering keeps roots near the surface — vulnerable to drought and heat.\nSoil aeration also promotes deeper rooting by relieving compaction.\nHealthy deep roots = more drought-resistant, more resilient turf.",
        'multiple_choice', 3,
        'Deep infrequent watering', ['Daily shallow watering', 'No watering', 'Fertilizer only']],

    [$turfCat,
        'What does yellow grass usually indicate first?', 'easy',
        "Yellow turf is most commonly a nitrogen deficiency — nitrogen is the primary nutrient for green colour.\nOther causes to check: iron deficiency (yellowing between veins), drought stress, disease, or scalping.\nFertilizer fix: apply a balanced slow-release N-P-K fertilizer during growing season.\nFirst step: always check for obvious causes (drought, mowing height) before assuming nutrient deficiency.",
        'multiple_choice', 2,
        'Nutrient deficiency', ['Too much oxygen', 'Excess sunlight', 'Neutral soil pH']],

    [$turfCat,
        'What does lawn thatch consist of?', 'medium',
        "Thatch is the layer of living and dead organic matter between the grass blades and the soil surface.\nMade of: dead grass stems, roots, crowns, and rhizomes.\nThin thatch (<1 cm): beneficial — acts as mulch.\nThick thatch (>1 cm): blocks water and nutrients, harbours pests and disease.\nControl: regular aeration, dethatching (scarifying), avoiding excess nitrogen.",
        'multiple_choice', 1,
        'Dead grass stems and roots', ['Soil particles', 'Fertilizer residue', 'Sand']],

    [$turfCat,
        'What mowing height is healthiest for most Vancouver lawns?', 'easy',
        "3 inches (7.5 cm) is the ideal mowing height for most cool-season turf in BC.\nWhy taller is better:\n• Shades soil to retain moisture\n• Reduces weed germination (blocks light)\n• Allows deeper root development\n• Less stress on the plant\nRaise blade in summer heat. Lower slightly (2.5\") in spring/fall for neat appearance.",
        'multiple_choice', 1,
        '3 inches', ['1 inch', '5 inches', 'Half an inch']],

    [$turfCat,
        'What mowing practice most improves lawn density over time?', 'easy',
        "Regular consistent mowing stimulates lateral growth and tillering — the grass produces more shoots, filling in thin areas.\nKey practices: maintain a regular schedule (weekly in growing season), never remove more than 1/3 of blade length, keep blades sharp, vary mowing direction each visit to prevent grain.\nInfrequent mowing and then heavy cutting weakens the turf and reduces density.",
        'multiple_choice', 3,
        'Regular consistent mowing', ['Rare mowing', 'Cutting extremely short', 'Skipping mowing entirely']],

    // ═══════════════════════════════════════════════════════════════════════
    // WEED IDENTIFICATION (5) — Weed cat, level 1 Recognition
    // ═══════════════════════════════════════════════════════════════════════
    [$weedCat,
        'What weed has bright yellow flowers and jagged serrated leaves?', 'easy',
        "Dandelion (Taraxacum officinale) — one of the most common broadleaf weeds in BC.\nKey identifiers: yellow ray flowers, hollow stems with milky sap, deep taproot, basal rosette.\nSeeds: white spherical puffball that disperses with wind.\nControl: hand-pull including taproot, broadleaf herbicide, or improve turf density to crowd them out.",
        'multiple_choice', 1,
        'Dandelion', ['Clover', 'Plantain', 'Crabgrass']],

    [$weedCat,
        'What weed often indicates low nitrogen in the soil?', 'easy',
        "Clover (Trifolium spp.) is a nitrogen-fixing legume — it thrives where grass is thin due to low nitrogen.\nClover's root bacteria convert atmospheric N₂ into soil nitrogen, giving it a competitive advantage over nutrient-starved turf.\nIdentifiers: three-leaf clusters, white or pink flowers, creeping growth habit.\nFix: apply nitrogen fertilizer to strengthen turf and crowd out clover. Broadleaf herbicide is also effective.",
        'multiple_choice', 2,
        'Clover', ['Moss', 'Dandelion', 'Thistle']],

    [$weedCat,
        'Which weed grows flat along the ground in a spreading mat?', 'easy',
        "Crabgrass (Digitaria spp.) spreads in a flat, star-like pattern from a central point.\nIt is an annual grassy weed that germinates in spring when soil warms, grows aggressively through summer, then dies in fall — leaving gaps for next year's seeds.\nControl: pre-emergent herbicide in spring (before soil reaches 10°C), healthy dense turf, hand removal before seed set.",
        'multiple_choice', 1,
        'Crabgrass', ['Clover', 'Thistle', 'Plantain']],

    [$weedCat,
        'What weed has wide ribbed leaves and grows in a basal rosette?', 'easy',
        "Broadleaf Plantain (Plantago major) has wide oval leaves with prominent parallel veins and a tough fibrous root system.\nIt tolerates compacted, wet, and low-fertility soils where grass struggles.\nFlower spike: tall, narrow, bearing tiny greenish flowers.\nControl: improve soil drainage and fertility, hand-pull or broadleaf herbicide. Aeration reduces plantain's advantage.",
        'multiple_choice', 1,
        'Plantain', ['Clover', 'Chickweed', 'Dandelion']],

    [$weedCat,
        'What weed produces white spherical puffball seed heads?', 'easy',
        "Dandelion (Taraxacum officinale) produces the iconic white puffball (seed clock) after flowering.\nEach puffball contains 100–200 achene seeds, each with a feathery parachute (pappus) for wind dispersal.\nA single dandelion can produce thousands of seeds per season, spreading up to 5km in wind.\nBest control: remove before seed set, or apply broadleaf herbicide in fall when plants are actively drawing nutrients to roots.",
        'multiple_choice', 1,
        'Dandelion', ['Clover', 'Moss', 'Japanese knotweed']],

    // ═══════════════════════════════════════════════════════════════════════
    // WEED CONTROL (5) — Weed cat, level 3 Treatment
    // ═══════════════════════════════════════════════════════════════════════
    [$weedCat,
        'What is the single best long-term way to prevent weeds in a lawn?', 'easy',
        "A thick, healthy, dense turf canopy is the best weed prevention.\nDense grass physically blocks weed seed germination by shading the soil.\nWeed prevention strategy: mow at 3\", fertilize regularly, overseed thin areas, aerate annually.\nHerbicides treat weeds but don't prevent them long-term — healthy turf does.",
        'multiple_choice', 3,
        'Healthy dense turf', ['Cutting grass short', 'Daily watering', 'Leaving soil bare']],

    [$weedCat,
        'What treatment prevents crabgrass from germinating?', 'medium',
        "Pre-emergent herbicide creates a chemical barrier in the soil that prevents crabgrass seeds from germinating.\nTiming is critical: apply before soil temperature reaches 10°C (50°F) consistently — typically early April in Vancouver.\nProducts: corn gluten meal (organic), or synthetic pre-emergents (prodiamine, pendimethalin).\nDo NOT overseed after applying pre-emergent — it also blocks desirable grass seeds.",
        'multiple_choice', 3,
        'Pre-emergent herbicide', ['Aeration', 'Overseeding', 'Raking']],

    [$weedCat,
        'What time of year do most broadleaf weeds germinate in Vancouver?', 'easy',
        "Spring is peak weed germination — warming soil temperatures (8–15°C) trigger seed germination for most annual and perennial weeds.\nDandelion: germinates early spring, also reseed in fall.\nCrabgrass: germinates when soil consistently reaches 10°C (late April–May).\nThistle and clover: throughout spring and early summer.\nPro tip: apply pre-emergent control in early April to intercept spring germination.",
        'multiple_choice', 4,
        'Spring', ['Winter', 'Late fall', 'Snow season']],

    [$weedCat,
        'Why do weeds invade thin, sparse lawns?', 'easy',
        "Thin turf = exposed bare soil = open opportunity for weed seeds to germinate.\nWeed seeds require light to germinate — dense turf shades the soil, blocking their germination.\nFix: overseed thin areas, fertilize to thicken turf, aerate to improve root depth.\nA lawn with 95%+ coverage density is significantly less susceptible to weed invasion.",
        'multiple_choice', 2,
        'Open soil space for seeds to germinate', ['Too much fertilizer', 'Too much shade', 'Too much mowing']],

    [$weedCat,
        'What service most effectively thickens turf to crowd out weeds?', 'easy',
        "Overseeding introduces millions of new grass seeds into an existing lawn, dramatically increasing turf density.\nProcess: mow low, scarify or aerate to improve seed-soil contact, spread seed, apply starter fertilizer, water daily until germination.\nBest time in Vancouver: late August to October — warm soil, cooling air, good moisture.\nDenser turf = less bare soil = fewer weeds.",
        'multiple_choice', 3,
        'Overseeding', ['Mowing shorter', 'Herbicide only', 'Sand topdressing']],

    // ═══════════════════════════════════════════════════════════════════════
    // SOIL KNOWLEDGE (5) — Turf & Soil cat, level 2
    // ═══════════════════════════════════════════════════════════════════════
    [$turfCat,
        'What soil type compacts most easily under foot and equipment traffic?', 'easy',
        "Clay soil has extremely small, flat particles that stack tightly under pressure, eliminating air pockets.\nCompacted clay: poor drainage, minimal oxygen to roots, surface runoff, weak grass.\nSigns of clay compaction: water pools after rain, soil feels rock-hard when dry, poor grass growth.\nFix: core aeration (ideally twice per year), topdress with compost, reduce traffic.",
        'multiple_choice', 1,
        'Clay', ['Sand', 'Loam', 'Compost']],

    [$turfCat,
        'What does soil aeration primarily improve?', 'easy',
        "Core aeration removes plugs of compacted soil, opening channels for:\n• Oxygen to reach grass roots\n• Water and fertilizer penetration\n• Root expansion\n• Microbial activity\nAeration holes fill naturally within 2–4 weeks as soil and roots grow back in.\nLeave cores on surface — they break down and return nutrients to soil.",
        'multiple_choice', 2,
        'Oxygen flow to roots', ['Grass colour', 'Fertilizer smell', 'Soil temperature']],

    [$turfCat,
        'What does sandy soil do most rapidly compared to other soil types?', 'medium',
        "Sandy soil has large particles with large air pockets — water drains through very quickly.\nAdvantage: well-aerated, doesn't compact easily.\nDisadvantage: nutrients leach out rapidly with water, requires more frequent irrigation and fertilization.\nFix: add organic matter (compost) to improve water and nutrient retention (increases CEC).",
        'multiple_choice', 1,
        'Drain water rapidly', ['Release oxygen', 'Grow roots', 'Retain nitrogen']],

    [$turfCat,
        'What is the ideal soil pH range for healthy turf grass?', 'medium',
        "pH 6.0–7.0 is the ideal range for most turf grasses — slightly acidic to neutral.\nAt this pH: nitrogen, phosphorus, iron, and micronutrients are most available to roots.\nBelow 5.5 (too acidic): aluminum and manganese become toxic, nutrients lock up.\nAbove 7.5 (too alkaline): iron and manganese deficiency, grass yellows.\nVancouver soils often test acidic (pH 5.5–6.5) — lime may be needed to raise pH.",
        'multiple_choice', 2,
        '6.0–7.0', ['3.0–4.0', '8.0–9.0', '10.0+']],

    [$turfCat,
        'What amendment most improves soil organic matter content?', 'easy',
        "Compost (decomposed organic matter) is the most effective amendment for improving soil organic matter.\nBenefits: feeds soil microbes, improves water retention in sandy soil, improves drainage in clay, increases CEC.\nApplication: topdress 0.5–1 cm after aerating, or work into soil during renovation.\nOrganic matter is the foundation of healthy soil biology — healthy soil = healthy grass.",
        'multiple_choice', 3,
        'Compost', ['Sand', 'Lime', 'Gravel']],

    // ═══════════════════════════════════════════════════════════════════════
    // TURF PESTS (5) — Pest & Disease cat, level 2
    // ═══════════════════════════════════════════════════════════════════════
    [$pestCat,
        'What lawn pest causes birds and raccoons to dig up turf looking for food?', 'easy',
        "European Chafer Grubs (Amphimallon majale) are the larvae of the European chafer beetle — common in Metro Vancouver.\nGrubs feed on grass roots in late summer and fall, killing turf in irregular patches.\nBirds, raccoons, and crows dig up the loosened turf to eat the grubs.\nVancouver has an active chafer management program — reporting sightings is encouraged.",
        'multiple_choice', 1,
        'Chafer grubs', ['Earthworms', 'Beetles', 'Ants']],

    [$pestCat,
        'What chafer beetle life stage causes the most damage to turf roots?', 'medium',
        "The larvae (grub) stage causes all the turf damage — they feed underground on grass roots through late summer, fall, and early spring.\nAdult beetles are harmless to lawns (they feed on tree leaves and do not damage grass).\nEgg stage: laid in soil in early summer, hatches in 2–3 weeks.\nPupae stage: dormant underground, no damage.\nGrub (larvae): active root feeders from August through April.",
        'multiple_choice', 1,
        'Larvae (grubs)', ['Adult beetle', 'Egg', 'Pupae']],

    [$pestCat,
        'When do European chafer beetles typically lay their eggs in Vancouver?', 'medium',
        "Adult chafer beetles emerge in late May to June, swarm trees at dusk in warm evenings, then females lay eggs in lawns in early summer (June–July).\nEggs hatch within 2–3 weeks. Young grubs begin feeding on roots in August.\nDamage peaks in fall (September–November) when large grubs are actively feeding near the surface.\nSpring (March–April): grubs resume feeding before pupating.",
        'multiple_choice', 4,
        'Early summer (June–July)', ['Winter', 'Late fall', 'January']],

    [$pestCat,
        'What product is approved to prevent chafer grub damage in lawns?', 'medium',
        "Acelepryn (chlorantraniliprole) is the primary insecticide registered in BC for chafer grub control.\nApplication: apply in late spring to early summer BEFORE eggs hatch, water in thoroughly.\nIt is a low-toxicity option relative to older products — minimal impact on birds, mammals, bees when used correctly.\nBiological control: entomopathogenic nematodes (Heterorhabditis bacteriophora) can also be applied in late August when grubs are young.",
        'multiple_choice', 3,
        'Acelepryn', ['Lime', 'Compost', 'Sand']],

    [$pestCat,
        'What is the most visible symptom of chafer grub damage in a lawn?', 'easy',
        "Spongy, loose turf that can be peeled back like a carpet is the hallmark of chafer grub damage — the roots have been severed by feeding grubs.\nOther symptoms: irregular brown patches, birds and raccoons digging, turf lifts easily by hand.\nConfirm by rolling back a patch and counting grubs — more than 5 grubs per 0.1 square meter = significant infestation.\nDamage is worst in fall after a dry summer.",
        'multiple_choice', 2,
        'Spongy turf that lifts like a carpet', ['Dark green lush grass', 'Excessively tall grass', 'Very deep roots']],

    // ═══════════════════════════════════════════════════════════════════════
    // PLANT IDENTIFICATION (5) — Plant & Grass ID cat, level 1
    // ═══════════════════════════════════════════════════════════════════════
    [$plantCat,
        'What tall ornamental grass produces large feathery plumes up to 3 metres high?', 'easy',
        "Pampas grass (Cortaderia selloana) is a large clumping ornamental grass producing showy white or pink plumes on tall stalks.\nCommon in Vancouver landscapes as a dramatic focal point.\nNote: Pampas grass is considered invasive in some coastal BC areas — check local regulations before planting.\nIdentifiers: sharp-edged leaves, massive tussock form, plumes in late summer–fall.",
        'multiple_choice', 1,
        'Pampas grass', ['Bamboo', 'Iris', 'Hosta']],

    [$plantCat,
        'What flowering shrub is famous for its spectacular purple and pink flower clusters in spring?', 'easy',
        "Rhododendron (Rhododendron spp.) is one of BC's most beloved garden shrubs — Vancouver's official city flower.\nIdentifiers: large leathery evergreen leaves, large flower trusses in pink, purple, red, or white, spring bloom.\nGrows best in acidic, well-drained soil with dappled shade.\nVancouver's mild wet climate is ideal — rhododendrons thrive here better than almost anywhere.",
        'multiple_choice', 1,
        'Rhododendron', ['Maple', 'Boxwood', 'Laurel']],

    [$plantCat,
        'What ornamental tree produces brilliant red and orange leaves in fall?', 'easy',
        "Japanese maple (Acer palmatum) is prized for its spectacular fall colour in red, orange, and burgundy.\nIdentifiers: delicate, deeply lobed leaves, elegant layered branching, slow-growing, compact form.\nMany varieties available: red-leaf types (Bloodgood), weeping types (Dissectum), dwarf forms.\nFall colour is most vivid with warm sunny days and cool nights — Metro Vancouver conditions are ideal.",
        'multiple_choice', 1,
        'Japanese maple', ['Pine', 'Cedar', 'Spruce']],

    [$plantCat,
        'What hedge plant is most commonly used for privacy screening in Vancouver?', 'easy',
        "Western Red Cedar (Thuja plicata) is the most popular hedge plant in Metro Vancouver.\nIdentifiers: flat, scale-like foliage in bright green sprays, reddish fibrous bark, aromatic when cut.\nAdvantages: fast-growing, dense, native to BC, tolerates wet clay soil, frost-hardy.\nCare: trim 1–2 times per year during growing season, avoid cutting into bare brown wood.",
        'multiple_choice', 1,
        'Cedar', ['Palm', 'Olive', 'Banana']],

    [$plantCat,
        'What popular evergreen hedge plant stays green year-round and handles Vancouver\'s shade?', 'easy',
        "Laurel (Prunus laurocerasus — English or Cherry Laurel) is a fast-growing, glossy evergreen hedge common throughout Metro Vancouver.\nIdentifiers: large, shiny dark green oval leaves, white flower spikes in spring, black berries in fall.\nAdvantages: dense growth for privacy, tolerates shade and clay soil, fast establishment.\nTrim with secateurs (not hedge trimmer) to avoid leaf browning.",
        'multiple_choice', 1,
        'Laurel', ['Maple', 'Birch', 'Cherry']],

    // ═══════════════════════════════════════════════════════════════════════
    // PLANT CARE (5) — Plant & Grass ID cat, level 3 Treatment
    // ═══════════════════════════════════════════════════════════════════════
    [$plantCat,
        'When is the best time to trim hedges in Vancouver?', 'easy',
        "Trim hedges during the active growing season — late spring through early fall (May–September).\nFor most hedges: 2 cuts per year (late spring after initial growth flush, late summer before growth stops).\nAvoid trimming in late fall/winter — new growth stimulated by trimming is frost-tender.\nNever trim during extreme heat waves — stressed plants can't heal pruning wounds quickly.",
        'multiple_choice', 4,
        'During the growing season (spring to early fall)', ['Mid-winter freeze', 'Snow season', 'Extreme heat wave']],

    [$plantCat,
        'What trimming practice encourages bushier, denser hedge growth?', 'easy',
        "Regular trimming stimulates lateral branching (the plant produces more side shoots to compensate for removed tips).\nTrimming technique: cut back slightly beyond where you want the hedge to grow — growth will be fuller each season.\nTip: taper hedges slightly wider at the base than top — allows sunlight to reach lower branches, preventing bare legs.\nInfrequent heavy cuts result in hollow, leggy hedges.",
        'multiple_choice', 3,
        'Regular consistent trimming', ['Never trimming', 'Fertilizer only', 'Water only']],

    [$plantCat,
        'What environmental stress most commonly causes leaf scorch on ornamental plants?', 'medium',
        "Leaf scorch = brown, crispy leaf margins — caused by the plant losing more water through leaves than roots can supply.\nCommon causes: drought stress in hot weather, reflected heat (paving, walls), salt in soil or spray, sudden wind exposure.\nFix: water deeply at the root zone, apply mulch to retain soil moisture, protect from reflected heat sources.\nNew transplants are especially vulnerable in their first summer.",
        'multiple_choice', 2,
        'Heat and drought stress', ['Cold rain', 'Fertilizer application', 'Compost topdressing']],

    [$plantCat,
        'What application best helps retain soil moisture around plants and trees?', 'easy',
        "Mulch (wood chips, bark, or arborist chips) applied 5–10cm deep around plants dramatically reduces water evaporation from soil.\nAdditional benefits: suppresses weeds, moderates soil temperature, feeds soil biology as it decomposes, improves soil structure.\nKeep mulch away from plant stems (leave 5cm gap to prevent rot).\nRefresh mulch annually as it breaks down.",
        'multiple_choice', 3,
        'Mulch', ['Gravel', 'Sand', 'Lime']],

    [$plantCat,
        'What is the most effective way to prevent root rot in ornamental plants?', 'easy',
        "Good drainage is the primary defense against root rot — roots need oxygen, not standing water.\nRoot rot (often caused by Phytophthora or Pythium fungus) thrives in waterlogged, oxygen-depleted soil.\nPrevention: amend clay soil with compost, raise planting beds, avoid overwatering, ensure container drainage holes are open.\nPlants most susceptible: rhododendrons, boxwood, cedars in poorly drained clay.",
        'multiple_choice', 3,
        'Good soil drainage', ['Extra watering', 'Deep shade', 'More fertilizer']],

    // ═══════════════════════════════════════════════════════════════════════
    // EQUIPMENT KNOWLEDGE (5) — Equipment & Tools cat, level 1
    // ═══════════════════════════════════════════════════════════════════════
    [$equipCat,
        'What power tool is specifically designed to cut clean edges between lawn and garden beds?', 'easy',
        "The lawn edger creates a clean vertical cut at turf edges — along sidewalks, driveways, and bed borders.\nTypes: rotary edger (vertical spinning blade), string trimmer used vertically, or manual half-moon edger.\nProper edging creates a professional, sharp separation that dramatically improves the finished look of any lawn.\nEdge before blowing to contain debris.",
        'multiple_choice', 1,
        'Edger', ['Blower', 'Rake', 'Aerator']],

    [$equipCat,
        'What machine is used to remove cylindrical plugs of soil from a lawn?', 'easy',
        "A core aerator (hollow-tine aerator) removes plugs of compacted soil 2–10cm deep to relieve compaction and improve root zone conditions.\nLeave the cores on the surface — they break down naturally within 2–4 weeks, returning nutrients.\nMachine types: tow-behind drum aerator (large properties), walk-behind drum aerator, or plug-pull aerator.\nDo not confuse with a spike aerator — spikes compact surrounding soil and are less effective.",
        'multiple_choice', 1,
        'Core aerator', ['Lawn mower', 'Overseeder', 'Roller']],

    [$equipCat,
        'What power equipment is used to clear leaves and debris quickly from lawns and beds?', 'easy',
        "A leaf blower (handheld, backpack, or wheeled) moves large volumes of leaves and debris quickly using high-velocity air.\nBackpack blowers are most common in professional landscaping — powerful and hands-free.\nProper technique: blow leaves into piles or rows for collection, work with the wind when possible.\nAlways blow debris away from sensitive areas: storm drains, newly seeded grass, open beds.",
        'multiple_choice', 1,
        'Leaf blower', ['Broadcast spreader', 'Edger', 'Boom sprayer']],

    [$equipCat,
        'What machine spreads granular fertilizer and seed evenly across a lawn?', 'easy',
        "A broadcast (rotary) spreader distributes granular products in a wide pattern as the operator walks.\nSpinner disc throws granules in a semicircle — coverage width is 2–3x walking path.\nAlways calibrate before use — set the spreader to the rate specified on the fertilizer label.\nAlternative: drop spreader — more precise, narrower path, better for small lawns or near beds.",
        'multiple_choice', 1,
        'Broadcast spreader', ['Core aerator', 'Lawn mower', 'Roller']],

    [$equipCat,
        'What tool is designed to cut and shape hedge surfaces flat and even?', 'easy',
        "A hedge trimmer (electric, battery, or gas powered) has a reciprocating double-sided blade that cuts multiple stems simultaneously.\nProper use: keep blade parallel to the hedge surface, use long sweeping strokes, shape wider at base.\nSharp blades are essential — dull blades crush stems rather than cutting cleanly (promotes disease).\nSafety: always cut away from your body, wear eye protection and gloves.",
        'multiple_choice', 1,
        'Hedge trimmer', ['Chainsaw', 'Lawn edger', 'Garden rake']],

    // ═══════════════════════════════════════════════════════════════════════
    // CUSTOMER QUESTIONS (5) — Customer Service cat, level 5
    // ═══════════════════════════════════════════════════════════════════════
    [$svcCat,
        'A client asks: "Why did your machine leave holes all over my lawn?" How do you best explain aeration?', 'easy',
        "Client explanation script:\n\"Those holes are actually the goal of the service — it's called core aeration. The machine pulls out small plugs of compacted soil to open up the ground. This lets air, water, and nutrients reach deeper into the roots, which makes your grass healthier and stronger. The plugs you see on the surface will break down naturally within a couple of weeks and actually return nutrients to the lawn. The holes will fill in as the roots grow.\"",
        'customer_explanation', 5,
        'The holes improve soil oxygen and water penetration — the cores break down and return nutrients', ['The machine caused accidental damage', 'Pest activity created the holes', 'Rain caused the soil to collapse']],

    [$svcCat,
        'A client asks: "Why does my lawn need fertilizer if it looks green enough?" How do you best explain lawn fertilization?', 'easy',
        "Client explanation script:\n\"Fertilizer gives your lawn the key nutrients it needs to grow thick, stay resilient, and fight off weeds and disease. Even if it looks green today, grass under the surface may be running low on nitrogen, which affects root strength and density. Think of it like vitamins for your lawn — you might feel okay without them, but with the right nutrition, your lawn stays healthier, denser, and better equipped to handle heat, drought, and pests.\"",
        'customer_explanation', 5,
        'Fertilizer improves root strength, colour, and density — essential nutrients even when grass looks okay', ['Fertilizer only kills weeds', 'Fertilizer stops the need to mow', 'Fertilizer prevents rain damage']],

    [$svcCat,
        'A client asks: "Why are you spreading seed on a lawn that already has grass?" How do you best explain overseeding?', 'easy',
        "Client explanation script:\n\"We're overseeding to thicken up the lawn and fill in the thinner areas. Grass plants age and thin out over time, and by adding millions of new seeds, we encourage a much denser turf. Thicker grass crowds out weeds naturally, holds up better under foot traffic, and looks greener and more uniform. It's the most natural and effective way to improve the long-term health and appearance of the lawn without having to replace it entirely.\"",
        'customer_explanation', 5,
        'Overseeding thickens the turf, crowds out weeds, and improves long-term lawn density', ['Overseeding is for reducing watering needs', 'Overseeding changes the soil colour', 'Overseeding stops insects']],

    [$svcCat,
        'A client asks: "Why do you mow every week? Can\'t we just do it every two weeks?" How do you best respond?', 'easy',
        "Client explanation script:\n\"Weekly mowing is actually healthier for the lawn. Grass has a natural rule — you should never remove more than one-third of the blade at once. If we skip a week and it gets long, we'd have to cut it very short to catch up, which stresses and weakens the grass. Regular mowing also encourages denser growth, keeps the lawn looking its best, and actually makes each visit faster. We can discuss a schedule that works for you, but I'd recommend staying as consistent as possible.\"",
        'customer_explanation', 5,
        'Regular mowing prevents scalping stress and promotes denser, healthier turf growth', ['Short grass is the only goal', 'Frequent mowing prevents fertilizer needs', 'More mowing prevents watering needs']],

    [$svcCat,
        'A client asks: "Why do you leave the grass clippings on the lawn instead of collecting them?" How do you respond?', 'easy',
        "Client explanation script:\n\"Leaving the clippings is actually a practice called grasscycling, and it's very beneficial. Short clippings decompose quickly and return valuable nitrogen and nutrients back to the soil — equivalent to one free fertilizer application per season. They don't cause thatch buildup when the lawn is mowed regularly at the right height. If you prefer a cleaner look, we can bag them, but grasscycling is the healthier and more sustainable approach.\"",
        'customer_explanation', 5,
        'Clippings return nutrients to the soil — equivalent to one fertilizer application per season', ['Clippings increase weed growth', 'Clippings block the soil permanently', 'Clippings reduce lawn colour']],

    // ═══════════════════════════════════════════════════════════════════════
    // TURF PROBLEMS (5) — Turf & Soil cat, level 2 Diagnosis
    // ═══════════════════════════════════════════════════════════════════════
    [$turfCat,
        'What most commonly causes patchy, uneven growth across a lawn?', 'easy',
        "Uneven nutrient distribution is the most common cause — areas receiving less fertilizer, more shade, or different soil types grow differently.\nOther common causes: compaction in high-traffic areas, different soil types across the lawn, irrigation coverage gaps, thatch buildup in patches.\nDiagnosis: check if patches correlate with shade, traffic paths, or irrigation heads.\nFix: soil test, aerate, overseed thin patches, ensure even fertilizer coverage.",
        'multiple_choice', 2,
        'Uneven nutrients or soil conditions', ['Too much mowing in one area', 'Cold air pockets', 'Too much oxygen in one area']],

    [$turfCat,
        'What most commonly causes thin, sparse turf that doesn\'t fill in?', 'medium',
        "Poor soil health is the root cause of persistently thin turf — compacted, nutrient-poor, or poorly draining soil prevents healthy root development.\nSigns: thin stand, visible soil between plants, moss or weed invasion.\nFull diagnosis checklist: aeration (compaction?), soil pH test, fertility assessment, shade level, irrigation coverage.\nFix: core aerate, soil amend with compost, overseed, fertilize, improve drainage.",
        'multiple_choice', 2,
        'Poor underlying soil health', ['Too much mowing', 'Too much rainfall', 'Too much sunshine']],

    [$turfCat,
        'What most commonly causes bare spots in an otherwise healthy lawn?', 'medium',
        "Traffic damage — repeated foot traffic or equipment over the same area kills grass by compaction and physical wear.\nOther specific causes: dog urine (round brown spots with green ring), spilled chemicals, buried rocks or debris, vole tunnels, chinch bug damage.\nDiagnosis: observe the shape and location — irregular + traffic path = wear; round + perimeter green = dog urine.\nFix: restrict traffic, loosen soil, topdress, overseed the bare area.",
        'multiple_choice', 2,
        'Traffic damage and wear', ['Excess oxygen in the soil', 'Too much fertilizer', 'Too much shade']],

    [$turfCat,
        'What combination of conditions causes moss to grow in a lawn?', 'easy',
        "Moss thrives where grass struggles — the four main conditions it exploits:\n1. Shade (too little light for grass)\n2. Moisture / poor drainage (moss loves wet conditions)\n3. Low soil pH (acidic soil)\n4. Compacted or nutrient-poor soil\nMoss doesn't cause lawn problems — it's a symptom of underlying issues.\nFix: remove shade, aerate, apply lime to raise pH, improve drainage — then overseed.",
        'multiple_choice', 2,
        'Shade and persistent moisture', ['Too much fertilizer', 'Mowing too often', 'Too much aeration']],

    [$turfCat,
        'What combination of conditions causes lawn fungal disease to develop?', 'medium',
        "Lawn fungus (dollar spot, red thread, brown patch, etc.) needs three things: moisture, warmth, and poor airflow.\nMost common in Vancouver: red thread (pink threads in grass), dollar spot (small tan circles).\nRisk factors: overwatering, evening watering, excessive nitrogen, thick thatch, compaction reducing airflow.\nFix: water in the morning, improve aeration, remove thatch, avoid over-fertilizing with nitrogen.\nFungicide is rarely needed if cultural practices are corrected.",
        'multiple_choice', 2,
        'Excess moisture combined with poor airflow', ['Too much sunshine', 'Aeration', 'Fertilizer application']],

    // ═══════════════════════════════════════════════════════════════════════
    // SEASONAL KNOWLEDGE (5) — Turf & Soil cat, level 4 Timing
    // ═══════════════════════════════════════════════════════════════════════
    [$turfCat,
        'What season sees the most vigorous cool-season lawn growth in Vancouver?', 'easy',
        "Spring (March–May) is peak growth for cool-season grasses in Vancouver.\nCool days (10–18°C), increasing daylight, and reliable rainfall create perfect conditions for perennial ryegrass, fescue, and bluegrass.\nSecond growth peak: fall (September–October) as temperatures cool after summer.\nSummer can slow growth if temperatures exceed 25°C consistently — cool-season grasses semi-dormant in heat.",
        'multiple_choice', 4,
        'Spring', ['Winter', 'Late fall', 'Snow season']],

    [$turfCat,
        'When does lawn growth typically slow down the most in Vancouver?', 'easy',
        "Mid-summer heat (July–August) slows cool-season grass growth significantly — temperatures above 25–30°C cause semi-dormancy.\nCool-season grasses (ryegrass, fescue) prefer 15–20°C — they slow, may thin, and turn slightly brown in heat.\nDo not fertilize heavily in summer heat — can burn already-stressed grass.\nReduce mowing frequency during dormancy, maintain 3\" height, water deeply if needed.",
        'multiple_choice', 4,
        'Mid-summer heat', ['Spring', 'Early fall', 'Early winter']],

    [$turfCat,
        'When is the best time to overseed a lawn in Vancouver?', 'easy',
        "Late summer to early fall (late August–October) is optimal for overseeding in Vancouver.\nReasons: soil is warm (germinates seeds faster), air temperature is cooling (less stress on seedlings), fall rains reduce irrigation needs, reduced weed competition vs. spring.\nSpring overseeding also works but competes with spring weeds and summer heat arrives before new grass fully establishes.\nTip: mow short, scarify or aerate, spread seed, starter fertilizer, keep moist 2–3 weeks.",
        'multiple_choice', 4,
        'Late summer to early fall', ['Mid-winter', 'Snow season', 'Peak summer heat']],

    [$turfCat,
        'When do adult chafer beetles typically emerge in Vancouver?', 'medium',
        "European chafer beetles emerge as adults in late May to early June in Metro Vancouver.\nThey swarm on warm evenings (above 15°C), mate in trees (often maples and chestnuts), and lay eggs in lawns through June–July.\nEggs hatch in 2–3 weeks, and young grubs begin feeding on roots in August.\nKey prevention window: apply Acelepryn insecticide or nematodes in June–July before eggs hatch and grubs establish.",
        'multiple_choice', 4,
        'Late spring (May–June)', ['Mid-winter', 'Snow season', 'January']],

    [$turfCat,
        'When is the best time to apply fertilizer to a lawn in Vancouver?', 'easy',
        "Apply fertilizer during the active growing season when the grass can use the nutrients:\n• Spring: March–May (promote growth after winter)\n• Fall: September–October (root strengthening before winter)\nAvoid: frozen soil (fertilizer doesn't reach roots, may run off), peak summer heat (can burn stressed grass), dormant winter grass.\nSpring and fall applications give the highest return on investment for turf health.",
        'multiple_choice', 4,
        'During the active growing season (spring and fall)', ['Frozen winter soil', 'Snow covered ground', 'Dormant summer heat']],

    // ═══════════════════════════════════════════════════════════════════════
    // ADVANCED DIAGNOSIS (5) — Pest & Disease cat, level 2 scenario
    // ═══════════════════════════════════════════════════════════════════════
    [$pestCat,
        'You arrive at a property and find spongy, loose turf with multiple areas dug up overnight. What is the most likely cause?', 'medium',
        "Classic chafer grub damage signature in Metro Vancouver:\n• Spongy turf = roots have been severed by feeding grubs\n• Nocturnal digging = raccoons and skunks digging for grubs\nConfirmation: peel back a section of turf and look for white C-shaped grubs near the soil surface.\nMore than 5 grubs per 0.1 sq metre = significant infestation requiring treatment.\nDocument and report to client, recommend treatment program.",
        'scenario', 2,
        'Chafer grub infestation (animals digging for grubs)', ['Fungal disease outbreak', 'Overwatering', 'Dog urine damage']],

    [$pestCat,
        'A lawn is persistently thin in shaded areas with visible moss patches despite regular fertilizing. What does this suggest?', 'medium',
        "Shade + compaction diagnosis:\n• Thin turf in shade = grass struggling for light\n• Persistent moss = moisture retained, soil may be compacted\n• Fertilizing alone doesn't fix shade or compaction\nAction plan: soil pH test (lime if acidic), core aerate to relieve compaction, overseed with shade-tolerant fescue varieties, reduce shade if possible (prune tree canopy).",
        'scenario', 2,
        'Shade stress combined with soil compaction', ['Overwatering problem', 'Excessive summer heat', 'Too many nutrients applied']],

    [$pestCat,
        'Clover is spreading across multiple areas of a client\'s lawn. What does this most reliably indicate about the soil?', 'medium',
        "Clover is a nitrogen-fixing legume that outcompetes nitrogen-deficient grass.\nIt produces its own nitrogen through root bacteria, giving it a significant advantage when soil nitrogen is low.\nRela tionship is very reliable: widespread clover invasion = low soil nitrogen.\nFix: apply a nitrogen-rich fertilizer program to strengthen grass, apply broadleaf herbicide for existing clover, overseed thin areas.",
        'scenario', 2,
        'Low nitrogen levels in the soil', ['Low phosphorus', 'Soil that is too alkaline', 'Clay soil compaction']],

    [$pestCat,
        'A lawn shows irregular yellow streaks and patches that don\'t match shade or traffic patterns. What does this most likely indicate?', 'medium',
        "Irregular yellow streaking not explained by environmental factors strongly suggests nutrient deficiency:\n• Nitrogen deficiency: general yellowing, often older leaves first\n• Iron deficiency: yellowing between leaf veins (interveinal chlorosis), often in alkaline soil\n• Magnesium deficiency: similar interveinal pattern\nDiagnosis steps: soil test, check pH (high pH locks out iron), evaluate fertilizer history.\nFix: adjust pH, apply appropriate nutrient correction based on soil test.",
        'scenario', 2,
        'Nutrient deficiency (nitrogen or iron)', ['Overwatering damage', 'Pest chewing damage', 'Scalping from mowing']],

    [$pestCat,
        'A lawn has defined circular bare patches with bright green grass growing in a ring around each patch. What is the most likely cause?', 'medium',
        "Round bare patches with a green ring = dog urine damage — a classic pattern.\nDog urine contains high concentrations of nitrogen salts:\n• Center of patch: killed by nitrogen toxicity (salt burn)\n• Ring of green: diluted nitrogen acts as fertilizer, stimulating lush growth\nFix: flush the area heavily with water to dilute salts, loosen soil, overseed, restrict dog access to that area if possible.",
        'scenario', 2,
        'Dog urine nitrogen burn', ['Fungal fairy ring disease', 'Soil compaction pattern', 'Drought stress pattern']],

    // ═══════════════════════════════════════════════════════════════════════
    // QUALITY STANDARDS (5) — Customer Service cat, level 6
    // ═══════════════════════════════════════════════════════════════════════
    [$svcCat,
        'What characteristic most defines a professional mowing result?', 'easy',
        "Straight, consistent mowing lines across the lawn define a professional finish.\nKey quality indicators: parallel straight passes with slight overlap, consistent height across all areas, no missed strips, no scalping at turns.\nPro technique: choose a reference line (fence, edge) and maintain it across the full lawn.\nAfter mowing: check for any missed areas from a standing vantage point before moving on.",
        'quality_judgement', 6,
        'Straight, consistent mowing lines with no missed strips', ['Random circular mowing pattern', 'Diagonal cuts only', 'Very short cut for appearance']],

    [$svcCat,
        'What visual result defines properly completed lawn edging?', 'easy',
        "Clean, vertical soil separation between the turf edge and the hard surface (sidewalk, driveway, bed border) defines professional edging.\nStandard: grass should not extend over the edge, the cut should be vertical (not angled), and the separation line should be consistent and visible.\nAfter edging: blow or sweep debris off the hard surface.\nPoor edging (grass hanging over edges) is one of the most visually obvious quality fails.",
        'quality_judgement', 6,
        'Clean vertical separation between lawn and hard surface', ['Grass extending over the edge', 'Rough uneven border', 'No visible separation at all']],

    [$svcCat,
        'What colour most defines a professionally maintained, healthy lawn?', 'easy',
        "Deep, uniform green across the full lawn is the standard for professionally maintained turf.\nColour indicators:\n• Deep green = proper nutrition, adequate water, healthy grass\n• Yellow-green = nitrogen deficiency\n• Brown patches = drought, disease, or pest damage\n• Light lime green = mild nutrient stress\nNote variation from shade vs. sun — some difference is normal, but major colour contrast signals a problem.",
        'quality_judgement', 6,
        'Deep uniform green across the full lawn', ['Yellow-green patchy colour', 'Light lime green overall', 'Brown dormant appearance']],

    [$svcCat,
        'Which of these lawn care results indicates poor mowing technique?', 'easy',
        "Scalping — visible brown stems or crowns exposed by cutting too short — is the most obvious and damaging mowing error.\nScalping weakens the plant, invites disease and weeds, and creates a brown stressed appearance.\nOther signs of poor mowing: missed strips, scalped turns, inconsistent height, tracks in soft turf, clumping from dull blades.\nProfessional standard: 1/3 rule always respected, 3\" height maintained, sharp blades.",
        'quality_judgement', 6,
        'Scalping with visible brown stems and crowns', ['Consistent straight mowing lines', 'Maintaining the correct 3-inch height', 'Clean stripe pattern']],

    [$svcCat,
        'What appearance indicates a hedge that needs professional maintenance?', 'easy',
        "An uneven, irregular shape — with visible gaps, varying heights, stray branches, and an undefined silhouette — indicates an overgrown hedge needing professional trimming.\nProfessional hedge standard: flat top (or defined shape), vertical sides, consistent width, no stray shoots extending beyond the defined profile.\nQuality check: step back 5 metres and assess the overall silhouette — it should be clean and intentional.",
        'quality_judgement', 6,
        'Irregular, uneven shape with stray branches beyond the profile', ['Rounded naturally shaped top', 'Square tightly defined shape', 'Flat uniform surface']],

    // ═══════════════════════════════════════════════════════════════════════
    // PLANT HEALTH (5) — Plant & Grass ID cat, level 2 Diagnosis
    // ═══════════════════════════════════════════════════════════════════════
    [$plantCat,
        'What is the most common cause of plant wilting during a warm day?', 'easy',
        "Lack of water (drought stress) is the most common cause — the plant is losing more water through its leaves than the roots can supply.\nWilt mechanism: cells lose turgor pressure when water is scarce, causing the plant to droop.\nCheck before watering: insert finger 5cm into soil — if dry, water deeply. If moist, wilting may be from root rot or heat stress.\nPrevention: mulch to retain moisture, water deeply and less frequently.",
        'multiple_choice', 2,
        'Lack of water (drought stress)', ['Too much direct sunlight', 'Wind exposure', 'Cold soil temperature']],

    [$plantCat,
        'What does widespread yellowing of plant leaves most often indicate?', 'medium',
        "Nutrient deficiency — particularly nitrogen — is the most common cause of yellowing (chlorosis).\nNitrogen is essential for chlorophyll production. Without it, leaves lose their green colour.\nIron deficiency causes interveinal chlorosis (yellowing between veins, veins stay green) — often in alkaline soil.\nOther causes: overwatering (root rot preventing nutrient uptake), natural leaf senescence (old leaves yellow normally).\nDiagnosis: check the pattern of yellowing to identify which nutrient is deficient.",
        'multiple_choice', 2,
        'Nutrient deficiency (especially nitrogen)', ['Receiving too much water', 'Too much direct sun', 'Recent pruning damage']],

    [$plantCat,
        'What most commonly causes plant leaves to curl or roll inward?', 'medium',
        "Leaf curling is a stress response — the plant reduces its exposed surface area to conserve water.\nCauses:\n• Drought stress (most common): leaves curl to reduce transpiration\n• Pest damage: aphids, spider mites, thrips cause distortion and curling\n• Herbicide drift: causes cupped/curled leaves\n• Viral disease: irregular curling patterns\nDiagnosis: check undersides of leaves for pests, check soil moisture, note if curling is uniform (drought) or irregular (pest/disease).",
        'multiple_choice', 2,
        'Drought stress or pest activity', ['Overwatering', 'Growing in deep shade', 'Recent fertilizer application']],

    [$plantCat,
        'What soil condition most directly causes root rot in plants?', 'medium',
        "Waterlogged, poorly draining soil deprives roots of oxygen — roots need air to respire and function.\nWithout oxygen, roots die. Dead roots can't take up water or nutrients, and opportunistic fungi (Phytophthora, Pythium) colonize and rot the tissue.\nVisible symptoms: wilting despite moist soil, brown mushy roots, leaf yellowing, plant collapse.\nFix: improve drainage, reduce watering frequency, consider raised beds or amended soil for susceptible plants.",
        'multiple_choice', 2,
        'Waterlogged, poorly drained soil', ['Dry compacted soil', 'Low soil nitrogen', 'Surface soil compaction']],

    [$plantCat,
        'What is the single most important factor in maintaining long-term plant health?', 'medium',
        "Healthy, well-structured soil with good biology is the foundation of all plant health.\nSoil provides: water, nutrients, oxygen, physical support, and beneficial microorganisms that support root health.\nNo amount of fertilizer, irrigation, or pruning overcomes fundamentally poor soil.\nSoil improvement: add compost annually, reduce compaction, test pH, avoid overwatering.\nHealthy soil = healthy roots = healthy plant above ground.",
        'multiple_choice', 6,
        'Healthy, well-structured soil with good biology', ['Frequent watering schedule', 'Maximum direct sunlight', 'Frequent fertilizer application']],

    // ═══════════════════════════════════════════════════════════════════════
    // FIELD DECISION MAKING (5) — Turf & Soil cat, level 6 scenario
    // ═══════════════════════════════════════════════════════════════════════
    [$turfCat,
        'You assess a client\'s lawn and find it very thin, patchy, and weed-invaded. The soil is healthy. What is the best solution to recommend?', 'medium',
        "Overseeding is the primary recommendation when the lawn is thin and soil is healthy.\nRationale: thin turf = open soil = weed opportunity. Overseeding dramatically increases turf density, which naturally crowds out weeds and improves appearance.\nFull recommendation package: aerate first (improves seed-soil contact), overseed with premium blend, apply starter fertilizer, instruct client to water daily for 3 weeks.\nDo NOT overseed without aerating — seed needs soil contact to germinate.",
        'scenario', 6,
        'Recommend overseeding to increase turf density', ['Apply fertilizer only and wait', 'Aerate only without seeding', 'Increase watering frequency alone']],

    [$turfCat,
        'A property has heavy clay soil with standing water for hours after rain. What is the most impactful service to recommend?', 'medium',
        "Core aeration is the most impactful immediate service for heavy clay soil with drainage problems.\nAeration channels allow water to penetrate rather than pool on the surface, oxygen reaches roots, compaction is relieved.\nFull program: core aerate (double-pass for severe compaction), topdress with compost to improve soil structure, overseed.\nLong-term: annual aeration, organic matter additions over multiple years will progressively improve clay soil drainage.",
        'scenario', 6,
        'Core aeration to relieve compaction and improve drainage', ['Apply fertilizer to encourage growth', 'Overseed immediately without preparation', 'Install an irrigation system']],

    [$turfCat,
        'A weed-invaded lawn is being treated with herbicide but weeds keep coming back. What is the most effective long-term prevention strategy?', 'medium',
        "Building a thick, dense turf canopy is the only long-term solution — herbicide alone treats symptoms, not the cause.\nWhy: thin turf + bare soil = light reaches the ground = weed seed germination. Dense grass physically prevents this.\nLong-term plan: stop the cycle with herbicide, then immediately overseed and fertilize to thicken turf, aerate annually.\nResult: dense turf shades soil, new weed seeds can't germinate, herbicide becomes rarely needed.",
        'scenario', 6,
        'Build a thick dense turf canopy through overseeding', ['Continue herbicide applications indefinitely', 'Mow shorter to suppress weeds', 'Leave soil bare between grass plants']],

    [$turfCat,
        'A lawn is heavily covered in moss and has poor drainage. What is the correct sequence of actions?', 'medium',
        "Correct moss management sequence:\n1. Address the root cause — improve drainage (aerate, dethatch, reduce shade)\n2. Apply lime if pH is acidic (test first)\n3. Apply moss control product\n4. Rake out dead moss\n5. Overseed with shade-tolerant fescue\nWhy drainage first: applying moss control without fixing drainage just kills moss temporarily — it returns when conditions are unchanged.\nLong-term: annual aeration, compost topdressing, shade reduction.",
        'scenario', 6,
        'Improve drainage first, then treat moss and overseed', ['Apply more nitrogen fertilizer to outcompete moss', 'Aerate only without addressing other factors', 'Overseed immediately without moss removal']],

    [$turfCat,
        'You notice a lawn feels unusually spongy and soft underfoot, especially in fall. What should you inspect first?', 'easy',
        "Spongy, loose turf in fall = chafer grub damage until proven otherwise in Metro Vancouver.\nInspection process:\n1. Press firmly on the spongy area\n2. Try to peel back a section of turf — if roots are severed it lifts easily\n3. Look underneath for white C-shaped grubs near the soil surface\n4. Count grubs per 0.1 sq metre — 5+ = significant infestation\nAlso check for: raccoon or bird digging (animals eating the grubs)\nReport findings to client and recommend treatment.",
        'scenario', 6,
        'Chafer grubs severing roots beneath the turf', ['Fungal disease in the soil', 'Excessive moss buildup', 'Thick thatch layer']],

    // ═══════════════════════════════════════════════════════════════════════
    // PLANT ID EXTENSION (5) — Plant & Grass ID cat, level 1
    // ═══════════════════════════════════════════════════════════════════════
    [$plantCat,
        'What evergreen conifer is most commonly used as a hedge or privacy screen in Metro Vancouver?', 'easy',
        "Western Red Cedar (Thuja plicata) dominates as a hedge plant in Vancouver — native to BC and perfectly suited to the climate.\nIdentifiers: flat sprays of scale-like bright green foliage, aromatic when cut, reddish-brown fibrous bark.\nGrowth rate: 30–60cm per year when young and well-watered.\nAlternative cedars used: Emerald Cedar (Thuja occidentalis 'Smaragd') for narrow formal hedges.",
        'multiple_choice', 1,
        'Western Red Cedar', ['Bigleaf Maple', 'Red Alder', 'White Birch']],

    [$plantCat,
        'What popular shade-tolerant perennial is known for its large, textured, tropical-looking leaves?', 'easy',
        "Hosta (Hosta spp.) is the go-to shade perennial for Vancouver gardens.\nLeaves range from small to enormous (up to 60cm across), in colours from deep green to blue-grey to gold.\nFlowers: tall spikes of white or lavender flowers in summer.\nCare: thrives in moist, rich soil with morning sun and afternoon shade — ideal under tree canopy.\nDivide clumps every 3–5 years to maintain vigour.",
        'multiple_choice', 1,
        'Hosta', ['Rose bush', 'Iris', 'Lavender']],

    [$plantCat,
        'What flowering shrub produces brilliant pink, red, or orange blossoms in spring before leaves appear?', 'easy',
        "Azalea (Rhododendron genus, deciduous species) bursts into colour in March–May — often before leaves fully emerge.\nIdentifiers: smaller leaves than rhododendrons, more tubular flowers, often deciduous.\nColours: brilliant pink, orange, red, coral, white.\nCare: acidic well-drained soil, partial shade, protect from harsh afternoon sun.\nRelated to rhododendron — both are Rhododendron genus, but azaleas tend to be smaller and deciduous.",
        'multiple_choice', 1,
        'Azalea', ['Boxwood', 'Cedar', 'English Laurel']],

    [$plantCat,
        'What plant produces tall, dense, jointed green canes that can reach 5–10 metres high?', 'easy',
        "Bamboo (various genera including Phyllostachys and Fargesia) produces the tall jointed canes recognizable in many Vancouver gardens.\nRunning bamboo: spreads aggressively via underground rhizomes — can invade neighbouring properties. Must be contained with root barriers.\nClumping bamboo: slower spread, less invasive — better choice for most garden situations.\nVisual identifier: hollow round jointed canes, narrow lance-shaped leaves, rapid vertical growth.",
        'multiple_choice', 1,
        'Bamboo', ['Western Red Cedar', 'Giant Hosta', 'Tall Ornamental Grass']],

    [$plantCat,
        'What ornamental spring bulb produces upright cup-shaped flowers in red, yellow, pink, purple, or white?', 'easy',
        "Tulip (Tulipa spp.) is one of the most recognized spring bulbs — planted in fall for spring blooms.\nFlower shape: classic cup form on upright stems, single or double varieties.\nColours: nearly every colour except true blue.\nPlanting depth: 3x the bulb height (typically 15–20cm), in well-drained soil, full sun.\nIn Vancouver: plant October–November, blooms March–May. Deer resistant if varieties are chosen carefully.",
        'multiple_choice', 1,
        'Tulip', ['Western Red Cedar branch', 'Rose bush', 'Sword fern']],

    // ═══════════════════════════════════════════════════════════════════════
    // PROFESSIONAL PRACTICE (5) — Customer Service cat, level 5
    // ═══════════════════════════════════════════════════════════════════════
    [$svcCat,
        'What single action most instantly improves the professional appearance of a maintained lawn?', 'easy',
        "Clean, precise edging along all hard borders (sidewalks, driveways, beds) instantly elevates a lawn from average to professional.\nWhy edging first: even a freshly mowed lawn looks unkempt with ragged edges. Clean lines frame the lawn.\nProfessional sequence: mow → edge → trim → blow.\nLong-term: consistent edging prevents grass from creeping into beds and maintains permanent clean lines.",
        'multiple_choice', 5,
        'Clean precise edging along all borders', ['Applying extra fertilizer', 'Cutting shorter than usual', 'Additional watering before mowing']],

    [$svcCat,
        'What technique creates attractive alternating light and dark stripes in a lawn?', 'medium',
        "Lawn striping is created by bending grass in alternating directions as you mow — a roller attachment behind the mower bends the grass flat.\nLight stripe: grass bent toward viewer (light reflects off sides of blades).\nDark stripe: grass bent away from viewer (you see the tips, less reflection).\nRoller mowers or mowers with rear rollers create the strongest stripes.\nTip: mow adjacent rows in opposite directions — use a fence or edge as a straight reference line.",
        'multiple_choice', 5,
        'Rolling grass in alternating directions with a roller attachment', ['Mowing more frequently', 'Using shorter cutting blades', 'Applying stripe pattern fertilizer']],

    [$svcCat,
        'What most often causes uneven mow lines or a washboard effect across a lawn?', 'medium',
        "Inconsistent or incorrect cutting height setting — if the mower deck height changes between runs (or isn't set evenly across all wheels) — creates uneven stripes.\nOther causes: operator speed variation, scalping on uneven terrain, one wheel in a rut.\nCheck: ensure all four wheels are set to the same height, mow at consistent pace, check deck levelness.\nA washboard pattern can also result from mowing in the same direction every time — vary direction each visit.",
        'multiple_choice', 5,
        'Inconsistent mowing height setting or uneven deck', ['Dull mower blades', 'Mowing too fast', 'Mowing wet grass']],

    [$svcCat,
        'What most defines a lawn that looks professionally maintained over time?', 'easy',
        "Consistency — a regular schedule of mowing, edging, and cleaning — is the hallmark of professional maintenance.\nProfessional appearance depends on: the same mowing height every visit, edging every visit, no scalping, no missed areas, clean blowdown.\nClients notice inconsistency: if you skip edging one week, the lawn looks unmaintained regardless of how well it was mowed.\nSet the standard on visit one and maintain it every visit after.",
        'multiple_choice', 5,
        'Consistent schedule and consistent standards every visit', ['Cutting shorter each visit', 'Mowing rarely to reduce wear', 'Skipping edging to save time']],

    [$svcCat,
        'What input most improves long-term soil structure and turf health?', 'easy',
        "Regular addition of organic matter — compost, aged manure, or arborist chips — is the single best long-term investment in soil quality.\nOrganic matter improves: water retention in sandy soil, drainage in clay, nutrient holding capacity, microbial life, and soil structure.\nApplication method: topdress 0.5–1cm after aeration so it works into the holes, or spread before overseeding.\nResults take time (1–3 years of consistent application) but are permanent improvements.",
        'multiple_choice', 5,
        'Regular organic matter (compost) additions', ['Sand topdressing only', 'Irrigation alone', 'Lime application']],

    // ═══════════════════════════════════════════════════════════════════════
    // SALES KNOWLEDGE (5) — Customer Service cat, level 5
    // ═══════════════════════════════════════════════════════════════════════
    [$svcCat,
        'When a client asks why they should pay for aeration, what is the most compelling reason to give?', 'easy',
        "Sales script:\n\"Aeration is the single most impactful thing we can do for your lawn's long-term health. It relieves soil compaction — which is the number one reason lawns thin out and struggle. By opening up the soil, water, air, and nutrients can reach the roots instead of sitting on the surface. Think of it as letting your lawn breathe again. Most Vancouver lawns are on clay soil and need it at least once a year to stay healthy and dense.\"",
        'customer_explanation', 5,
        'Aeration relieves compaction and allows water, air, and nutrients to reach the roots effectively', ['Aeration kills weeds', 'Aeration replaces fertilizer treatments', 'Aeration improves grass colour instantly']],

    [$svcCat,
        'When a client questions the value of fertilizer, what is the most compelling explanation?', 'easy',
        "Sales script:\n\"Fertilizer is the nutrition program for your lawn. Without the right nutrients — especially nitrogen — grass slowly thins, loses colour, and becomes vulnerable to weeds and disease. A healthy fertilizer program keeps the turf dense, green, and resilient. Dense turf is your best defence against weeds and the best way to protect your landscaping investment. We use timed-release formulas so the lawn gets consistent feeding rather than boom-and-bust spikes.\"",
        'customer_explanation', 5,
        'Fertilizer maintains turf density, colour, and resilience — the foundation of a healthy lawn', ['Fertilizer removes existing weeds instantly', 'Fertilizer reduces mowing frequency', 'Fertilizer prevents all moss growth']],

    [$svcCat,
        'A client with obvious bare and thin spots asks whether they should sod or overseed. What do you recommend and why?', 'medium',
        "Overseeding recommendation for thin/bare spots (unless more than 50% bare):\n\"For the bare and thin areas you're seeing, overseeding is the most cost-effective and lasting solution. We spread millions of new grass seeds into the existing lawn — they germinate and fill in those gaps, creating a seamless result without seams or edges. Sod is faster but much more expensive and can struggle to integrate with existing turf. Overseed in fall when conditions are ideal, and you'll see significant improvement within a season.\"",
        'customer_explanation', 5,
        'Recommend overseeding to fill bare spots naturally and cost-effectively', ['Always recommend sod for any bare area', 'Recommend herbicide to clear and restart', 'Recommend reduced watering to force thicker growth']],

    [$svcCat,
        'Why is a regular maintenance program more valuable to recommend than one-time visits?', 'medium',
        "Program value script:\n\"A consistent maintenance program is where the real value is for your property. Lawn health is cumulative — regular mowing at the right height, consistent fertilization, and seasonal aeration build on each other year after year. One-off visits might look good temporarily, but without consistency, the lawn degrades between visits. A program also means we catch problems early — we'll notice pest damage, disease, or drainage issues before they become expensive repairs.\"",
        'customer_explanation', 5,
        'Consistent programs build cumulative turf health and allow early problem detection', ['Programs are only needed for large properties', 'One-time visits are equally effective', 'Programs are just more billing opportunities']],

    [$svcCat,
        'A client says their neighbour\'s lawn looks better without any professional service. How do you respond?', 'medium',
        "Professional value response:\n\"That's possible — some lawns are lucky with great soil, good sun, and motivated owners. But most Vancouver lawns are on heavy clay with compaction challenges, moss pressure, and chafer beetle risk — problems that build up quietly over years. The investment in professional care isn't just about appearance today, it's about protecting your soil health long-term. We catch problems early, prevent expensive lawn replacements, and consistently deliver a result that adds real value to your property.\"",
        'customer_explanation', 5,
        'Professional care protects long-term soil health and catches problems before they become costly', ['Agree that the neighbour is getting better value', 'Offer to lower the price immediately', 'Argue that self-care never works well']],

    // ═══════════════════════════════════════════════════════════════════════
    // MASTERY QUESTIONS (5) — Turf & Soil cat, level 6 scenario
    // ═══════════════════════════════════════════════════════════════════════
    [$turfCat,
        'What is the single most important foundation for creating and maintaining a healthy lawn ecosystem?', 'hard',
        "Healthy soil biology and structure is the irreducible foundation.\nAll turf health flows from soil: nutrient availability, water retention, root depth, microbial life, disease resistance.\nYou cannot consistently overcome poor soil with fertilizer, irrigation, or pesticides alone — these are inputs that work best in healthy soil.\nLong-term investment: annual compost topdressing, regular aeration, pH management, and reduced compaction build soil health progressively.\nHealthy soil produces strong roots. Strong roots produce resilient grass. Resilient grass outcompetes weeds and withstands pests.",
        'scenario', 6,
        'Healthy soil biology and structure', ['A consistent herbicide application program', 'Keeping the grass cut very short', 'Daily watering routines']],

    [$turfCat,
        'What is the most effective and sustainable long-term strategy for reducing weed pressure?', 'hard',
        "Building a thick, competitive turf canopy is the most sustainable long-term strategy.\nWhy: weed seeds need light to germinate — dense grass shades soil and physically prevents germination.\nHerbicides treat existing weeds but don't prevent future invasion if turf remains thin.\nComprehensive weed reduction program:\n1. Aerate to improve root depth\n2. Overseed to thicken turf\n3. Fertilize to maintain density\n4. Mow at 3\" to maximize canopy cover\n5. Treat remaining weeds as needed\nWith dense turf, herbicide use drops dramatically over 2–3 seasons.",
        'scenario', 6,
        'Building a dense, competitive turf canopy through overseeding and proper maintenance', ['Continuing herbicide applications indefinitely', 'Keeping the soil bare between grass plants', 'Mowing very short to suppress weed growth']],

    [$turfCat,
        'What watering practice most directly improves grass root depth and long-term drought resistance?', 'hard',
        "Deep, infrequent watering trains grass roots to grow deeper into the soil seeking moisture.\nMechanism: when surface soil dries between waterings, roots extend deeper to find water in the subsoil.\nOptimal: 25mm (1\") applied 2–3 times per week rather than 5–7mm daily.\nDaily shallow watering keeps moisture at the surface — roots have no reason to grow deep — creating a shallow root system vulnerable to summer heat and drought.\nThe difference: shallowly watered lawns brown and die in drought within days; deeply watered lawns can survive weeks.",
        'scenario', 6,
        'Deep infrequent watering to encourage roots to seek deeper soil moisture', ['Daily shallow watering for consistent moisture', 'Fertilizer applications combined with irrigation', 'Core aeration without adjusting watering habits']],

    [$turfCat,
        'What soil condition most improves turf resilience against both summer drought and winter wet?', 'hard',
        "Balanced, well-structured soil with good organic matter content provides resilience in both extremes.\nOrganic matter:\n• In drought: increases water-holding capacity, reducing stress\n• In wet: improves drainage and aeration, preventing root rot\nAdditionally: good soil structure (aggregates) maintains pore space for both air and water.\nBuilding this soil: annual compost topdressing, core aeration, reduced compaction — takes 2–5 years but creates permanent improvement.\nChemical inputs (fertilizer, pesticide) cannot substitute for structural soil health.",
        'scenario', 6,
        'Balanced, well-structured soil with healthy organic matter levels', ['High fertilizer nutrient content', 'Consistently waterlogged soil', 'Fine sand content added to clay']],

    [$turfCat,
        'What combination of services most consistently produces a premium, professional lawn over multiple seasons?', 'hard',
        "A holistic, integrated care program delivered consistently is what separates premium lawns from average ones:\n• Regular mowing at correct height (3\") — promotes density\n• Seasonal fertilization (spring + fall) — maintains nutrition\n• Annual core aeration — relieves compaction, promotes roots\n• Overseeding as needed — maintains and improves density\n• Pre-emergent weed control + spot treatment — suppresses invasion\n• Soil pH management + compost topdressing — improves soil long-term\nNo single service creates a premium result — it is the combination, executed consistently, season after season, that compounds into a genuinely exceptional lawn.",
        'scenario', 6,
        'A consistent holistic program: mowing, fertilizing, aeration, overseeding, and soil management', ['Using only premium expensive fertilizer products', 'Daily mowing and irrigation', 'Irrigation system alone without other services']],

];  // end $questions

// ── 4. Insert questions ────────────────────────────────────────────────────────
$inserted = 0;
$skipped  = 0;
$errors2  = [];

$checkStmt = $db->prepare("SELECT id FROM quiz_questions WHERE question_text = ? AND category_id = ? LIMIT 1");
$insertQ   = $db->prepare(
    "INSERT INTO quiz_questions (category_id, question_text, learn_notes, difficulty, question_type, learning_level, is_active)
     VALUES (?, ?, ?, ?, ?, ?, 1)"
);
$insertOpt = $db->prepare("INSERT INTO quiz_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");

foreach ($questions as $idx => $q) {
    [$catId, $text, $diff, $notes, $qType, $level, $correct, $wrong] = $q;

    $checkStmt->execute([$text, $catId]);
    if ($checkStmt->fetch()) {
        $skipped++;
        continue;
    }

    try {
        $insertQ->execute([$catId, $text, $notes, $diff, $qType, $level]);
        $qid = (int)$db->lastInsertId();

        $options = array_merge(
            [['text' => $correct, 'correct' => 1]],
            array_map(fn($w) => ['text' => $w, 'correct' => 0], $wrong)
        );
        shuffle($options);

        foreach ($options as $opt) {
            $insertOpt->execute([$qid, $opt['text'], $opt['correct']]);
        }

        $inserted++;
    } catch (PDOException $e) {
        $errors2[] = "Q#{$idx}: " . $e->getMessage();
    }
}

$steps[] = "✅ Seeded {$inserted} questions ({$skipped} already existed, " . count($errors2) . " errors)";
if ($errors2) {
    foreach ($errors2 as $e) {
        $errors[] = $e;
    }
}

echo json_encode([
    'success' => empty($errors),
    'steps'   => $steps,
    'errors'  => $errors,
    'summary' => [
        'inserted' => $inserted,
        'skipped'  => $skipped,
        'total'    => count($questions),
    ],
    'done'    => true,
], JSON_PRETTY_PRINT);
