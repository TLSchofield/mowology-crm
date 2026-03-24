<?php
/**
 * BC Pesticide Applicator Certificate — Quiz Seed (Q45–Q104)
 * Seeds 60 exam-prep questions across all 10 BC provincial exam-matter domains.
 * Idempotent — skips questions already present (matched on question_text + category_id).
 * Also maps all questions to the bc-pesticide-applicator cert course.
 * Admin only.
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

// ── Get category ID ───────────────────────────────────────────────────────────
$catRow = $db->query("SELECT id FROM quiz_categories WHERE name = 'BC Pesticide Applicator' LIMIT 1")->fetch();
if (!$catRow) {
    echo json_encode(['error' => 'Category "BC Pesticide Applicator" not found — run run-migration-991.php first']);
    exit;
}
$cat = (int) $catRow['id'];

// ── Get cert course ID ────────────────────────────────────────────────────────
$courseRow = $db->query("SELECT id FROM cert_courses WHERE slug = 'bc-pesticide-applicator' LIMIT 1")->fetch();
if (!$courseRow) {
    echo json_encode(['error' => 'Cert course bc-pesticide-applicator not found — run run-migration-991.php first']);
    exit;
}
$courseId = (int) $courseRow['id'];

// ── Question data ─────────────────────────────────────────────────────────────
// [$category_id, $question_text, $difficulty, $learn_notes, $correct_answer, [$wrong1, $wrong2, $wrong3]]
// Sourced from BC Ministry of Environment pesticide applicator study materials and
// the provincial 10-domain Certificate Exam Matters framework.

$questions = [

// ════════════════════════════════════════════════════════════════════════════
// DOMAIN 1 — GENERAL PESTICIDE CHARACTERISTICS (Q45–Q52)
// ════════════════════════════════════════════════════════════════════════════

[$cat, 'A pesticide is best defined as:', 'easy',
"BC Ministry training defines pesticides by intended pest-management purpose — not just 'killing insects.'\nAny substance used to prevent, destroy, repel, attract, or manage a pest qualifies as a pesticide.",
'Anything intended to prevent, destroy, repel, attract, or manage a pest',
['Only a chemical that kills insects', 'Any fertilizer used on plants', "Only products labelled 'Restricted'"]],

[$cat, 'Which list shows organisms that can be considered "pests" in pest management?', 'easy',
"The Ministry's assistant training explicitly lists broad pest categories: plant pests (weeds), insects, fungi, bacteria, viruses, and rodents.\nKnowing the full scope prevents the mistake of thinking pesticide law only covers insecticides.",
'Weeds, insects, fungi, bacteria, viruses, rodents, or other troublesome organisms',
['Only weeds', 'Only animals', 'Only organisms visible to the naked eye']],

[$cat, 'The part of a pesticide formulation that actually controls the pest is the:', 'easy',
"Active ingredient = the component that produces the desired pest control effect.\nThe guarantee statement on the label lists the active ingredient(s) by common name and concentration.\nAll other parts of the product are considered 'inert' or carrier ingredients.",
'Active ingredient',
['Net contents', 'Company address', 'Container type']],

[$cat, 'Why are active ingredients typically sold as "formulations" rather than in pure form?', 'medium',
"Active ingredients are combined with other ingredients to make the final product more stable, easier to measure, safer to handle, and more effective.\nFormulation types include emulsifiable concentrates (EC), wettable powders (WP), granules (G), and soluble liquids (SL).",
'Active ingredients often need other ingredients for stability, mixing, handling, and effectiveness',
['Active ingredients are always safe to handle alone', 'Formulation is just marketing and has no function', 'Active ingredients cannot be registered in Canada']],

[$cat, '"Trade name" or "product name" refers to:', 'easy',
"Trade name = the brand name assigned by the manufacturer that appears prominently on the label front.\nDistinct from the 'common name' of the active ingredient (found in the guarantee statement) and the chemical name.\nExample: 'Roundup' is the trade name; 'glyphosate' is the common name of its active ingredient.",
'The name assigned by the manufacturer/registrant that appears prominently on the label',
['The chemical structure name shown only in a lab', 'A nickname used by crews on site', "The pesticide's registration number"]],

[$cat, 'The "guarantee statement" on a Canadian pesticide label contains:', 'medium',
"The guarantee statement lists the common name(s) of the active ingredient(s) and the amount (concentration) in the product.\nThis is where you confirm exactly which pesticide you have and how strong it is — critical before mixing.",
'The common name of the active ingredient(s) and how much is in the product',
['Only the disposal instructions', 'Only the company address', 'The net contents of the container']],

[$cat, 'A "systemic" pesticide is one that:', 'medium',
"Systemic = the pesticide enters the organism or plant and moves (translocates) through it to reach all tissues.\nContrast with contact pesticides that only affect the parts directly sprayed.\nSystemic insecticides/herbicides are effective against pests in areas not directly sprayed.",
'Enters the organism and moves through it',
['Sits only on the surface where it is sprayed', 'Must always be a fumigant', 'Is always a granular product']],

[$cat, '"Residual" pesticide activity generally means:', 'medium',
"Residual = the pesticide breaks down slowly and remains active in the environment for a longer period.\nNon-residual pesticides break down quickly after application.\nResidual products require longer re-entry intervals and greater care around water bodies and non-target organisms.",
'It breaks down slowly and stays active longer in the environment',
['It breaks down instantly after application', 'It cannot be used outdoors', 'It is always a domestic-class product']],

// ════════════════════════════════════════════════════════════════════════════
// DOMAIN 2 — LABELLING & REGISTRATION (Q53–Q60)
// ════════════════════════════════════════════════════════════════════════════

[$cat, 'A pesticide label is best described as:', 'easy',
"BC guidance explicitly states: 'labels are legal documents — using a product in a way not on the label is against the law.'\nFollowing the label is not optional — it is a legal requirement under the federal Pest Control Products Act.",
'A legal document that tells how the product must be used',
['Optional guidance that can be overridden by experience', 'Only marketing information', 'Only for farmers or large commercial operations']],

[$cat, 'It is against the law in Canada to:', 'easy',
"BC guidance explicitly prohibits representing that a pesticide can be used for purposes not on the label.\nThis applies to telling a client, a coworker, or anyone else that an off-label use is acceptable.",
'Tell someone they can use a pesticide for something not listed on the label',
['Read the label before purchase', 'Keep the label booklet attached to the container', 'Follow first aid instructions on the label']],

[$cat, 'The "label" for a pesticide product includes:', 'easy',
"BC guidance states: 'the label includes any booklet or information attached to the package.'\nThis means a multi-page booklet stapled to the container is part of the legal label — not just the printed panel on the bottle.",
'Any booklet or attached information that comes with the package',
['Only the printed panel on the bottle', 'Only the Safety Data Sheet (SDS/MSDS)', 'Only the company website']],

[$cat, 'When should you read the pesticide label?', 'easy',
"BC guidance lists three key reading checkpoints:\n1. Before purchase — to confirm it is registered for your intended use\n2. Before mixing/applying — for rates, PPE, restrictions\n3. Before disposal — for container disposal instructions\nLabels change, so re-read even if you have used the product before.",
'Before purchase, before mixing/applying, and before disposal',
['Only the first time you ever use the product', 'Only if an inspector asks to see it', "Only if the product is 'Restricted' class"]],

[$cat, 'BC guidance warns that pesticide labels change over time because:', 'medium',
"BC guidance notes labels are 'constantly changing' as a result of regulatory re-evaluations.\nChanges can affect: allowed uses, application rates, PPE requirements, re-entry intervals, and pre-harvest intervals.\nAlways confirm you have the current label version before using a product.",
'Re-evaluations can change allowed uses, safety info, rates, and precautions',
['Labels never change once a product is registered', 'Only municipalities are allowed to change labels', 'Labels change only when a company updates its branding']],

[$cat, 'On a Canadian pesticide label, "Registration No. … Pest Control Products Act" indicates:', 'easy',
"The registration number confirms the product is registered under the federal Pest Control Products Act and can legally be used in Canada.\nProducts without a Canadian registration number are not legal to use in Canada regardless of where they were purchased.",
'The product is registered for use in Canada',
['The product is registered only in the United States', 'The product is not regulated by government', 'The product is a fertilizer, not a pesticide']],

[$cat, 'Products with only a US EPA registration number are:', 'easy',
"BC guidance explicitly states: 'American products with EPA numbers are not allowed to be used in Canada.'\nUsing a US-registered product in Canada is illegal under the federal Pest Control Products Act.\nAlways look for the Canadian Pest Control Products Act registration number.",
'Not allowed to be used in Canada',
['Allowed in Canada if diluted below label rate', 'Allowed if used outdoors only', 'Allowed if the client provides written consent']],

[$cat, 'The four federal label classification levels in Canada are:', 'medium',
"The four classifications determine who can legally purchase and use the product:\n• Domestic — for general public use\n• Commercial — licensed/trained applicators or employees under supervision\n• Restricted — licensed applicators only (cannot be purchased by unlicensed individuals)\n• Manufacturing — for production of other pesticide products",
'Domestic, Commercial, Restricted, Manufacturing',
['Household, Industrial, Medical, Mining', 'Residential, Urban, Wilderness, Coastal', 'Food-grade, Non-food, Export, Import']],

// ════════════════════════════════════════════════════════════════════════════
// DOMAIN 3 — HUMAN HEALTH (Q61–Q68)
// ════════════════════════════════════════════════════════════════════════════

[$cat, 'Acute pesticide poisoning generally refers to effects that:', 'easy',
"Acute = health effects that occur shortly after a single or brief exposure to a high dose.\nSymptoms can appear within minutes to hours.\nContrast with chronic effects from repeated lower-dose exposures over time.",
'Occur shortly after a single or brief exposure',
['Occur only after many years of use', 'Are always mild and temporary', 'Only affect the skin surface']],

[$cat, 'Chronic pesticide poisoning generally refers to effects that:', 'medium',
"Chronic = effects that develop gradually from repeated exposures over a long period — often at lower doses.\nSymptoms may not be obvious until significant damage has occurred.\nThis is why consistent PPE use matters even when no immediate symptoms are felt.",
'Develop over time after many repeated exposures',
['Occur only within minutes of a large spill', 'Are impossible to prevent with PPE', 'Are only respiratory in nature']],

[$cat, 'The four major routes of pesticide exposure taught in BC Ministry training are:', 'easy',
"The four routes are: skin (dermal), breathing (inhalation), eyes (ocular), and swallowing (oral/ingestion).\nSkin is the most common in field application work.\nAll four must be considered when selecting PPE for a given application.",
'Skin (dermal), breathing (inhalation), eyes, and swallowing (ingestion)',
['Skin, ears, hair, and breathing', 'Nails, hair, skin, and eyes', 'Only swallowing and breathing']],

[$cat, 'According to WorkSafeBC, workers most commonly absorb pesticides through:', 'easy',
"WorkSafeBC emphasizes skin contact (dermal absorption) as the most common route of pesticide exposure for application workers.\nLarge skin surface areas, especially hands and forearms, are most at risk.\nThis is why chemical-resistant gloves and protective clothing are the most critical PPE for most spray operations.",
'Their skin (dermal contact)',
['Their hair follicles', 'The soles of their feet only', 'Their ear canals']],

[$cat, 'When pesticides are absorbed through the skin, WorkSafeBC notes that symptoms can take:', 'medium',
"WorkSafeBC warns that skin absorption can cause delayed symptoms — up to approximately 24 hours after exposure.\nThis delayed onset is dangerous because workers may not realize they have been poisoned until much later.\nNever assume you are fine just because you feel fine immediately after a spill on skin.",
'Up to about 24 hours to develop',
['Zero minutes — symptoms are always immediate', 'Exactly 5 minutes in all cases', 'Two to three weeks minimum']],

[$cat, 'BC Ministry training identifies which body area as absorbing pesticides most easily?', 'medium',
"The Ministry training explicitly identifies the eyes as the body area that absorbs pesticides most easily.\nThis reinforces the importance of chemical splash goggles during mixing and application.\nIf pesticide contacts the eye, flush with clean water for at least 15 minutes and seek medical attention.",
'Eyes',
['Forearms', 'Soles of the feet', 'Scalp/hair']],

[$cat, 'If you suspect pesticide poisoning and the person is unconscious, having convulsions, or having trouble breathing, you should first:', 'easy',
"BC guidance is clear: for severe symptoms (unconscious, convulsing, trouble breathing), call 9-1-1 immediately.\nDo not try to drive the person to a clinic yourself — the condition can deteriorate rapidly.\nKeep the product label or SDS ready to show emergency responders.",
'Call 9-1-1',
['Drive them to a clinic without calling anyone', 'Give them food or water immediately', 'Wait and see if symptoms improve on their own']],

[$cat, 'If a person is awake and you suspect pesticide poisoning, BC guidance says to call:', 'easy',
"BC Poison Control Centre: 1-800-567-8911 (available 24/7)\nHave the product name, active ingredient, and amount of exposure ready when you call.\nThe Poison Control Centre will give specific first-aid advice based on the actual product involved — do not guess at treatment.",
'BC Poison Control Centre (1-800-567-8911)',
['City hall or municipal bylaw office', 'The pesticide retailer where you bought the product', 'A landscaping industry forum or online group']],

// ════════════════════════════════════════════════════════════════════════════
// DOMAIN 4 — PESTICIDE SAFETY & PPE (Q69–Q76)
// ════════════════════════════════════════════════════════════════════════════

[$cat, 'WorkSafeBC recommends controlling pesticide exposure using a hierarchy that starts with:', 'medium',
"WorkSafeBC hierarchy of controls (most to least effective):\n1. Elimination — remove the hazard entirely\n2. Substitution — replace with a less hazardous product\n3. Engineering controls — modify equipment/environment\n4. Administrative controls — procedures, training, scheduling\n5. PPE — last resort, least effective\nAlways start at the top of the hierarchy.",
'Elimination or substitution of the hazard',
['PPE — choose the best-quality gloves and mask first', 'Wearing two pairs of gloves', 'Ignoring the hazard if exposure is outdoors']],

[$cat, 'According to WorkSafeBC, PPE is best described as:', 'medium',
"PPE is the least preferred control because it depends entirely on correct use every single time and provides no protection if improperly worn or damaged.\nWorkSafeBC states PPE must be used in addition to at least one other control measure.\nPPE failure = direct exposure — there is no backup.",
'The least preferred control — must be used alongside at least one other control',
['The only control needed if it is high quality', 'More effective than eliminating the hazard', 'A complete substitute for training and engineering controls']],

[$cat, 'You must use the protective equipment specified:', 'easy',
"BC Ministry training is explicit: it is the applicator's legal responsibility to use the PPE specified on the pesticide label.\nThe label PPE requirements are the legal minimum for that product — you may use more protection but not less.\nIgnoring label PPE requirements is an offense under BC's Integrated Pest Management Act.",
'On the pesticide label for the product you are applying',
['On the invoice from your supplier', 'On a social media post by another applicator', 'Only when a supervisor is watching']],

[$cat, 'Common label "restrictions" that an applicator must know and follow include:', 'medium',
"Label restrictions are legally binding conditions of use.\nCommon examples: re-entry interval (REI) — how long before workers/public can re-enter; grazing restriction — how long before livestock can graze; pre-harvest interval (PHI) — days before harvest.\nViolating a restriction is an offense and can cause serious harm.",
'Re-entry intervals, grazing restrictions, and days before harvest (pre-harvest intervals)',
['Restaurant hours and municipal business schedules', 'Vehicle licensing and insurance requirements', 'Phone script templates for client communication']],

[$cat, 'WorkSafeBC notes pesticides are often most dangerous:', 'medium',
"Stored pesticides in their concentrated form are far more toxic per unit volume than diluted spray.\nConcentration also affects vapour pressure — concentrated products in storage can create hazardous indoor air.\nNever store pesticides in enclosed unventilated spaces, and always use full label PPE when handling concentrates.",
'In storage because they are highly concentrated',
['When diluted and applied to target areas as a spray', 'After all residues have broken down in the environment', 'Only in winter due to freezing temperatures']],

[$cat, 'WorkSafeBC rule for entering a space where fumigants might be present:', 'hard',
"WorkSafeBC: never enter a container, building, or structure where fumigants might be present unless a formal hazard assessment has been conducted.\nIf fumigants are found or suspected after entry: leave immediately, do not re-enter, notify your supervisor.\nFumigant poisoning can be rapid and fatal — there may be no smell warning for some fumigants.",
'Never enter unless a hazard assessment has been done; leave and notify supervisor if fumigants are found',
['Enter briefly to check, then exit quickly', 'Mask odour with an air freshener before entering', 'Enter if you can hold your breath long enough']],

[$cat, 'If pesticide gets on a worker\'s skin, immediate first aid includes:', 'easy',
"BC poisoning guidance emphasizes: remove contaminated clothing immediately, then wash contaminated skin and hair thoroughly with soap and water.\nLeaving contaminated clothing on continues exposure.\nSave the contaminated clothing for later washing — do not put it back on.",
'Remove contaminated clothing and wash exposed skin thoroughly with soap and water',
['Leave contaminated clothing on to stay warm', 'Apply petroleum jelly or moisturizer to the skin', 'Cover with dry absorbent material to dry up the pesticide']],

[$cat, 'Pesticide hazard level on a Canadian label is shown by a combination of:', 'medium',
"Canadian label hazard communication uses three elements together:\n• Hazard symbol (skull, flame, etc.)\n• Hazard statement shape (the more sides, the greater the hazard — octagon > diamond > triangle)\n• Signal word: DANGER (highest), WARNING (moderate), CAUTION (lowest)\nAll three work together to communicate the risk level quickly.",
'Precautionary symbols, hazard shape, and signal word (DANGER / WARNING / CAUTION)',
['Only the colour of the bottle', 'Only the company logo size', 'Only the net contents weight on the label']],

// ════════════════════════════════════════════════════════════════════════════
// DOMAIN 5 — ENVIRONMENT & DRIFT (Q77–Q84)
// ════════════════════════════════════════════════════════════════════════════

[$cat, '"Spray drift" is best described as:', 'easy',
"Spray drift = liquid droplets that become airborne during or after application and are carried away from the intended treatment area by air movement.\nSmaller droplets drift farther and are harder to control.\nDrift can contaminate water, non-target plants, neighbouring properties, and people.",
'Pesticide droplets that become airborne and move away from the treatment site',
['Pesticide vapour moving because of high temperature', 'Pesticide movement through soil driven by water', 'Soil erosion that carries pesticide residues']],

[$cat, 'A practical way to reduce spray drift during application is to:', 'easy',
"Larger droplets fall more quickly and resist wind movement, reducing drift.\nLower pressure also reduces fine droplet production.\nAdditionally: apply when wind is 3–16 km/h (not dead calm and not gusty), use a wind shield where possible, and keep the nozzle closer to the target.",
'Use nozzles that produce larger droplets and apply at lower pressure',
['Use the smallest nozzle tip available for maximum coverage', 'Spray in dead calm air during a temperature inversion', 'Spray when rain is forecast to help wash product in']],

[$cat, '"Vapour drift" risk is greatest when:', 'medium',
"Vapour drift (volatilization) occurs when a pesticide evaporates into gas form and moves with air currents.\nHigh temperature accelerates volatilization.\nSome products (e.g., certain ester-formulation herbicides) are especially prone to vapour drift in heat.\nAvoid applying volatile products during the hottest part of the day.",
'Temperatures are high, causing pesticide to volatilize into vapour that moves with wind',
['It is cool and raining, which increases evaporation', 'Droplets are large and pressure is low', 'Soil is fully covered with target plants']],

[$cat, 'BC Ministry training warns: "Don\'t apply pesticides when air is completely dead calm" because:', 'medium',
"Dead calm conditions often indicate a temperature inversion — a layer of warm air trapping cool air near the ground.\nDroplets and vapour cannot rise or disperse and instead move horizontally as a concentrated cloud.\nThis greatly increases the risk of off-target drift to neighbouring areas, sensitive ecosystems, and people.",
'A temperature inversion may be trapping air near the ground, causing concentrated horizontal drift',
['Dead calm is actually perfect safe spray conditions', 'It indicates better pesticide absorption by target plants', 'Buffer zones are not required in dead calm conditions']],

[$cat, 'Pesticide runoff risk is greatest on:', 'easy',
"Sloped land concentrates water flow, increasing the speed and volume of runoff that can carry dissolved or suspended pesticide into watercourses.\nFlat land allows more infiltration time.\nApply buffers at the downslope edge of applications near water bodies.",
'Sloped areas where water can flow quickly downhill toward water bodies',
['Completely flat, level ground only', 'Indoor treated surfaces', 'Frozen lake surfaces in winter']],

[$cat, 'The most effective way to protect a nearby water body from pesticide contamination is to establish:', 'easy',
"A buffer zone (no-treatment zone) is a strip of untreated land between the application area and the water body.\nThe buffer slows runoff, filters pesticide, and prevents direct spray from reaching water.\nBuffer width requirements are often stated on the product label or in BC's IPM Regulation.",
'A buffer (no-treatment) zone between the application area and the water body',
['A food storage area near the water body', 'A ventilation duct to direct spray away from water', 'A secondary containment spray-down area beside the water']],

[$cat, 'BC\'s Integrated Pest Management Regulation defines a "no-treatment zone" as:', 'easy',
"Definition directly from BC's IPM Regulation:\n'An area of land that must not be treated with pesticide.'\nNo-treatment zones are legally mandated buffers — the applicator must know where they are before starting.\nCommon no-treatment zones include areas near water bodies, schools, and parks.",
'An area of land that must not be treated with pesticide',
['An area where only domestic-class pesticides may be used', 'An area where pesticide application rates must be doubled for effectiveness', 'Any indoor area in a residential building']],

[$cat, 'Under the BC IPM Regulation outdoor use rules, the no-treatment zone near a body of water must be:', 'medium',
"The IPM Regulation requires the no-treatment zone to be sufficient to prevent the release of pesticide spray or runoff into the body of water.\nThe actual width depends on the slope, formulation, application method, and weather conditions.\nThe label may specify a minimum buffer distance — always follow whichever is more restrictive: label or Regulation.",
'Sufficient to prevent release of pesticide spray or runoff into the body of water',
['Any width the applicator prefers based on experience', 'Zero if the client specifically requests treatment to the water edge', 'Required only for insecticides — not for herbicides or fungicides']],

// ════════════════════════════════════════════════════════════════════════════
// DOMAIN 6 — EMERGENCY RESPONSE (Q85–Q90)
// ════════════════════════════════════════════════════════════════════════════

[$cat, 'According to BC Ministry training, the most common pesticide emergency in application work is:', 'easy',
"The Ministry's emergency response lesson identifies spills as the most common emergency applicators will encounter.\nBeing prepared with spill kit supplies (absorbent material, PPE, disposal bags) and knowing the response steps before a spill occurs is essential.",
'A spill',
['Losing vehicle keys on a job site', 'A flat tire while transporting products', 'Missing or incomplete paperwork before an inspection']],

[$cat, 'The first step when responding to a small pesticide spill is:', 'easy',
"BC Ministry emergency response sequence for small spills:\n1. Protect yourself — put on appropriate PPE and ensure ventilation\n2. Stop/control the source — upright containers, close valves\n3. Contain the spill — use absorbent material; prevent it reaching drains or water\n4. Clean up — collect and bag all contaminated material\n5. Clean yourself — decontaminate exposed skin/clothing\n6. Report to supervisor\nNever skip step 1 — contaminating yourself while responding makes the emergency worse.",
'Protect yourself with appropriate PPE and control/stop the spill source',
['Immediately hose everything down to dilute the pesticide', "Ignore it if it doesn't look like much", 'Leave the area and proceed to the next job immediately']],

[$cat, 'Under BC\'s Spill Reporting Regulation, a pesticide spill becomes reportable if:', 'hard',
"A spill is reportable under the BC Spill Reporting Regulation (SRR) if:\n• The pesticide enters or is likely to enter a body of water, OR\n• The quantity spilled equals or exceeds the listed 'reportable quantity' for that substance\nWhen in doubt, report — it is always better to report a spill that did not require it than to fail to report one that did.",
'It enters (or is likely to enter) a body of water, or the spilled quantity meets the listed reportable threshold',
['Any spill where a noticeable smell is detected nearby', 'Only if it occurs on a provincial highway or public road', 'Only if the business is licensed under the BC IPM Act']],

[$cat, 'Under BC\'s Spill Reporting Regulation, the initial report must be made by calling:', 'medium',
"The BC Spill Reporting Regulation requires immediate notification by calling the Provincial Emergency Program at 1-800-663-3456.\nThis number is available 24/7.\nHave ready: your name, contact info, location, product name, quantity spilled, and any actions already taken.",
'1-800-663-3456 (BC Provincial Emergency Program / EMBC)',
['A municipal gardening or bylaw hotline', 'The pesticide manufacturer customer service line only', 'Your personal insurance broker']],

[$cat, 'The BC Spill Reporting fact sheet identifies who must ensure the initial spill report has been made as the:', 'medium',
"The 'responsible person' under the SRR is the person who was in possession, charge, or control of the spilled substance at the time of the spill.\nEven if another person physically makes the call, the responsible person is accountable for ensuring the report has been made completely and accurately.",
'Responsible person — the person in possession, charge, or control of the substance at the time',
['The first bystander who notices the spill', 'The retail store that sold the pesticide', 'The local newspaper or media outlet']],

[$cat, 'BC agricultural emergency guidance states you should NOT do which of the following when responding to a pesticide spill?', 'medium',
"BC guidance explicitly states: do NOT apply water to a pesticide spill because it can spread the contamination further.\nInstead: use dry absorbent material (kitty litter, sand, vermiculite) to soak up the spill, then carefully collect and bag the material.\nAlso: do not apply heat or flame near flammable formulations.",
'Apply water to the spilled pesticide (it spreads the contamination)',
['Put on PPE before approaching the spill', 'Use dry absorbent material to contain the spill', 'Call 1-800-663-3456 if the quantity requires reporting']],

// ════════════════════════════════════════════════════════════════════════════
// DOMAIN 7 — APPLICATION TECHNOLOGY (Q91–Q96)
// ════════════════════════════════════════════════════════════════════════════

[$cat, 'If you notice an uneven spray pattern, abnormal pressure, or leaks during application, you should:', 'easy',
"BC Ministry application technology training is clear: stop work immediately if you observe equipment problems.\nContinuing with faulty equipment can result in under/over-application, equipment failure, contamination, or personal exposure.\nNotify your supervisor — calibration and repair is the supervisor's responsibility.",
'Stop work immediately and notify your supervisor',
['Keep spraying to finish the job quickly, then report it later', 'Increase the spray pressure until the pattern looks even', 'Blow out the nozzle with your mouth to clear any blockage']],

[$cat, 'The correct way to clean a plugged nozzle tip is:', 'easy',
"BC Ministry training explicitly warns: NEVER blow out a nozzle with your mouth — this directly ingests pesticide residue.\nCorrect method: use a soft brush (not metal objects that can alter nozzle orifice size) and rinse with clean water.\nA metal probe or pin will permanently damage the calibrated orifice, changing the application rate.",
'Use a soft brush and clean water to clear the blockage',
['Blow it out with your mouth — the amount is too small to be harmful', 'Use a sharp metal pin or wire to scrape it clean', 'Hit the nozzle against a hard surface to clear it']],

[$cat, 'The purpose of equipment calibration in pesticide application is to:', 'medium',
"Calibration = checking and adjusting the delivery rate of the equipment so the correct amount of pesticide is applied per unit area or volume — as specified on the label.\nUnder-application may not control the pest; over-application wastes product, increases cost, and increases environmental risk.\nCalibration is the supervisor's responsibility; assistants must report problems.",
'Check and adjust the equipment delivery rate so the correct labelled amount is applied',
['Make the label instructions optional once output is verified', 'Make equipment look newer and better maintained', 'Intentionally increase drift to cover more area per tank']],

[$cat, 'According to BC Ministry training, calibration of application equipment is the responsibility of:', 'medium',
"Ministry training is explicit: determining the recommended output and calibrating the equipment to achieve it is the supervisor's responsibility.\nThe assistant's role is to use the equipment as set up, watch for problems, and report them — not to recalibrate independently.\nAssistants should never adjust equipment settings without supervisor direction.",
'The supervisor',
['The assistant applicator working on site', 'The equipment manufacturer only', 'The client or property owner']],

[$cat, 'What is the minimum frequency for cleaning pesticide application equipment?', 'easy',
"BC Ministry training keys 'every day it is used' as the minimum cleaning frequency for application equipment.\nDaily cleaning prevents: cross-contamination between products, corrosion of seals and fittings, nozzle blockages, and residue buildup.\nEquipment left dirty overnight causes accelerated wear and potential contamination on next use.",
'Every day it is used',
['Once per month during the application season', 'Once at the beginning and once at the end of each season', 'Only when it looks visibly dirty or clogged']],

[$cat, 'BC Ministry training identifies the correct location to clean application equipment after use as:', 'medium',
"Ministry training keys 'in the treatment area' as the correct location for equipment cleaning.\nCleaning in the treatment area means any rinse water (which contains pesticide residue) is deposited where the pesticide was already applied — not onto untreated areas, drains, or water bodies.\nThis is an important environmental protection practice.",
'In the treatment area, so rinse water stays where pesticide was already applied',
['Over any available storm drain for convenience', 'Next to food or feed storage areas', 'At any roadside curb near the job site']],

// ════════════════════════════════════════════════════════════════════════════
// DOMAIN 8 — PROFESSIONALISM & EXAM LOGISTICS (Q97–Q104)
// ════════════════════════════════════════════════════════════════════════════

[$cat, 'BC pesticide applicator/dispenser certificate exams are:', 'easy',
"BC exam instructions explicitly state the exam is open book — you may bring and use your study materials.\nHowever, open book does NOT mean easy — you must know the material well enough to find answers quickly.\nThe invigilator cannot answer any questions or provide hints during the exam.",
'Open book — you bring and use your study materials during the exam',
['Closed book — no materials allowed in the exam room', 'Oral examination only — no written component', 'Practical field test only — no written questions']],

[$cat, 'For a BC invigilated pesticide exam, which items may you bring?', 'medium',
"BC exam instructions specify:\n✓ Allowed: study materials (open book), non-programmable calculator\n✗ NOT allowed: cell phone, smartphone, any other electronic device\nYou will also be asked to show government-issued photo ID at check-in.\nArrive early — late arrivals may not be admitted.",
'Study materials and a non-programmable calculator (no cell phones or other electronics)',
['Any electronic device including a smartphone for web searching', 'A programmable calculator and a laptop computer', 'A cell phone (for calls only, not web search)']],

[$cat, 'Which statement about BC pesticide certification categories is most accurate?', 'easy',
"BC issues separate study materials and separate exams for each certification category where you apply or dispense pesticides.\nFor example: landscape category, structural category, and forestry category each have their own exam and study guide.\nYou must write the exam for each category in which you want to work.",
'Each certification category has its own separate study materials and exam',
['There is one shared exam for all pesticide categories in BC', 'No study materials are provided since the exam is open book', 'Only on-the-job experience testing — no written exam required']],

[$cat, 'Which statement is most accurate about training courses and BC pesticide certification?', 'medium',
"BC explicitly states: online training covering the core manual does not by itself certify applicators or issue dispenser certificates.\nTo receive a certificate, you must pass the invigilated exam at a test centre.\nTraining courses prepare you for the exam — but the exam is what issues the credential.",
'You must pass an invigilated exam to receive a certificate; training courses prepare you but do not certify you',
['Completing an approved online course automatically certifies you in BC', 'The exam is optional if you complete a recognized training program', 'Training courses are not available or permitted in BC']],

[$cat, 'When working in a municipality in BC, a professional best practice regarding pesticide use is to:', 'easy',
"BC guidance explicitly advises: know and follow local pesticide bylaws for your work area.\nMany BC municipalities have bylaws that restrict or prohibit pesticide use on ornamental plants and lawns — stricter than provincial rules.\nCheck local bylaws as part of job planning, not after complaints arise.",
'Check local municipal bylaws as part of job planning before applying any pesticide',
['Ignore local bylaws because provincial law always overrides municipal regulations', 'Ask the client to determine what is legally allowed in their municipality', 'Apply the product first and check the bylaw only if a complaint is received']],

[$cat, 'BC pesticide use guidance does not endorse homemade pesticide preparations because:', 'medium',
"BC guidance states homemade preparations may not have been scientifically tested and can still pose health and environmental risks.\nHomemade pesticides are also not registered under the federal Pest Control Products Act and cannot legally be used as pesticides.\nThe guidance recommends considering tested, registered, non-chemical or reduced-risk alternatives first.",
'They may not be scientifically tested, can pose risk, and are not registered for legal pesticide use',
['Homemade mixes are freely available and therefore not worth paying for', 'They are automatically safer than commercial products because they are natural', 'They are recommended in BC as a cost-saving alternative to commercial products']],

[$cat, 'On exam day, what must you bring to a BC pesticide certificate exam (select all that apply)?', 'medium',
"BC exam instructions explicitly list three requirements:\n1. Government-issued photo ID (to verify identity at check-in)\n2. Study materials (it is open book — bring your manual and booklets)\n3. Non-programmable calculator (if math is required for your category)\nWithout photo ID you may be turned away. Without study materials you cannot use the open-book privilege.",
'Government-issued photo ID, study materials, and a non-programmable calculator',
['Social insurance card, a pencil, and earplugs', 'Any two pieces of ID, a laptop, and reference binder', 'Your employee ID badge, a phone, and a printed practice exam']],

[$cat, 'Why does the "open book" format of the BC pesticide exam still require serious preparation?', 'easy',
"Open book means you can use materials — but you still need to:\n• Understand the concepts well enough to apply them quickly\n• Know your study materials well enough to find specific answers under time pressure\n• Complete the exam within the allotted time without help from the invigilator\nStudents who have not studied often spend too long flipping through materials and run out of time.",
'You must know concepts and where to find answers quickly; the invigilator cannot help you during the exam',
['Because calculators are not allowed in open-book exams', 'Because the study materials are only permitted for the first 10 minutes', 'Because the exam is timed and open book means you get unlimited extra time']],

]; // end $questions

// ── Insert questions ──────────────────────────────────────────────────────────
$insertQ = $db->prepare("
    INSERT INTO quiz_questions (category_id, question, difficulty, learn_notes, is_active, created_at)
    VALUES (?, ?, ?, ?, 1, NOW())
");
$insertOpt = $db->prepare("
    INSERT INTO quiz_question_options (question_id, option_text, is_correct, sort_order)
    VALUES (?, ?, ?, ?)
");
$checkQ = $db->prepare("
    SELECT id FROM quiz_questions WHERE category_id = ? AND question = ? LIMIT 1
");
$insertMap = $db->prepare("
    INSERT IGNORE INTO cert_course_questions (course_id, question_id, is_required, weight)
    VALUES (?, ?, 0, 1)
");

$added   = 0;
$skipped = 0;
$errors  = [];
$newIds  = [];

foreach ($questions as $idx => $q) {
    [$catId, $questionText, $difficulty, $learnNotes, $correct, $wrongs] = $q;

    // Idempotency check
    $checkQ->execute([$catId, $questionText]);
    if ($existing = $checkQ->fetch()) {
        $skipped++;
        $newIds[] = (int) $existing['id'];
        continue;
    }

    try {
        $insertQ->execute([$catId, $questionText, $difficulty, $learnNotes]);
        $qId = (int) $db->lastInsertId();
        $newIds[] = $qId;

        // Insert correct answer first (sort_order=0)
        $insertOpt->execute([$qId, $correct, 1, 0]);

        // Insert wrong answers
        foreach ($wrongs as $i => $wrong) {
            $insertOpt->execute([$qId, $wrong, 0, $i + 1]);
        }

        $added++;
    } catch (Throwable $e) {
        $errors[] = "Q" . ($idx + 45) . ": " . $e->getMessage();
    }
}

// ── Map all questions to the cert course ─────────────────────────────────────
$mapped = 0;
foreach ($newIds as $qId) {
    try {
        $insertMap->execute([$courseId, $qId]);
        $mapped++;
    } catch (Throwable $e) {
        // Duplicate — already mapped, ignore
    }
}

echo json_encode([
    'ok'      => empty($errors),
    'added'   => $added,
    'skipped' => $skipped,
    'mapped_to_course' => $mapped,
    'course_id' => $courseId,
    'errors'  => $errors,
    'message' => $added > 0
        ? "✓ Seeded {$added} questions into BC Pesticide Applicator category and mapped {$mapped} to the cert course."
        : "All questions already exist — nothing new added.",
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
