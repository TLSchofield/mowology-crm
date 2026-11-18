<?php
/**
 * PruningKnowledge — what the pruning planner is allowed to lean on.
 *
 * Distilled 2026-11-03 from Tim's copies of "Niwaki" (Jake Hobson, Timber Press 2007) and
 * "The Bonsai Bible" (Peter Chan); the long form is .claude/skills/otto-operations/references/
 * pruning-niwaki.md, which is not deployed — this file is. Where the books are silent the entry
 * says so ("unknown"), so the model says "check" rather than inventing a BC disease window.
 *
 * Pure constants; no I/O. Global namespace; require_once it.
 */
declare(strict_types=1);

final class PruningKnowledge
{
    /** Vancouver, BC coast, zone 8b: the months the planner reasons in. */
    public const CLIMATE = 'Pacific Northwest coast (Vancouver, BC, zone 8b): mild wet winters, dry summers, frosts possible to late March.';

    public const SYSTEM = <<<'TXT'
You are the pruning planner for a landscaping company on the Pacific Northwest coast (Vancouver, BC, zone 8b). You are given one to three photos of ONE plant on a client's property (photo 0 = whole plant, then closer, then close-up), what it was identified as, and where it stands. You write a plan a crew will carry out and a client (often a strata property manager) will read.

How to think (Japanese garden pruning, Hobson):
- Structure first, then foliage: thin to reveal trunk and framework, then shape. Space between branches matters as much as what is kept.
- Asymmetry and odd numbers over symmetry; a natural outline, never a sheared ball unless the species is grown that way.
- Little and often beats one hard cut. A reshaped tree is a multi-year job: state how many seasons and what each one does.
- Cut just above an outward bud; branch to trunk just outside the collar; thick branches in three cuts; never leave a snag.
- Species decide everything: pines, most junipers and cypresses do NOT re-sprout from leafless old wood; yew, box, holly, Podocarpus, camellia, azalea, most deciduous shrubs do.
- Flowering shrubs: cut after flowering if they flower on old wood (bigleaf hydrangea, camellia, forsythia, lilac); cut in late winter if they flower on new wood (panicle and smooth hydrangea, roses, buddleja).
- Prunus (cherry, plum): dislikes regular pruning; only what is needed, in summer after flowering or in late winter before sap rises, and say that disease (silver leaf, canker) timing must be checked locally.
- Maples: thin in winter after leaf drop; remove one of each opposite pair, inward and strongly vertical shoots; keep horizontal layers.

Rules for this plan:
1. Confirm or question the species from the photos. If the plan would differ for a look-alike (e.g. Hydrangea macrophylla vs paniculata), say what changes.
2. Give two windows as month numbers 1–12 for Vancouver: "now" (what can safely be done this visit) and "main" (the proper prune). A window may wrap the year (from 11 to 2).
3. Number the actions 1, 2, 3… in the order to do them. Each has a type, one line of what, one line of why, and where it is on photo 0 as fractions x,y (0–1, from the top-left). Put the anchor where the cut is made (the base of a stem for remove_ground, the cut point for shorten). Give anchor confidence honestly; use 0 when you cannot see the spot.
4. Keep actions to what the photos show. At most 14. Prefer fewer, clearer actions.
5. Site constraints: anything in the photos the crew must respect (windows, roofline, fire department connection, hydrant, mailboxes, path, parking, neighbours, power lines).
6. checks_on_site: what cannot be judged from the photos and must be checked by hand (dead wood, bark, which of two crossing stems is in front, disease, whether old wood will re-sprout).
7. client_summary: at most 80 words, plain, for the property manager: what we will do, when, and why it is good for the plant and the building. No jargon.
8. crew_checklist: the actions as short imperative lines a crew member can tick off, at most 12 words each, in the order to do them.
9. Never invent a BC-specific disease window or a local bylaw. Say "check" instead.
TXT;

    /**
     * Species notes the planner is handed when the genus matches. Keyed by genus (lower case).
     * season = when to cut and why; method; cautions; gaps = what the books do not say.
     */
    public const SPECIES = [
        'pinus' => ['season' => 'Candles early summer before needles open; thinning and formative cuts autumn, or spring before growth.',
                    'method' => 'Pinch candles by hand; autumn thin to two shoots in a V and pluck old needles; pull branches below horizontal.',
                    'cautions' => 'Will NOT re-sprout from leafless old wood. Cannot be cut back hard. Pinching too late = no second flush.',
                    'gaps' => 'none'],
        'taxus' => ['season' => 'Clip at end of summer, optionally twice (early summer, early autumn).',
                    'method' => 'Shears; cutting through leaves is fine; can be cut back to the framework and rebuilt (re-sprouts from old wood).',
                    'cautions' => 'Slow; a hard cut takes years to green up.', 'gaps' => 'winter hard-prune timing unknown'],
        'buxus' => ['season' => 'First clip early June after the spring flush stops; second late summer, shallow.',
                    'method' => 'Shears to an imagined outline; deep cuts into old growth are fine; re-sprouts.',
                    'cautions' => 'Not in bright sun (scorch); not hard late in the year; remove clippings and dead leaves (blight).', 'gaps' => 'box tree moth and blight management unknown'],
        'ilex' => ['season' => 'Clip twice in summer; cut back hard in spring after the worst frosts.',
                   'method' => 'Shears; trains well into pads; remove epicormic shoots.',
                   'cautions' => 'Poorly clipped plants throw epicormic growth.', 'gaps' => 'none'],
        'prunus' => ['season' => 'Ornamental cherries: as little as possible; dead wood only, late winter before sap rises. Plum/apricot (P. mume type): late winter before blossom.',
                     'method' => 'Thin congested branches with secateurs; cut newest shoots to 3–4 outward buds on trained plums; remove non-weeping growth on weeping forms.',
                     'cautions' => 'Most species dislike regular pruning; wood is brittle; sacrifices flower buds.',
                     'gaps' => 'silver leaf, bacterial canker and brown rot windows for BC unknown — summer cutting after flowering is the usual local advice; say check'],
        'acer' => ['season' => 'Thin in winter after leaf drop, well before sap rises; shaped (not thinned) style midsummer and autumn.',
                   'method' => 'Remove epicormic and strong vertical shoots; remove one of each opposite pair; cut over-long branches at outward buds; aim for flat horizontal layers.',
                   'cautions' => 'Keep the natural grace; shaped style sacrifices autumn colour.', 'gaps' => 'spring sap-bleed timing on the coast unknown'],
        'rhododendron' => ['season' => 'Evergreen azaleas: first clip just after flowering (remove ~2.5 cm), then little and often to October.',
                           'method' => 'Shears to an organic mound (karikomi); cut below spent flowers; can be cut hard (re-sprouts).',
                           'cautions' => 'Delaying the post-flower clip leaves woody shoots and bare patches.', 'gaps' => 'large-leaved rhododendrons not in the books'],
        'camellia' => ['season' => 'After flowering, then mid-summer; sasanqua (autumn-flowering) the same.',
                       'method' => 'Shears for most; hard cut in spring for a tight shape; re-sprouts.',
                       'cautions' => 'No autumn pruning if flowers are wanted (flowers on summer growth).', 'gaps' => 'none'],
        'juniperus' => ['season' => 'Clip two or three times late spring to early autumn; final clip in autumn.',
                        'method' => 'Pinch by hand or shears; keep to green growth; build balls or pads.',
                        'cautions' => 'No re-sprout from old leafless wood; cutting too hard even in green growth gives grey prickly juvenile foliage.', 'gaps' => 'none'],
        'cryptomeria' => ['season' => 'Cut in spring; thin new growth in autumn or the following spring.',
                          'method' => 'Thin and cut sides back yearly; pick old brown foliage off round the trunk.',
                          'cautions' => 'Cut a trunk high first then trim, to avoid a split.', 'gaps' => 'none'],
        'podocarpus' => ['season' => 'One clip in early autumn in cooler climates.',
                         'method' => 'Shears top down, undersides too; thin by hand for a natural look; re-sprouts.',
                         'cautions' => 'Cutting leaves is harmless.', 'gaps' => 'none'],
        'cedrus' => ['season' => 'Mid-summer and autumn or early winter.',
                     'method' => 'Prune to a downward-facing bud; remove growth back toward the trunk; cut new growth twice a year.',
                     'cautions' => 'Heavy winter thinning looks like a pollard.', 'gaps' => 'none'],
        'osmanthus' => ['season' => 'O. fragrans: soon after flowering (October) or in spring. O. heterophyllus: train like Ilex crenata.',
                        'method' => 'Shears into rounded shapes.',
                        'cautions' => 'Flowers on summer growth: do not prune mid-summer.', 'gaps' => 'O. heterophyllus season unknown'],
        'hydrangea' => ['season' => 'Species decides: paniculata and arborescens flower on new wood — cut late winter (2–3) before growth; macrophylla and serrata flower on old wood — only dead stems and spent heads in spring, shaping right after flowering.',
                        'method' => 'New-wood types: remove about a third of the oldest stems at ground level, thin weak and crossing stems, shorten the rest by a third to a half to an outward bud pair. Old-wood types: do not shorten stems that carry buds.',
                        'cautions' => 'Getting the species wrong removes a year of flowers. Leaving dried heads over winter protects buds and looks fine.',
                        'gaps' => 'not in the two books; standard horticultural practice — say so'],
    ];

    /** The species note for a scientific name, or null. */
    public static function forName(?string $scientific): ?array
    {
        $genus = strtolower(trim((string)strtok((string)$scientific, ' ')));
        return $genus !== '' ? (self::SPECIES[$genus] ?? null) : null;
    }

    /** Action types the schema allows, with the label the UI shows. */
    public const TYPES = [
        'remove_ground' => 'Remove at ground level',
        'shorten'       => 'Shorten (1/3–1/2)',
        'thin'          => 'Thin / remove crossing or weak',
        'deadwood'      => 'Dead, broken or diseased',
        'clear_access'  => 'Clear from building / access',
        'keep'          => 'Keep (framework)',
    ];
}
