<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * QuickBooks Online connection, step 1 (plan 2026-10-20): the token vault refuses a wrong key, the
 * sign-in state is checked, tokens refresh and a rotated refresh token replaces the old one, an
 * expired sign-in says so, lists are read page by page, and the mapping only takes ids QuickBooks gave.
 * No test calls Intuit: every HTTP call is a fake.
 */
class QboConnectionTest extends TestCase
{
    private const NOW = 1792540800;   // 2026-10-20 local-ish; only differences matter

    private function key(): string { return str_repeat("\x07", 32); }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE qbo_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, environment TEXT,
                   realm_id TEXT, company_name TEXT, country TEXT, access_token_enc TEXT, refresh_token_enc TEXT, access_expires_at TEXT,
                   refresh_expires_at TEXT, status TEXT NOT NULL DEFAULT 'connected', last_error TEXT, last_pull_at TEXT, connected_by INT,
                   connected_at TEXT, disconnected_at TEXT, created_at TEXT, updated_at TEXT)");
        $db->exec("CREATE TABLE qbo_remote_items (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, kind TEXT, qbo_id TEXT, name TEXT,
                   detail TEXT, active INT DEFAULT 1, pulled_at TEXT)");
        $db->exec("CREATE TABLE qbo_mappings (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, kind TEXT, ittai_key TEXT, qbo_id TEXT,
                   qbo_name TEXT, set_by INT, updated_at TEXT)");
        return $db;
    }

    private function connected(PDO $db, QboTokenVault $v, string $accessExpires): void
    {
        $db->prepare("INSERT INTO qbo_connections (site_id, environment, realm_id, access_token_enc, refresh_token_enc, access_expires_at, status)
                      VALUES (1, 'sandbox', '123', ?, ?, ?, 'connected')")
           ->execute([$v->encrypt('ACCESS-1'), $v->encrypt('REFRESH-1'), $accessExpires]);
    }

    /** @param list<array{status:int, body:string}> $answers */
    private function oauth(array &$calls, array $answers): QboOAuth
    {
        return new QboOAuth('cid', 'secret', function (string $url, array $h, string $body) use (&$calls, &$answers) {
            $calls[] = $body;
            return array_shift($answers) ?? ['status' => 500, 'body' => ''];
        });
    }

    // ── vault ────────────────────────────────────────────────────────────────

    public function test_vault_round_trips_and_a_wrong_key_gives_null_never_garbage(): void
    {
        $v = new QboTokenVault($this->key());
        $enc = $v->encrypt('the-token');
        $this->assertStringStartsWith('q1:', $enc);
        $this->assertSame('the-token', $v->decrypt($enc));
        $this->assertNull((new QboTokenVault(str_repeat("\x08", 32)))->decrypt($enc), 'wrong key');
        $this->assertNull($v->decrypt(substr($enc, 0, -4) . 'AAAA'), 'tampered');
        $this->assertNull($v->decrypt('not ours'));
        $this->assertNull($v->decrypt(null));
    }

    public function test_key_must_be_32_bytes_as_base64_or_hex(): void
    {
        $this->assertSame(32, strlen((string)QboTokenVault::decodeKey(base64_encode(random_bytes(32)))));
        $this->assertSame(32, strlen((string)QboTokenVault::decodeKey(str_repeat('ab', 32))));
        $this->assertNull(QboTokenVault::decodeKey(base64_encode('short')));
        $this->assertNull(QboTokenVault::decodeKey('password123'));
    }

    // ── sign-in ──────────────────────────────────────────────────────────────

    public function test_auth_url_carries_scope_redirect_and_state(): void
    {
        $u = QboOAuth::authUrl('cid', 'https://mowology.ca/crm/accounting/quickbooks-callback.php', 'st8');
        $this->assertStringStartsWith('https://appcenter.intuit.com/connect/oauth2?', $u);
        parse_str((string)parse_url($u, PHP_URL_QUERY), $q);
        $this->assertSame(['cid', 'code', 'com.intuit.quickbooks.accounting', 'https://mowology.ca/crm/accounting/quickbooks-callback.php', 'st8'],
                          [$q['client_id'], $q['response_type'], $q['scope'], $q['redirect_uri'], $q['state']]);
    }

    public function test_state_must_match_this_session_site_and_be_recent(): void
    {
        $saved = ['state' => 'abc', 'at' => self::NOW, 'site' => 1];
        $this->assertTrue(QboConnectionService::stateOk($saved, 'abc', self::NOW + 30, 1));
        $this->assertFalse(QboConnectionService::stateOk($saved, 'abd', self::NOW + 30, 1), 'other state');
        $this->assertFalse(QboConnectionService::stateOk($saved, 'abc', self::NOW + 601, 1), 'too late');
        $this->assertFalse(QboConnectionService::stateOk($saved, 'abc', self::NOW, 2), 'other business');
        $this->assertFalse(QboConnectionService::stateOk(null, 'abc', self::NOW, 1), 'no sign-in started');
        $this->assertFalse(QboConnectionService::stateOk($saved, '', self::NOW, 1));
    }

    public function test_finish_refuses_a_foreign_state_and_calls_nothing(): void
    {
        $calls = [];
        $svc = new QboConnectionService($this->db(), 1, $this->oauth($calls, []), new QboTokenVault($this->key()), fn() => self::NOW);
        $session = ['qbo_oauth' => ['state' => 'mine', 'at' => self::NOW, 'site' => 1]];
        $r = $svc->finish($session, 'code', 'theirs', '123', 1);
        $this->assertFalse($r['ok']);
        $this->assertSame([], $calls, 'no code exchange');
        $this->assertArrayHasKey('qbo_oauth', $session, 'a foreign link leaves the sign-in in progress alone');
        $this->assertFalse($svc->cancel($session, 'theirs'), 'nor can a forged cancel wipe it');
        $this->assertArrayHasKey('qbo_oauth', $session);
        $this->assertTrue($svc->cancel($session, 'mine'));
        $this->assertArrayNotHasKey('qbo_oauth', $session);
    }

    private function finishing(PDO $db, array &$calls, int $companyStatus): QboConnectionService
    {
        $http = fn(string $url, array $h) => $companyStatus === 200
            ? ['status' => 200, 'body' => strpos($url, 'companyinfo') !== false ? '{"CompanyInfo":{"CompanyName":"B Co","Country":"CA"}}' : '{"QueryResponse":{}}']
            : ['status' => $companyStatus, 'body' => '{"Fault":{"Error":[{"Message":"Forbidden"}]}}'];
        return new QboConnectionService($db, 1, $this->oauth($calls, [
            ['status' => 200, 'body' => '{"access_token":"A","refresh_token":"R","expires_in":3600}'],
        ]), new QboTokenVault($this->key()), fn() => self::NOW, $http);
    }

    public function test_a_company_the_tokens_cannot_read_is_never_saved(): void
    {
        $db = $this->db(); $calls = [];
        $svc = $this->finishing($db, $calls, 403);
        $session = ['qbo_oauth' => ['state' => 's', 'at' => self::NOW, 'site' => 1]];
        $r = $svc->finish($session, 'code', 's', '456', 1);
        $this->assertFalse($r['ok']);
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM qbo_connections')->fetchColumn());
    }

    public function test_reconnecting_to_another_company_clears_the_old_mapping(): void
    {
        $db = $this->db(); $v = new QboTokenVault($this->key()); $calls = [];
        $this->connected($db, $v, date('Y-m-d H:i:s', self::NOW + 1200));     // realm 123
        $db->exec("INSERT INTO qbo_mappings (site_id, kind, ittai_key, qbo_id, qbo_name) VALUES (1, 'account', '6100', '42', 'Fuel')");
        $session = ['qbo_oauth' => ['state' => 's', 'at' => self::NOW, 'site' => 1]];
        $this->assertTrue($this->finishing($db, $calls, 200)->finish($session, 'code', 's', '456', 1)['ok']);
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM qbo_mappings')->fetchColumn());
        $this->assertSame('456', $db->query('SELECT realm_id FROM qbo_connections')->fetchColumn());
    }

    public function test_a_passing_refresh_failure_is_tried_again_next_time(): void
    {
        $db = $this->db(); $v = new QboTokenVault($this->key()); $calls = [];
        $this->connected($db, $v, date('Y-m-d H:i:s', self::NOW - 5));
        $svc = new QboConnectionService($db, 1, $this->oauth($calls, [
            ['status' => 503, 'body' => ''],
            ['status' => 200, 'body' => '{"access_token":"ACCESS-2","refresh_token":"REFRESH-2","expires_in":3600}'],
        ]), $v, fn() => self::NOW);
        $this->assertNull($svc->accessToken());
        $this->assertSame('error', $svc->status()['status']);
        $this->assertTrue($svc->status()['connected'], 'still connected');
        $this->assertSame('ACCESS-2', $svc->accessToken(), 'the next call renews');
        $this->assertSame('connected', $svc->status()['status']);
    }

    public function test_token_answer_parsed_with_a_minute_margin(): void
    {
        $t = QboOAuth::parseTokens('{"access_token":"a","refresh_token":"r","expires_in":3600,"x_refresh_token_expires_in":8726400}', self::NOW);
        $this->assertSame('a', $t['access']);
        $this->assertSame(date('Y-m-d H:i:s', self::NOW + 3540), $t['access_expires_at']);
        $this->assertSame(date('Y-m-d H:i:s', self::NOW + 8726400), $t['refresh_expires_at']);
        $this->assertNull(QboOAuth::parseTokens('{"error":"invalid_grant"}', self::NOW));
    }

    public function test_errors_read_as_something_a_person_can_do(): void
    {
        $this->assertStringContainsString('Connect again', QboOAuth::errorLine(400, '{"error":"invalid_grant"}'));
        $this->assertStringContainsString('secrets.php', QboOAuth::errorLine(401, '{"error":"invalid_client"}'));
        $this->assertStringContainsString('busy', QboClient::faultLine(429, ''));
        $this->assertSame('QuickBooks: Invalid Reference Id: Tax code', QboClient::faultLine(400,
            '{"Fault":{"Error":[{"Message":"Invalid Reference Id","Detail":"Tax code"}]}}'));
    }

    // ── tokens ───────────────────────────────────────────────────────────────

    public function test_a_fresh_token_is_used_without_calling_intuit(): void
    {
        $db = $this->db(); $v = new QboTokenVault($this->key()); $calls = [];
        $this->connected($db, $v, date('Y-m-d H:i:s', self::NOW + 1200));
        $svc = new QboConnectionService($db, 1, $this->oauth($calls, []), $v, fn() => self::NOW);
        $this->assertSame('ACCESS-1', $svc->accessToken());
        $this->assertSame([], $calls);
    }

    public function test_an_old_token_refreshes_and_the_new_refresh_token_replaces_the_old(): void
    {
        $db = $this->db(); $v = new QboTokenVault($this->key()); $calls = [];
        $this->connected($db, $v, date('Y-m-d H:i:s', self::NOW - 5));
        $svc = new QboConnectionService($db, 1, $this->oauth($calls, [
            ['status' => 200, 'body' => '{"access_token":"ACCESS-2","refresh_token":"REFRESH-2","expires_in":3600}'],
        ]), $v, fn() => self::NOW);
        $this->assertSame('ACCESS-2', $svc->accessToken());
        $this->assertStringContainsString('refresh_token=REFRESH-1', $calls[0]);
        $row = $db->query('SELECT * FROM qbo_connections')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('REFRESH-2', $v->decrypt($row['refresh_token_enc']), 'rotated refresh token kept');
        $this->assertSame('ACCESS-2', $svc->accessToken(), 'and used again without a call');
        $this->assertCount(1, $calls);
    }

    public function test_a_revoked_sign_in_marks_the_connection_expired(): void
    {
        $db = $this->db(); $v = new QboTokenVault($this->key()); $calls = [];
        $this->connected($db, $v, date('Y-m-d H:i:s', self::NOW - 5));
        $svc = new QboConnectionService($db, 1, $this->oauth($calls, [['status' => 400, 'body' => '{"error":"invalid_grant"}']]), $v, fn() => self::NOW);
        $this->assertNull($svc->accessToken());
        $st = $svc->status();
        $this->assertFalse($st['connected']);
        $this->assertSame('expired', $st['status']);
        $this->assertStringContainsString('Connect again', (string)$st['last_error']);
    }

    public function test_tokens_under_a_changed_key_are_reported_as_a_changed_key(): void
    {
        $db = $this->db(); $calls = [];
        $this->connected($db, new QboTokenVault(str_repeat("\x09", 32)), date('Y-m-d H:i:s', self::NOW - 5));
        $svc = new QboConnectionService($db, 1, $this->oauth($calls, []), new QboTokenVault($this->key()), fn() => self::NOW);
        $this->assertNull($svc->accessToken());
        $this->assertSame([], $calls, 'never sends garbage to Intuit');
        $this->assertStringContainsString('QBO_TOKEN_KEY', (string)$svc->status()['last_error']);
    }

    // ── reading lists ────────────────────────────────────────────────────────

    public function test_pull_reads_every_page_and_keeps_the_lists(): void
    {
        $db = $this->db(); $v = new QboTokenVault($this->key()); $calls = [];
        $this->connected($db, $v, date('Y-m-d H:i:s', self::NOW + 1200));
        $urls = [];
        $accounts = [];
        for ($i = 1; $i <= 1001; $i++) $accounts[] = ['Id' => (string)$i, 'Name' => 'Acct ' . $i, 'AccountType' => 'Expense'];
        $http = function (string $url, array $h) use (&$urls, $accounts) {
            $urls[] = $url;
            $q = urldecode((string)parse_url($url, PHP_URL_QUERY));
            if (strpos($q, 'FROM Account') !== false) {
                $page = strpos($q, 'STARTPOSITION 1001') !== false ? array_slice($accounts, 1000) : array_slice($accounts, 0, 1000);
                return ['status' => 200, 'body' => json_encode(['QueryResponse' => ['Account' => $page]])];
            }
            if (strpos($q, 'FROM TaxCode') !== false) {
                return ['status' => 200, 'body' => json_encode(['QueryResponse' => ['TaxCode' => [['Id' => '5', 'Name' => 'GST', 'Description' => 'GST 5%']]]])];
            }
            return ['status' => 200, 'body' => json_encode(['QueryResponse' => ['Customer' => [['Id' => '9', 'DisplayName' => 'A Client', 'PrimaryEmailAddr' => ['Address' => 'a@x.ca']]]]])];
        };
        $svc = new QboConnectionService($db, 1, $this->oauth($calls, []), $v, fn() => self::NOW, $http);
        $r = $svc->pull();
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('Read 1001 accounts, 1 tax codes and 1 customers.', $r['message']);
        $this->assertStringContainsString('minorversion=75', $urls[0]);
        $this->assertCount(1001, $svc->remote('account'));
        $this->assertSame([['qbo_id' => '5', 'name' => 'GST', 'detail' => 'GST 5%']], $svc->remote('tax_code'));
    }

    public function test_a_401_refreshes_once_and_retries(): void
    {
        $db = $this->db(); $v = new QboTokenVault($this->key()); $calls = [];
        $this->connected($db, $v, date('Y-m-d H:i:s', self::NOW + 1200));
        $seen = [];
        $http = function (string $url, array $h) use (&$seen) {
            $seen[] = $h[0];
            return $h[0] === 'Authorization: Bearer ACCESS-1' ? ['status' => 401, 'body' => ''] : ['status' => 200, 'body' => '{"QueryResponse":{}}'];
        };
        $svc = new QboConnectionService($db, 1, $this->oauth($calls, [
            ['status' => 200, 'body' => '{"access_token":"ACCESS-2","refresh_token":"REFRESH-2","expires_in":3600}'],
        ]), $v, fn() => self::NOW, $http);
        $this->assertTrue($svc->client()->all('TaxCode')['ok']);
        $this->assertSame(['Authorization: Bearer ACCESS-1', 'Authorization: Bearer ACCESS-2'], $seen);
    }

    // ── mapping ──────────────────────────────────────────────────────────────

    public function test_mapping_only_takes_an_id_quickbooks_gave_and_can_be_cleared(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO qbo_remote_items (site_id, kind, qbo_id, name, active, pulled_at) VALUES (1, 'account', '42', 'Fuel', 1, 'x')");
        $svc = new QboConnectionService($db, 1, null, new QboTokenVault($this->key()), fn() => self::NOW);
        $this->assertFalse($svc->setMapping('account', '6100', '999', 1), 'unknown QuickBooks id');
        $this->assertTrue($svc->setMapping('account', '6100', '42', 1));
        $this->assertTrue($svc->setMapping('account', '6100', '42', 1), 'saving the same again is fine');
        $this->assertSame(['qbo_id' => '42', 'qbo_name' => 'Fuel'], $svc->mappings()['account']['6100']);
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM qbo_mappings')->fetchColumn());
        $this->assertTrue($svc->setMapping('account', '6100', '', 1));
        $this->assertSame([], $svc->mappings()['account']);
        $this->assertFalse($svc->setMapping('nonsense', '6100', '42', 1));
    }

    public function test_suggestions_are_cautious_guesses(): void
    {
        $remote = [['qbo_id' => '1', 'name' => 'Expenses:Fuel'], ['qbo_id' => '2', 'name' => 'Vehicle Repairs'], ['qbo_id' => '3', 'name' => 'Repairs & Maintenance']];
        $this->assertSame('1', QboConnectionService::suggestAccount('Fuel', $remote), 'same name under a parent');
        $this->assertNull(QboConnectionService::suggestAccount('Repairs', $remote), 'two candidates: no guess');
        $this->assertNull(QboConnectionService::suggestAccount('', $remote));
        $tax = [['qbo_id' => '5', 'name' => 'GST'], ['qbo_id' => '6', 'name' => 'Exempt'], ['qbo_id' => '7', 'name' => 'GST/PST BC']];
        $this->assertSame('5', QboConnectionService::suggestTaxCode('gst', $tax));
        $this->assertSame('6', QboConnectionService::suggestTaxCode('exempt', $tax));
        $this->assertNull(QboConnectionService::suggestTaxCode('out_of_scope', $tax));
    }

    public function test_only_accounts_of_the_same_kind_are_offered(): void
    {
        // 2026-10-20 sandbox: "4000 Revenue" was guessed as "Arizona Dept. of Revenue Payable" (a liability).
        $remote = [
            ['qbo_id' => '1', 'name' => 'Arizona Dept. of Revenue Payable', 'detail' => 'Other Current Liability'],
            ['qbo_id' => '2', 'name' => 'Services', 'detail' => 'Income'],
            ['qbo_id' => '3', 'name' => 'Supplies', 'detail' => 'Expense · 6200'],
            ['qbo_id' => '4', 'name' => 'Truck', 'detail' => 'Fixed Asset'],
        ];
        $this->assertSame(['2'], array_column(QboConnectionService::fitting('revenue', $remote), 'qbo_id'));
        $this->assertSame(['3'], array_column(QboConnectionService::fitting('expense', $remote), 'qbo_id'));
        $this->assertSame(['4'], array_column(QboConnectionService::fitting('asset', $remote), 'qbo_id'));
        $this->assertNull(QboConnectionService::suggestAccount('Revenue', QboConnectionService::fitting('revenue', $remote)));
    }

    public function test_remote_item_shapes(): void
    {
        $this->assertSame(['qbo_id' => '7', 'name' => 'Expenses:Fuel', 'detail' => 'Expense · 6100', 'active' => 1],
            QboConnectionService::remoteItem('account', ['Id' => '7', 'Name' => 'Fuel', 'FullyQualifiedName' => 'Expenses:Fuel', 'AccountType' => 'Expense', 'AcctNum' => '6100']));
        $this->assertNull(QboConnectionService::remoteItem('customer', ['Id' => '', 'DisplayName' => 'x']));
        $this->assertSame(0, QboConnectionService::remoteItem('tax_code', ['Id' => '1', 'Name' => 'Old', 'Active' => false])['active']);
    }
}
