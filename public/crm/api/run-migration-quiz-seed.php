<?php
/**
 * /public/crm/api/run-migration-quiz-seed.php
 * Seeds ~150 questions across all 6 quiz categories.
 * Run once as admin, then delete this file.
 * Idempotent — skips questions that already exist (matched on text + category).
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

session_write_close(); // Release session lock before heavy DB work

$db = getDB();

// ── Get category IDs ─────────────────────────────────────────────────────────
$catRows   = $db->query("SELECT id, name FROM quiz_categories")->fetchAll(PDO::FETCH_KEY_PAIR);
$byName    = array_flip($catRows);
$weedCat   = (int)($byName['Weed Identification'] ?? 0);
$plantCat  = (int)($byName['Plant & Grass ID']    ?? 0);
$equipCat  = (int)($byName['Equipment & Tools']   ?? 0);
$safetyCat = (int)($byName['Safety & PPE']         ?? 0);
$pestCat   = (int)($byName['Pest & Disease ID']    ?? 0);
$turfCat   = (int)($byName['Turf & Soil']          ?? 0);

$missing = [];
foreach (['Weed Identification' => $weedCat, 'Plant & Grass ID' => $plantCat,
          'Equipment & Tools' => $equipCat, 'Safety & PPE' => $safetyCat,
          'Pest & Disease ID' => $pestCat, 'Turf & Soil' => $turfCat] as $n => $id) {
    if (!$id) $missing[] = $n;
}
if ($missing) {
    echo json_encode(['error' => 'Missing categories — run run-migration-quiz.php first', 'missing' => $missing]);
    exit;
}

// ── Question data ─────────────────────────────────────────────────────────────
// [$category_id, $question_text, $difficulty, $learn_notes, $correct, [$wrong1,$wrong2,$wrong3]]

$questions = [

// ════════════════════════════════════════════════════════════════════════════
// 1. WEED IDENTIFICATION
// ════════════════════════════════════════════════════════════════════════════

[$weedCat, 'This weed has a rosette of jagged, deeply lobed leaves and a hollow stem that oozes milky sap when broken. What is it?', 'easy',
"Dandelion — Taraxacum officinale\nKey ID: jagged basal rosette, hollow stem with milky sap, deep taproot, single bright-yellow composite flower, fluffy white seed clock.\nControl: dig out entire taproot — regenerates from any fragment left behind.",
'Dandelion', ['Broadleaf Plantain', 'Creeping Buttercup', 'White Clover']],

[$weedCat, 'This creeping weed produces shiny, bright-yellow five-petalled flowers and spreads by long rooting runners across lawns. What is it?', 'easy',
"Creeping Buttercup — Ranunculus repens\nKey ID: 3-lobed toothed leaves (often with pale markings), glossy 5-petalled yellow flowers, creeping stolons that root at nodes.\nFavours wet, poorly drained soil. Improve drainage to suppress it.",
'Creeping Buttercup', ['Dandelion', 'Yellow Wood Sorrel', 'Creeping Jenny']],

[$weedCat, 'This aggressive perennial weed has spiny, lobed leaves and small purple flowers. It spreads by deep creeping roots and is designated noxious in BC. What is it?', 'medium',
"Canada Thistle — Cirsium arvense\nKey ID: spiny wavy-lobed leaves, clusters of small purple flowers, spreads by far-reaching underground rhizomes AND wind-blown seeds.\nDesignated noxious weed in BC. Repeated cutting weakens root reserves over time.",
'Canada Thistle', ['Bull Thistle', 'Burdock', 'Spear Thistle']],

[$weedCat, 'This weed forms a flat rosette of oval, ribbed leaves with prominent parallel veins, and sends up a narrow rat-tail spike covered in tiny flowers. What is it?', 'easy',
"Broadleaf Plantain — Plantago major\nKey ID: oval leaves with 5–7 parallel veins, fibrous roots that snap like strings when pulled, narrow upright seed spike.\nIndicator plant for compacted soil. Very tolerant of foot traffic.",
'Broadleaf Plantain', ['Dandelion', "Lamb's Quarters", 'Common Chickweed']],

[$weedCat, 'This low-growing weed has trifoliate leaves often marked with a white crescent, and produces small white globe-shaped flowers. What is it?', 'easy',
"White Clover — Trifolium repens\nKey ID: 3-part leaves with white chevron marking, round white/pinkish flower heads, nitrogen-fixing root nodules.\nBeneficial for pollinators. Can indicate low nitrogen in the lawn.",
'White Clover', ['Oxalis', "Bird's-Foot Trefoil", 'Dandelion']],

[$weedCat, 'This delicate low-growing weed forms dense mats with tiny star-shaped white flowers. It thrives in cool, moist conditions. What is it?', 'easy',
"Common Chickweed — Stellaria media\nKey ID: oval paired leaves, single line of hairs along the stem, tiny 5-petalled star flowers (deeply notched so they look like 10 petals).\nWinter annual — germinates in autumn, sets masses of seed in spring.",
'Common Chickweed', ['Hairy Bittercress', 'Speedwell', 'Mouse-ear Chickweed']],

[$weedCat, 'This low-growing weed has square stems, kidney-shaped scalloped leaves, and smells strongly of mint when crushed. What is it?', 'medium',
"Ground Ivy (Creeping Charlie) — Glechoma hederacea\nKey ID: square stems (mint family), round scalloped leaves, small purple tubular flowers, minty smell, roots at every node.\nBroadleaf herbicide or persistent hand-pulling required.",
'Ground Ivy', ['Creeping Thyme', 'Wild Mint', 'Henbit']],

[$weedCat, 'This weed has heart-shaped, three-part leaves that fold down at night and small yellow flowers. It is often confused with clover. What is it?', 'easy',
"Yellow Wood Sorrel — Oxalis stricta\nKey ID: shamrock-shaped 3-lobed leaves that fold at night, small 5-petalled yellow flowers, explosive cylindrical seed pods.\nDistinct from clover — leaves fold in evening, no white chevron marking.",
'Yellow Wood Sorrel', ['White Clover', 'Creeping Buttercup', "Bird's-Foot Trefoil"]],

[$weedCat, 'This small winter annual has a basal rosette of pinnate leaves and tiny white flowers. When ripe, it catapults seeds if the pod is touched. What is it?', 'medium',
"Hairy Bittercress — Cardamine hirsuta\nKey ID: pinnate rosette leaves, tiny white 4-petalled flowers, long narrow seed pods that explosively catapult seeds on contact.\nSets seed very rapidly — deadhead before pods form. Common in containers.",
'Hairy Bittercress', ["Shepherd's Purse", 'Common Chickweed', 'Herb Robert']],

[$weedCat, 'This invasive weed has hollow, bamboo-like jointed stems, large heart-shaped leaves with a flat base, and forms dense monocultures. It is regulated in BC. What is it?', 'hard',
"Japanese Knotweed — Reynoutria japonica\nKey ID: hollow jointed stems (like bamboo), heart-shaped leaves with a flat base, creamy-white flowers in late summer, deep rhizomes.\nExtremely invasive — rhizomes penetrate foundations. Do NOT compost. Report large stands.",
'Japanese Knotweed', ['Giant Knotweed', 'Common Horsetail', 'Himalayan Balsam']],

[$weedCat, 'This invasive vine has three-to-five lobed, waxy leaves and spreads aggressively over ground and trees. It is listed as invasive in Metro Vancouver. What is it?', 'medium',
"English Ivy — Hedera helix\nKey ID: 3–5 lobed waxy leaves (juvenile form), aerial rootlets that cling to surfaces, black berries in clusters on mature plants.\nSmothers native understory. Designated invasive in Metro Vancouver — do not plant.",
'English Ivy', ['Virginia Creeper', 'Wild Grape', 'Climbing Hydrangea']],

[$weedCat, 'This invasive shrub has large arching canes with stout curved thorns, white or pink five-petalled flowers, and edible black berries. What is it?', 'easy',
"Himalayan Blackberry — Rubus armeniacus\nKey ID: thick canes with large curved thorns, 3–5 oval leaflets, white/pink 5-petalled flowers, black aggregate fruit.\nMost problematic invasive shrub in BC. Dig roots repeatedly — rhizomes can extend 1 m deep.",
'Himalayan Blackberry', ['Wild Rose', 'Scotch Broom', 'Gooseberry']],

[$weedCat, 'This prehistoric-looking weed has segmented hollow stems with whorls of tiny scale-like sheaths instead of leaves. It spreads aggressively by deep rhizomes. What is it?', 'medium',
"Common Horsetail — Equisetum arvense\nKey ID: segmented hollow stems with whorls of fine green side branches, no true leaves, reproduces by spores and deep rhizomes.\nAncient vascular plant. Nearly impossible to eradicate — improve drainage to suppress.",
'Common Horsetail', ['Scouring Rush', 'Bamboo', 'Japanese Knotweed']],

[$weedCat, 'This pale green grass weed has boat-shaped leaf tips and produces whitish seed heads year-round, even at low mowing heights. What is it?', 'medium',
"Annual Bluegrass — Poa annua\nKey ID: light green colour, boat-shaped (keeled) leaf tip, crinkled leaves, whitish-green triangular seed head even when mown short.\nWinter annual; can become perennial in mild climates like Vancouver.",
'Annual Bluegrass (Poa annua)', ['Kentucky Bluegrass', 'Perennial Ryegrass', 'Bentgrass']],

[$weedCat, 'This summer annual grass weed germinates in warm soil, spreads prostrate along the ground, and produces distinctive finger-like seed heads in late summer. What is it?', 'medium',
"Large Crabgrass — Digitaria sanguinalis\nKey ID: hairy leaf sheaths and blades, prostrate habit, roots at stem nodes, 3–8 finger-like seed branches from a single point.\nPrevents with pre-emergent in spring; hand-pull before seed set.",
'Crabgrass', ['Annual Bluegrass', 'Quackgrass', 'Foxtail']],

[$weedCat, 'This perennial grass weed has sharp-tipped white rhizomes and clasping claw-like projections (auricles) at the leaf base. What is it?', 'hard',
"Quackgrass — Elymus repens\nKey ID: sharp pointed white rhizomes (can pierce other roots), clasping auricles at leaf base, dull blue-green leaves.\nDo not rototill — multiplies from every root fragment. Systemic herbicide required.",
'Quackgrass', ['Crabgrass', 'Annual Bluegrass', 'Bentgrass']],

[$weedCat, 'This perennial weed has arrow-shaped leaves and funnel-shaped white or pink flowers. Its roots can extend 9 metres deep. What is it?', 'medium',
"Field Bindweed — Convolvulus arvensis\nKey ID: arrow-shaped leaves with pointed basal lobes, white/pink funnel flowers, extremely deep taproot and lateral roots.\nOne of the hardest weeds to eradicate. Roots must be repeatedly cut over several seasons.",
'Field Bindweed', ['Morning Glory', 'English Ivy', 'Creeping Jenny']],

[$weedCat, 'This mat-forming weed has tiny bright blue four-petalled flowers and small oval leaves. It often invades thin, shaded lawns. What is it?', 'medium',
"Common Speedwell — Veronica serpyllifolia\nKey ID: tiny bright blue/lilac 4-petalled flowers, small paired oval leaves, heart-shaped notched seed pods.\nSeveral Veronica species invade lawns — all have the distinctive tiny blue flowers.",
'Speedwell', ['Ground Ivy', 'Forget-Me-Not', 'Common Chickweed']],

[$weedCat, 'This aromatic perennial has feathery, finely divided grey-green leaves and flat-topped clusters of tiny white flowers. Very drought tolerant. What is it?', 'medium',
"Common Yarrow — Achillea millefolium\nKey ID: feathery pinnately-divided aromatic leaves, flat-topped dense clusters of tiny white flowers, spreads by rhizomes and seed.\nIndicator of poor, dry, infertile soil.",
'Yarrow', ["Queen Anne's Lace", 'Feverfew', 'Chamomile']],

[$weedCat, 'This annual weed has soft, toothed leaves and small yellow flowers without ray petals, producing fluffy white seeds similar to a miniature dandelion. What is it?', 'hard',
"Common Groundsel — Senecio vulgaris\nKey ID: soft toothed leaves, small yellow flowers without ray petals, fluffy white seed heads, green bracts with black tips.\nProduces seed in as little as 6 weeks. Year-round in mild climates.",
'Common Groundsel', ['Ragwort', 'Chamomile', 'Feverfew']],

[$weedCat, 'This weed has reddish stems, deeply divided fern-like leaves, small pink flowers, and smells unpleasant when touched. What is it?', 'hard',
"Herb Robert — Geranium robertianum\nKey ID: reddish hairy stems, deeply divided (pinnate) leaves that turn red in autumn, small bright pink 5-petalled flowers, strong unpleasant smell when crushed.\nAnnual/biennial — remove before seeding.",
'Herb Robert', ['Wild Geranium', 'Hairy Bittercress', 'Herb Paris']],

[$weedCat, 'This perennial weed has toothed, heart-shaped leaves covered with stinging hairs that inject chemicals causing a painful burning sensation. What is it?', 'easy',
"Stinging Nettle — Urtica dioica\nKey ID: toothed heart-shaped opposite leaves covered in hollow stinging hairs (trichomes) that inject histamine and formic acid on contact.\nWear gloves! Indicates nitrogen-rich soil. Young leaves edible when cooked.",
'Stinging Nettle', ['White Dead-nettle', 'Hemp Nettle', "Lamb's Quarters"]],

[$weedCat, 'This annual weed has a small basal rosette and produces triangular, heart-shaped seed pods that resemble a small purse or pouch. What is it?', 'medium',
"Shepherd's Purse — Capsella bursa-pastoris\nKey ID: basal rosette of lobed leaves, white 4-petalled cross-shaped flowers, distinctive triangular/heart-shaped flattened seed pods.\nYear-round annual — extremely prolific seed production.",
"Shepherd's Purse", ['Hairy Bittercress', 'Pennycress', 'Field Pepperwort']],

[$weedCat, 'This perennial weed has small, round bright-green leaves on long trailing stems and produces small yellow cup-shaped flowers. It forms dense mats in moist areas. What is it?', 'medium',
"Creeping Jenny (Moneywort) — Lysimachia nummularia\nKey ID: round coin-shaped opposite leaves on prostrate stems, small bright-yellow 5-petalled flowers, roots at nodes in moist soil.\nSpreads aggressively from gardens into lawns near water features.",
'Creeping Jenny', ['Creeping Buttercup', 'Ground Ivy', 'Wild Strawberry']],

[$weedCat, 'This non-vascular plant forms dense, low-growing green mats in shaded, acidic, compacted, or waterlogged lawn areas. What is it?', 'easy',
"Lawn Moss — various species (Polytrichum, Hypnum, Bryum)\nKey ID: dense low green mat with tiny overlapping leaf-like structures, no flowers or seeds, thrives in shade, wet, acidic or compacted conditions.\nMoss is a symptom — address shade, drainage, compaction, and pH to prevent recurrence.",
'Moss', ['Liverwort', 'Algae', 'Lichen']],

// ════════════════════════════════════════════════════════════════════════════
// 2. PLANT & GRASS ID
// ════════════════════════════════════════════════════════════════════════════

[$plantCat, 'This cool-season turf grass has a distinctive boat-shaped (keel-tipped) leaf tip, spreads by rhizomes, and forms a dense dark-green lawn. What is it?', 'medium',
"Kentucky Bluegrass — Poa pratensis\nKey ID: boat-shaped (folded/keeled) leaf tip, medium-fine texture, blue-green colour, spreads by rhizomes.\nBest in full sun; goes summer dormant in drought. Common in Pacific Northwest lawn mixes.",
'Kentucky Bluegrass', ['Annual Bluegrass', 'Creeping Red Fescue', 'Tall Fescue']],

[$plantCat, 'This cool-season grass germinates rapidly (5–7 days), has a glossy underside on its blade with prominent veins, and is widely used in overseeding mixes. What is it?', 'medium',
"Perennial Ryegrass — Lolium perenne\nKey ID: bright glossy underside on leaf blade, prominent parallel veins, rolled in the bud (not folded), rapid germination.\nExcellent wear tolerance. Widely used in BC sports fields and lawn mixes.",
'Perennial Ryegrass', ['Annual Ryegrass', 'Kentucky Bluegrass', 'Tall Fescue']],

[$plantCat, 'This fine-leaved, shade-tolerant grass has a rolled (circular) leaf cross-section and reddish leaf sheaths. It is the most common grass in BC shade mixes. What is it?', 'medium',
"Creeping Red Fescue — Festuca rubra\nKey ID: very fine bristle-like leaves with circular cross-section (rolled, not folded), reddish leaf sheaths, slow-creeping rhizomes.\nBest grass for shade, dry slopes, and low-input lawns in the Pacific Northwest.",
'Creeping Red Fescue', ['Hard Fescue', 'Bentgrass', 'Kentucky Bluegrass']],

[$plantCat, 'This coarse-bladed, clump-forming grass has a prominent midrib and rough leaf edges. It is drought tolerant but tends to appear as coarse clumps in finer lawns. What is it?', 'medium',
"Tall Fescue — Festuca arundinacea\nKey ID: wide flat blades with prominent midrib and rough edges, rolled in bud, forms clumps (non-spreading).\nMore drought tolerant than other cool-season grasses; used on low-maintenance areas.",
'Tall Fescue', ['Quackgrass', 'Perennial Ryegrass', 'Orchardgrass']],

[$plantCat, 'This very fine-textured grass spreads by above-ground creeping stolons and forms a dense, putting-green quality surface. It produces significant thatch. What is it?', 'hard',
"Creeping Bentgrass — Agrostis stolonifera\nKey ID: very fine texture, flat leaf blade with rounded tip, spreads by stolons, produces heavy thatch, grey-green colour.\nRequires very low mowing (3–10mm) and intense management. Can be a weed in other turf types.",
'Creeping Bentgrass', ['Creeping Red Fescue', 'Annual Bluegrass', 'Kentucky Bluegrass']],

[$plantCat, 'This popular hedging shrub has small oval glossy dark-green leaves, a dense compact form, and a distinctive musty smell. What is it?', 'easy',
"Boxwood — Buxus sempervirens\nKey ID: small (1–3cm) opposite oval glossy leaves, dense branching, insignificant greenish flowers, distinctive musty/foxy smell.\nBoxwood blight (Cylindrocladium buxicola) is an emerging disease in BC — inspect regularly.",
'Boxwood', ['Privet', 'Holly', 'Yew']],

[$plantCat, 'This ornamental tree has deeply lobed, palmate leaves on slender stalks and is prized for striking autumn colours from gold to crimson. What is it?', 'easy',
"Japanese Maple — Acer palmatum\nKey ID: palmate leaves with 5–9 deeply cut lobes, opposite branching, paired winged samaras.\nHundreds of cultivars. Protect from wind scorch; many prefer dappled shade.",
'Japanese Maple', ['Vine Maple', 'Norway Maple', 'Sweetgum']],

[$plantCat, 'This large evergreen shrub has big leathery leaves and produces spectacular clusters of flowers in spring. It is the official floral emblem of BC. What is it?', 'easy',
"Rhododendron — Rhododendron macrophyllum (BC floral emblem)\nKey ID: large (10–25cm) elliptical leathery evergreen leaves, large showy terminal flower trusses in spring, leaves curl downward in cold.\nPrefers acidic, moist, well-drained soil. Keep root flare at surface — do not plant deep.",
'Rhododendron', ['Mountain Laurel', 'Camellia', 'Magnolia']],

[$plantCat, 'This popular shrub produces large rounded or flat-topped flower heads whose colour changes from pink to blue based on soil pH. What is it?', 'easy',
"Bigleaf Hydrangea — Hydrangea macrophylla\nKey ID: large ovate toothed leaves, mophead or lacecap flower clusters, colour changes with pH (blue in acidic pH <6, pink in alkaline pH >7).\nPrune only after flowering. Late frost can kill flower buds.",
'Bigleaf Hydrangea', ['Snowball Bush (Viburnum)', 'Mountain Laurel', 'Oakleaf Hydrangea']],

[$plantCat, 'This aromatic perennial has narrow grey-green leaves and tall spikes of small purple-blue flowers. It is widely used as a border plant and attracts pollinators. What is it?', 'easy',
"English Lavender — Lavandula angustifolia\nKey ID: narrow silvery-grey leaves, square stems, dense spikes of purple-blue flowers, intensely aromatic.\nPrefers well-drained alkaline soil and full sun. Prune lightly after flowering — never cut into old wood.",
'Lavender', ['Catmint', 'Russian Sage', 'Salvia']],

[$plantCat, 'This shade-loving perennial is grown primarily for its large, ribbed, heart-shaped leaves in shades of green, blue, or gold. What is it?', 'easy',
"Hosta — Hosta spp.\nKey ID: large ovate to heart-shaped leaves with prominent ribbing, wide colour range (green/blue/gold/variegated), tall scapes of tubular flowers.\nSlug damage is the main issue — use iron phosphate bait. Divide congested clumps in spring.",
'Hosta', ['Bergenia', 'Brunnera', 'Ligularia']],

[$plantCat, 'This native BC groundcover has thick, leathery oval leaves with finely toothed margins, pinkish urn-shaped flowers, and dark edible berries. It dominates the coastal forest understory. What is it?', 'medium',
"Salal — Gaultheria shallon\nKey ID: thick leathery glossy oval leaves (5–10cm), pinkish-white urn-shaped flowers, dark purple-black edible berries, spreads by rhizomes.\nNative to coastal BC. Excellent for shaded dry slopes. Berries used by First Nations.",
'Salal', ['Manzanita', 'Kalmia', 'Leucothoe']],

[$plantCat, "This native BC shrub has holly-like compound leaves with spiny leaflets, yellow flowers in early spring, and clusters of blue-black berries. It is BC's provincial flower. What is it?", 'medium',
"Oregon Grape — Mahonia aquifolium\nKey ID: pinnate leaves with 5–9 holly-like spiny leaflets (glossy green, turning bronze in winter), dense yellow flower clusters in spring, blue-black waxy berries.\nBC provincial flower. Extremely shade and drought tolerant.",
'Oregon Grape', ['Holly', 'Skimmia', 'Pieris']],

[$plantCat, 'This native BC fern forms large upright clumps. Each pinna (leaflet) has a distinctive ear-like lobe (auricle) at its base pointing toward the frond tip. What is it?', 'medium',
"Sword Fern — Polystichum munitum\nKey ID: dark glossy green strap-like fronds, each pinna has a small pointed auricle at the base, round brown sori on the underside.\nDominant fern in coastal BC forests. Evergreen — excellent year-round groundcover in shade.",
'Sword Fern', ['Lady Fern', 'Deer Fern', 'Bracken Fern']],

[$plantCat, 'This native BC shrub is best known for its brilliant red stems, most striking in winter. It has opposite oval leaves and flat-topped clusters of small white flowers. What is it?', 'medium',
"Red-Osier Dogwood — Cornus sericea\nKey ID: opposite oval leaves with arcuate veins, bright red stems (most vivid on young growth in winter), flat-topped white flower clusters, white/bluish berries.\nPrune hard every 2–3 years in late winter to keep young red stems prominent.",
'Red-Osier Dogwood', ['Pacific Dogwood', 'Tatarian Dogwood', 'Ninebark']],

[$plantCat, 'This deciduous shrub produces a mass of bright yellow flowers on bare stems in very early spring, before its leaves emerge. What is it?', 'easy',
"Forsythia — Forsythia × intermedia\nKey ID: bright yellow 4-petalled tubular flowers on bare branches in early spring (Feb–Apr), opposite ovate toothed leaves appear after flowers.\nPrune immediately after flowering — pruning in late summer removes next year's flower buds.",
'Forsythia', ['Winter Jasmine', 'Kerria', 'Cornelian Cherry Dogwood']],

[$plantCat, 'This flowering tree produces masses of pink or white blossom in spring. The smooth grey-brown bark has distinctive horizontal markings called lenticels. What is it?', 'easy',
"Ornamental Cherry — Prunus serrulata / Prunus spp.\nKey ID: pink or white 5-petalled flowers in clusters in spring, smooth brownish-grey bark with prominent horizontal lenticels, serrated ovate leaves.\nPrune only in dry weather to avoid silver leaf disease.",
'Ornamental Cherry', ['Ornamental Plum', 'Crabapple', 'Pear']],

[$plantCat, 'This conifer has flat dark-green needles in two ranks and produces red fleshy cup-shaped fruits. All parts except the red flesh are highly toxic. What is it?', 'medium',
"English Yew — Taxus baccata\nKey ID: flat dark-green needles with pale underside in two rows, red fleshy aril (seed cup), peeling reddish-brown bark.\nAll parts toxic except the red fleshy aril. Excellent for formal hedging; very long-lived.",
'English Yew', ['Western Red Cedar', 'Douglas Fir', 'Western Hemlock']],

[$plantCat, 'This evergreen shrub has glossy, spiny leaves and bright red berries in winter. Male and female plants are both needed for berry production. What is it?', 'easy',
"English Holly — Ilex aquifolium\nKey ID: glossy dark-green leaves with sharp spines, bright red berries (winter), dioecious — separate male/female plants needed for berries.\nInvasive in Pacific Northwest forests — remove volunteer seedlings spread by birds.",
'English Holly', ['Skimmia', 'Oregon Grape', 'Cotoneaster']],

[$plantCat, 'This spreading perennial has strap-like leaves and produces large trumpet-shaped flowers on tall scapes, but each individual flower only lasts one day. What is it?', 'easy',
"Daylily — Hemerocallis spp.\nKey ID: arching strap-like leaves, large 6-tepalled funnel flowers on branched scapes, each flower open only one day, spreads by fleshy tubers.\nToxic to cats — causes acute kidney failure. Divide every 3–4 years.",
'Daylily', ['Agapanthus', 'Crocosmia', 'Bearded Iris']],

[$plantCat, 'This woodland perennial has arching stems of pendulous heart-shaped pink-and-white flowers hanging like lockets in spring. It dies back completely in summer. What is it?', 'medium',
"Bleeding Heart — Lamprocapnos spectabilis (syn. Dicentra spectabilis)\nKey ID: glaucous blue-green compound leaves, arching stems bearing rows of heart-shaped flowers with protruding white inner petals.\nAll parts toxic. Goes summer dormant — interplant with hostas or ferns to fill the gap.",
'Bleeding Heart', ['Fuchsia', 'Columbine', 'Virginia Bluebells']],

[$plantCat, 'This shade-tolerant perennial has finely divided fern-like foliage and produces large feathery plumes of tiny flowers in white, pink, or red. It needs consistently moist soil. What is it?', 'medium',
"Astilbe — Astilbe spp.\nKey ID: deeply divided (2–3× pinnate) dark-green or bronze-tinted leaves, large erect or arching plumes of tiny flowers, ornamental dead plumes in winter.\nWilts dramatically in drought. Divide every 3 years to maintain vigour.",
'Astilbe', ["Goat's Beard", 'Meadowsweet', 'Rodgersia']],

[$plantCat, 'This tall ornamental grass forms arching clumps and produces silvery-pink feathery plumes in autumn. It is one of the most widely used landscape grasses. What is it?', 'easy',
"Maiden Grass — Miscanthus sinensis\nKey ID: tall (1–2.5m) arching clumps with white midrib stripe, silky silver-pink plumes in late summer/autumn, copper/tan in winter.\nCut back hard to 15cm in late winter before new growth.",
'Miscanthus (Maiden Grass)', ['Pampas Grass', 'Feather Reed Grass', 'Blue Oat Grass']],

[$plantCat, 'This native BC tree has distinctive peeling cinnamon-red bark that reveals a smooth, cool, greenish inner surface. It is the only native broadleaf tree on the BC coast. What is it?', 'hard',
"Pacific Madrone (Arbutus) — Arbutus menziesii\nKey ID: peeling cinnamon-red to orange bark revealing smooth greenish inner bark, thick leathery oval evergreen leaves, clusters of small white urn-shaped flowers, red-orange berries.\nBC's only native broadleaf evergreen tree. Very sensitive to root disturbance — do not irrigate in summer.",
'Pacific Madrone (Arbutus)', ['Strawberry Tree', 'Manzanita', 'Paper Birch']],

[$plantCat, 'This large BC native tree has scale-like foliage in flat sprays, reddish-brown fibrous bark, and is the most common tree used in BC hedging and windbreaks. What is it?', 'easy',
"Western Red Cedar — Thuja plicata\nKey ID: flat sprays of scale-like aromatic foliage, reddish-brown stringy fibrous bark, small oval cones.\nBC's provincial tree. Extremely long-lived. Widely used for hedging (often sold as 'Emerald Green' cedar).",
'Western Red Cedar', ['Leyland Cypress', 'White Cedar', 'Douglas Fir']],

// ════════════════════════════════════════════════════════════════════════════
// 3. EQUIPMENT & TOOLS
// ════════════════════════════════════════════════════════════════════════════

[$equipCat, 'This handheld power tool uses a rapidly rotating monofilament line to cut grass and weeds along edges and under obstacles. What is it?', 'easy',
"String Trimmer (Line Trimmer / Weed Eater)\nKey safety: wear safety glasses and closed-toe footwear — line throws debris at high speed. Keep bystanders back 15m. Never put hands near rotating head.\nMaintenance: replace line before it wears too short; clean air filter regularly.",
'String Trimmer', ['Hedge Trimmer', 'Edger', 'Reciprocating Saw']],

[$equipCat, 'This tool uses two reciprocating blades to cut plant material. It is used for shaping hedges, shrubs, and topiaries. What is it?', 'easy',
"Hedge Trimmer\nKey safety: never reach across the blade bar; keep both hands on handles; disengage blade before moving to a new position. Wear gloves and eye protection.\nMaintenance: lubricate blades before and after each use; sharpen when cutting becomes ragged.",
'Hedge Trimmer', ['String Trimmer', 'Chainsaw', 'Reciprocating Saw']],

[$equipCat, 'This walk-behind power tool cuts a precise vertical line along lawn edges, pavement joints, and garden beds, using a vertically rotating steel blade. What is it?', 'medium',
"Lawn Edger\nKey safety: wear safety glasses and steel-toed boots — blade hits soil, roots, and hidden debris. Keep feet clear of blade path. Never use on slopes where you could slip into the blade.\nMaintenance: keep blade sharp for clean cuts. Adjust blade depth to avoid damaging pavement.",
'Lawn Edger', ['String Trimmer', 'Rotary Tiller', 'Sod Cutter']],

[$equipCat, 'This gas-powered cutting tool has a guide bar and chain that moves at high speed. It is primarily used for felling trees and cutting logs. What is it?', 'easy',
"Chainsaw\nKey safety: wear chainsaw chaps, hard hat with face shield, hearing protection, chainsaw gloves, and steel-toed boots. Never cut above shoulder height. Use two hands. Beware kickback zone.\nMaintenance: check chain tension and sharpness before every use. Keep chain oiler full.",
'Chainsaw', ['Reciprocating Saw', 'Circular Saw', 'Pole Saw']],

[$equipCat, 'This tool is an extension pole with a chainsaw or pruning saw head at the end. It is used to cut branches at height without a ladder. What is it?', 'easy',
"Pole Saw\nKey safety: plan your escape route before cutting — branches can fall unpredictably. Wear hard hat and eye protection. Never stand directly under the branch being cut.\nMaintenance: keep the cutting head blade/chain sharp. Check all pole connections before use.",
'Pole Saw', ['Chainsaw', 'Hedge Trimmer', 'Reciprocating Saw']],

[$equipCat, 'This large machine pulls or drives a drum with hollow tines that remove small plugs of soil from turf to reduce compaction and improve water and nutrient penetration. What is it?', 'medium',
"Core Aerator (Hollow-Tine Aerator)\nKey safety: mark irrigation heads and shallow utilities before aerating. Walk at a consistent pace — uneven coverage reduces effectiveness.\nBest practice: aerate when soil is moist but not saturated. Leave plugs on surface to break down naturally.",
'Core Aerator', ['Lawn Roller', 'Scarifier', 'Overseeder']],

[$equipCat, 'This machine uses rotating vertical blades (knives) to slice through turf thatch and deposit seed directly into the soil slits. What is it?', 'medium',
"Slit Seeder (Overseeder)\nKey safety: mark all irrigation heads and utilities before using. Set blade depth carefully — too deep damages existing turf; too shallow gives poor seed-soil contact.\nBest practice: overseed in late summer/early fall for cool-season grasses in BC.",
'Slit Seeder (Overseeder)', ['Core Aerator', 'Scarifier', 'Lawn Roller']],

[$equipCat, 'This machine uses rapidly spinning horizontal flail blades or tines to tear out the thatch layer from turf, improving air and water penetration to roots. What is it?', 'medium',
"Scarifier (Dethatcher / Power Rake)\nKey safety: remove debris from lawn before use — the machine throws objects at high speed. Wear eye protection and steel-toed boots.\nBest time: spring or early fall. Do not scarify stressed or dormant turf.",
'Scarifier (Dethatcher)', ['Core Aerator', 'Slit Seeder', 'Rotary Tiller']],

[$equipCat, 'This walk-behind or tow-behind device uses a heavy cylinder to flatten and firm the soil or turf surface. It is often used after seeding or frost heave. What is it?', 'easy',
"Lawn Roller\nKey safety: never use on steep slopes — roller can run away on downhill grades. Keep bystanders clear.\nBest practice: fill only 1/3 to 1/2 with water — a fully filled roller (500+ kg) can cause compaction. Use only on loose/freshly seeded areas.",
'Lawn Roller', ['Core Aerator', 'Scarifier', 'Compactor Plate']],

[$equipCat, 'This pressurised container worn on the back is used to apply liquid pesticides, fertilisers, or water to plants. What is it?', 'easy',
"Backpack Sprayer\nKey safety: wear chemical-resistant gloves, goggles, and a respirator when using pesticides. Never spray in wind >15 km/h. Check for leaks before putting on. Triple-rinse after use.\nMaintenance: flush with clean water after every use; replace worn seals and O-rings regularly.",
'Backpack Sprayer', ['Broadcast Spreader', 'Drop Spreader', 'Compression Sprayer']],

[$equipCat, 'This wheeled device throws granular materials (fertiliser, seed, ice melt) in a wide arc using a spinning disc. What is it?', 'easy',
"Broadcast (Rotary) Spreader\nKey safety: calibrate before each application — an incorrect setting wastes product or damages turf/plants.\nBest practice: overlap passes by 50% to avoid missed strips. Clean thoroughly after each use — salt and fertiliser corrode metal.",
'Broadcast Spreader', ['Drop Spreader', 'Backpack Sprayer', 'Granule Applicator']],

[$equipCat, 'This wheeled spreader deposits granular material directly below the hopper through bottom openings, giving very precise edge control. What is it?', 'medium',
"Drop Spreader\nKey safety: calibrate settings carefully — drop spreaders are unforgiving; incorrect calibration causes striping or over-application.\nBest practice: close the hopper before turning at the end of each pass to avoid over-application at the headlands.",
'Drop Spreader', ['Broadcast Spreader', 'Backpack Sprayer', 'Granule Duster']],

[$equipCat, 'This machine uses a high-velocity stream of water to clean hard surfaces, equipment, and vehicles. What is it?', 'easy',
"Pressure Washer\nKey safety: never point at people or animals — the jet can penetrate skin. Wear safety glasses and waterproof footwear. Keep both hands on the wand.\nMaintenance: flush with clean water and run briefly with pump saver solution before storage.",
'Pressure Washer', ['Garden Hose', 'Steam Cleaner', 'Air Compressor']],

[$equipCat, 'This hand tool has a long, narrow, asymmetrically ground blade used for digging planting holes, cutting roots, dividing perennials, and weeding. It is sometimes called a Japanese garden knife. What is it?', 'medium',
"Hori-Hori (Soil Knife)\nKey ID: 30cm stainless steel blade, one serrated edge, one smooth edge, pointed tip, measurement markings on blade.\nVersatile tool — can divide plants, cut sod, dig out taproots, and open bags. Keep sharp and oiled.",
'Hori-Hori (Soil Knife)', ['Hand Trowel', 'Cape Cod Weeder', 'Dibber']],

[$equipCat, 'This hand pruning tool has two sharp curved blades and a spring mechanism. It is used for cuts up to approximately 20mm diameter. What is it?', 'easy',
"Bypass Pruning Shears (Secateurs)\nKey ID: two curved blades that bypass (cross) each other like scissors, giving a clean cut.\nNever use to cut wire or zip ties — damages the blade. Keep clean, sharp, and lightly oiled. Clean between plants to prevent disease spread.",
'Bypass Pruning Shears', ['Loppers', 'Anvil Shears', 'Garden Scissors']],

[$equipCat, 'This two-handed cutting tool works like giant scissors or has an anvil mechanism. It is used for branches 20–50mm in diameter that are too large for hand secateurs. What is it?', 'easy',
"Loppers\nKey ID: long handles (50–90cm) for leverage, cutting head for branches 20–50mm diameter, available in bypass (clean cut) or anvil (crushing) styles.\nFor live branches always use bypass style — anvil crushes plant cells. Keep blades sharp and clean between plants.",
'Loppers', ['Pruning Shears', 'Bow Saw', 'Chainsaw']],

[$equipCat, 'This gas-powered backpack unit generates a high-velocity airstream used to clear leaves, grass clippings, and light debris from hard surfaces and turf edges. What is it?', 'easy',
"Backpack Blower\nKey safety: wear hearing protection — backpack blowers exceed 100 dB. Eye protection required. Never blow debris toward people, traffic, or open windows.\nMaintenance: clean air filter regularly; check spark plug annually.",
'Backpack Blower', ['Backpack Sprayer', 'Leaf Vacuum', 'Air Compressor']],

[$equipCat, 'This machine uses a spinning cutting disc or drum at adjustable heights to remove a tree stump below ground level, producing wood chip mulch. What is it?', 'hard',
"Stump Grinder\nKey safety: wear full face shield, ear protection, and steel-toed boots — stump grinders throw chips and rocks at high velocity. Clear the area of all bystanders within 15m.\nMark any nearby utilities before grinding. Grind 30cm below grade for replanting.",
'Stump Grinder', ['Wood Chipper', 'Chainsaw', 'Trencher']],

[$equipCat, 'This machine accepts branches and brush and mechanically reduces them to wood chip mulch using a fast-spinning disc or drum with cutting knives. What is it?', 'medium',
"Wood Chipper\nKey safety: never reach into the feed chute. Keep hair, strings, and loose clothing away from the infeed. Always feed material from the side, not while standing directly in front.\nMaintenance: check and sharpen cutting knives regularly. Keep emergency stop accessible.",
'Wood Chipper', ['Stump Grinder', 'Tub Grinder', 'Shredder']],

// ════════════════════════════════════════════════════════════════════════════
// 4. SAFETY & PPE
// ════════════════════════════════════════════════════════════════════════════

[$safetyCat, 'This class of PPE protects the skull from falling objects, bumps against fixed objects, and electric shocks. It is required on all construction and tree work sites. What is it?', 'easy',
"Hard Hat / Safety Helmet\nClasses: Class E (electrical, up to 20,000V), Class G (general, up to 2,200V), Class C (no electrical protection).\nInspect before each use — replace after any impact, or after 5 years from manufacture date even if undamaged.",
'Hard Hat', ['Bump Cap', 'Climbing Helmet', 'Welding Hood']],

[$safetyCat, 'This brightly coloured garment with retroreflective strips makes workers visible to vehicle traffic. It is mandatory when working within 30m of traffic in BC. What is it?', 'easy',
"High-Visibility (Hi-Vis) Safety Vest\nClass 1: low risk, 0.22 m² retroreflective material. Class 2: medium risk, 0.13 m² background material + retroreflective strips. Class 3: highest risk — full coverage including arms.\nBC WorkSafeBC requires Class 2 minimum when working within 30m of moving traffic.",
'Hi-Vis Safety Vest', ['Reflective Jacket', 'Safety Harness', 'Fire-Retardant Coveralls']],

[$safetyCat, 'This PPE protects the eyes from flying debris, chemical splashes, and UV radiation. They are required when operating power equipment and applying chemicals. What is it?', 'easy',
"Safety Glasses / Goggles\nSafety glasses: impact-rated lenses (ANSI Z87.1), side shields recommended for power tools.\nGoggles: required for chemical applications — provide full eye seal. Use face shield PLUS goggles for grinding operations.\nReplace when lenses become scratched or pitted.",
'Safety Glasses / Goggles', ['Sunglasses', 'Face Shield', 'Welding Goggles']],

[$safetyCat, 'This PPE reduces noise exposure to protect hearing. Repeated exposure to sounds above 85 dB without protection causes permanent hearing loss. What is it?', 'easy',
"Hearing Protection (Ear Muffs or Ear Plugs)\nDecibel reference: string trimmer ~95 dB, backpack blower ~100 dB, chainsaw ~110 dB.\nNoise Reduction Rating (NRR): higher NRR = more protection. Foam plugs (NRR 29–33) or ear muffs (NRR 20–30).\nRequired for any equipment above 85 dB for sustained use under WorkSafeBC.",
'Hearing Protection', ['Ear Canal Guards', 'Noise-Cancelling Headphones', 'Hard Hat']],

[$safetyCat, 'These gloves are required when operating a chainsaw. They contain layers of cut-resistant fibre that jam and stop the chain if contacted. What is it?', 'medium',
"Chainsaw Gloves (Cut-Resistant Gloves)\nKey feature: left-hand palm and back are reinforced with layers of protective fibres (Kevlar/Dyneema) that entangle and stop the chain.\nAlways check for cuts, tears, or damage before use — a damaged glove provides no protection.",
'Chainsaw Gloves', ['Leather Work Gloves', 'Nitrile Chemical Gloves', 'Anti-Vibration Gloves']],

[$safetyCat, 'This lower body PPE is worn over regular pants when operating a chainsaw. It contains multiple layers of cut-resistant fibres designed to stop a moving chain. What is it?', 'medium',
"Chainsaw Chaps / Chainsaw Trousers\nKey feature: inner layers of Kevlar/Dyneema fibres entangle and stop the chain on contact. Must meet CSA Z62.1 standard.\nInspect before each use — any cut or damage to the material renders chaps unsafe and they must be replaced.",
'Chainsaw Chaps', ['Cut-Resistant Pants', 'Leather Apron', 'Arc Flash Trousers']],

[$safetyCat, 'This transparent shield protects the entire face from flying debris, chemical splashes, and chainsaw kickback. It must be worn in addition to safety glasses, not instead of them. What is it?', 'medium',
"Face Shield\nKey rule: a face shield does NOT replace eye protection — always wear safety glasses underneath.\nRequired for: chainsaw operation, grinding, chemical mixing/pouring, and stump grinding.\nCSA Z94.3 rating required in workplace applications.",
'Face Shield', ['Safety Goggles', 'Welding Mask', 'Hard Hat with Visor']],

[$safetyCat, 'This footwear has reinforced toe caps (steel or composite) and puncture-resistant midsoles. It is required when operating power equipment and on all work sites. What is it?', 'easy',
"Steel-Toed Safety Boots\nCSA Grade 1 (Green Triangle patch): protective toe + puncture-resistant sole.\nCSA Grade 2 (Yellow Triangle patch): protective toe only.\nFor landscaping: Grade 1 recommended. Use chainsaw boots (marked with orange/yellow chainsaw icon) for chainsaw work.",
'Steel-Toed Safety Boots', ['Work Boots', 'Rubber Gumboots', 'Anti-Slip Shoes']],

[$safetyCat, 'This respiratory PPE is worn when mixing or applying pesticides and when working in dusty conditions. It filters out particles, chemical vapours, or both, depending on the cartridge type. What is it?', 'medium',
"Respirator / Half-Face Respirator\nTypes: N95 (disposable, filters 95% of particles), P100 cartridge (99.97% particles), OV cartridge (organic vapours — required for most pesticides).\nRead the SDS to determine correct cartridge type. Fit-test required for tight-fitting respirators.",
'Respirator', ['Dust Mask (surgical)', 'Full-Face Respirator', 'Air-Purifying Hood']],

[$safetyCat, 'This safety system requires workers to isolate and lock energy sources (electrical, hydraulic, pneumatic) before performing maintenance on equipment. What is it?', 'hard',
"Lockout/Tagout (LOTO)\nProcedure: 1. Notify affected workers. 2. Identify all energy sources. 3. Shut down. 4. Isolate energy (switch/valve). 5. Apply personal lock. 6. Release stored energy. 7. Verify isolation.\nRequired under WorkSafeBC before any maintenance where unexpected start-up could cause injury.",
'Lockout / Tagout', ['Permit-to-Work', 'Tag-Only System', 'Circuit Breaker Reset']],

[$safetyCat, 'These are the standardized symbols used on chemical safety labels in Canada, part of the Globally Harmonized System. They indicate hazard types such as flammability, toxicity, and environmental harm. What are they?', 'hard',
"WHMIS / GHS Hazard Pictograms\nKey symbols: Flame (flammable), Skull & Crossbones (acute toxicity), Exclamation Mark (irritant), Health Hazard (serious long-term effects), Environment (aquatic toxicity), Corrosion, Exploding Bomb, Gas Cylinder, Biohazard.\nEvery hazardous chemical must have an SDS (Safety Data Sheet) — keep on site and accessible to all workers.",
'WHMIS / GHS Hazard Pictograms', ['NFPA Diamond', 'Transport Canada Placards', 'MSDS Labels']],

[$safetyCat, 'Early symptoms of this heat-related condition include heavy sweating, cold and pale skin, fast weak pulse, nausea, and muscle cramps. If untreated, it can progress to heat stroke. What is it?', 'medium',
"Heat Exhaustion\nSymptoms: heavy sweating, cool/pale/clammy skin, fast weak pulse, nausea, dizziness, muscle cramps, fatigue.\nAction: move to cool area, loosen clothing, apply cool wet cloths, drink water slowly. Call 911 if symptoms don't improve within 15 minutes or person loses consciousness.\nHeat stroke is a life-threatening emergency — hot/red/dry skin, confusion, no sweating.",
'Heat Exhaustion', ['Heat Stroke', 'Dehydration', 'Sunstroke']],

[$safetyCat, 'This condition occurs when core body temperature drops below 35°C due to prolonged exposure to cold. Early signs include uncontrollable shivering, confusion, and slurred speech. What is it?', 'medium',
"Hypothermia\nStages: mild (35–32°C: shivering, confusion), moderate (32–28°C: no shivering, rigid muscles), severe (<28°C: no pulse, unconscious).\nAction: move to warm shelter, remove wet clothing, wrap in blankets, warm drinks if conscious. Call 911 for moderate/severe cases.\nWind chill significantly increases risk — always layer and carry emergency gear.",
'Hypothermia', ['Frostbite', 'Cold Urticaria', 'Raynaud\'s Disease']],

[$safetyCat, 'Under BC pesticide regulations, anyone who applies pesticides commercially must hold this certification, which requires passing a standardized exam. What is it?', 'hard',
"BC Pesticide Applicator Licence (Landscape/Structural category)\nIssued by: BC Ministry of Environment.\nRequired for: any commercial application of pesticides in BC, including applying fertiliser blended with pesticide.\nRenewal: every 5 years (continuing education credits required). Keep licence on person during application.",
'BC Pesticide Applicator Licence', ['WorkSafeBC First Aid Certificate', 'WHMIS Training Certificate', 'CARETM Certification']],

[$safetyCat, 'This document, required on every worksite in BC, provides detailed information about a hazardous product including its ingredients, health hazards, safe handling, PPE requirements, and emergency procedures. What is it?', 'easy',
"Safety Data Sheet (SDS) — formerly MSDS\nRequired by WHMIS 2015 (aligned with GHS). Must be readily accessible to all workers who may be exposed.\n16 sections including: Identification, Hazard ID, Composition, First Aid, Fire Fighting, Handling & Storage, PPE, and Disposal.\nKeep SDS binder in vehicle/site for all chemicals used.",
'Safety Data Sheet (SDS)', ['Product Label', 'Technical Data Sheet', 'MSDS']],

[$safetyCat, 'This safe fuel handling practice prevents potentially explosive vapour buildup in enclosed spaces. It requires filling two-stroke equipment at least this far from ignition sources, and waiting before starting after a fuel spill. What practice is it?', 'medium',
"Safe Fuel Handling / Fuel Storage\nKey rules: never fuel indoors or near flames. Allow engine to cool before refuelling. Use an approved fuel container. Wipe up spills immediately and move away before starting.\nTwo-stroke mix ratio: check equipment manual — typically 50:1 (gasoline:oil) for most modern equipment.\nStore fuel in approved containers away from heat sources.",
'Safe Fuel Handling', ['Lockout/Tagout', 'Hazmat Response', 'Fire Extinguisher Use']],

[$safetyCat, 'In BC, what is the minimum distance workers must maintain from overhead power lines when using equipment such as cranes, boom trucks, or tall hedges/trees? (For lines up to 75kV)', 'hard',
"Minimum Distance from Overhead Power Lines (BC)\nFor lines up to 75kV: minimum 3 metres (10 feet) — mandated by WorkSafeBC.\nFor lines 75–250kV: minimum 4.5 metres.\nFor lines over 250kV: minimum 6 metres.\nCall BC One Call (1-800-474-6886) to identify buried utilities before digging.",
'3 metres (10 feet)', ['1.5 metres', '5 metres', '6 metres']],

// ════════════════════════════════════════════════════════════════════════════
// 5. PEST & DISEASE ID
// ════════════════════════════════════════════════════════════════════════════

[$pestCat, 'Irregular, irregularly-shaped patches of brown turf appear in late summer. When the brown grass is pulled, it lifts like a carpet and a C-shaped white grub is found in the soil. What pest is responsible?', 'medium',
"European Chafer Grub — Amphimallon majale\nKey ID: C-shaped white grub with orange-brown head capsule, found 5–15cm deep in soil, turf lifts easily where roots are eaten.\nSecondary damage: skunks, raccoons, crows tear up turf to feed on grubs.\nControl: beneficial nematodes (Heterorhabditis bacteriophora) applied Aug–Sep while soil is moist and warm.",
'European Chafer Grub', ['Leatherjacket', 'Sod Webworm', 'Wireworm']],

[$pestCat, 'Yellow-brown patches of turf appear in spring after snow melt. The roots and shoots are eaten by fat, grey-brown legless fly larvae found just below the turf surface. What pest is responsible?', 'medium',
"Leatherjacket (Crane Fly Larva) — Tipula paludosa / T. oleracea\nKey ID: grey-brown, legless, tough-skinned larva (5–45mm), no distinct head capsule visible, found at turf-soil interface.\nDamage appears April–May as irregular brown patches; grubs active Oct–Apr.\nControl: beneficial nematodes (Steinernema feltiae) applied Oct–Nov in mild, moist conditions.",
'Leatherjacket', ['European Chafer Grub', 'Sod Webworm', 'Cut Worm']],

[$pestCat, 'Small, irregular patches of brown turf appear in hot, dry weather. Small beige moths fly out when the grass is disturbed and tiny green caterpillars are found at the base of grass blades. What pest is responsible?', 'medium',
"Sod Webworm — Crambus spp.\nKey ID: small beige moths flutter low over turf at dusk, green/tan caterpillars (12–20mm) feed on grass blades at soil level at night, silky white tunnels at turf base.\nControl: beneficial nematodes (Steinernema carpocapsae) applied in warm evenings when larvae are active.",
'Sod Webworm', ['Armyworm', 'Cutworm', 'Leatherjacket']],

[$pestCat, 'Small, straw-coloured circular patches (5–25cm) appear in turf, particularly in cool, wet conditions. The patches have a distinctive tan centre with darker borders and the grass appears bleached. What disease is this?', 'medium',
"Dollar Spot — Sclerotinia homoeocarpa\nKey ID: small circular bleached spots (size of a silver dollar), water-soaked lesions with tan/brown borders on individual grass blades, white cobwebby mycelium visible in early morning dew.\nFavoured by: nitrogen deficiency, drought stress, morning dew, poor air circulation.\nManagement: improve fertility, water deeply in morning, improve air flow.",
'Dollar Spot', ['Brown Patch', 'Necrotic Ring Spot', 'Powdery Mildew']],

[$pestCat, 'Large, irregular brown patches (30cm–1m+) appear in hot, humid weather. Affected grass blades show brown lesions with a darker brown border. The disease is most active at night above 20°C. What is it?', 'medium',
"Brown Patch — Rhizoctonia solani\nKey ID: large irregular tan/brown patches with a darker 'smoke ring' border in early morning dew, brown lesions with tan centre and dark border on individual blades.\nFavoured by: high humidity, night temps >20°C, excess nitrogen, poor drainage.\nManagement: reduce nitrogen, water early morning, improve drainage.",
'Brown Patch', ['Dollar Spot', 'Large Patch', 'Pythium Blight']],

[$pestCat, 'Pink-red threads extend from grass blades in cool, moist weather. Affected patches have a pinkish hue and irregular shape. The grass is weakened but rarely killed. What disease is this?', 'easy',
"Red Thread — Laetisaria fuciformis\nKey ID: pink-red gelatinous threads (stromata) extending 1–2cm from grass blade tips, fluffy pink mycelium visible in damp conditions, irregularly shaped patches.\nFavoured by: low nitrogen, temperatures 15–22°C, prolonged leaf wetness.\nManagement: apply nitrogen fertiliser — this usually resolves red thread rapidly.",
'Red Thread', ['Pink Patch', 'Pythium Red Blight', 'Fusarium Patch']],

[$pestCat, 'Circular patches of matted, bleached, or salmon-pink grass appear in late winter/early spring after snow melt. A pink fungal growth may be visible on affected areas. What disease is this?', 'medium',
"Pink Snow Mold — Microdochium nivale\nKey ID: circular patches (5–30cm) of matted bleached grass after snow melt, salmon/pink fungal mycelium visible at edge of patch in cool wet conditions.\nMost common lawn disease in BC winters. Unlike grey snow mold, it does NOT require snow cover.\nManagement: avoid late-season high nitrogen; mow to proper height in fall; fungicide in autumn if recurring.",
'Pink Snow Mold', ['Grey Snow Mold', 'Dollar Spot', 'Pythium Blight']],

[$pestCat, 'Rings or arcs of darker, faster-growing grass appear in turf, sometimes with a ring of mushrooms. The grass inside the ring may be dead or stimulated. What is this condition?', 'medium',
"Fairy Ring — various Basidiomycete fungi\nKey ID: ring or arc of dark stimulated grass (or dead turf), mushrooms or toadstools may appear at the ring margin, caused by decomposing organic matter underground.\nType 1 (lethal): hydrophobic zone kills grass. Type 2: stimulated ring only. Type 3: mushrooms only.\nManagement: aeration + wetting agents to break hydrophobic zone; remove buried wood/stumps.",
'Fairy Ring', ['Mushroom Circles', 'Dollar Spot Rings', 'Dog Urine Spots']],

[$pestCat, 'Yellow-orange powdery pustules appear on grass blades in late summer/fall, especially on ryegrass. Shoes and equipment turn yellow-orange after walking through affected areas. What disease is this?', 'medium',
"Turf Rust — Puccinia spp.\nKey ID: orange-yellow powdery pustules on grass blades, rust-coloured powder rubs off on hands/shoes, affects ryegrass and bluegrass in late summer.\nFavoured by: slow growth conditions (low N, drought, shade), temperatures 16–22°C.\nManagement: apply nitrogen to stimulate growth; mow frequently to remove infected leaf tissue.",
'Turf Rust', ['Red Thread', 'Brown Patch', 'Helminthosporium Leaf Spot']],

[$pestCat, 'Clusters of small soft-bodied insects — often green, black, or woolly — colonise new shoot growth and undersides of leaves. They secrete a sticky substance that leads to black mold growth. What are they?', 'easy',
"Aphids — various species (Macrosiphum, Aphis, Myzus spp.)\nKey ID: soft-bodied pear-shaped insects (1–3mm), clustered on new growth and leaf undersides, honeydew (sticky excretion) leads to sooty mold growth.\nControl: strong water blast; insecticidal soap; encourage ladybugs, lacewings, and parasitic wasps.\nAnt farming of aphids is common — control ants to reduce aphid pressure.",
'Aphids', ['Mealybugs', 'Scale Insects', 'Whiteflies']],

[$pestCat, 'A notch-chewing pattern appears on leaf margins of plants such as rhododendrons and hostas. The adult is a black weevil that feeds at night; the larvae destroy roots underground in spring. What pest is this?', 'medium',
"Black Vine Weevil — Otiorhynchus sulcatus\nKey ID: irregular notch-chewing on leaf margins (adult feeding, April–Sep), C-shaped white grubs with brown head destroy roots (Oct–Apr), adults are black/grey and cannot fly.\nControl: beneficial nematodes (Steinernema kraussei) applied to soil in Oct–Nov; sticky barriers on pot rims.",
'Black Vine Weevil', ['Root Weevil', 'Lily Beetle', 'Strawberry Root Weevil']],

[$pestCat, 'A bright scarlet beetle and its black slimy larvae skeletonise lily and fritillary leaves rapidly. Both adult and larva are destructive. What pest is this?', 'medium',
"Scarlet Lily Beetle — Lilioceris lilii\nKey ID: adult is brilliant red with black legs and head (7–8mm), larva is orange-red covered in black slime/excrement, both skeletonise Lilium and Fritillaria leaves.\nControl: hand-pick adults and larvae daily; neem oil; encourage natural predators.",
'Scarlet Lily Beetle', ['Colorado Potato Beetle', 'Rosemary Beetle', 'Asparagus Beetle']],

[$pestCat, 'Slime trails, irregular holes chewed in leaves, and seedling collapse are signs of this common nocturnal soil-dwelling pest. Damage is worst in cool, moist conditions. What pest is it?', 'easy',
"Slugs and Snails — Arion, Deroceras, Helix spp.\nKey ID: slime trails (silvery when dry), irregular jagged holes in leaves (especially hostas, dahlias, seedlings), most active at night and after rain.\nControl: iron phosphate bait (Slug-B-Gon) is effective and safe for wildlife/pets. Copper tape for containers. Encourage ground beetles.",
'Slugs / Snails', ['Cutworms', 'Earwigs', 'Flea Beetles']],

[$pestCat, 'Fine webbing appears on the underside of leaves during hot, dry weather. Leaves develop a stippled, bronze appearance and may drop prematurely. What pest causes this?', 'medium',
"Spider Mites — Tetranychus urticae\nKey ID: very tiny (0.5mm) eight-legged mites invisible to naked eye, fine silk webbing on leaf undersides, stippled/bronzed leaves, populations explode in hot dry conditions.\nControl: strong water spray to undersides; insecticidal soap; predatory mites (Phytoseiulus persimilis).",
'Spider Mites', ['Aphids', 'Thrips', 'Whiteflies']],

[$pestCat, 'White or grey powdery coating appears on the surface of leaves, often on roses, squash, and peonies, in warm days with cool nights and high humidity. What disease is this?', 'easy',
"Powdery Mildew — Erysiphe, Sphaerotheca spp.\nKey ID: white/grey powdery coating on leaf surfaces (primarily upper side), affected leaves may yellow and distort.\nUnlike most fungal diseases, powdery mildew thrives in warm dry days with cool humid nights.\nControl: improve air circulation; baking soda spray; potassium bicarbonate; fungicide if severe.",
'Powdery Mildew', ['Downy Mildew', 'Botrytis (Grey Mold)', 'White Rust']],

// ════════════════════════════════════════════════════════════════════════════
// 6. TURF & SOIL
// ════════════════════════════════════════════════════════════════════════════

[$turfCat, 'The soil pH scale runs from 0 to 14. Most turf grasses in BC grow best in what pH range?', 'easy',
"Optimum Turf Grass Soil pH: 6.0 to 7.0\nAt this range, the major nutrients (N, P, K, Ca, Mg) are most available to plant roots.\nBelow pH 5.5: aluminum and manganese become toxic; phosphorus becomes locked up.\nAbove pH 7.5: iron and manganese become deficient (interveinal chlorosis).\nAmend: lime to raise pH; sulfur or acidifying fertilizer to lower pH.",
'6.0 to 7.0', ['5.0 to 6.0', '7.0 to 8.0', '4.5 to 5.5']],

[$turfCat, 'A lawn shows uniform yellowing of older leaves first, stunted growth, and pale green colour across the entire lawn. The soil test shows deficiency of this primary macronutrient. What nutrient is deficient?', 'easy',
"Nitrogen (N) Deficiency\nSymptoms: uniform yellowing starting on older (lower) leaves, slow growth, pale green to yellow-green colour, thin turf.\nRole: essential for chlorophyll production, leaf and shoot growth.\nAmend: balanced fertiliser (e.g. 24-4-8) or quick nitrogen source. Avoid excessive N — causes excessive growth, disease susceptibility, and leaching.",
'Nitrogen (N)', ['Iron (Fe)', 'Phosphorus (P)', 'Potassium (K)']],

[$turfCat, 'Leaves show yellowing between the veins while the veins themselves remain green — a pattern called interveinal chlorosis. This is most common on alkaline soils above pH 7.0. What nutrient is deficient?', 'medium',
"Iron (Fe) Deficiency — Interveinal Chlorosis\nSymptoms: young leaves yellow between green veins (veins stay green), most severe in new growth.\nCause: iron becomes insoluble above pH 7.0, or in waterlogged/overwatered soils.\nAmend: chelated iron spray (fast response) or soil acidification. Check drainage. Do not over-lime.",
'Iron (Fe)', ['Nitrogen (N)', 'Magnesium (Mg)', 'Manganese (Mn)']],

[$turfCat, 'The layer of dead and living organic matter that accumulates between the grass blades and the soil surface is called what? Excess of this layer (>1.5cm) can impede water and nutrient penetration.', 'easy',
"Thatch\nComposition: mainly dead stem material (crowns, stolons, rhizomes) plus some roots and leaves.\nProblems at >1.5cm: water repellency, pest/disease harbour, root growth into thatch (shallow roots).\nRemoval: power raking (scarifying) removes light thatch; core aeration breaks down heavy thatch over time.\nPrevention: avoid excess nitrogen and irrigation; mow regularly.",
'Thatch', ['Mat', 'Humus Layer', 'Organic Mulch']],

[$turfCat, 'This cultural practice involves pushing hollow metal tubes into the turf to remove plugs of soil. It relieves compaction, reduces thatch, and improves air/water/nutrient penetration. What is it?', 'easy',
"Core Aeration (Hollow-Tine Aeration)\nBest timing: autumn (Sep–Oct) for cool-season grasses in BC — grass is actively growing and recovers quickly.\nCore holes: 7–10cm deep, 5–7cm apart for maximum benefit.\nLeave plugs on surface — they break down in 2–4 weeks, returning organic matter to soil.\nCombine with overseeding for best turf renovation results.",
'Core Aeration', ['Solid-Tine Spiking', 'Scarifying', 'Lawn Rolling']],

[$turfCat, 'This practice involves applying a thin layer of a sand/compost or sand/soil mixture (3–6mm) evenly over the turf surface. It levels the lawn, improves soil structure, and aids thatch breakdown. What is it?', 'medium',
"Topdressing\nMaterial: match to existing soil type — sandy soil gets sandy topdress; avoid heavy clay on sandy soil (creates layers).\nApplication: 3–6mm is ideal; too thick buries grass and causes suffocation.\nBest combined with: core aeration (fills holes) and overseeding.\nImproves: drainage, levelness, root development, organic matter.",
'Topdressing', ['Top Dressing with Mulch', 'Sodding', 'Soil Amendment']],

[$turfCat, 'This nutrient ratio labelling system on fertiliser bags shows the percentage of nitrogen, phosphate, and potash in the product. A bag labelled 24-4-8 contains what percentages?', 'easy',
"NPK — Nitrogen (N), Phosphate (P₂O₅), Potassium/Potash (K₂O)\n24-4-8 means: 24% N, 4% P₂O₅, 8% K₂O by weight.\nN: leaf and shoot growth. P: root development, germination. K: stress tolerance, root strength.\nFor established lawns: higher N, lower P (lawns rarely need extra P). For seeding: balanced N-P-K or starter fertiliser with higher P.",
'24% Nitrogen, 4% Phosphate, 8% Potash', ['24% Phosphate, 4% Nitrogen, 8% Potash', '24% Potassium, 4% Phosphate, 8% Nitrogen', '24% total nutrients evenly split']],

[$turfCat, 'This type of fertiliser releases nutrients slowly over weeks or months through microbial activity or controlled-release coating, reducing the risk of burn and leaching. What is it called?', 'medium',
"Slow-Release (Controlled-Release) Fertiliser\nExamples: polymer-coated urea (PCU), sulfur-coated urea (SCU), IBDU, methylene urea.\nAdvantages: lower burn risk, fewer applications, more even growth, less leaching into waterways.\nDisadvantage: more expensive per unit of nutrient.\nUse for: established lawns, low-maintenance programs, near water bodies.",
'Slow-Release Fertiliser', ['Organic Fertiliser', 'Liquid Fertiliser', 'Fast-Release Fertiliser']],

[$turfCat, 'When the top of a mower blade cuts off more than one-third of the grass blade height in a single pass, it causes browning, stress, and root dieback. What is this mowing mistake called?', 'easy',
"Scalping\nThe One-Third Rule: never remove more than 1/3 of the total blade height in a single mow.\nExample: if target height is 7cm, mow before grass reaches 10.5cm.\nScalping consequences: reduced photosynthetic area, weakened roots, increased weed and pest susceptibility, brown appearance.\nIf scalped: water, fertilise lightly, avoid traffic until grass recovers.",
'Scalping', ['Striping', 'Coring', 'Edging']],

[$turfCat, 'This soil type has particles between 0.05 and 2mm in size. It drains well but has poor nutrient and water retention. It is often described as gritty to the touch. What soil type is it?', 'easy',
"Sandy Soil\nParticle size: 0.05–2mm. Large air spaces = fast drainage but poor water/nutrient retention.\nCharacteristics: warms quickly in spring, drought-prone, low cation exchange capacity (CEC).\nAmend with: compost, peat, or biochar to improve water and nutrient retention.\nGood for: root vegetables, drainage applications. Poor for: lawns without amendment.",
'Sandy Soil', ['Clay Soil', 'Silt Soil', 'Loam']],

[$turfCat, 'Soil compaction causes turf stress by reducing pore space. Which symptom is NOT typically caused by soil compaction?', 'medium',
"Not typically caused by compaction: Red Thread fungal disease\nCompaction symptoms: slow water infiltration (puddles after rain), shallow roots, hard soil surface, moss invasion, poor fertiliser response, thin/stressed turf.\nRed thread is caused by nitrogen deficiency and leaf wetness — not compaction directly.\nTest for compaction: push a screwdriver into the soil — if it requires force below 10cm, compaction is present.",
'Red Thread fungal disease', ['Slow water infiltration', 'Shallow roots', 'Moss invasion']],

[$turfCat, 'This soil amendment raises pH in acidic soils (below 6.0) and supplies calcium. It is commonly applied to lawns in the acidic Pacific Northwest. What is it?', 'easy',
"Agricultural Lime (Calcium Carbonate — CaCO₃)\nTypes: calcitic lime (calcium carbonate), dolomitic lime (calcium + magnesium carbonate).\nApplication: typically 25–50kg per 100m² in autumn; takes 3–6 months to fully react.\nTest soil pH before and 6 months after application — overliming is as harmful as under-liming.\nBC/Pacific Northwest soils tend to be naturally acidic (pH 5.0–6.0) due to high rainfall leaching.",
'Agricultural Lime', ['Gypsum', 'Sulfur', 'Calcium Nitrate']],

[$turfCat, 'Deep, infrequent watering is preferred over shallow, frequent watering for established lawns. The recommended irrigation depth for most BC cool-season grasses is approximately how deep per week in dry weather?', 'medium',
"25mm (1 inch) per week — applied in 1–2 deep sessions\nDeep watering encourages deep roots (30–45cm); shallow daily watering keeps roots in the top 5cm.\nTest: place empty tuna cans under sprinkler — stop when they contain 25mm of water.\nWater early morning to reduce evaporation and leaf wetness duration (reduces disease).\nDrought stress signs: footprints remain visible (turf doesn't spring back), blue-grey tint, wilting.",
'25mm (1 inch) per week', ['10mm per day', '50mm per week', '5mm every other day']],

[$turfCat, 'This practice involves applying grass seed directly onto existing turf to thicken the lawn, fill thin spots, and introduce improved cultivars. What is it called?', 'easy',
"Overseeding\nBest time in BC: late summer to early fall (Aug–Sep) — soil is warm, air is cool, reduced weed competition, adequate moisture through fall rains.\nPreparation: mow low, scarify or core aerate to improve seed–soil contact, apply starter fertiliser.\nPost-care: keep surface moist until germination (light daily watering for 2–3 weeks), avoid heavy traffic.\nUse recommended cool-season mixes: perennial ryegrass, creeping red fescue, Kentucky bluegrass.",
'Overseeding', ['Sodding', 'Hydroseeding', 'Topdressing']],

[$turfCat, 'This soil characteristic measures its ability to hold and exchange positively-charged nutrient ions (Ca²⁺, Mg²⁺, K⁺, NH₄⁺). Clay and organic soils have a high value; sandy soils have a low value. What is it called?', 'hard',
"Cation Exchange Capacity (CEC)\nHigh CEC (>15 meq/100g): clay soils and organic soils hold nutrients well, less leaching.\nLow CEC (<5 meq/100g): sandy soils release nutrients quickly — require smaller, more frequent fertiliser applications.\nImprove CEC: add compost, peat moss, or biochar — organic matter dramatically increases CEC.\nSoil test results often include CEC — use it to calibrate fertiliser program.",
'Cation Exchange Capacity (CEC)', ['Soil Buffering Capacity', 'Water Holding Capacity', 'Aggregate Stability']],

];  // end $questions array

// ── Insert questions ─────────────────────────────────────────────────────────
$inserted = 0;
$skipped  = 0;
$errors   = [];

$checkStmt = $db->prepare("SELECT id FROM quiz_questions WHERE question_text = ? AND category_id = ? LIMIT 1");
$insertQ   = $db->prepare("INSERT INTO quiz_questions (category_id, question_text, learn_notes, difficulty, is_active) VALUES (?, ?, ?, ?, 1)");
$insertOpt = $db->prepare("INSERT INTO quiz_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");

foreach ($questions as $idx => $q) {
    [$catId, $text, $diff, $notes, $correct, $wrong] = $q;

    $checkStmt->execute([$text, $catId]);
    if ($checkStmt->fetch()) {
        $skipped++;
        continue;
    }

    try {
        $insertQ->execute([$catId, $text, $notes, $diff]);
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
        $errors[] = ['index' => $idx, 'text' => substr($text, 0, 60), 'error' => $e->getMessage()];
    }
}

$summary = [];
foreach ($questions as $q) {
    $catName = $catRows[$q[0]] ?? 'Unknown';
    $summary[$catName] = ($summary[$catName] ?? 0) + 1;
}

echo json_encode([
    'migration'        => 'quiz-seed-all-categories',
    'total_questions'  => count($questions),
    'by_category'      => $summary,
    'inserted'         => $inserted,
    'skipped_existing' => $skipped,
    'errors'           => $errors,
    'done'             => true,
], JSON_PRETTY_PRINT);
