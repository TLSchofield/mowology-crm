<?php
/**
 * MiaPageWriter — drafts a whole new web page for a search the business does not yet have a page for (Phase 2 page generation,
 * 2026-10-26). Facts only: the page is written from the business's own service list, its descriptions and its places, and from
 * nothing else.
 *
 * What the model is given: the search words, the business's short name and contact details, the services that match the search (name
 * and the owner's own description), the other services' names, and the places the business works in. What it writes: a title, a search
 * description, the page text in MiaPageMarkup, and a one-line note to the owner. What it is NOT asked for: a case study. The real
 * projects that match the search are added by MiaPageExtras straight from the portfolio, word for word. If no service matches the search
 * Mia refuses, because there would be nothing true to say.
 *
 * The draft is then checked in PHP, not trusted: the shared wording rules for title and description, plus for the body: no claim that
 * cannot be checked, no number that is not in the facts, no web or email address, no phone number that is not the business's, no
 * capitalised name that is not in the facts, no promise, no filler phrase, and a minimum shape (several headed sections and an FAQ).
 * One repair attempt with the exact problems, then nothing is attached.
 *
 * It runs only when the owner asks ("Write this page"): one model call, no schedule. Output goes into mia_content_drafts as kind 'page'
 * (body column from migration 1405). Global namespace, no autoloader. PHP 7.4-compatible. Never throws: returns result arrays.
 */
declare(strict_types=1);

require_once __DIR__ . '/MiaLlmGateway.php';
require_once __DIR__ . '/MiaActionService.php';
require_once __DIR__ . '/MiaMemoryService.php';
require_once __DIR__ . '/MiaRecommendationWriter.php';
require_once __DIR__ . '/MiaStoryWriter.php';
require_once __DIR__ . '/MiaStoryPublisher.php';
require_once __DIR__ . '/MiaPageMarkup.php';
require_once __DIR__ . '/MiaPageExtras.php';
require_once __DIR__ . '/../../../Services/Copy/DraftSafety.php';

final class MiaPageWriter
{
    public const KIND = 'page';
    public const REPAIR_ATTEMPTS = 1;
    public const POLICY_VERSION = 1;
    public const MIN_WORDS = 350;
    public const MAX_WORDS = 1100;

    /** Filler that makes a page read as machine-written. Same list as ArticleGeneratorService. */
    private const FILLER = ["in today's digital landscape", "it's important to note", 'at the end of the day', 'dive into', 'delve into', 'in conclusion', 'in summary',
        'to summarize', 'it is worth noting', 'needless to say', 'without further ado', 'as we navigate', 'in the realm of', 'game-changer', 'leverage', 'utilize', 'paradigm',
        'holistic approach', 'synergy'];

    private const SCHEMA = [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['title', 'meta_description', 'body', 'explanation'],
        'properties' => [
            'title'            => ['type' => 'string', 'minLength' => 10, 'maxLength' => 120],
            'meta_description' => ['type' => 'string', 'minLength' => 40, 'maxLength' => 300],
            'body'             => ['type' => 'string', 'minLength' => 1500, 'maxLength' => 11000],
            'explanation'      => ['type' => 'string', 'minLength' => 20, 'maxLength' => 700],
        ],
    ];

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
        if ($siteId <= 0) { throw new InvalidArgumentException('MiaPageWriter needs a site'); }
        $this->db = $db; $this->siteId = $siteId; $this->llm = $llm; $this->actions = $actions;
        $this->memory = new MiaMemoryService($db, $siteId);
    }

    /** Is this proposal a request for a NEW page (a search with no page), not a change to an existing one? */
    public static function wantsNewPage(array $action): bool
    {
        if (($action['type'] ?? '') !== 'page_draft') { return false; }
        $ev = json_decode((string)($action['evidence'] ?? ''), true);
        $e0 = is_array($ev) && isset($ev[0]) && is_array($ev[0]) ? $ev[0] : [];
        return !empty($e0['query']) && empty($e0['page']);
    }

    // ---- facts --------------------------------------------------------------------------------------

    /**
     * @return array{business:string, phone:string, email:string, query:string, matched:array, others:string[], places:string[]}
     */
    public function facts(string $query): array
    {
        $qt = MiaPageExtras::tokens($query);
        $matched = []; $others = [];
        try {
            foreach ($this->db->query("SELECT service_type, package_name, description FROM service_packages WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $name = trim((string)$r['package_name']);
                $desc = trim(preg_replace('/\s+/', ' ', strip_tags((string)$r['description'])) ?? '');
                $hay = $name . ' ' . str_replace('_', ' ', (string)$r['service_type']) . ' ' . $desc;
                if (MiaPageExtras::overlaps($qt, $hay)) { $matched[] = ['name' => mb_substr($name, 0, 80), 'description' => mb_substr($desc, 0, 300)]; }
                else { $others[$name] = true; }
            }
        } catch (Throwable $e) { /* no catalogue: nothing matches */ }
        $places = [];
        foreach (["SELECT DISTINCT location v FROM portfolio_projects WHERE status = 'published' AND location IS NOT NULL AND location <> '' LIMIT 8",
                  "SELECT DISTINCT area v FROM ba_pairs WHERE status = 'approved' AND area IS NOT NULL AND area <> '' LIMIT 8"] as $sql) {
            try { foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $v) { if (MiaBundleServiceSafe::place((string)$v)) { $places[self::cleanPlace((string)$v)] = true; } } } catch (Throwable $e) { /* table missing */ }
        }
        $bs = ['company_name' => '', 'phone' => '', 'email' => ''];
        try { $r = $this->db->query("SELECT company_name, phone, email FROM business_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC); if ($r) { $bs = array_merge($bs, $r); } } catch (Throwable $e) { /* none */ }
        $short = $this->memory->recall('brand', 'short_name');
        $business = is_string($short) && trim($short) !== '' ? trim($short) : trim((string)$bs['company_name']);
        return ['business' => mb_substr((string)preg_replace('/[^\p{L}\p{N} &\'.,-]+/u', '', $business), 0, 60), 'phone' => trim((string)$bs['phone']), 'email' => trim((string)$bs['email']),
                'query' => mb_substr(trim($query), 0, 120), 'matched' => array_slice($matched, 0, 6), 'others' => array_slice(array_keys($others), 0, 12), 'places' => array_slice(array_keys($places), 0, 8)];
    }

    /** "Point Grey Vancouver BC" -> "Point Grey": the city and province are said once on the page, not after every neighbourhood. */
    public static function cleanPlace(string $s): string
    {
        $t = trim((string)preg_replace('/[,\s]+(Vancouver)?[,\s]*(BC|B\.C\.|British Columbia)?\s*$/iu', '', trim($s)));
        return $t !== '' ? $t : trim($s);
    }

    /** Everything the checks may treat as "given". */
    public static function factsText(array $f): string
    {
        $t = 'Business: ' . $f['business'] . '. Phone: ' . $f['phone'] . '. Email: ' . $f['email'] . '. Search: ' . $f['query'] . '.';
        foreach ($f['matched'] as $m) { $t .= ' Service: ' . $m['name'] . ' - ' . $m['description'] . '.'; }
        if ($f['others']) { $t .= ' Other services: ' . implode(', ', $f['others']) . '.'; }
        if ($f['places']) { $t .= ' Places worked in: ' . implode(', ', $f['places']) . '.'; }
        return $t;
    }

    // ---- the checks ---------------------------------------------------------------------------------

    /**
     * @param array{title:string,meta_description:string,body:string,explanation:string} $out
     * @return string[] problems; empty = acceptable
     */
    public static function lint(array $out, array $facts, array $prohibited): array
    {
        $factsText = self::factsText($facts);
        $ctx = ['current_title' => null, 'current_desc' => null, 'allowed_numbers' => MiaRecommendationWriter::numbers($factsText), 'public_facts' => $factsText,
                'prohibited' => $prohibited, 'known_facts' => $factsText];
        $p = MiaRecommendationWriter::lint(['title' => $out['title'], 'meta_description' => $out['meta_description'], 'explanation' => $out['explanation']], $ctx);

        $body = MiaPageMarkup::normalise((string)$out['body']);
        foreach (MiaPageMarkup::problems($body) as $x) { $p[] = $x; }
        $words = MiaPageMarkup::wordCount($body);
        if ($words < self::MIN_WORDS) { $p[] = "The page has $words words (at least " . self::MIN_WORDS . ' are needed)'; }
        if ($words > self::MAX_WORDS) { $p[] = "The page has $words words (at most " . self::MAX_WORDS . ' are allowed)'; }
        preg_match_all('/^##\s+(.+)$/mu', $body, $h2);
        $faqHeads = array_filter($h2[1], function ($h) { return (bool)preg_match('/question/i', $h); });
        if (count($h2[1]) - count($faqHeads) < 3) { $p[] = 'The page needs at least three headed sections (lines starting with ##)'; }
        if (!$faqHeads) { $p[] = 'The page needs a section headed "Frequently asked questions"'; }
        if (preg_match_all('/^###\s+.+$/mu', $body) < 3) { $p[] = 'The questions section needs at least three questions (lines starting with ###)'; }
        if (preg_match('/^#\s/m', $body)) { $p[] = 'Do not use a single # heading (the page title is separate)'; }

        $n = MiaActionService::normalizeForChecks($body);
        if (preg_match('/[!\x{FF01}]/u', $n)) { $p[] = 'The page contains an exclamation mark'; }
        if (preg_match_all(MiaRecommendationWriter::CLAIMS, $n, $m)) { $p[] = 'The page makes claims that cannot be checked: ' . implode(', ', array_slice(array_unique(array_map('strtolower', $m[0])), 0, 4)); }
        if (preg_match_all(MiaRecommendationWriter::SOFT_CLAIMS, $n, $mm)) {
            foreach (array_unique(array_map('strtolower', $mm[0])) as $c) { if (stripos($factsText, $c) === false) { $p[] = "The page says \"$c\", which is not in the facts given"; } }
        }
        if (MiaActionService::benefitProblems($body)) { $p[] = 'The page promises a result'; }
        $lower = mb_strtolower($body, 'UTF-8');
        foreach (self::FILLER as $f) { if (strpos($lower, $f) !== false) { $p[] = "The page uses a filler phrase: \"$f\""; } }
        $p = array_merge($p, MiaRecommendationWriter::groundingProblems($n, MiaRecommendationWriter::numbers($factsText)));
        $flags = DraftSafety::flags($n, $factsText);
        foreach (['email' => 'an email address', 'link' => 'a web address', 'bank' => 'payment details'] as $k => $label) { if (!empty($flags[$k])) { $p[] = "The page contains $label that is not in the facts given"; } }
        if (preg_match_all('/(?<!\d)(?:\+?1[-. ]?)?\(?\d{3}\)?[-. ]?\d{3}[-. ]?\d{4}(?!\d)/', $n, $pm)) {
            $own = preg_replace('/\D/', '', $facts['phone']);
            foreach ($pm[0] as $ph) { $d = preg_replace('/\D/', '', $ph); if (strlen($d) === 11 && $d[0] === '1') { $d = substr($d, 1); } if ($d !== substr((string)$own, -10)) { $p[] = 'The page contains a phone number that is not the business\'s'; } }
        }
        foreach ($prohibited as $term) { if (strpos(' ' . mb_strtolower($body, 'UTF-8') . ' ', ' ' . $term . ' ') !== false) { $p[] = "The page uses a term the owner ruled out: \"$term\""; } }
        $p = array_merge($p, MiaStoryWriter::nameProblems((string)preg_replace('/^(#{2,3}|[-*])\s+/m', '', $body), $factsText, false, false));
        $q = mb_strtolower($facts['query'], 'UTF-8');
        if ($q !== '' && substr_count($lower, $q) > 6) { $p[] = 'The search words are repeated too often'; }
        return array_values(array_unique($p));
    }

    // ---- drafting -----------------------------------------------------------------------------------

    /** @return array{ok:bool,code:?string,error:?string,draft_id:?int,reused:bool,attempts:int,problems:string[],cost_usd:float,last_candidate:?array} */
    public function prepare(int $actionId, int $websiteId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $res = ['ok' => false, 'code' => null, 'error' => null, 'draft_id' => null, 'reused' => false, 'attempts' => 0, 'problems' => [], 'cost_usd' => 0.0, 'last_candidate' => null];
        try {
            $a = $this->actions->get($actionId);
            if (!$a)                         { return $this->fail($res, 'not_found', 'No such action'); }
            if ($a['status'] !== 'proposed') { return $this->fail($res, 'not_open', 'The action is already ' . $a['status']); }
            if (!self::wantsNewPage($a))     { return $this->fail($res, 'unsupported', 'Mia only writes new pages for a search that has no page yet'); }
            if (!$this->bodyColumn())        { return $this->fail($res, 'not_ready', 'Writing pages needs migration 1405, which has not been run yet.'); }
            $w = $this->db->prepare("SELECT id FROM mia_websites WHERE id = ? AND site_id = ?");
            $w->execute([$websiteId, $this->siteId]);
            if ($w->fetchColumn() === false) { return $this->fail($res, 'not_found', 'No such website'); }
            $ev = json_decode((string)$a['evidence'], true);
            $query = (string)$ev[0]['query'];

            $facts = $this->facts($query);
            if (!$facts['matched'])    { return $this->fail($res, 'no_match', 'None of your services match this search, so Mia has nothing true to write about it.'); }
            if ($facts['business'] === '') { return $this->fail($res, 'thin', 'The business name is missing from the business record.'); }
            $prohibited = $this->prohibited();
            $inputHash = sha1((string)json_encode([$facts, $prohibited, MiaLlmGateway::MODEL, self::POLICY_VERSION]));

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

            $g = $this->generate($facts, $prohibited);
            $res['attempts'] = $g['attempts']; $res['cost_usd'] = $g['cost_usd']; $res['last_candidate'] = $g['last'];
            if (!empty($g['code'])) { return $this->fail($res, (string)$g['code'], in_array($g['code'], ['cap_month', 'cap_day'], true) ? 'The AI limit has been reached' : 'The page could not be written'); }
            $out = $g['out']; $problems = $g['problems'];
            if ($out === null) { $res['problems'] = $problems; return $this->fail($res, 'lint', 'The draft did not pass the checks, so no page is attached'); }

            $ver = $this->db->prepare("SELECT COALESCE(MAX(version), 0) FROM mia_content_drafts WHERE site_id = ? AND action_id = ?");
            $ver->execute([$this->siteId, $actionId]);
            $version = (int)$ver->fetchColumn() + 1;
            $own = !$this->db->inTransaction();
            if ($own) { $this->db->beginTransaction(); }
            try {
                $this->db->prepare("UPDATE mia_content_drafts SET status = 'superseded' WHERE site_id = ? AND action_id = ? AND status = 'draft'")->execute([$this->siteId, $actionId]);
                $this->db->prepare("INSERT INTO mia_content_drafts (site_id, website_id, action_id, kind, page_url, current_title, current_desc, title, meta_desc, body, rationale, input_hash, status, version, created_at)
                                    VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, 'draft', ?, ?)")
                    ->execute([$this->siteId, $websiteId, $actionId, self::KIND, 'page:query:' . sha1(mb_strtolower($query)), trim($out['title']), trim($out['meta_description']), $out['body'],
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
            error_log('MiaPageWriter: ' . $e->getMessage());
            return $this->fail($res, 'error', 'The draft could not be saved');
        }
    }

    /**
     * The model call and the checks, with no saving: one try plus one repair. Also what a rolled-back production run uses.
     * @return array{out:?array, problems:string[], attempts:int, cost_usd:float, last:?array, code:?string}
     */
    public function generate(array $facts, array $prohibited): array
    {
        $g = ['out' => null, 'problems' => [], 'attempts' => 0, 'cost_usd' => 0.0, 'last' => null, 'code' => null];
        for ($try = 0; $try <= self::REPAIR_ATTEMPTS; $try++) {
            $g['attempts']++;
            $r = $this->llm->call('page_write', $this->systemPrompt($facts, $prohibited), $this->userPrompt($facts, $g['problems']), ['schema' => self::SCHEMA, 'max_tokens' => 6000, 'known_facts' => self::factsText($facts)]);
            $g['cost_usd'] += (float)$r['cost_usd'];
            if (!$r['ok']) { $g['code'] = (string)$r['code']; return $g; }
            $cand = ['title' => (string)$r['data']['title'], 'meta_description' => (string)$r['data']['meta_description'], 'body' => MiaPageMarkup::normalise((string)$r['data']['body']), 'explanation' => (string)$r['data']['explanation']];
            $g['last'] = $cand;
            $g['problems'] = self::lint($cand, $facts, $prohibited);
            if (!$g['problems']) { $g['out'] = $cand; return $g; }
        }
        return $g;
    }

    private function bodyColumn(): bool
    {
        try { $this->db->query("SELECT body FROM mia_content_drafts LIMIT 0"); return true; } catch (Throwable $e) { return false; }
    }

    private function systemPrompt(array $f, array $prohibited): string
    {
        return "You write ONE web page for a small local service business (named in the facts), for people who searched for the phrase in the facts. You also write its title, a search description, and a one-line note to the owner.\n"
            . "Rules:\n"
            . "- Use only the facts supplied. Describe the matching services in plain words using their descriptions. Never invent a service, price, number, duration, year, guarantee, award, certification, client, project, neighbourhood or result. If a fact is not given, leave it out.\n"
            . "- Do NOT write a case study or mention any specific project or client: the website adds the business's real recent projects after your text.\n"
            . "- Write in a warm, plain, confident voice, as the business talking to a homeowner or property manager. Short paragraphs. No hype, no superlatives, no exclamation marks, no promises about results.\n"
            . "- Do not name anyone. The only capitalised words allowed are the business name, the places and services in the facts, and the first word of a sentence.\n"
            . "- No web addresses, email addresses or phone numbers except the business's own if you mention them.\n"
            . "- Title: 20 to 60 characters. Search description: 110 to 155 characters. Note to the owner: one or two short sentences.\n"
            . "- Body format (plain text only, no HTML): an opening paragraph with no heading, then three to five sections each starting with a line '## Heading', then a last section '## Frequently asked questions' with four questions, each as a line '### Question' followed by a short answer paragraph. A bullet list may use lines starting '- '. Between 450 and 900 words in total.\n"
            . "- Use the search phrase naturally a few times, never stuffed.\n"
            . "- The page ends with the last FAQ answer: write nothing after it.\n"
            . ($prohibited ? "- Never use these terms: " . implode(', ', $prohibited) . ".\n" : '')
            . "Reply with JSON only: title, meta_description, body, explanation.";
    }

    private function userPrompt(array $f, array $problems): string
    {
        $t = "Facts from the business's own records:\nBusiness: {$f['business']}\n";
        if ($f['phone'] !== '') { $t .= "Phone: {$f['phone']}\n"; }
        if ($f['email'] !== '') { $t .= "Email: {$f['email']}\n"; }
        $t .= "People searched for: {$f['query']}\nServices that match the search:\n";
        foreach ($f['matched'] as $m) { $t .= '- ' . $m['name'] . ': ' . $m['description'] . "\n"; }
        if ($f['others']) { $t .= 'Other services offered: ' . implode(', ', $f['others']) . "\n"; }
        if ($f['places']) { $t .= 'Places the business has worked in: ' . implode(', ', $f['places']) . "\n"; }
        if ($problems) { $t .= "\nYour previous attempt was rejected for these reasons. Fix every one:\n- " . implode("\n- ", $problems) . "\n"; }
        return $t;
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

    private function fail(array $res, string $code, string $error): array { $res['code'] = $code; $res['error'] = $error; return $res; }
}

/** Tiny shared check so the page writer and the bundle agree on what a "place" is. */
final class MiaBundleServiceSafe
{
    public static function place(string $s): bool
    {
        $s = trim($s);
        return $s !== '' && (bool)preg_match('/^[\p{L}][\p{L} .\'&,-]{1,79}$/u', $s);
    }
}
