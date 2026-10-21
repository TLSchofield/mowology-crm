<?php
/**
 * QboConnectionService — a business's QuickBooks Online connection (plan 2026-10-20, step 1:
 * connect + read). Nothing is SENT to QuickBooks here: it signs in, keeps the tokens fresh, reads the
 * account list, tax codes and customers, and stores the owner's account / tax-code mapping.
 *
 * One set of books (owner-approved plan): a business with a live connection keeps its books in
 * QuickBooks; step 2's sends are off until the mapping is complete and the owner switches them on.
 *
 * Keys (secrets.php, set by the owner — never in code): QBO_CLIENT_ID, QBO_CLIENT_SECRET,
 * QBO_ENVIRONMENT ('sandbox' | 'production'), QBO_TOKEN_KEY (32 bytes, base64 or hex),
 * optional QBO_REDIRECT_URI (default below; must match the Intuit app's setting exactly).
 *
 * Refresh: the row is locked (SELECT … FOR UPDATE) while a token is renewed, because Intuit may
 * return a NEW refresh token — two requests renewing at once could keep the stale one.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/QboTokenVault.php';
require_once __DIR__ . '/QboOAuth.php';
require_once __DIR__ . '/QboClient.php';

class QboConnectionService
{
    public const DEFAULT_REDIRECT = 'https://mowology.ca/crm/accounting/quickbooks-callback.php';
    public const STATE_TTL = 600;            // the sign-in must come back within 10 minutes
    public const KINDS = ['account', 'tax_code', 'customer'];

    private PDO $db;
    private int $siteId;
    private ?QboOAuth $oauth;
    private ?QboTokenVault $vault;
    /** @var callable(): int */
    private $clock;
    /** @var callable|null  GET transport for QboClient (tests) */
    private $http;
    /** @var callable|null  POST transport for QboClient sends (tests) */
    private $httpPost;

    public function __construct(PDO $db, int $siteId = 1, ?QboOAuth $oauth = null, ?QboTokenVault $vault = null,
                                ?callable $clock = null, ?callable $http = null, ?callable $httpPost = null)
    {
        $this->httpPost = $httpPost;
        $this->db = $db;
        $this->siteId = $siteId;
        $cfg = self::config();
        $this->oauth = $oauth ?? ($cfg['client_id'] !== '' && $cfg['client_secret'] !== '' ? new QboOAuth($cfg['client_id'], $cfg['client_secret']) : null);
        $key = QboTokenVault::keyFromSecrets();
        $this->vault = $vault ?? ($key !== null ? new QboTokenVault($key) : null);
        $this->clock = $clock ?? 'time';
        $this->http = $http;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Setup
    // ─────────────────────────────────────────────────────────────────────────

    public static function config(): array
    {
        $env = defined('QBO_ENVIRONMENT') ? strtolower((string)QBO_ENVIRONMENT) : 'sandbox';
        return [
            'client_id'     => defined('QBO_CLIENT_ID') ? (string)QBO_CLIENT_ID : '',
            'client_secret' => defined('QBO_CLIENT_SECRET') ? (string)QBO_CLIENT_SECRET : '',
            'environment'   => in_array($env, ['sandbox', 'production'], true) ? $env : 'sandbox',
            'redirect_uri'  => defined('QBO_REDIRECT_URI') ? (string)QBO_REDIRECT_URI : self::DEFAULT_REDIRECT,
        ];
    }

    /** What the owner still has to set up, in words (empty = ready to connect). */
    public function missing(): array
    {
        $m = [];
        if (!$this->tablesReady()) $m[] = 'run migration 1382 (QuickBooks tables)';
        $cfg = self::config();
        if ($this->oauth === null && ($cfg['client_id'] === '' || $cfg['client_secret'] === '')) $m[] = 'add QBO_CLIENT_ID and QBO_CLIENT_SECRET (from your Intuit developer app) to secrets.php';
        if (QboTokenVault::keyFromSecrets() === null && $this->vault === null) $m[] = 'add QBO_TOKEN_KEY (32 random bytes, base64) to secrets.php';
        return $m;
    }

    public function tablesReady(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM qbo_connections LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sign-in
    // ─────────────────────────────────────────────────────────────────────────

    /** The Intuit sign-in link; the state is kept in the session to check on return. */
    public function startUrl(array &$session): string
    {
        if ($this->missing()) throw new RuntimeException('QuickBooks is not set up: ' . implode('; ', $this->missing()));
        $state = QboOAuth::newState();
        $session['qbo_oauth'] = ['state' => $state, 'at' => ($this->clock)(), 'site' => $this->siteId];
        return QboOAuth::authUrl(self::config()['client_id'], self::config()['redirect_uri'], $state);
    }

    /** The sign-in came back refused or cancelled: forget it only when it is ours (a forged link changes nothing). */
    public function cancel(array &$session, string $state): bool
    {
        $saved = $session['qbo_oauth'] ?? null;
        if (!self::stateOk(is_array($saved) ? $saved : null, $state, ($this->clock)(), $this->siteId)) return false;
        unset($session['qbo_oauth']);
        return true;
    }

    /** Pure (tested): did the sign-in come back to the session that started it, in time? */
    public static function stateOk(?array $saved, string $state, int $now, int $siteId): bool
    {
        if (!$saved || empty($saved['state']) || $state === '') return false;
        if ((int)($saved['site'] ?? 0) !== $siteId) return false;
        if ($now - (int)($saved['at'] ?? 0) > self::STATE_TTL) return false;
        return hash_equals((string)$saved['state'], $state);
    }

    /**
     * The sign-in came back: check the state, swap the code for tokens, store them, read the company.
     * @return array{ok:bool, message:string}
     */
    public function finish(array &$session, string $code, string $state, string $realmId, int $userId): array
    {
        $saved = $session['qbo_oauth'] ?? null;
        $now = ($this->clock)();
        if (!self::stateOk(is_array($saved) ? $saved : null, $state, $now, $this->siteId)) {
            // The browser loaded the return link twice (seen 2026-10-20, one second apart): the first
            // connected this company moments ago — say so instead of a false failure.
            $row = $this->row();
            if (!$saved && $row && (string)$row['realm_id'] === $realmId && $row['status'] === 'connected'
                && $row['connected_at'] && $now - strtotime((string)$row['connected_at']) < 120) {
                return ['ok' => true, 'message' => 'Connected to ' . ($row['company_name'] ?: 'your QuickBooks company') . '.'];
            }
            // A foreign link leaves a sign-in in progress alone (Codex review 2026-10-20).
            return ['ok' => false, 'message' => 'That QuickBooks sign-in did not start here or took too long. Press Connect again.'];
        }
        unset($session['qbo_oauth']);    // our state: used once
        if ($code === '' || !preg_match('/^\d{1,32}$/', $realmId)) {
            return ['ok' => false, 'message' => 'QuickBooks did not send back a company. Press Connect again.'];
        }
        if ($this->missing() || !$this->oauth || !$this->vault) return ['ok' => false, 'message' => 'QuickBooks is not set up yet.'];
        $r = $this->oauth->exchangeCode($code, self::config()['redirect_uri'], $now);
        if (!$r['ok']) return ['ok' => false, 'message' => $r['error']];
        // The company must answer to these tokens BEFORE anything is saved (a changed realmId in the
        // return link would otherwise store a connection that can never read — Codex review 2026-10-20).
        $fresh = $r['tokens']['access'];
        $probe = new QboClient(self::config()['environment'], $realmId, fn(bool $force) => $force ? null : $fresh, $this->http);
        $info = $probe->companyInfo($realmId);
        if (!$info['ok']) return ['ok' => false, 'message' => 'QuickBooks signed in, but that company could not be read: ' . $info['error']];
        $ci = (array)($info['data']['CompanyInfo'] ?? []);
        $prev = $this->row();
        if ($prev && (string)$prev['realm_id'] !== $realmId) {
            // A different QuickBooks company: its account and tax ids mean nothing here — start the mapping clean.
            $this->db->prepare('DELETE FROM qbo_mappings WHERE site_id = ?')->execute([$this->siteId]);
            $this->db->prepare('DELETE FROM qbo_remote_items WHERE site_id = ?')->execute([$this->siteId]);
        }
        $this->saveTokens($r['tokens'], $realmId, $userId);
        $this->db->prepare("UPDATE qbo_connections SET company_name = ?, country = ? WHERE site_id = ?")
                 ->execute([mb_substr((string)($ci['CompanyName'] ?? ''), 0, 255) ?: null, mb_substr((string)($ci['Country'] ?? ''), 0, 8) ?: null, $this->siteId]);
        $pull = $this->pull();
        $name = (string)($ci['CompanyName'] ?? 'your QuickBooks company');
        return ['ok' => true, 'message' => 'Connected to ' . $name . '.' . ($pull['ok'] ? ' ' . $pull['message'] : ' ' . $pull['message'])];
    }

    /** Disconnect: revoke at Intuit (best effort) and forget the tokens. Mappings are kept for a reconnect. */
    public function disconnect(): array
    {
        $row = $this->row();
        if (!$row) return ['ok' => true, 'message' => 'Not connected.'];
        $refresh = $this->vault ? $this->vault->decrypt($row['refresh_token_enc']) : null;
        $revoked = ($refresh !== null && $this->oauth) ? $this->oauth->revoke($refresh) : false;
        $this->db->prepare("UPDATE qbo_connections SET access_token_enc = NULL, refresh_token_enc = NULL, access_expires_at = NULL,
                            status = 'disconnected', disconnected_at = ? WHERE site_id = ?")
                 ->execute([date('Y-m-d H:i:s', ($this->clock)()), $this->siteId]);
        return ['ok' => true, 'message' => 'Disconnected from QuickBooks' . ($revoked ? '.' : ' (QuickBooks did not confirm the revoke; you can also remove the app in QuickBooks → Apps).')];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Status + tokens
    // ─────────────────────────────────────────────────────────────────────────

    /** For the page: never contains a token. */
    public function status(): array
    {
        $row = $this->row();
        if (!$row) return ['connected' => false, 'status' => 'none'];
        $connected = in_array($row['status'], ['connected', 'error'], true) && $row['refresh_token_enc'] !== null;
        return [
            'connected'          => $connected,
            'status'             => (string)$row['status'],
            'environment'        => (string)$row['environment'],
            'company'            => $row['company_name'],
            'country'            => $row['country'],
            'realm_id'           => (string)$row['realm_id'],
            'connected_at'       => $row['connected_at'],
            'refresh_expires_at' => $row['refresh_expires_at'],
            'last_pull_at'       => $row['last_pull_at'],
            'last_error'         => $row['last_error'],
        ];
    }

    /** A usable access token, renewing it when it is old or when $force (after a 401). Null = sign in again. */
    public function accessToken(bool $force = false): ?string
    {
        if (!$this->vault || !$this->oauth) return null;
        $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->db->beginTransaction();
        try {
            $s = $this->db->prepare('SELECT * FROM qbo_connections WHERE site_id = ?' . ($mysql ? ' FOR UPDATE' : ''));
            $s->execute([$this->siteId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            // 'error' = the last renewal failed for a passing reason (timeout, Intuit down): try again.
            // Only 'expired' (refused sign-in / unreadable token) and 'disconnected' need the owner.
            if (!$row || !in_array($row['status'], ['connected', 'error'], true)) { $this->db->commit(); return null; }
            $now = ($this->clock)();
            $access = $this->vault->decrypt($row['access_token_enc']);
            if (!$force && $access !== null && $row['access_expires_at'] && strtotime((string)$row['access_expires_at']) > $now) {
                $this->db->commit();
                return $access;
            }
            $refresh = $this->vault->decrypt($row['refresh_token_enc']);
            if ($refresh === null) {
                // Present but unreadable = the key changed: say so, never treat it as a token.
                $this->markError($row['refresh_token_enc'] !== null
                    ? 'The stored QuickBooks sign-in cannot be read with the current QBO_TOKEN_KEY (the key changed). Connect again.'
                    : 'Not signed in to QuickBooks.', 'expired');
                $this->db->commit();
                return null;
            }
            $r = $this->oauth->refresh($refresh, $now);
            if (!$r['ok']) {
                $this->markError($r['error'], strpos($r['error'], 'expired or was revoked') !== false || strpos($r['error'], 'app keys') !== false ? 'expired' : 'error');
                $this->db->commit();
                return null;
            }
            $t = $r['tokens'];
            $this->db->prepare("UPDATE qbo_connections SET access_token_enc = ?, refresh_token_enc = ?, access_expires_at = ?,
                                refresh_expires_at = COALESCE(?, refresh_expires_at), status = 'connected', last_error = NULL WHERE site_id = ?")
                     ->execute([$this->vault->encrypt($t['access']), $this->vault->encrypt($t['refresh']), $t['access_expires_at'], $t['refresh_expires_at'], $this->siteId]);
            $this->db->commit();
            return $t['access'];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('QuickBooks token refresh failed: ' . $e->getMessage());
            return null;
        }
    }

    public function client(): QboClient
    {
        $row = $this->row();
        return new QboClient((string)($row['environment'] ?? self::config()['environment']), (string)($row['realm_id'] ?? ''),
                             fn(bool $force) => $this->accessToken($force), $this->http, $this->httpPost);
    }

    /** The connected company's id and environment (for links), or null. */
    public function company(): ?array
    {
        $r = $this->row();
        return ($r && in_array($r['status'], ['connected', 'error'], true)) ? ['realm_id' => (string)$r['realm_id'], 'environment' => (string)$r['environment']] : null;
    }

    public function siteId(): int { return $this->siteId; }
    public function db(): PDO { return $this->db; }

    // ─────────────────────────────────────────────────────────────────────────
    // Read: accounts, tax codes, customers
    // ─────────────────────────────────────────────────────────────────────────

    /** Read the three lists from QuickBooks and keep them for the mapping screen. */
    public function pull(): array
    {
        if (!($this->status()['connected'] ?? false)) return ['ok' => false, 'message' => 'Not connected to QuickBooks.'];
        $c = $this->client();
        $lists = [
            'account'  => $c->all('Account', 'Active = true'),
            'tax_code' => $c->all('TaxCode'),
            'customer' => $c->all('Customer', 'Active = true'),
        ];
        foreach ($lists as $kind => $r) {
            if (!$r['ok']) { $this->markError($r['error'], 'connected'); return ['ok' => false, 'message' => $r['error']]; }
        }
        $now = date('Y-m-d H:i:s', ($this->clock)());
        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare('DELETE FROM qbo_remote_items WHERE site_id = ? AND kind = ?');
            $ins = $this->db->prepare('INSERT INTO qbo_remote_items (site_id, kind, qbo_id, name, detail, active, pulled_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($lists as $kind => $r) {
                $del->execute([$this->siteId, $kind]);
                foreach ($r['rows'] as $row) {
                    $item = self::remoteItem($kind, $row);
                    if ($item) $ins->execute([$this->siteId, $kind, $item['qbo_id'], $item['name'], $item['detail'], $item['active'], $now]);
                }
            }
            $this->db->prepare("UPDATE qbo_connections SET last_pull_at = ?, last_error = NULL WHERE site_id = ?")->execute([$now, $this->siteId]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['ok' => false, 'message' => 'Read from QuickBooks but could not save it: ' . $e->getMessage()];
        }
        return ['ok' => true, 'message' => sprintf('Read %d accounts, %d tax codes and %d customers.',
            count($lists['account']['rows']), count($lists['tax_code']['rows']), count($lists['customer']['rows']))];
    }

    /** Pure (tested): one QuickBooks row → what we keep. */
    public static function remoteItem(string $kind, array $row): ?array
    {
        $id = (string)($row['Id'] ?? '');
        if ($id === '') return null;
        if ($kind === 'account') {
            $name = (string)($row['FullyQualifiedName'] ?? $row['Name'] ?? '');
            $detail = trim((string)($row['AccountType'] ?? '') . (isset($row['AcctNum']) && $row['AcctNum'] !== '' ? ' · ' . $row['AcctNum'] : ''));
        } elseif ($kind === 'tax_code') {
            $name = (string)($row['Name'] ?? '');
            $detail = trim((string)($row['Description'] ?? ''));
        } else {
            $name = (string)($row['DisplayName'] ?? $row['CompanyName'] ?? '');
            $detail = (string)($row['PrimaryEmailAddr']['Address'] ?? '');
        }
        if ($name === '') return null;
        return ['qbo_id' => $id, 'name' => mb_substr($name, 0, 255), 'detail' => mb_substr($detail, 0, 255) ?: null,
                'active' => (isset($row['Active']) && $row['Active'] === false) ? 0 : 1];
    }

    public function remote(string $kind): array
    {
        $s = $this->db->prepare('SELECT qbo_id, name, detail FROM qbo_remote_items WHERE site_id = ? AND kind = ? AND active = 1 ORDER BY name');
        $s->execute([$this->siteId, $kind]);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Mapping (set once by the owner)
    // ─────────────────────────────────────────────────────────────────────────

    /** Tax treatments an Ittai line can have → a QuickBooks tax code each. */
    public const TAX_KEYS = ['gst' => 'GST charged (5%)', 'exempt' => 'Exempt / zero-rated', 'out_of_scope' => 'Not taxable (out of scope)'];

    /** Ittai accounts that sends will use: revenue, expense, and the fixed-asset account. */
    public function ittaiAccounts(): array
    {
        $s = $this->db->query("SELECT code, name, type FROM chart_of_accounts WHERE is_active = 1 AND (type IN ('revenue', 'expense') OR code = '1500') ORDER BY code");
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** kind => ittai_key => [qbo_id, qbo_name] */
    public function mappings(): array
    {
        $out = ['account' => [], 'tax_code' => [], 'pay_account' => []];
        $s = $this->db->prepare('SELECT kind, ittai_key, qbo_id, qbo_name FROM qbo_mappings WHERE site_id = ?');
        $s->execute([$this->siteId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['kind']][$r['ittai_key']] = ['qbo_id' => $r['qbo_id'], 'qbo_name' => $r['qbo_name']];
        return $out;
    }

    /** Save one choice; the QuickBooks id must be one we read (never a typed id). '' clears it. */
    public function setMapping(string $kind, string $ittaiKey, string $qboId, int $userId): bool
    {
        // 'pay_account': the Ittai account a receipt was paid from (1010 chequing, 2400 card) → a QuickBooks
        // Bank / Credit Card account (its id is an Account read like the others).
        if (!in_array($kind, ['account', 'tax_code', 'pay_account'], true) || $ittaiKey === '' || strlen($ittaiKey) > 64) return false;
        $remoteKind = $kind === 'pay_account' ? 'account' : $kind;
        if ($qboId === '') {
            $this->db->prepare('DELETE FROM qbo_mappings WHERE site_id = ? AND kind = ? AND ittai_key = ?')->execute([$this->siteId, $kind, $ittaiKey]);
            return true;
        }
        $s = $this->db->prepare('SELECT name FROM qbo_remote_items WHERE site_id = ? AND kind = ? AND qbo_id = ?');
        $s->execute([$this->siteId, $remoteKind, $qboId]);
        $name = $s->fetchColumn();
        if ($name === false) return false;
        if ($kind === 'pay_account') {
            // Paid from: only a Bank or Credit Card account (enforced here, not just in the page's list).
            $d = $this->db->prepare("SELECT detail FROM qbo_remote_items WHERE site_id = ? AND kind = 'account' AND qbo_id = ?");
            $d->execute([$this->siteId, $qboId]);
            if (!in_array(trim(explode(' ·', (string)$d->fetchColumn())[0]), self::TYPE_FIT['pay'], true)) return false;
        }
        $u = $this->db->prepare('UPDATE qbo_mappings SET qbo_id = ?, qbo_name = ?, set_by = ? WHERE site_id = ? AND kind = ? AND ittai_key = ?');
        $u->execute([$qboId, $name, $userId ?: null, $this->siteId, $kind, $ittaiKey]);
        if ($u->rowCount() === 0) {
            $chk = $this->db->prepare('SELECT 1 FROM qbo_mappings WHERE site_id = ? AND kind = ? AND ittai_key = ?');
            $chk->execute([$this->siteId, $kind, $ittaiKey]);
            if (!$chk->fetchColumn()) {
                $this->db->prepare('INSERT INTO qbo_mappings (site_id, kind, ittai_key, qbo_id, qbo_name, set_by) VALUES (?, ?, ?, ?, ?, ?)')
                         ->execute([$this->siteId, $kind, $ittaiKey, $qboId, $name, $userId ?: null]);
            }
        }
        return true;
    }

    /** QuickBooks account types an Ittai account may map to (an income line never lands in a liability). */
    public const TYPE_FIT = [
        'revenue' => ['Income', 'Other Income'],
        'expense' => ['Expense', 'Cost of Goods Sold', 'Other Expense'],
        'asset'   => ['Fixed Asset', 'Other Asset', 'Other Current Asset'],
        'pay'     => ['Bank', 'Credit Card'],          // what a receipt was paid from
    ];

    /**
     * Pure (tested): the QuickBooks accounts that fit an Ittai account's type. The stored detail is
     * "AccountType · AcctNum" (remoteItem). An unknown Ittai type keeps them all.
     * @param list<array{qbo_id:string,name:string,detail:?string}> $remote
     */
    public static function fitting(string $ittaiType, array $remote): array
    {
        $ok = self::TYPE_FIT[$ittaiType] ?? null;
        if ($ok === null) return $remote;
        return array_values(array_filter($remote, fn($r) => in_array(trim(explode(' ·', (string)($r['detail'] ?? ''))[0]), $ok, true)));
    }

    /**
     * Pure (tested): Otto's first guess for an Ittai account — same name, then one name containing the
     * other. Only a guess: the owner confirms it on the page.
     * @param list<array{qbo_id:string,name:string}> $remote
     */
    public static function suggestAccount(string $ittaiName, array $remote): ?string
    {
        $norm = fn(string $s) => trim((string)preg_replace('/[^a-z0-9]+/', ' ', strtolower(preg_replace('/^.*:/', '', $s))));
        $want = $norm($ittaiName);
        if ($want === '') return null;
        foreach ($remote as $r) if ($norm($r['name']) === $want) return (string)$r['qbo_id'];
        $hits = array_values(array_filter($remote, function ($r) use ($norm, $want) {
            $n = $norm($r['name']);
            return $n !== '' && (strpos($n, $want) !== false || strpos($want, $n) !== false);
        }));
        return count($hits) === 1 ? (string)$hits[0]['qbo_id'] : null;
    }

    /** Pure (tested): a guess for each tax key from QuickBooks Canada's usual code names. */
    public static function suggestTaxCode(string $taxKey, array $remote): ?string
    {
        $pick = function (callable $test) use ($remote) {
            $hits = array_values(array_filter($remote, fn($r) => $test(strtoupper(trim((string)$r['name'])))));
            return count($hits) === 1 ? (string)$hits[0]['qbo_id'] : null;
        };
        if ($taxKey === 'gst') return $pick(fn($n) => $n === 'GST' || $n === 'GST ON SALES' || $n === 'G');
        if ($taxKey === 'exempt') return $pick(fn($n) => $n === 'EXEMPT' || $n === 'E' || $n === 'ZERO-RATED' || $n === 'Z');
        if ($taxKey === 'out_of_scope') return $pick(fn($n) => $n === 'OUT OF SCOPE' || $n === 'OUT OF SCOPE GST');
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function row(): ?array
    {
        if (!$this->tablesReady()) return null;
        $s = $this->db->prepare('SELECT * FROM qbo_connections WHERE site_id = ?');
        $s->execute([$this->siteId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function saveTokens(array $t, string $realmId, int $userId): void
    {
        $now = date('Y-m-d H:i:s', ($this->clock)());
        $vals = [self::config()['environment'], $realmId, $this->vault->encrypt($t['access']), $this->vault->encrypt($t['refresh']),
                 $t['access_expires_at'], $t['refresh_expires_at'], $userId ?: null, $now];
        $u = $this->db->prepare("UPDATE qbo_connections SET environment = ?, realm_id = ?, access_token_enc = ?, refresh_token_enc = ?,
                                 access_expires_at = ?, refresh_expires_at = ?, connected_by = ?, connected_at = ?, status = 'connected',
                                 last_error = NULL, disconnected_at = NULL WHERE site_id = ?");
        $u->execute(array_merge($vals, [$this->siteId]));
        if ($u->rowCount() === 0 && !$this->row()) {
            $this->db->prepare("INSERT INTO qbo_connections (environment, realm_id, access_token_enc, refresh_token_enc, access_expires_at,
                                refresh_expires_at, connected_by, connected_at, status, site_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'connected', ?)")
                     ->execute(array_merge($vals, [$this->siteId]));
        }
    }

    private function markError(string $msg, string $status): void
    {
        $this->db->prepare('UPDATE qbo_connections SET last_error = ?, status = ? WHERE site_id = ?')
                 ->execute([mb_substr($msg, 0, 500), $status, $this->siteId]);
    }
}
