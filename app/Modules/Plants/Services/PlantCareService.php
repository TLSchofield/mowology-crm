<?php
declare(strict_types=1);

require_once __DIR__ . '/GbifNames.php';
require_once __DIR__ . '/PlantLibrarySeed.php';

/**
 * PlantCareService — the plant care library (2026-10-15, migration 1368, plan step 4).
 *
 * Tim: "build a library that we match against" — standard Vancouver landscape plants first
 * (PlantLibrarySeed), then anything crews identify. Each card: the jobs a landscaper schedules with
 * month windows for the coast (zone 8b), pet toxicity, sun, soil, size — written by Claude in its own
 * words (never copied) from TRUSTED gardening sources found by web search, links kept. Cards are
 * drafted in bulk through Anthropic's Message Batches (half the token price), reviewed and approved
 * by the owner once, then matched to every plant by its GBIF accepted species id (any name finds it),
 * or by genus for a "general" card. A card is never shown as care until a person approved it.
 */
class PlantCareService
{
    public const API_URL = 'https://api.anthropic.com/v1/messages';
    public const BATCH_URL = 'https://api.anthropic.com/v1/messages/batches';
    public const MODEL = 'claude-sonnet-5-5';
    public const CLIMATE = 'pnw-coastal';
    public const MAX_SEARCHES = 4;
    public const DAILY_CAP = 25;                       // one-at-a-time lookups (crew finds); batches are separate
    public const PRICE_IN = 3.0, PRICE_OUT = 15.0, PRICE_SEARCH = 0.01;
    public const GROUPS = ['hedge' => 'Hedges', 'shrub' => 'Shrubs', 'perennial' => 'Perennials and ferns', 'grass' => 'Grasses and bamboo',
                           'groundcover' => 'Groundcovers', 'tree' => 'Trees', 'bulb' => 'Bulbs', 'vine' => 'Climbers', 'annual' => 'Annuals',
                           'weed' => 'Weeds and invasives', '' => 'Other'];
    public const TRUSTED = [
        'rhs.org.uk', 'missouribotanicalgarden.org', 'botanicalgarden.ubc.ca', 'extension.wsu.edu',
        'extension.oregonstate.edu', 'plants.ces.ncsu.edu', 'extension.umn.edu', 'aspca.org', 'gardenia.net',
        // Weeds: BC's invasive / noxious lists and local control guidance.
        'bcinvasives.ca', 'gov.bc.ca', 'metrovancouver.org', 'vancouver.ca',
    ];
    public const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    private PDO $db;
    /** @var callable|null fn(string $method, string $url, ?array $body): array{code: int, body: string} */
    private $http;
    private GbifNames $names;

    public function __construct(PDO $db, ?callable $http = null, ?GbifNames $names = null)
    {
        $this->db = $db;
        $this->http = $http;
        $this->names = $names ?? new GbifNames($db);
    }

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM plant_care_cards LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function hasKey(): bool
    {
        return $this->http !== null || (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '');
    }

    // ── Matching ───────────────────────────────────────────────────────────

    /**
     * The card for a plant: its species (by GBIF accepted id, then by name), else its genus's general card.
     * @return ?array the card + 'match' => 'species' | 'genus'
     */
    public function forPlant(string $scientific): ?array
    {
        $scientific = trim($scientific);
        if ($scientific === '' || !$this->ready()) return null;
        $g = $this->names->resolve($scientific);
        $row = null;
        if ($g['gbif_key']) {
            $s = $this->db->prepare("SELECT * FROM plant_care_cards WHERE gbif_key = ? AND climate = ? AND level = 'species' AND status <> 'rejected' LIMIT 1");
            $s->execute([$g['gbif_key'], self::CLIMATE]);
            $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$row) {
            $s = $this->db->prepare("SELECT * FROM plant_care_cards WHERE scientific_name = ? AND climate = ? AND level = 'species' AND status <> 'rejected' LIMIT 1");
            $s->execute([$g['accepted_name'] ?: $scientific, self::CLIMATE]);
            $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($row) return self::shape($row) + ['match' => 'species'];
        $genus = $g['genus'] ?: GbifNames::genusOf($scientific);
        if (!$genus) return null;
        $s = $this->db->prepare("SELECT * FROM plant_care_cards WHERE genus = ? AND climate = ? AND level = 'genus' AND status <> 'rejected' LIMIT 1");
        $s->execute([$genus, self::CLIMATE]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ? self::shape($row) + ['match' => 'genus'] : null;
    }

    public function byId(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM plant_care_cards WHERE id = ?');
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? self::shape($r) : null;
    }

    public static function shape(array $r): array
    {
        $r['card'] = json_decode((string)($r['card'] ?? ''), true) ?: [];
        $r['sources'] = json_decode((string)($r['sources'] ?? ''), true) ?: [];
        return $r;
    }

    // ── Building the library ───────────────────────────────────────────────

    /**
     * Queue a plant for a card (no Claude call yet). Species names are GBIF-resolved first, so an old
     * name lands on the accepted species and isn't queued twice. @return int|null the card id
     */
    public function queue(string $name, string $common, string $group, string $level = 'species', string $origin = 'library'): ?int
    {
        $name = trim($name);
        if ($name === '' || !$this->ready()) return null;
        $level = $level === 'genus' ? 'genus' : 'species';
        $key = null; $genus = GbifNames::genusOf($name);
        if ($level === 'species') {
            $g = $this->names->resolve($name);
            $key = $g['gbif_key'];
            $genus = $g['genus'] ?: $genus;
            if ($g['accepted_name']) $name = $g['accepted_name'];
            if ($key) {
                $s = $this->db->prepare("SELECT id FROM plant_care_cards WHERE gbif_key = ? AND climate = ? AND level = 'species'");
                $s->execute([$key, self::CLIMATE]);
                if ($id = $s->fetchColumn()) return (int)$id;
            }
        }
        $find = $this->db->prepare('SELECT id FROM plant_care_cards WHERE scientific_name = ? AND climate = ? AND level = ?');
        $find->execute([mb_substr($name, 0, 160), self::CLIMATE, $level]);
        if ($id = $find->fetchColumn()) return (int)$id;
        $now = date('Y-m-d H:i:s');
        try {
            $this->db->prepare("INSERT INTO plant_care_cards (scientific_name, climate, level, gbif_key, genus, plant_group, common_name, status, origin, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, 'queued', ?, ?, ?)")
               ->execute([mb_substr($name, 0, 160), self::CLIMATE, $level, $key, $genus, $group ?: null, $common ?: null, $origin, $now, $now]);
        } catch (PDOException $e) { /* queued by someone else at the same moment (unique key) */ }
        $find->execute([mb_substr($name, 0, 160), self::CLIMATE, $level]);
        return ($id = $find->fetchColumn()) ? (int)$id : null;
    }

    /** Queue the whole starter list. @return array{queued: int, unresolved: string[]} */
    public function seed(): array
    {
        $n = 0; $unresolved = [];
        foreach (PlantLibrarySeed::PLANTS as [$name, $common, $group, $level]) {
            $id = $this->queue($name, $common, $group, $level, 'library');
            if ($id) $n++;
            if ($level === 'species' && !$this->names->resolve($name)['gbif_key']) $unresolved[] = $name;
        }
        return ['queued' => $n, 'unresolved' => $unresolved];
    }

    /** Send every queued card (not already in a batch) to Anthropic as one message batch. */
    public function submitBatch(int $max = 200): array
    {
        if (!$this->hasKey()) return ['ok' => false, 'message' => 'No Anthropic key set up.'];
        // Claim the rows first (a unique marker), so two clicks at once can't buy the same cards twice.
        $claim = 'claim-' . bin2hex(random_bytes(8));
        $want = $this->db->query("SELECT id FROM plant_care_cards WHERE status = 'queued' AND batch_id IS NULL ORDER BY id LIMIT " . max(1, min(1000, $max)))->fetchAll(PDO::FETCH_COLUMN);
        if ($want) {
            $this->db->prepare("UPDATE plant_care_cards SET batch_id = ? WHERE batch_id IS NULL AND status = 'queued' AND id IN (" . implode(',', array_map('intval', $want)) . ")")
                     ->execute([$claim]);   // only rows still unclaimed become ours
        }
        $s = $this->db->prepare("SELECT id, scientific_name, common_name, level, plant_group FROM plant_care_cards WHERE batch_id = ? ORDER BY id");
        $s->execute([$claim]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['ok' => true, 'sent' => 0, 'message' => 'Nothing waiting.'];
        $requests = array_map(fn($r) => ['custom_id' => 'pcc-' . (int)$r['id'],
                                         'params' => self::request((string)$r['scientific_name'], (string)$r['common_name'], (string)$r['level'], (string)($r['plant_group'] ?? ''))], $rows);
        $res = $this->call('POST', self::BATCH_URL, ['requests' => $requests]);
        $j = json_decode($res['body'], true) ?: [];
        if ($res['code'] !== 200 || empty($j['id'])) {
            $this->db->prepare("UPDATE plant_care_cards SET batch_id = NULL WHERE batch_id = ?")->execute([$claim]);
            error_log('Plant care batch: HTTP ' . $res['code'] . ' ' . substr($res['body'], 0, 300));
            return ['ok' => false, 'message' => 'Anthropic did not take the batch (' . $res['code'] . ').'];
        }
        $this->db->prepare("UPDATE plant_care_cards SET batch_id = ?, updated_at = ? WHERE batch_id = ?")->execute([$j['id'], date('Y-m-d H:i:s'), $claim]);
        return ['ok' => true, 'sent' => count($rows), 'batch_id' => $j['id'], 'message' => count($rows) . ' plants sent — cards are usually ready within the hour.'];
    }

    /** Collect finished batches: each answer becomes a draft card (or 'failed', to retry). */
    public function collect(): array
    {
        if (!$this->ready() || !$this->hasKey()) return ['drafted' => 0, 'failed' => 0, 'pending' => 0];
        $ids = $this->db->query("SELECT DISTINCT batch_id FROM plant_care_cards WHERE status = 'queued' AND batch_id IS NOT NULL AND batch_id NOT LIKE 'claim-%'")->fetchAll(PDO::FETCH_COLUMN);
        $drafted = 0; $failed = 0; $pending = 0;
        foreach ($ids as $bid) {
            $st = json_decode($this->call('GET', self::BATCH_URL . '/' . rawurlencode((string)$bid), null)['body'], true) ?: [];
            if (($st['processing_status'] ?? '') !== 'ended' || empty($st['results_url'])) { $pending++; continue; }
            $res = $this->call('GET', (string)$st['results_url'], null);
            if ($res['code'] !== 200) { $pending++; continue; }
            foreach (preg_split('/\r?\n/', trim($res['body'])) as $line) {
                $r = json_decode($line, true);
                if (!$r || !preg_match('/^pcc-(\d+)$/', (string)($r['custom_id'] ?? ''), $m)) continue;
                $ok = ($r['result']['type'] ?? '') === 'succeeded' && $this->store((int)$m[1], $r['result']['message'] ?? [], 0.5, (string)$bid);
                if ($ok) { $drafted++; } else {
                    $this->db->prepare("UPDATE plant_care_cards SET status = 'failed', updated_at = ? WHERE id = ? AND status = 'queued'")->execute([date('Y-m-d H:i:s'), (int)$m[1]]);
                    $failed++;
                }
            }
            // The batch is over: any of its cards the results didn't cover failed (retry puts them back).
            $miss = $this->db->prepare("UPDATE plant_care_cards SET status = 'failed', updated_at = ? WHERE batch_id = ? AND status = 'queued'");
            $miss->execute([date('Y-m-d H:i:s'), $bid]);
            $failed += $miss->rowCount();
        }
        return ['drafted' => $drafted, 'failed' => $failed, 'pending' => $pending];
    }

    /** Put failed cards back in the queue for the next batch. */
    public function retryFailed(): int
    {
        $s = $this->db->prepare("UPDATE plant_care_cards SET status = 'queued', batch_id = NULL, updated_at = ? WHERE status = 'failed'");
        $s->execute([date('Y-m-d H:i:s')]);
        return $s->rowCount();
    }

    /**
     * A plant a crew found that isn't in the library: queue it and draft it now (one call, ~30–60 s).
     * An existing species card (any status but rejected) is returned as it is.
     */
    public function draftNow(string $scientific, string $common, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Care cards need migration 1368.'];
        if (!$this->hasKey()) return ['ok' => false, 'message' => 'No Anthropic key set up.'];
        $have = $this->forPlant($scientific);
        if ($have && $have['match'] === 'species' && $have['status'] !== 'queued' && $have['status'] !== 'failed') return ['ok' => true, 'card' => $have, 'message' => 'Already in the library.'];
        // Every attempt counts (drafted_at is stamped when the card is claimed), failures included.
        $s = $this->db->prepare("SELECT COUNT(*) FROM plant_care_cards WHERE drafted_at >= ? AND batch_id IS NULL");
        $s->execute([date('Y-m-d 00:00:00')]);
        if ((int)$s->fetchColumn() >= self::DAILY_CAP) return ['ok' => false, 'message' => "That's today's care lookups used up — try tomorrow."];
        $id = $this->queue($scientific, $common, '', 'species', 'identified');
        if (!$id) return ['ok' => false, 'message' => 'Could not add that plant to the library.'];
        // Claim it: one lookup per card at a time (a 'writing' card stuck over 10 minutes may be claimed again).
        $now = date('Y-m-d H:i:s');
        $c = $this->db->prepare("UPDATE plant_care_cards SET status = 'writing', drafted_at = ?, drafted_by = ?, updated_at = ?
                                 WHERE id = ? AND batch_id IS NULL AND (status IN ('queued', 'failed') OR (status = 'writing' AND updated_at < ?))");
        $c->execute([$now, $userId ?: null, $now, $id, date('Y-m-d H:i:s', time() - 600)]);
        if ($c->rowCount() === 0) return ['ok' => false, 'message' => 'That card is already being written — check the plant library in a minute.'];
        $card = $this->byId($id);
        $body = self::request((string)$card['scientific_name'], $common, 'species', (string)($card['plant_group'] ?? ''));
        $msg = null;
        for ($i = 0; $i < 3; $i++) {   // a long search turn can pause; send it back to finish
            $res = $this->call('POST', self::API_URL, $body);
            if ($res['code'] !== 200) {
                error_log('Plant care: Claude HTTP ' . $res['code'] . ' ' . substr($res['body'], 0, 300));
                $this->db->prepare("UPDATE plant_care_cards SET status = 'failed', updated_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $id]);
                return ['ok' => false, 'message' => "Couldn't look that up just now (" . $res['code'] . '). Try again in a minute.'];
            }
            $msg = self::merge($msg, json_decode($res['body'], true) ?: []);
            if (($msg['stop_reason'] ?? '') !== 'pause_turn') break;
            $body['messages'] = [$body['messages'][0], ['role' => 'assistant', 'content' => $msg['content']]];
        }
        if (!$this->store($id, $msg ?: [], 1.0, null, $userId)) {
            $this->db->prepare("UPDATE plant_care_cards SET status = 'failed', updated_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $id]);
            return ['ok' => false, 'message' => "I couldn't put a care card together for that plant from the sources I trust."];
        }
        return ['ok' => true, 'card' => $this->byId($id), 'message' => 'Care card drafted — check it, then approve.'];
    }

    /** Fold a continued (pause_turn) answer into the first: content appended, usage summed. */
    private static function merge(?array $a, array $b): array
    {
        if (!$a) return $b;
        $a['content'] = array_merge($a['content'] ?? [], $b['content'] ?? []);
        foreach (['input_tokens', 'output_tokens'] as $k) $a['usage'][$k] = (int)($a['usage'][$k] ?? 0) + (int)($b['usage'][$k] ?? 0);
        $a['usage']['server_tool_use']['web_search_requests'] = (int)($a['usage']['server_tool_use']['web_search_requests'] ?? 0) + (int)($b['usage']['server_tool_use']['web_search_requests'] ?? 0);
        $a['stop_reason'] = $b['stop_reason'] ?? null;
        return $a;
    }

    /** Claude's answer → a draft card on row $id. $tokenRate 0.5 for batches. */
    private function store(int $id, array $msg, float $tokenRate, ?string $batchId, ?int $userId = null): bool
    {
        $p = self::parse($msg);
        if (!$p['card']) return false;
        $card = self::clean($p['card']);
        if (!$card['tasks']) return false;
        $u = $msg['usage'] ?? [];
        $searches = (int)($u['server_tool_use']['web_search_requests'] ?? 0);
        $in = (int)($u['input_tokens'] ?? 0) + (int)($u['cache_read_input_tokens'] ?? 0) + (int)($u['cache_creation_input_tokens'] ?? 0);
        $cost = round(($in / 1e6 * self::PRICE_IN + (int)($u['output_tokens'] ?? 0) / 1e6 * self::PRICE_OUT) * $tokenRate + $searches * self::PRICE_SEARCH, 4);
        $common = $card['common_name'] ?? '';
        $group = $card['group'] ?? '';
        unset($card['common_name'], $card['group']);
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE plant_care_cards SET card = ?, sources = ?, status = 'draft', model = ?, searches = ?, cost_usd = ?, drafted_by = ?, drafted_at = ?,
                                   common_name = COALESCE(common_name, ?), plant_group = COALESCE(plant_group, ?), batch_id = COALESCE(?, batch_id), updated_at = ?
                            WHERE id = ? AND status IN ('queued', 'writing', 'failed', 'rejected')")
           ->execute([json_encode($card), json_encode($p['sources']), self::MODEL, $searches, $cost, $userId, $now,
                      $common !== '' ? $common : null, isset(self::GROUPS[$group]) && $group !== '' ? $group : null, $batchId, $now, $id]);
        return true;
    }

    // ── The owner's review ─────────────────────────────────────────────────

    /** Approve (with any edits) or throw out one card. */
    public function decide(int $id, array $in, int $userId): array
    {
        $have = $this->byId($id);
        if (!$have) return ['ok' => false, 'message' => 'That card is gone.'];
        $now = date('Y-m-d H:i:s');
        if (($in['what'] ?? '') === 'reject') {
            $this->db->prepare("UPDATE plant_care_cards SET status = 'rejected', updated_at = ? WHERE id = ?")->execute([$now, $id]);
            return ['ok' => true, 'message' => 'Thrown out.'];
        }
        if (($in['what'] ?? '') === 'redo') {
            $this->db->prepare("UPDATE plant_care_cards SET status = 'queued', batch_id = NULL, approved_by = NULL, approved_at = NULL, updated_at = ? WHERE id = ?")->execute([$now, $id]);
            return ['ok' => true, 'message' => 'Back in the queue for the next batch.'];
        }
        if (!in_array($have['status'], ['draft', 'approved'], true)) return ['ok' => false, 'message' => 'There is no draft to approve yet.'];
        $card = self::clean(is_array($in['card'] ?? null) ? $in['card'] : $have['card']);
        if (!$card['tasks']) return ['ok' => false, 'message' => 'Keep at least one care task.'];
        unset($card['common_name'], $card['group']);
        $common = mb_substr(trim((string)($in['common_name'] ?? $have['common_name'] ?? '')), 0, 160) ?: null;
        $this->db->prepare("UPDATE plant_care_cards SET common_name = ?, card = ?, status = 'approved', approved_by = ?, approved_at = ?, updated_at = ? WHERE id = ?")
           ->execute([$common, json_encode($card), $userId ?: null, $now, $now, $id]);
        return ['ok' => true, 'card' => $this->byId($id), 'message' => 'Approved.'];
    }

    /** Approve every draft in one group as it stands. */
    public function approveGroup(string $group, int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $s = $this->db->prepare("UPDATE plant_care_cards SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = ?
                                 WHERE status = 'draft' AND climate = ? AND COALESCE(plant_group, '') = ?");
        $s->execute([$userId ?: null, $now, $now, self::CLIMATE, $group]);
        return $s->rowCount();
    }

    /** The library for the review page, grouped; counts by status. */
    public function library(): array
    {
        $rows = $this->db->query("SELECT * FROM plant_care_cards WHERE climate = '" . self::CLIMATE . "' ORDER BY plant_group, common_name, scientific_name")->fetchAll(PDO::FETCH_ASSOC);
        $groups = []; $counts = [];
        foreach ($rows as $r) {
            $r = self::shape($r);
            $groups[(string)($r['plant_group'] ?? '')][] = $r;
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
        }
        $spent = (float)$this->db->query("SELECT COALESCE(SUM(cost_usd), 0) FROM plant_care_cards")->fetchColumn();
        return ['groups' => $groups, 'counts' => $counts, 'spent_usd' => round($spent, 2)];
    }

    // ── The request and its answer ─────────────────────────────────────────

    /** Only the fields we keep, trimmed; months 1–12; at most 8 tasks. */
    public static function clean(array $c): array
    {
        $t = fn($v, $n = 200) => mb_substr(trim((string)$v), 0, $n);
        $tasks = [];
        foreach ((array)($c['tasks'] ?? []) as $x) {
            if (!is_array($x)) continue;
            $task = $t($x['task'] ?? '', 80);
            $from = (int)($x['from'] ?? 0); $to = (int)($x['to'] ?? $from);
            if ($task === '' || $from < 1 || $from > 12) continue;
            if ($to < 1 || $to > 12) $to = $from;
            $tasks[] = ['task' => $task, 'from' => $from, 'to' => $to, 'how' => $t($x['how'] ?? '', 240)];
            if (count($tasks) >= 8) break;
        }
        $out = ['tasks' => $tasks, 'toxic_pets' => !empty($c['toxic_pets']) && $c['toxic_pets'] !== 'false', 'toxic_note' => $t($c['toxic_note'] ?? '', 160),
                'sun' => $t($c['sun'] ?? '', 80), 'soil' => $t($c['soil'] ?? '', 80), 'size' => $t($c['size'] ?? '', 80),
                'hardiness' => $t($c['hardiness'] ?? '', 60), 'notes' => $t($c['notes'] ?? '', 400),
                // Weeds: on BC's invasive / noxious lists, and what a crew must know to remove it safely.
                'weed' => !empty($c['weed']) && $c['weed'] !== 'false',
                'invasive_bc' => !empty($c['invasive_bc']) && $c['invasive_bc'] !== 'false', 'invasive_note' => $t($c['invasive_note'] ?? '', 200),
                'safety' => $t($c['safety'] ?? '', 240), 'disposal' => $t($c['disposal'] ?? '', 200)];
        if (isset($c['common_name'])) $out['common_name'] = $t($c['common_name'], 160);
        if (isset($c['group'])) $out['group'] = strtolower($t($c['group'], 20));
        return $out;
    }

    /** "May–Jun", "Oct", "Nov–Feb". */
    public static function window(int $from, int $to): string
    {
        return $from === $to ? self::MONTHS[$from] : self::MONTHS[$from] . '–' . self::MONTHS[$to];
    }

    public static function request(string $scientific, string $commonHint, string $level = 'species', string $group = ''): array
    {
        if ($group === 'weed') return self::weedRequest($scientific, $commonHint);
        $who = $level === 'genus'
            ? "the genus {$scientific}" . ($commonHint !== '' ? " ({$commonHint})" : '') . " — general care for the garden species and cultivars, saying in notes where the common ones differ"
            : $scientific . ($commonHint !== '' ? " ({$commonHint})" : '');
        $prompt = "Write a care card for {$who}, for a landscaping company maintaining client gardens in Vancouver, BC "
            . "(Pacific Northwest coast, USDA zone 8b: mild wet winters, dry summers).\n\n"
            . "Search the trusted gardening sources for its care. Use only what the sources support, in your own words — never copy sentences. "
            . "Give the jobs a gardener schedules (pruning, shearing, pinching, deadheading, cutting back, dividing, feeding, mulching, winter protection) "
            . "with the months to do them in Vancouver's climate. Skip watering schedules for established garden plants and houseplant care.\n\n"
            . "Do not write the care out in prose: after searching, reply with ONE short line saying which sources you used, then ONE fenced ```json block, exactly this shape:\n"
            . "{\"common_name\": \"the usual garden name\", \"group\": \"hedge|shrub|perennial|grass|groundcover|tree|bulb|vine|annual\", "
            . "\"tasks\": [{\"task\": \"Cut back to 10 cm\", \"from\": 11, \"to\": 12, \"how\": \"one short line\"}], "
            . "\"toxic_pets\": true, \"toxic_note\": \"Toxic to dogs and cats if eaten\", \"sun\": \"Full sun\", \"soil\": \"Well-drained\", "
            . "\"size\": \"30–90 cm tall\", \"hardiness\": \"Zones 5–9\", \"notes\": \"anything a crew must know, one line\"}\n"
            . "Months are numbers 1–12 (a window may wrap the year, e.g. from 11 to 2). At most 6 tasks. Leave a field empty when the sources don't support it.";
        return [
            'model' => self::MODEL,
            'max_tokens' => 6000,   // search results are long; prose before the card once ran a 3000 cap out (2026-10-15)
            'tools' => [[
                'type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => self::MAX_SEARCHES,
                'allowed_domains' => self::TRUSTED,
                'user_location' => ['type' => 'approximate', 'city' => 'Vancouver', 'region' => 'British Columbia', 'country' => 'CA', 'timezone' => 'America/Vancouver'],
            ]],
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
    }

    /** A weed / invasive: how and when to control it on the coast, BC's lists, safety and disposal. */
    public static function weedRequest(string $scientific, string $commonHint): array
    {
        $who = $scientific . ($commonHint !== '' ? " ({$commonHint})" : '');
        $prompt = "Write a control card for {$who}, a weed a landscaping company finds in client gardens and lawns in Vancouver, BC "
            . "(Pacific Northwest coast, zone 8b).\n\n"
            . "Search the trusted sources (BC's invasive species council and government lists first). Use only what they support, in your own words. "
            . "Give the control jobs a crew schedules — pulling, digging out roots, cutting before seed set, smothering, mowing — with the months to do them "
            . "on the coast. Say whether it is on BC's invasive or noxious weed lists, what a crew must know to stay safe (sap, spines, toxicity, gloves), "
            . "and how to dispose of it (bagged to the landfill, never green waste, when the sources say so). No herbicide product names or rates.\n\n"
            . "Do not write it out in prose: after searching, reply with ONE short line saying which sources you used, then ONE fenced ```json block, exactly this shape:\n"
            . "{\"common_name\": \"the usual name\", \"group\": \"weed\", \"weed\": true, "
            . "\"tasks\": [{\"task\": \"Dig out before it seeds\", \"from\": 4, \"to\": 6, \"how\": \"one short line\"}], "
            . "\"invasive_bc\": true, \"invasive_note\": \"which BC list and what it means for the client\", \"safety\": \"one line, or empty\", "
            . "\"disposal\": \"one line\", \"toxic_pets\": false, \"toxic_note\": \"\", \"sun\": \"\", \"soil\": \"\", \"size\": \"\", "
            . "\"hardiness\": \"\", \"notes\": \"anything a crew must know, one line\"}\n"
            . "Months are numbers 1–12. At most 6 tasks. Leave a field empty when the sources don't support it.";
        $body = self::request($scientific, $commonHint, 'species');
        $body['messages'] = [['role' => 'user', 'content' => $prompt]];
        return $body;
    }

    /** Claude's answer → the card JSON (last ```json block) and the cited sources (unique by URL). */
    public static function parse(array $resp): array
    {
        $text = '';
        $sources = [];
        $seen = [];   // pages the searches returned — the fallback when the short answer cites nothing
        foreach ($resp['content'] ?? [] as $b) {
            if (($b['type'] ?? '') === 'web_search_tool_result' && is_array($b['content'] ?? null) && !isset($b['content']['error_code'])) {
                foreach ($b['content'] as $res) {
                    $url = (string)($res['url'] ?? '');
                    if ($url !== '' && preg_match('#^https?://#', $url) && !isset($seen[$url])) $seen[$url] = ['url' => $url, 'title' => mb_substr((string)($res['title'] ?? $url), 0, 160)];
                }
            }
            if (($b['type'] ?? '') !== 'text') continue;
            $text .= (string)($b['text'] ?? '');
            foreach ($b['citations'] ?? [] as $c) {
                $url = (string)($c['url'] ?? '');
                if ($url !== '' && preg_match('#^https?://#', $url) && !isset($sources[$url])) $sources[$url] = ['url' => $url, 'title' => mb_substr((string)($c['title'] ?? $url), 0, 160)];
            }
        }
        $card = null;
        if (preg_match_all('/```json\s*(\{.*?\})\s*```/s', $text, $m)) $card = json_decode(end($m[1]), true);
        if (!$sources) $sources = array_slice($seen, 0, 5, true);
        return ['card' => is_array($card) ? $card : null, 'sources' => array_values($sources)];
    }

    private function call(string $method, string $url, ?array $body): array
    {
        if ($this->http) return ($this->http)($method, $url, $body);
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150, CURLOPT_CONNECTTIMEOUT => 10,
                 CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01']];
        if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($body); }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) error_log('Plant care curl: ' . curl_error($ch));
        curl_close($ch);
        return ['code' => $raw === false ? 0 : $code, 'body' => (string)$raw];
    }
}
