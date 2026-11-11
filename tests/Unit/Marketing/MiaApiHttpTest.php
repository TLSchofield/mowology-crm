<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * End-to-end HTTP test of Marketing/Api/mia.php: the REAL endpoint file served by PHP's built-in web server in a
 * throwaway app tree, with stubbed login/permission/CSRF functions (headers pick the user, the permission and the token).
 * Proves the order that matters: login -> marketing.approve -> CSRF -> mode, and that the new modes cannot be reached around it.
 */
class MiaApiHttpTest extends TestCase
{
    private static string $root = '';
    private static int $port = 0;
    /** @var resource|null */
    private static $proc = null;

    public static function setUpBeforeClass(): void
    {
        $repo = realpath(__DIR__ . '/../../..');
        self::$root = sys_get_temp_dir() . '/mia_http_' . bin2hex(random_bytes(4));
        foreach (['app/Core', 'app/Modules/Marketing/Api', 'public/crm/includes', 'public/loginAuth'] as $d) { mkdir(self::$root . '/' . $d, 0777, true); }
        copy($repo . '/app/Core/paths.php', self::$root . '/app/Core/paths.php');
        copy($repo . '/app/Modules/Marketing/Api/mia.php', self::$root . '/app/Modules/Marketing/Api/mia.php');
        symlink($repo . '/app/Services', self::$root . '/app/Services');
        symlink($repo . '/app/Modules/Marketing/Services', self::$root . '/app/Modules/Marketing/Services');
        file_put_contents(self::$root . '/public/crm/includes/functions.php', "<?php\n");
        file_put_contents(self::$root . '/public/loginAuth/auth.php', <<<'PHP'
<?php
function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . getenv('MIA_TEST_DB'));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    return $db;
}
function requireLogin(): void { if (($_SERVER['HTTP_X_TEST_LOGIN'] ?? '') !== 'yes') { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'login']); exit; } }
function requirePermission(string $p): void { if (($_SERVER['HTTP_X_TEST_PERM'] ?? '') !== 'yes' || $p !== 'marketing.approve') { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'permission']); exit; } }
function getCurrentUser(): array { return ['id' => (int)($_SERVER['HTTP_X_TEST_USER'] ?? 0), 'first_name' => 'Tim']; }
function verifyCSRFToken($t): bool { return $t === 'good-token'; }
PHP);
        file_put_contents(self::$root . '/router.php', "<?php\nrequire " . var_export(self::$root . '/app/Modules/Marketing/Api/mia.php', true) . ";\n");

        $db = new PDO('sqlite:' . self::$root . '/test.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY)");
        $db->exec("INSERT INTO users VALUES (7)");
        $db->exec("CREATE TABLE mia_websites (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, base_url TEXT, host TEXT, mode TEXT DEFAULT 'existing', connector TEXT DEFAULT 'none',
            capabilities TEXT, status TEXT DEFAULT 'pending', verify_token TEXT, verified_via TEXT, verified_at TEXT, last_verify_attempt_at TEXT, last_verify_error TEXT, crawl_cap INT DEFAULT 200, robots_hash TEXT, created_by INT, created_at TEXT)");
        $db->exec("CREATE TABLE mia_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, opportunity_key TEXT, type TEXT, side_effect TEXT, status TEXT NOT NULL DEFAULT 'proposed',
            title TEXT, why TEXT, expected_benefit TEXT, risk TEXT, evidence TEXT, score REAL, score_inputs TEXT, evidence_hash TEXT, draft_ref_table TEXT, draft_ref_id INT, draft_hash TEXT, preview_url TEXT,
            autonomy_level INT DEFAULT 1, proposed_by TEXT DEFAULT 'mia', proposed_at TEXT, decided_by INT, decided_at TEXT, decision_note TEXT, executed_at TEXT, result TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE mia_action_events (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, action_id INT, event TEXT, actor TEXT, detail TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE mia_decisions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, subject_key TEXT, action_type TEXT, evidence_hash TEXT, decision TEXT, reason_code TEXT, reason_text TEXT, decided_by INT, decided_at TEXT, suppress_until TEXT)");
        $db->exec("CREATE TABLE mia_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, kind TEXT, mem_key TEXT, mem_value TEXT, source TEXT DEFAULT 'owner', updated_by INT, updated_at TEXT)");
        $db->exec("CREATE TABLE mia_content_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT NOT NULL, action_id INT, kind TEXT, page_url TEXT, current_title TEXT, current_desc TEXT,
            title TEXT, meta_desc TEXT, rationale TEXT, input_hash TEXT, status TEXT DEFAULT 'draft', version INT DEFAULT 1, created_at TEXT)");

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int)explode(':', stream_socket_get_name($sock, false))[1];
        fclose($sock);
        self::$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$root . '/public', self::$root . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', self::$root . '/server.log', 'w']],
            $pipes, self::$root, ['MIA_TEST_DB' => self::$root . '/test.sqlite', 'PATH' => getenv('PATH') ?: '/usr/bin']);
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', self::$port, $e, $m, 0.2);
            if ($c) { fclose($c); return; }
            usleep(100000);
        }
        self::fail('test server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proc) { proc_terminate(self::$proc); proc_close(self::$proc); }
        if (self::$root !== '' && is_dir(self::$root)) { exec('rm -rf ' . escapeshellarg(self::$root)); }
    }

    protected function setUp(): void
    {
        $db = $this->db();
        foreach (['mia_actions', 'mia_action_events', 'mia_decisions', 'mia_content_drafts', 'mia_websites'] as $t) { $db->exec("DELETE FROM $t"); }
        $db->exec("INSERT INTO mia_actions (site_id, opportunity_key, type, side_effect, status, title, why, expected_benefit, risk, evidence, score, proposed_at)
                   VALUES (1, 'k', 'fix_technical', 'none', 'proposed', 'Add a description to 5 pages', 'Because.', 'Estimate: tidier pages.', 'Low.', '[]', 40, '2026-10-26 08:00:00')");
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite:' . self::$root . '/test.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    }

    /** @return array{0:int,1:array} */
    private function call(string $method, string $query, ?array $body = null, array $headers = ['X-Test-Login: yes', 'X-Test-Perm: yes', 'X-Test-User: 7'], ?string $rawBody = null): array
    {
        $opts = ['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 10, 'header' => implode("\r\n", array_merge($headers, $method === 'POST' ? ['Content-Type: application/json'] : []))]];
        if ($method === 'POST') { $opts['http']['content'] = $rawBody ?? json_encode($body ?? []); }
        $res = @file_get_contents('http://127.0.0.1:' . self::$port . '/mia.php' . $query, false, stream_context_create($opts));
        $code = 0;
        foreach ((array)($http_response_header ?? []) as $h) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int)$m[1]; } }
        return [$code, (array)json_decode((string)$res, true)];
    }

    private function actionId(): int
    {
        return (int)$this->db()->query("SELECT id FROM mia_actions")->fetchColumn();
    }

    private function actionStatus(): string
    {
        return (string)$this->db()->query("SELECT status FROM mia_actions")->fetchColumn();
    }

    // ---- the gate, in order ----

    public function testNotLoggedInIsStoppedFirst(): void
    {
        list($c) = $this->call('GET', '?mode=actions', null, ['X-Test-Perm: yes', 'X-Test-User: 7']);
        $this->assertSame(401, $c);
        list($c2) = $this->call('POST', '', ['mode' => 'action_decide', 'id' => 1, 'decision' => 'reject', 'csrf_token' => 'good-token'], ['X-Test-Perm: yes']);
        $this->assertSame(401, $c2);
        $this->assertSame('proposed', $this->actionStatus());
    }

    public function testWithoutTheMarketingApprovePermissionEveryNewModeIsForbidden(): void
    {
        $h = ['X-Test-Login: yes', 'X-Test-Perm: no', 'X-Test-User: 7'];
        foreach (['websites', 'actions', 'action_detail&id=1'] as $m) { $this->assertSame(403, $this->call('GET', "?mode=$m", null, $h)[0], $m); }
        foreach (['connect_website' => ['address' => 'mowology.ca'], 'verify_website' => ['id' => 1], 'disconnect_website' => ['id' => 1],
                  'draft_edit' => ['id' => 1, 'title' => 'x', 'meta_description' => 'y'], 'action_decide' => ['id' => 1, 'decision' => 'reject']] as $mode => $b) {
            $this->assertSame(403, $this->call('POST', '', array_merge(['mode' => $mode, 'csrf_token' => 'good-token'], $b), $h)[0], $mode);
        }
        $this->assertSame('proposed', $this->actionStatus());
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM mia_websites")->fetchColumn());
    }

    public function testEveryPostModeNeedsAValidCsrfTokenBeforeAnythingHappens(): void
    {
        foreach (['connect_website' => ['address' => 'mowology.ca'], 'verify_website' => ['id' => 1], 'disconnect_website' => ['id' => 1],
                  'draft_edit' => ['id' => 1, 'title' => 'x', 'meta_description' => 'y'], 'action_decide' => ['id' => 1, 'decision' => 'reject', 'seen_hash' => 'x']] as $mode => $b) {
            foreach ([null, 'wrong', ''] as $tok) {
                $body = array_merge(['mode' => $mode], $b);
                if ($tok !== null) { $body['csrf_token'] = $tok; }
                list($c, $r) = $this->call('POST', '', $body);
                $this->assertSame(403, $c, "$mode / " . var_export($tok, true));
                $this->assertSame('Invalid CSRF token', $r['error'] ?? '');
            }
        }
        $this->assertSame('proposed', $this->actionStatus());
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM mia_action_events")->fetchColumn());
    }

    public function testCsrfInTheQueryStringOrHeaderIsNotAccepted(): void
    {
        list($c) = $this->call('POST', '?csrf_token=good-token', ['mode' => 'action_decide', 'id' => 1, 'decision' => 'reject'], ['X-Test-Login: yes', 'X-Test-Perm: yes', 'X-Test-User: 7', 'X-CSRF-Token: good-token']);
        $this->assertSame(403, $c);
        $this->assertSame('proposed', $this->actionStatus());
    }

    // ---- working requests ----

    public function testApprovalQueueReadAndDecideThroughTheRealEndpoint(): void
    {
        list($c, $b) = $this->call('GET', '?mode=actions');
        $this->assertSame(200, $c, json_encode($b));
        $this->assertCount(1, $b['items']);
        $seen = $b['items'][0]['seen_hash'];

        list($c2, $b2) = $this->call('POST', '', ['mode' => 'action_decide', 'id' => $b['items'][0]['id'], 'decision' => 'approve', 'seen_hash' => $seen, 'csrf_token' => 'good-token', 'user_id' => 99, 'decided_by' => 99]);
        $this->assertSame(200, $c2, json_encode($b2));
        $row = $this->db()->query("SELECT status, decided_by FROM mia_actions")->fetch();
        $this->assertSame('approved', $row['status']);
        $this->assertSame(7, (int)$row['decided_by'], 'the session user, not the one named in the body');
        $this->assertSame('user:7', $this->db()->query("SELECT actor FROM mia_action_events WHERE event = 'approved'")->fetchColumn());
    }

    public function testStaleHashIsRefusedOverHttpToo(): void
    {
        list($c, $b) = $this->call('POST', '', ['mode' => 'action_decide', 'id' => $this->actionId(), 'decision' => 'approve', 'seen_hash' => 'old', 'csrf_token' => 'good-token']);
        $this->assertSame(409, $c);
        $this->assertSame('changed', $b['code']);
        $this->assertSame('proposed', $this->actionStatus());
    }

    public function testWebsiteModesWorkAndNeverReturnAnotherSitesRows(): void
    {
        $this->db()->exec("INSERT INTO mia_websites (site_id, base_url, host, status, verify_token) VALUES (2, 'https://secret.example', 'secret.example', 'pending', 'topsecrettoken')");
        list($c, $b) = $this->call('GET', '?mode=websites');
        $this->assertSame(200, $c);
        $this->assertSame([], $b['websites']);
        $this->assertStringNotContainsString('topsecrettoken', json_encode($b));
    }

    // ---- shape of the endpoint ----

    public function testMethodsAndUnknownModes(): void
    {
        $this->assertSame(405, $this->call('POST', '', ['mode' => 'actions', 'csrf_token' => 'good-token'])[0], 'a GET mode cannot be POSTed');
        $this->assertSame(405, $this->call('GET', '?mode=action_decide')[0], 'a POST mode cannot be GET');
    }

    public function testAnOversizedOrBrokenBodyIsHandledNotCrashed(): void
    {
        list($c) = $this->call('POST', '', null, ['X-Test-Login: yes', 'X-Test-Perm: yes', 'X-Test-User: 7'], '{"mode":"action_decide","csrf_token":"good-token","pad":"' . str_repeat('a', 300000) . '"}');
        $this->assertContains($c, [403, 400, 422], 'truncated at 256 KB -> invalid JSON -> no valid CSRF token');
        list($c2) = $this->call('POST', '', null, ['X-Test-Login: yes', 'X-Test-Perm: yes', 'X-Test-User: 7'], '[1,2,3]');
        $this->assertSame(403, $c2, 'a JSON list is not a request');
        $this->assertSame('proposed', $this->actionStatus());
    }

    public function testTheEmailDeskModesAreStillRoutedToTheirOwnCode(): void
    {
        // 'decide' (the email suggestions) must not be swallowed by the new handler: it reaches the old desk code,
        // which fails here only because this throwaway database has no desk tables.
        list($c, $b) = $this->call('POST', '', ['mode' => 'decide', 'id' => 1, 'action' => 'skip', 'csrf_token' => 'good-token']);
        $this->assertNotSame(405, $c);
        $this->assertArrayNotHasKey('code', $b, 'the new handler returns a code field; the old desk does not');
    }
}
