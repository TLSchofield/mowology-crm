<?php
/**
 * MiaStoryWriter — drafts the short project story for a finished job whose before/after photos the owner approved
 * (Phase 2.5, 2026-10-26). The story is a portfolio card: a name and a one-or-two-sentence description.
 *
 * What the model is given: the kind of work, the neighbourhood, the month, and the business name - nothing else.
 * No client, street, price, crew or note ever reaches it, so none can come back. The draft is then checked in PHP:
 *   - the same wording rules as every Mia draft (MiaRecommendationWriter::lint: no promises, no unproven claims,
 *     no numbers or contact details that are not in the facts, no owner-prohibited terms);
 *   - plus a name check: any capitalised word that is not in the facts (a person's name, a street) rejects the draft.
 * One repair attempt, then the question stays without wording. Gates are re-checked here too: the pair must still be
 * approved with consent covered (MiaFeeds::CONSENT_OK) on a completed visit.
 *
 * Output goes into mia_content_drafts as kind 'story' (title = project name, meta_desc = description, page_url =
 * portfolio:pair:<id>) so the existing edit / approve screen and the bound-approval hash work unchanged.
 *
 * Global namespace, no autoloader: require_once it. PHP 7.4-compatible. Never throws: returns result arrays.
 */
declare(strict_types=1);

require_once __DIR__ . '/MiaLlmGateway.php';
require_once __DIR__ . '/MiaActionService.php';
require_once __DIR__ . '/MiaMemoryService.php';
require_once __DIR__ . '/MiaRecommendationWriter.php';
require_once __DIR__ . '/MiaFeeds.php';

final class MiaStoryWriter
{
    public const KIND = 'story';
    public const REPAIR_ATTEMPTS = 1;
    /** Bump when the prompt or checks change: part of the reuse hash. */
    public const POLICY_VERSION = 2;
    public const MAX_UNKNOWN_WORDS = 3;

    private const SCHEMA = [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['title', 'meta_description', 'explanation'],
        'properties' => [
            'title'            => ['type' => 'string', 'minLength' => 5, 'maxLength' => 120],
            'meta_description' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 300],
            'explanation'      => ['type' => 'string', 'minLength' => 20, 'maxLength' => 700],
        ],
    ];

    /** Capitalised words that are always fine (start of the place line the site already uses). */
    private const ALWAYS_OK = ['vancouver', 'bc', 'metro', 'british', 'columbia', 'canada', 'i'];
    /**
     * The only words a public card may use besides the facts: plain everyday language for describing outdoor work.
     * Anything else - a name, a street, a brand, a number word, an invented detail - is rejected. Capital letters are not
     * trusted to find names ("for john smith" has none), so the check is an allow-list, not a name detector.
     */
    private const SAFE_WORDS = [
        'a', 'about', 'above', 'across', 'after', 'afternoon', 'again', 'all', 'along', 'also', 'always', 'an', 'and',
        'another', 'any', 'appeal', 'appealing', 'appearance', 'are', 'area', 'areas', 'around', 'as', 'at', 'attractive',
        'autumn', 'away', 'back', 'bare', 'be', 'because', 'bed', 'beds', 'been', 'before', 'began', 'begin', 'begun',
        'behind', 'being', 'below', 'beside', 'best', 'better', 'between', 'big', 'bit', 'blocks', 'border', 'borders',
        'both', 'bright', 'brighter', 'bring', 'bringing', 'brought', 'building', 'buildings', 'bunch', 'bush', 'bushes',
        'but', 'by', 'calm', 'calmer', 'came', 'can', 'care', 'cared', 'careful', 'carefully', 'caring', 'change', 'changed',
        'changes', 'clean', 'cleaned', 'cleaner', 'cleanest', 'cleaning', 'cleanly', 'cleanup', 'clear', 'cleared', 'closely',
        'come', 'coming', 'community', 'comparison', 'complete', 'completed', 'completing', 'corner', 'corners', 'correct',
        'correctly', 'could', 'courtyard', 'courtyards', 'crew', 'crews', 'crisp', 'crisply', 'curb', 'cut', 'cuts',
        'cutting', 'day', 'days', 'dense', 'detail', 'detailed', 'details', 'did', 'difference', 'dig', 'digging', 'do',
        'does', 'done', 'down', 'driveway', 'dug', 'during', 'each', 'early', 'edge', 'edged', 'edges', 'edging', 'end',
        'ended', 'ending', 'entrance', 'entry', 'equipment', 'even', 'evenly', 'every', 'fall', 'fed', 'feed', 'feet',
        'fence', 'fences', 'few', 'find', 'fine', 'finish', 'finished', 'finishing', 'first', 'flower', 'flowering',
        'flowers', 'foot', 'for', 'found', 'fresh', 'freshened', 'from', 'front', 'full', 'fuller', 'further', 'garden',
        'gardening', 'gardens', 'gate', 'gates', 'gave', 'gently', 'get', 'getting', 'give', 'giving', 'go', 'going', 'good',
        'got', 'grass', 'great', 'green', 'greener', 'had', 'hand', 'hands', 'happy', 'has', 'have', 'healthier', 'healthy',
        'heavy', 'hedge', 'hedges', 'here', 'high', 'higher', 'home', 'homes', 'house', 'houses', 'how', 'if', 'image',
        'images', 'in', 'inch', 'inches', 'into', 'inviting', 'is', 'it', 'its', 'job', 'jobs', 'just', 'keep', 'keeping',
        'kept', 'kerb', 'laid', 'large', 'larger', 'last', 'later', 'lawn', 'lawns', 'lay', 'laying', 'leave', 'leaving',
        'left', 'less', 'lift', 'lifted', 'light', 'like', 'line', 'lines', 'little', 'local', 'locally', 'long', 'longer',
        'look', 'looked', 'looking', 'looks', 'lot', 'lots', 'lovely', 'low', 'lower', 'lush', 'machine', 'machines', 'made',
        'maintain', 'maintained', 'maintenance', 'make', 'many', 'may', 'messy', 'month', 'months', 'more', 'morning', 'most',
        'mow', 'mowed', 'mowing', 'much', 'mulch', 'mulched', 'narrow', 'natural', 'naturally', 'near', 'neat', 'neater',
        'neatly', 'neatness', 'neighborhood', 'neighbourhood', 'neighbourhoods', 'never', 'new', 'next', 'nice', 'nicer',
        'no', 'not', 'now', 'of', 'off', 'often', 'old', 'on', 'once', 'one', 'only', 'onto', 'open', 'or', 'original',
        'other', 'our', 'out', 'over', 'overgrown', 'own', 'part', 'path', 'paths', 'pathway', 'patio', 'patios', 'per',
        'photo', 'photos', 'picture', 'pictures', 'piece', 'pieces', 'plain', 'plant', 'planted', 'planting', 'plants',
        'pleasant', 'pleased', 'plenty', 'polished', 'porch', 'precisely', 'presentable', 'pretty', 'project', 'projects',
        'proper', 'properly', 'properties', 'property', 'proud', 'prune', 'pruned', 'pruning', 'put', 'putting', 'quick',
        'quickly', 'quiet', 'rake', 'raked', 'raking', 'ready', 'recent', 'recently', 'refresh', 'refreshed', 'remove',
        'removed', 'removing', 'residence', 'residential', 'rest', 'result', 'results', 'right', 'rough', 'rugged', 's',
        'same', 'season', 'seasonal', 'see', 'seen', 'service', 'services', 'set', 'several', 'shape', 'shaped', 'shapes',
        'shaping', 'sharp', 'sharper', 'she', 'short', 'shorter', 'shot', 'shots', 'should', 'show', 'showing', 'shown',
        'shows', 'shrub', 'shrubs', 'side', 'sides', 'simple', 'since', 'small', 'smooth', 'smoothed', 'so', 'soil', 'some',
        'soon', 'space', 'spaces', 'spread', 'spreading', 'spring', 'staff', 'stairs', 'start', 'started', 'starting',
        'steadily', 'steps', 'still', 'street', 'streets', 'strong', 'such', 'summer', 'sweep', 'sweeping', 'swept', 'take',
        'taken', 'taking', 'tall', 'taller', 'tangled', 'team', 'teams', 'than', 'that', 'the', 'their', 'them', 'then',
        'there', 'these', 'they', 'thick', 'thicker', 'thin', 'this', 'thorough', 'thoroughly', 'those', 'through',
        'throughout', 'tidied', 'tidier', 'tidiness', 'tidy', 'to', 'today', 'together', 'too', 'took', 'tools', 'top',
        'toward', 'towards', 'transformation', 'transformed', 'tree', 'trees', 'tried', 'trim', 'trimmed', 'trimming',
        'trims', 'turf', 'two', 'under', 'unlike', 'unruly', 'until', 'up', 'update', 'updated', 'upkeep', 'upon', 'us',
        'use', 'used', 'usually', 'very', 'visit', 'visited', 'visits', 'walkway', 'walkways', 'wall', 'walls', 'was',
        'water', 'watered', 'watering', 'way', 'we', 'weed', 'weeded', 'weeding', 'week', 'weeks', 'welcoming', 'well',
        'went', 'were', 'what', 'when', 'where', 'whether', 'which', 'while', 'who', 'why', 'wide', 'wider', 'wild', 'will',
        'winter', 'with', 'within', 'without', 'work', 'worked', 'working', 'would', 'yard', 'yards', 'year'
    ];
    private const SMALL_WORDS = ['a', 'an', 'and', 'at', 'for', 'in', 'of', 'on', 'the', 'to', 'with', 'by', 'from'];

    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;
    /** @var MiaLlmGateway */
    private $llm;
    /** @var MiaActionService */
    private $actions;
    /** @var MiaMemoryService */
    private $memory;

    public function __construct(PDO $db, int $siteId, MiaLlmGateway $llm, MiaActionService $actions)
    {
        if ($siteId <= 0) { throw new InvalidArgumentException('MiaStoryWriter needs a site'); }
        $this->db = $db;
        $this->siteId = $siteId;
        $this->llm = $llm;
        $this->actions = $actions;
        $this->memory = new MiaMemoryService($db, $siteId);
    }

    // ---- pure helpers ---------------------------------------------------------------------------------

    /**
     * The only facts a story may rest on. @param array $pair ba_pairs row joined with completed_at
     * @return array{work:string, area:string, month:string, category:string}
     */
    public static function facts(array $pair): array
    {
        $clean = function ($v, int $max): string {
            $v = trim((string)preg_replace('/[^\p{L}\p{N} &\'.-]+/u', ' ', (string)$v));
            return mb_substr(trim((string)preg_replace('/\s+/u', ' ', $v)), 0, $max);
        };
        $ts = strtotime((string)($pair['completed_at'] ?? ''));
        return [
            'work' => $clean($pair['service'] ?? '', 60),
            'area' => $clean($pair['area'] ?? '', 60),
            'month' => $ts ? date('F', $ts) : '',
            'category' => $clean($pair['category'] ?? '', 30),
        ];
    }

    /** The text the checks treat as "the facts given". */
    public static function factsText(array $facts, string $company): string
    {
        return 'Business: ' . $company . '. Work: ' . $facts['work'] . '. Neighbourhood: ' . $facts['area'] . '. Month: ' . $facts['month'] . '.';
    }

    /**
     * Capitalised words that are not in the facts: a name or a street the model made up or was handed.
     * Sentence starts and small words in a title are not counted.
     * @return string[] problems
     */
    public static function nameProblems(string $text, string $factsText, bool $isTitle, bool $checkSentenceStart = true): array
    {
        $ok = array_flip(array_merge(self::ALWAYS_OK, preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($factsText, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $bad = [];
        $words = preg_split('/([.!?]\s+|\n)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];
        foreach ($words as $sentence) {
            if (preg_match('/^[.!?\s]+$/u', $sentence)) { continue; }
            $tokens = preg_split('/[^\p{L}\p{N}\'-]+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $i => $tok) {
                if (!preg_match('/^\p{Lu}/u', $tok)) { continue; }
                $low = mb_strtolower((string)preg_replace("/'s$/u", '', $tok), 'UTF-8');             // possessive only: rtrim() would also eat the s of "This" and "Yes"
                if ($i === 0 && !$isTitle && (!$checkSentenceStart || isset($ok[$low]) || in_array($low, self::SAFE_WORDS, true))) { continue; }   // a sentence starts with a capital: fine for a known word, not for a name
                if ($isTitle && in_array($low, self::SMALL_WORDS, true)) { continue; }
                if (isset($ok[$low]) || isset($ok[mb_strtolower($tok, 'UTF-8')])) { continue; }
                $bad[$tok] = true;
            }
        }
        return $bad ? ['Contains a name or place that is not in the facts given: ' . implode(', ', array_slice(array_keys($bad), 0, 4))] : [];
    }

    /**
     * Every word must be one of the facts' words or a SAFE_WORD.
     * @return string[] problems
     */
    public static function wordProblems(string $text, string $factsText, string $label): array
    {
        $bad = self::unknownWords($text, $factsText);
        return $bad ? ["The $label uses words outside the plain vocabulary allowed for a public card: " . implode(', ', array_slice($bad, 0, 6)) . '. Use simple everyday words and only the facts given.'] : [];
    }

    /** @return string[] words that are neither a fact nor a SAFE_WORD */
    public static function unknownWords(string $text, string $factsText): array
    {
        $ok = array_flip(array_merge(self::SAFE_WORDS, self::ALWAYS_OK, preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($factsText, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $bad = [];
        foreach (preg_split('/[^\p{L}\'-]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $w = trim($w, "'-");
            if ($w === '' || isset($ok[$w])) { continue; }
            $base = preg_replace('/(?:\'s|s)$/u', '', $w);                      // hedge's, hedges
            if (isset($ok[$base])) { continue; }
            $bad[$w] = true;
        }
        return array_keys($bad);
    }

    /** All checks for one candidate. @return string[] problems; empty = acceptable */
    public static function lint(array $cand, array $facts, string $company, array $prohibited): array
    {
        $factsText = self::factsText($facts, $company);
        $ctx = ['current_title' => null, 'current_desc' => null, 'allowed_numbers' => MiaRecommendationWriter::numbers($factsText),
                'public_facts' => $factsText, 'prohibited' => $prohibited, 'known_facts' => $factsText];
        $p = MiaRecommendationWriter::lint($cand, $ctx);
        // A few words outside the plain vocabulary are shown to the owner to check (see prepare()); many mean the model invented details.
        $unknown = array_unique(array_merge(self::unknownWords((string)$cand['title'], $factsText), self::unknownWords((string)$cand['meta_description'], $factsText)));
        if (count($unknown) > self::MAX_UNKNOWN_WORDS) { $p[] = 'The title and description use too many words outside the plain vocabulary: ' . implode(', ', array_slice($unknown, 0, 8)) . '. Use simple everyday words and only the facts given.'; }
        $p = array_merge($p, self::nameProblems((string)$cand['title'], $factsText, true));
        $p = array_merge($p, self::nameProblems((string)$cand['meta_description'], $factsText, false));
        $p = array_merge($p, self::nameProblems((string)$cand['explanation'], $factsText, false));
        return array_values(array_unique($p));
    }

    // ---- drafting -------------------------------------------------------------------------------------

    /**
     * @return array{ok:bool,code:?string,error:?string,draft_id:?int,reused:bool,attempts:int,problems:string[],cost_usd:float,last_candidate:?array}
     */
    public function prepare(int $actionId, int $websiteId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $res = ['ok' => false, 'code' => null, 'error' => null, 'draft_id' => null, 'reused' => false, 'attempts' => 0, 'problems' => [], 'cost_usd' => 0.0, 'last_candidate' => null];
        try {
            $a = $this->actions->get($actionId);
            if (!$a)                          { return $this->fail($res, 'not_found', 'No such action'); }
            if ($a['status'] !== 'proposed')  { return $this->fail($res, 'not_open', 'The action is already ' . $a['status']); }
            if ($a['type'] !== 'project_story') { return $this->fail($res, 'unsupported', 'This is not a project story'); }
            $ev = json_decode((string)$a['evidence'], true);
            $pairId = (int)($ev[0]['pair_id'] ?? 0);
            if ($pairId <= 0)                 { return $this->fail($res, 'unsupported', 'This proposal does not name a photo pair'); }
            $w = $this->db->prepare("SELECT id FROM mia_websites WHERE id = ? AND site_id = ?");
            $w->execute([$websiteId, $this->siteId]);
            if ($w->fetchColumn() === false)  { return $this->fail($res, 'not_found', 'No such website'); }

            $pair = $this->pair($pairId);
            if (!$pair) { return $this->fail($res, 'gate', 'The photos are no longer approved with consent covered on a completed visit'); }

            $facts = self::facts($pair);
            foreach (['work', 'area'] as $k) {
                if ($facts[$k] !== '' && (count(preg_split('/\s+/', $facts[$k])) > 4 || !preg_match('/^[\p{L} &\'.-]+$/u', $facts[$k]))) { $facts[$k] = ''; }   // not a plain label: no model call
            }
            if ($facts['work'] === '' || $facts['area'] === '') { return $this->fail($res, 'thin', 'The photo pair does not say what work was done and where, so there is nothing to build a story on'); }
            $company = $this->companyName();
            $prohibited = $this->prohibited();
            $inputHash = sha1((string)json_encode([$facts, $company, $prohibited, MiaLlmGateway::MODEL, self::POLICY_VERSION]));

            $ex = $this->db->prepare("SELECT id, rationale FROM mia_content_drafts WHERE site_id = ? AND action_id = ? AND input_hash = ? AND status = 'draft' ORDER BY id DESC LIMIT 1");
            $ex->execute([$this->siteId, $actionId, $inputHash]);
            $existing = $ex->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ((int)$a['draft_ref_id'] !== (int)$existing['id'] || $a['draft_ref_table'] !== 'mia_content_drafts') {
                    $att = $this->actions->attachDraft($actionId, 'mia_content_drafts', (int)$existing['id'], $existing['rationale'] !== '' ? $existing['rationale'] : null, $now);
                    if (!$att['ok']) { return $this->fail($res, (string)$att['code'], 'The saved draft could not be attached'); }
                }
                $res['ok'] = true; $res['draft_id'] = (int)$existing['id']; $res['reused'] = true;
                return $res;
            }

            $problems = []; $out = null;
            for ($try = 0; $try <= self::REPAIR_ATTEMPTS; $try++) {
                $res['attempts']++;
                $r = $this->llm->call('story_write', $this->systemPrompt($company, $prohibited), $this->userPrompt($facts, $company, $problems), [
                    'schema' => self::SCHEMA, 'max_tokens' => 1500, 'known_facts' => self::factsText($facts, $company),
                ]);
                $res['cost_usd'] += (float)$r['cost_usd'];
                if (!$r['ok']) {
                    return $this->fail($res, (string)$r['code'], in_array($r['code'], ['cap_month', 'cap_day'], true) ? 'The AI limit has been reached' : 'The story could not be written');
                }
                $cand = ['title' => (string)$r['data']['title'], 'meta_description' => (string)$r['data']['meta_description'], 'explanation' => (string)$r['data']['explanation']];
                $problems = self::lint($cand, $facts, $company, $prohibited);
                $res['last_candidate'] = $cand;
                if (!$problems) { $out = $cand; break; }
            }
            if ($out === null) { $res['problems'] = $problems; return $this->fail($res, 'lint', 'The draft did not pass the checks, so no wording is attached'); }

            $unknown = array_unique(array_merge(self::unknownWords($out['title'], self::factsText($facts, $company)), self::unknownWords($out['meta_description'], self::factsText($facts, $company))));
            if ($unknown) {
                $out['explanation'] = rtrim($out['explanation']) . ' Check: these words did not come from your records: ' . implode(', ', array_slice($unknown, 0, 3)) . '. Edit them out if they are not true.';
            }
            $ver = $this->db->prepare("SELECT COALESCE(MAX(version), 0) FROM mia_content_drafts WHERE site_id = ? AND action_id = ?");
            $ver->execute([$this->siteId, $actionId]);
            $version = (int)$ver->fetchColumn() + 1;
            $own = !$this->db->inTransaction();
            if ($own) { $this->db->beginTransaction(); }
            try {
                $this->db->prepare("UPDATE mia_content_drafts SET status = 'superseded' WHERE site_id = ? AND action_id = ? AND status = 'draft'")->execute([$this->siteId, $actionId]);
                $this->db->prepare("INSERT INTO mia_content_drafts (site_id, website_id, action_id, kind, page_url, current_title, current_desc, title, meta_desc, rationale, input_hash, status, version, created_at)
                                    VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, 'draft', ?, ?)")
                    ->execute([$this->siteId, $websiteId, $actionId, self::KIND, 'portfolio:pair:' . $pairId, trim($out['title']), trim($out['meta_description']),
                               mb_substr(trim($out['explanation']), 0, 600), $inputHash, $version, $now->format('Y-m-d H:i:s')]);
                $id = (int)$this->db->lastInsertId();
                $att = $this->actions->attachDraft($actionId, 'mia_content_drafts', $id, trim($out['explanation']), $now);
                if (!$att['ok']) { throw new RuntimeException('could not attach: ' . $att['code']); }
                if ($own) { $this->db->commit(); }
            } catch (Throwable $e) {
                if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
                throw $e;
            }
            $res['ok'] = true; $res['draft_id'] = $id;
            return $res;
        } catch (Throwable $e) {
            error_log('MiaStoryWriter: ' . $e->getMessage());
            return $this->fail($res, 'error', 'The draft could not be saved');
        }
    }

    /** The pair, only if it is still usable (same gates the event used). */
    private function pair(int $pairId): ?array
    {
        $in = implode(',', array_fill(0, count(MiaFeeds::CONSENT_OK), '?'));
        $s = $this->db->prepare("SELECT p.id, p.service, p.area, p.category, v.completed_at
                                   FROM ba_pairs p JOIN job_visits v ON v.id = p.visit_id
                                  WHERE p.id = ? AND p.status = 'approved' AND p.consent_state IN ($in) AND v.status = 'completed'");
        $s->execute(array_merge([$pairId], MiaFeeds::CONSENT_OK));
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function systemPrompt(string $company, array $prohibited): string
    {
        return "You write a short project card for the portfolio page of a small local service business, and a one-line note to the owner.\n"
            . "Rules:\n"
            . "- Use only the facts supplied: the kind of work, the neighbourhood, the month and the business name. Never add a client, street, house number, price, crew name, tool, plant or result.\n"
            . "- Title: 20 to 60 characters, plain, saying what was done and where (the neighbourhood). Description: 110 to 155 characters, one or two plain sentences about the job.\n"
            . "- Explanation: at most 2 short sentences to the owner saying what the card says. No jargon.\n"
            . "- Warm and specific, never boastful: no exclamation marks, no capital-letter words, no superlatives, no promises, no numbers at all (write the month as a word, no year).\n"
            . "- Describe the work itself. Never mention the card, the portfolio, the website, photos or the owner inside the title or description.\n"
            . "- Do not name anyone. The only capitalised words allowed are the business name, the neighbourhood, the month, Vancouver and BC.\n"
            . ($prohibited ? "- Never use these terms: " . implode(', ', $prohibited) . ".\n" : '')
            . "Reply with JSON only: title, meta_description, explanation.";
    }

    private function userPrompt(array $facts, string $company, array $problems): string
    {
        $t = "Facts from our own records:\nBusiness: $company\nWork: {$facts['work']}\nNeighbourhood: {$facts['area']}\nMonth: {$facts['month']}\n";
        if ($problems) { $t .= "\nYour previous attempt was rejected for these reasons. Fix every one:\n- " . implode("\n- ", $problems) . "\n"; }
        return $t;
    }

    /** The name the business uses for itself in text: the owner's remembered short name (brand memory 'short_name'), else the business record. */
    private function companyName(): string
    {
        $short = $this->memory->recall('brand', 'short_name');
        if (is_string($short) && trim($short) !== '') {
            $clean = trim((string)preg_replace('/[^\p{L}\p{N} &\'.,-]+/u', '', $short));
            if ($clean !== '') { return mb_substr($clean, 0, 60); }
        }
        try {
            $n = $this->db->query("SELECT company_name FROM business_settings WHERE id = 1")->fetchColumn();
            if (is_string($n) && trim($n) !== '') {
                $clean = trim((string)preg_replace('/[^\p{L}\p{N} &\'.,-]+/u', '', $n));
                if ($clean !== '') { return mb_substr($clean, 0, 60); }
            }
        } catch (Throwable $e) { /* not available */ }
        return 'the business';
    }

    private function prohibited(): array
    {
        $t = [];
        foreach ($this->memory->all('prohibited') as $k => $v) {
            foreach (array_merge([$k], explode(',', (string)$v)) as $x) {
                $x = trim((string)preg_replace('/\s+/u', ' ', mb_strtolower((string)$x, 'UTF-8')));
                if (mb_strlen($x, 'UTF-8') >= 3) { $t[$x] = true; }
            }
        }
        return array_keys($t);
    }

    private function fail(array $res, string $code, string $error): array
    {
        $res['code'] = $code; $res['error'] = $error;
        return $res;
    }
}
