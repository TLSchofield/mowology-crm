<?php
declare(strict_types=1);
/**
 * Hydrangea Masterclass — course content.
 *
 * Pure data, no database access. Read by HydrangeaMasterclassSeeder and checked
 * by tests/Unit/Quiz/HydrangeaMasterclassDataTest.php.
 *
 * Region: Lower Mainland BC (zones 7-8, wet mild winters, occasional spring frost).
 *
 * Question row: [question_text, difficulty, learning_level (1-3), learn_notes,
 *                correct_answer, [wrong1, wrong2, wrong3]]
 * Exactly one correct answer and three wrong answers per question.
 */
return [
    'course' => [
        'slug'        => 'hydrangea-masterclass',
        'name'        => 'Hydrangea Masterclass',
        'description' => 'Know every hydrangea type, when and how to prune each, how colour and soil pH work, '
                       . 'what goes wrong and what to tell clients. Six modules, then a final exam.',
        'tier_slug'   => 'basic',
        'icon'        => 'flower',
        'colour'      => '#7B5EA7',
        'pass_score_pct' => 80,
        'min_questions'  => 20,
        'max_questions'  => 40,
        'attempts_allowed' => 3,
        'cooldown_hours'   => 24,
        'recert_months'    => 24,
        'is_required'      => 0,
        'sort_order'       => 40,
    ],

    'modules' => [

        // ── Module 1 ─────────────────────────────────────────────────────────
        [
            'name'        => 'Hydrangea 1 — Know Your Hydrangeas',
            'description' => 'The six hydrangea types crews meet in Lower Mainland gardens, and how to tell them apart.',
            'icon'        => 'flower',
            'colour'      => '#7B5EA7',
            'sort_order'  => 41,
            'months'      => '1,2,3,4,5,6,7,8,9,10,11,12',
            'tags'        => 'hydrangea,identification',
            'questions'   => [

                ['Which hydrangea has flower heads shaped like cones (panicles) and is the type of the popular cultivar Limelight?', 'easy', 1,
"Module 1 · Identification\nPanicle hydrangea (Hydrangea paniculata) carries cone-shaped flower clusters, usually in white that ages to pink or green-bronze. Limelight, Vanilla Strawberry and Quick Fire are all panicle types.\nIt is a large shrub, so it is often sold as a small tree form too.",
'Panicle hydrangea (Hydrangea paniculata)',
['Bigleaf hydrangea (Hydrangea macrophylla)', 'Climbing hydrangea (Hydrangea petiolaris)', 'Mountain hydrangea (Hydrangea serrata)']],

                ['What does a lacecap hydrangea flower head look like?', 'easy', 1,
"Module 1 · Identification\nA lacecap is flat. Small fertile flowers form a button-like centre and a ring of larger showy sterile florets sits around the edge.\nA mophead is a full ball of showy florets. Both forms occur in bigleaf (macrophylla) and mountain (serrata) hydrangeas.",
'A flat head with small flowers in the middle ringed by larger showy florets',
['A tall cone made of many small florets', 'A full round ball made only of large florets', 'A single flower on a long bare stem']],

                ['Which hydrangea is easy to recognise by deeply lobed leaves shaped like oak leaves?', 'easy', 1,
"Module 1 · Identification\nOakleaf hydrangea (Hydrangea quercifolia) has large lobed leaves that turn burgundy and red in fall, and peeling cinnamon-coloured bark that gives winter interest.\nIt flowers in long cones that age from white to pink.",
'Oakleaf hydrangea (Hydrangea quercifolia)',
['Smooth hydrangea (Hydrangea arborescens)', 'Panicle hydrangea (Hydrangea paniculata)', 'Bigleaf hydrangea (Hydrangea macrophylla)']],

                ['Which hydrangea is the typical large, glossy-leaved "florist" hydrangea with big mophead or lacecap flowers that can be blue or pink?', 'easy', 1,
"Module 1 · Identification\nBigleaf hydrangea (Hydrangea macrophylla) has thick, glossy, coarsely toothed leaves. It is the type whose flower colour can shift between blue and pink depending on soil, and it is the type that most often fails to bloom after wrong pruning or a hard spring frost.\nThis is the type crews will meet most often.",
'Bigleaf hydrangea (Hydrangea macrophylla)',
['Oakleaf hydrangea (Hydrangea quercifolia)', 'Climbing hydrangea (Hydrangea petiolaris)', 'Panicle hydrangea (Hydrangea paniculata)']],

                ['Annabelle, with its large round white flower heads, is a cultivar of which hydrangea?', 'medium', 2,
"Module 1 · Identification\nAnnabelle is a smooth hydrangea (Hydrangea arborescens). It has soft, bright green, unlobed leaves and large round white heads.\nSmooth hydrangeas flower on new wood, so they are forgiving to prune, but heavy heads can flop after rain.",
'Smooth hydrangea (Hydrangea arborescens)',
['Oakleaf hydrangea (Hydrangea quercifolia)', 'Bigleaf hydrangea (Hydrangea macrophylla)', 'Climbing hydrangea (Hydrangea petiolaris)']],

                ['Which hydrangea climbs walls and tree trunks by clinging with aerial rootlets?', 'medium', 2,
"Module 1 · Identification\nClimbing hydrangea (Hydrangea petiolaris) clings with aerial rootlets and needs no ties once established. It is slow for the first few years, tolerates shade, and has flat white lacecap-style flowers in early summer.\nIt can become very heavy, so check the structure it is climbing.",
'Climbing hydrangea (Hydrangea petiolaris)',
['Panicle hydrangea (Hydrangea paniculata)', 'Smooth hydrangea (Hydrangea arborescens)', 'Mountain hydrangea (Hydrangea serrata)']],

                ['Which hydrangea type is generally the most sun-tolerant and cold-hardy of the common garden hydrangeas?', 'medium', 2,
"Module 1 · Identification\nPanicle hydrangeas (Hydrangea paniculata) handle more sun than other hydrangeas and are very cold hardy, so late frost rarely costs them their flowers.\nBigleaf hydrangeas are the least reliable in cold exposed sites because their flower buds are formed the year before.",
'Panicle hydrangea (Hydrangea paniculata)',
['Bigleaf hydrangea (Hydrangea macrophylla)', 'Climbing hydrangea (Hydrangea petiolaris)', 'Oakleaf hydrangea (Hydrangea quercifolia)']],

                ['Compared with bigleaf hydrangea, mountain hydrangea (Hydrangea serrata) is best described as:', 'hard', 3,
"Module 1 · Identification\nMountain hydrangeas have smaller, finer leaves and a more compact, delicate habit. They are usually lacecaps and often show good reddish fall colour. They are more sensitive to sun and wind than bigleaf types, so give them shelter and some shade.\nLike bigleaf hydrangeas they bloom on old wood, so the same pruning cautions apply.",
'Smaller, finer leaves and a more compact habit, usually lacecap, and more sensitive to sun and wind',
['Larger leaves and a taller habit, always a mophead', 'A climber that attaches itself with aerial rootlets', 'The same plant with a different common name, with no real differences']],

            ],
        ],

        // ── Module 2 ─────────────────────────────────────────────────────────
        [
            'name'        => 'Hydrangea 2 — Siting, Planting & Soil',
            'description' => 'Where hydrangeas thrive, how to plant them, and how to keep their roots happy.',
            'icon'        => 'sun',
            'colour'      => '#7B5EA7',
            'sort_order'  => 42,
            'months'      => '3,4,5,9,10',
            'tags'        => 'hydrangea,planting,soil',
            'questions'   => [

                ['Where is the best spot for a bigleaf hydrangea in a hot, sunny garden?', 'easy', 1,
"Module 2 · Siting\nBigleaf hydrangeas do best with morning sun and shade from the hot afternoon sun. Strong afternoon sun scorches the leaves and makes the plant wilt.\nPanicle hydrangeas are the exception: they handle full sun well if the soil stays moist.",
'Morning sun with shade in the hot afternoon',
['Full sun all day against a south-facing wall', 'Deep shade under a dense evergreen', 'A dry spot at the base of a large tree']],

                ['How deep should a hydrangea be planted?', 'easy', 1,
"Module 2 · Planting\nPlant so the top of the root ball sits level with, or very slightly above, the surrounding soil. Planting too deep buries the crown, keeps it wet and invites rot.\nLoosen circling roots on the root ball before planting.",
'With the top of the root ball level with the surrounding soil',
['About 15 cm below the soil surface so the stems are anchored', 'Well above the soil on a mound of bark mulch', 'Deep enough that the lowest leaves are buried']],

                ['What soil suits most hydrangeas best?', 'easy', 1,
"Module 2 · Soil\nHydrangeas want soil that holds moisture but drains well, rich in organic matter. Compost worked into the planting area helps both heavy clay and sandy soil.\nSoil that stays waterlogged in winter rots the roots.",
'Moist, well-drained soil rich in organic matter',
['Dry sandy soil with no added organic matter', 'Heavy clay that stays soggy all winter', 'Gravel with a thin layer of topsoil']],

                ['How thick a layer of mulch is generally recommended around a hydrangea, and what should you leave clear?', 'medium', 2,
"Module 2 · Soil\nA layer of at least 5 cm (2 inches) of mulch keeps the roots cool and moist. Leave a clear gap around the base of the stems so it does not stay wet and rot.\nMulch also helps protect the roots in cold snaps.",
'At least 5 cm, with a clear gap around the base of the stems',
['About 20 cm, piled against the stems for protection', 'No more than 1 cm so the soil can dry out', 'Any depth, as long as it is stone rather than bark']],

                ['A client wants hydrangeas planted at the foot of a wall with only a small gap. What do you check before planting?', 'medium', 2,
"Module 2 · Planting\nCheck the mature size on the plant tag. A panicle hydrangea can grow far larger than a compact bigleaf, and a shrub squeezed against a wall needs constant hard pruning that removes flowers.\nAlso allow airflow, which reduces mildew.",
'The mature size of that variety, so it has room without constant hard pruning',
['Only the colour of the flowers', 'Only the price of the plant', 'Nothing, because hydrangeas can be cut back to fit any space']],

                ['When are the best times to plant hydrangeas on the Lower Mainland?', 'medium', 2,
"Module 2 · Planting\nSpring and early fall are best because the plant can root in while it is cool and the soil is moist. Planting in a summer heatwave needs very careful watering.\nAvoid planting when the ground is frozen or waterlogged.",
'Spring or early fall, when it is cool and the soil is moist',
['The middle of a summer heatwave', 'During a hard winter freeze', 'Any time, because hydrangeas do not mind transplanting stress']],

                ['A newly planted hydrangea wilts on a hot afternoon but looks fine by the next morning. What is the first thing to check?', 'medium', 2,
"Module 2 · Watering\nMidday wilting that recovers overnight is usually heat stress. Check soil moisture a few centimetres down before adding water. Dry soil needs a deep soak; wet soil means more water will not help.\nA young plant needs deep watering once or twice a week in dry weather, rather than a light daily sprinkle.",
'Soil moisture a few centimetres down',
['Whether to apply fertiliser right away', 'Whether to prune it hard to reduce stress', 'Whether the plant is the wrong species and should be pulled out']],

                ['Why do hydrangeas in containers need extra attention compared with ones in the ground?', 'hard', 3,
"Module 2 · Containers\nPots dry out much faster and the roots have less protection from cold. Use a pot with drainage holes, water more often in summer, and in winter move the pot to a sheltered spot or insulate it.\nA pot with no drainage will leave the roots sitting in water and rot.",
'Pots dry out faster and expose the roots to cold, so they need more watering and winter protection',
['Container plants never need drainage holes', 'Container plants do not need any water in winter', 'Container plants grow too small to ever need feeding']],

            ],
        ],

        // ── Module 3 ─────────────────────────────────────────────────────────
        [
            'name'        => 'Hydrangea 3 — Pruning',
            'description' => 'Old wood versus new wood: when to prune each type so the flowers are not cut off.',
            'icon'        => 'scissors',
            'colour'      => '#7B5EA7',
            'sort_order'  => 43,
            'months'      => '2,3,4,7,8',
            'tags'        => 'hydrangea,pruning',
            'questions'   => [

                ['Why does pruning timing matter so much for hydrangeas?', 'easy', 1,
"Module 3 · Pruning\nSome hydrangeas set next year's flower buds on last year's stems (old wood). Others flower on stems grown this season (new wood). Cut the wrong type at the wrong time and you remove the flowers.\nFirst question on any hydrangea job: which type is it?",
'Some types flower on old wood and some on new wood, so the wrong cut removes the flowers',
['All hydrangeas flower on the same wood, so timing does not matter', 'Pruning only matters for appearance, not for flowers', 'Hydrangeas should never be pruned']],

                ['Which of these hydrangeas flowers mainly on old wood (last year\'s stems)?', 'easy', 1,
"Module 3 · Pruning\nBigleaf (macrophylla), mountain (serrata), oakleaf (quercifolia) and climbing (petiolaris) hydrangeas set flower buds on old wood.\nPanicle and smooth hydrangeas flower on new wood.\nException: reblooming (remontant) bigleaf types such as Endless Summer flower on both old and new wood, so check the plant tag.",
'Bigleaf hydrangea (Hydrangea macrophylla)',
['Panicle hydrangea (Hydrangea paniculata)', 'Smooth hydrangea (Hydrangea arborescens)', 'None of them: all hydrangeas flower on new wood']],

                ['When should a panicle hydrangea like Limelight be pruned?', 'easy', 1,
"Module 3 · Pruning\nPanicle hydrangeas bloom on new wood, so prune in late winter or early spring before new growth starts. Cut last year's stems back to a pair of healthy buds. Shorter cuts give fewer, larger flower heads; lighter cuts give more, smaller ones.",
'In late winter or early spring, before new growth starts',
['Right after it flowers, in late summer', 'In fall, to tidy it before winter', 'Never, because it blooms on old wood']],

                ['When should you do any real shaping or thinning of an old-wood hydrangea such as a bigleaf?', 'medium', 2,
"Module 3 · Pruning\nShape or thin old-wood hydrangeas right after the flowers fade, in summer, so the plant has time to make next year's buds. Cutting stems in fall, winter or early spring removes those buds.\nThe light spring tidy (dead stems and spent heads, cut just above healthy buds once hard frosts are over) is fine. Reblooming types flower on new growth too, so they are more forgiving.",
'Right after flowering, in summer',
['In late fall, once the leaves drop', 'In late winter, before growth starts', 'Any time, because the flower buds form in spring']],

                ['A client wants their bigleaf hydrangea cut to the ground in March "to tidy it". What happens?', 'medium', 2,
"Module 3 · Pruning\nCutting a non-reblooming old-wood hydrangea hard in spring removes the buds already formed, so the plant usually puts out leaves but no flowers that year.\nExplain this to the client and offer a light tidy instead: remove only dead stems and spent heads.",
'It will probably grow leaves but have few or no flowers that year',
['It will flower more heavily than ever', 'It will flower exactly the same as before', 'It will die, because hydrangeas cannot be cut in March']],

                ['In March some canes on a bigleaf hydrangea look dead. What is the best approach?', 'medium', 2,
"Module 3 · Pruning\nWait until the plant leafs out in spring. Canes that stay bare and brittle (no green when scratched) are dead and can be cut to the base or to healthy buds. Cutting too early can remove stems that would have flowered.\nFrost-damaged canes often show their true condition only once growth starts.",
'Wait until it leafs out, then cut off only the canes that stay bare and dead',
['Cut everything to the ground at once', 'Leave all the canes forever, even the dead ones', 'Cut all the canes to half height']],

                ['What is the gentlest way to keep an old-wood hydrangea from getting congested?', 'hard', 3,
"Module 3 · Pruning\nEach year cut out one or two of the oldest stems right at the base, along with dead wood. This keeps the plant renewing while younger stems still flower.\nCutting an overgrown plant right to the base can rescue it, but expect no flowers that summer.",
'Each year, cut out one or two of the oldest stems at the base',
['Cut the whole plant to the ground in one go in late fall', 'Shear the top off like a hedge every spring', 'Remove only the flower heads and leave all the stems']],

                ['Why do many gardeners leave the dried flower heads on a bigleaf hydrangea over winter?', 'hard', 3,
"Module 3 · Pruning\nThe spent heads give the buds just below them some protection from frost. In spring, remove them by cutting just above a pair of healthy buds.\nIn mild spots this matters less, but it is a safe habit where frost can hit.",
'They help protect the buds just below them from frost',
['Because the heads make the plant flower twice', 'Because removing them in winter is illegal', 'Because the heads feed the plant over winter']],

            ],
        ],

        // ── Module 4 ─────────────────────────────────────────────────────────
        [
            'name'        => 'Hydrangea 4 — Colour & Soil pH',
            'description' => 'Why bigleaf hydrangeas turn blue or pink, and what clients can realistically expect.',
            'icon'        => 'droplet',
            'colour'      => '#7B5EA7',
            'sort_order'  => 44,
            'months'      => '3,4,5,10',
            'tags'        => 'hydrangea,colour,ph',
            'questions'   => [

                ['What decides whether a bigleaf hydrangea flower is blue or pink?', 'easy', 1,
"Module 4 · Colour\nIt depends on how much aluminium the plant can take up. Aluminium is more available in acidic soil, which gives blue flowers. In less acidic soil it is locked up and flowers turn pink.\nThe variety has to be able to change colour for this to work.",
'How much aluminium the plant can take up, which depends on soil pH',
['The time of day the flowers are watered', 'How often the plant is pruned', 'The brand of fertiliser, regardless of the soil']],

                ['Which soil conditions generally give blue bigleaf hydrangeas?', 'easy', 1,
"Module 4 · Colour\nAcidic soil, roughly pH 5.5 or lower, makes aluminium available and gives blue flowers. Roughly pH 6.5 and above tends to give pink.\nBetween the two (about 5.5 to 6.5) the flowers come out purple or mixed.",
'Acidic soil, roughly pH 5.5 or lower',
['Alkaline soil, roughly pH 7.5', 'Soil with plenty of lime', 'Soil kept very dry']],

                ['Which of these will not change colour with soil pH?', 'easy', 1,
"Module 4 · Colour\nWhite hydrangeas stay white, and panicle and smooth hydrangeas do not shift between blue and pink. Panicle flowers often fade from white to pink as they age, but that is ageing, not soil chemistry.\nTell clients which type they have before promising a colour.",
'A white hydrangea such as Annabelle',
['A pink bigleaf hydrangea', 'A blue bigleaf hydrangea', 'A purple bigleaf hydrangea']],

                ['A client wants their pink bigleaf hydrangea to turn blue. What should you do first?', 'medium', 2,
"Module 4 · Colour\nTest the soil pH first. If it is above about 5.5, it needs to be lowered gradually (garden sulphur is the usual in-ground choice; aluminium sulphate is mainly for potted plants in soilless mix). Always follow the label rate.\nWithout a test, you are guessing, and too much can burn the roots.",
'Test the soil pH before adding anything',
['Add as much acidifier as possible right away', 'Add lime to make the soil more alkaline', 'Water with plain tap water more often']],

                ['What does adding garden lime to the soil around a hydrangea do?', 'medium', 2,
"Module 4 · Colour\nLime raises the soil pH, making it less acidic. On a bigleaf hydrangea that moves the colour toward pink.\nLime is not a quick fix: colour changes can take several years, and over-liming can cause leaf yellowing.",
'It raises the pH, which pushes bigleaf flowers toward pink',
['It lowers the pH and makes the flowers bluer', 'It has no effect on soil pH', 'It turns white hydrangeas blue']],

                ['Why can a blue bigleaf hydrangea next to a concrete path or foundation slowly turn pink?', 'medium', 2,
"Module 4 · Colour\nConcrete and mortar contain lime that can leach out and raise the soil pH nearby (a common field observation). Over time the soil becomes less acidic and the aluminium is locked up, so the blooms drift toward pink.\nThis is a common surprise for clients with new paving beside old plants.",
'Lime leaching from the concrete raises the soil pH',
['Concrete reflects too much sun onto the flowers', 'Concrete releases aluminium into the soil', 'Hydrangeas always turn pink when planted near any hard surface']],

                ['High-phosphorus fertiliser can work against blue flowers because:', 'hard', 3,
"Module 4 · Colour\nHigh phosphate limits the plant's ability to absorb aluminium. A low-phosphate fertiliser is better when blue is the goal.\nSoil pH still matters most, so test the soil first.",
'High phosphate limits the plant\'s ability to take up aluminium',
['Phosphorus raises the soil temperature', 'Phosphorus makes the leaves too thick', 'Phosphorus washes aluminium into the leaves']],

                ['What is the most honest message for a client who wants a particular flower colour from their bigleaf hydrangea?', 'hard', 3,
"Module 4 · Colour\nColour can be nudged, not guaranteed. It depends on the variety, soil pH, and time. Test the soil, change it gradually, and expect it to take time, possibly several years.\nA shift can also vary from plant to plant, so set expectations before you promise.",
'It can be nudged by changing soil pH, but takes time (possibly years) and is not guaranteed',
['Colour can be switched in a week with one treatment', 'Colour cannot be influenced at all', 'Colour is guaranteed once you apply any acidifier']],

            ],
        ],

        // ── Module 5 ─────────────────────────────────────────────────────────
        [
            'name'        => 'Hydrangea 5 — Problems & Troubleshooting',
            'description' => 'No blooms, wilting, mildew, leaf spots and pests: how to diagnose before you treat.',
            'icon'        => 'alert-triangle',
            'colour'      => '#7B5EA7',
            'sort_order'  => 45,
            'months'      => '4,5,6,7,8,9',
            'tags'        => 'hydrangea,problems,pests',
            'questions'   => [

                ['A healthy-looking bigleaf hydrangea has plenty of leaves but no flowers. What is the most common cause?', 'easy', 1,
"Module 5 · Problems\nThe usual causes on a non-reblooming type are pruning at the wrong time (buds cut off), frost killing the buds, or too much shade. Too much nitrogen fertiliser can also give leaves at the expense of flowers.\nAsk when it was last pruned before looking at anything else.",
'Pruning at the wrong time, or frost damage, that removed or killed the flower buds',
['It is a male plant and needs a female plant nearby', 'It needs more nitrogen fertiliser', 'It needs to be watered every day']],

                ['What does powdery mildew look like on hydrangea leaves?', 'easy', 1,
"Module 5 · Problems\nA white or grey powdery coating on the leaf surface, most common in humid weather with poor airflow.\nImprove airflow by thinning crowded stems, avoid wetting the leaves late in the day, and clear fallen leaves.",
'A white or grey powdery coating on the leaves',
['Small holes chewed along the leaf edges', 'Sticky black spots with a sweet smell', 'Bright orange tubes on the stems']],

                ['Brown or purple spots with darker edges on hydrangea leaves, mostly in wet weather, are usually:', 'medium', 2,
"Module 5 · Problems\nA fungal leaf spot. It is usually cosmetic and rarely kills the plant. Water at the base, avoid wetting leaves, remove badly spotted leaves and clear the fallen ones in fall.\nSpraying is rarely needed, and any product must be label-approved and allowed by the municipality.",
'A fungal leaf spot that is usually cosmetic',
['A sign the plant is about to die', 'A sign of too little water only', 'A pest that must be sprayed immediately with any available product']],

                ['A hydrangea wilts every afternoon, but the soil is soggy and wet. What is the likely problem?', 'medium', 2,
"Module 5 · Problems\nWilting in wet soil points to root problems: waterlogging and root rot. The roots cannot take up water so the leaves droop.\nDo not add more water. Improve drainage and cut back on watering.",
'Waterlogged soil and root problems, so more water will make it worse',
['The plant needs even more water', 'The plant needs a stronger fertiliser', 'The plant is simply too young to flower']],

                ['Leaves turn yellow with the veins staying green on a hydrangea in alkaline soil. This is most likely:', 'medium', 2,
"Module 5 · Problems\nChlorosis, often from iron that the plant cannot take up in high-pH soil. Test the soil, lower the pH gradually if it is too high, and use a chelated iron product to the label rate.\nMore general fertiliser does not fix it.",
'Iron deficiency caused by high soil pH',
['Too much shade', 'Too little nitrogen only', 'A normal sign of the flowers fading']],

                ['What is a good first response to a small aphid colony on new hydrangea growth?', 'medium', 2,
"Module 5 · Problems\nAphids are soft-bodied insects on tender new growth. Start with the gentlest option: a firm spray of water, or insecticidal soap used to its label. Encourage ladybugs and check back.\nAny pesticide must be label-registered for the use, and local bylaws on pesticide use apply.",
'A firm spray of water, then insecticidal soap if needed, following the label',
['Spray a strong pesticide on the whole garden', 'Ignore it, because aphids always kill hydrangeas', 'Cut the plant to the ground']],

                ['A late frost has blackened the new leaves and buds on a bigleaf hydrangea. What should you do?', 'hard', 3,
"Module 5 · Problems\nWait. Do not cut the plant back. The damaged leaves are cosmetic and the plant will usually produce new growth. Flowers may be reduced that year.\nIn future cold snaps after budbreak, cover the plant with fleece or burlap overnight.",
'Wait and prune only dead tissue later, rather than cutting the plant back',
['Cut the whole plant to the ground straight away', 'Fertilise heavily to speed recovery', 'Dig it up and replace it']],

                ['Brown, crispy edges on hydrangea leaves in hot, windy weather are most likely caused by:', 'hard', 3,
"Module 5 · Problems\nLeaf scorch from sun, wind and not enough water. Move the plant to a more sheltered spot if it persists, mulch, and water deeply.\nCheck the soil first. Scorch is not usually a disease.",
'Leaf scorch from sun, wind and too little water',
['A fungal infection that needs spraying', 'Too much shade', 'Aluminium sulphate burning the roots, in every case']],

            ],
        ],

        // ── Module 6 ─────────────────────────────────────────────────────────
        [
            'name'        => 'Hydrangea 6 — Seasonal Care & Client Advice',
            'description' => 'The yearly care calendar, feeding, winter protection, and what to tell the client.',
            'icon'        => 'calendar',
            'colour'      => '#7B5EA7',
            'sort_order'  => 46,
            'months'      => '1,2,3,4,5,6,7,8,9,10,11,12',
            'tags'        => 'hydrangea,seasonal,client',
            'questions'   => [

                ['Which hydrangea jobs belong in late winter (February to March) on the Lower Mainland?', 'easy', 1,
"Module 6 · Calendar\nLate winter is the time to prune panicle and smooth hydrangeas, which flower on new wood. Leave the bigleaf and oakleaf types alone except for tidying obviously dead material.\nThis is also a good time to plan spring mulch and fertiliser.",
'Pruning panicle and smooth hydrangeas',
['Heavy pruning of every bigleaf hydrangea', 'Fertilising heavily with nitrogen', 'Cutting every hydrangea to the ground']],

                ['When should shaping and thinning of old-wood hydrangeas (bigleaf, oakleaf) be booked in the yearly schedule?', 'easy', 1,
"Module 6 · Calendar\nRight after flowering, in summer. Book shaping and thinning jobs for July to August so the pruning does not remove next year's flowers. A light spring tidy of dead wood is fine.\nWhen you quote, say which type you are pruning and why the timing differs.",
'Right after flowering, in summer',
['Midwinter, along with the panicle hydrangeas', 'Late fall', 'Early spring, before any leaves appear']],

                ['What is a sensible fertiliser routine for hydrangeas?', 'medium', 2,
"Module 6 · Feeding\nApply a balanced slow-release fertiliser in spring as growth starts, to the label rate. Over-feeding with nitrogen gives lush leaves and fewer flowers.\nSoil that is already rich in compost often needs very little.",
'A balanced slow-release feed in spring, to the label rate',
['Heavy nitrogen feeding every few weeks all summer', 'No feeding or watering at any time', 'A big dose of fertiliser in late fall']],

                ['Why stop fertilising hydrangeas by mid to late summer?', 'medium', 2,
"Module 6 · Feeding\nLate feeding pushes soft new growth that does not harden off before frost, and can be killed by early cold.\nAllow growth to slow so the plant is ready for winter.",
'Late feeding pushes soft growth that frost can damage',
['Because plants cannot absorb fertiliser after July', 'Because fertiliser turns flowers brown', 'Because it is a legal requirement']],

                ['A client wants to cut their bigleaf hydrangea hard in November "so it is tidy for winter". What do you advise?', 'medium', 2,
"Module 6 · Client advice\nAdvise against it. Fall pruning removes next year's buds and leaves the plant more exposed to frost. Offer a light tidy and leave the flower heads on as winter protection.\nA short, plain explanation works better than a lecture.",
'Advise against it, since it removes next year\'s buds, and offer a light tidy instead',
['Do it, since it makes no difference', 'Cut it even lower so it grows back fuller', 'Cut it and cover it with plastic']],

                ['A client says their hydrangea "died over winter" because the canes look bare in March. What is the best reply?', 'medium', 2,
"Module 6 · Client advice\nExplain that many hydrangeas leaf out late, and check by scratching a cane for green or waiting until new growth appears. Many apparently dead plants shoot from the base or from buds on live canes.\nBook a check visit rather than removing anything now.",
'Many leaf out late, so wait and check for green growth before removing anything',
['Confirm it is dead and offer to remove it today', 'Say it needs heavy fertiliser immediately', 'Say hydrangeas cannot survive a Lower Mainland winter']],

                ['How can a bigleaf hydrangea be given winter protection in an exposed spot?', 'hard', 3,
"Module 6 · Winter\nMulch the base, leave the old stems and flower heads on, and in cold snaps wrap the plant loosely with burlap or a stake-and-burlap cage. Use breathable material, not plastic.\nProtection matters most for plants that bloom on old wood.",
'Mulch the base and leave stems on, with a loose burlap wrap in cold snaps',
['Cut it to the ground and cover it in plastic', 'Water heavily just before a freeze', 'Stand pots in standing water over winter']],

                ['A client has a shady corner and wants a hydrangea that will flower there. What is the honest advice?', 'hard', 3,
"Module 6 · Client advice\nMost hydrangeas flower best with some sun, so shade means fewer flowers. Bigleaf hydrangeas like afternoon shade, and climbing hydrangea copes well with shade, though flowering will still be reduced in deep shade.\nChoosing the right type at the quote stage prevents disappointment later.",
'Choose a type suited to part shade, such as a bigleaf or climbing hydrangea, and warn that flowers will be fewer',
['Plant a panicle hydrangea in deep shade and promise full flowers', 'Add more fertiliser to make up for the shade', 'Choose a plant at random, since shade does not affect flowering']],

            ],
        ],

    ],
];
