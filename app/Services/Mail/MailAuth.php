<?php
/**
 * MailAuth — is this email really from the sender it names? (security review #4/#5/#20, 2026-10-12)
 *
 * A From header is free text: anyone can send mail "From: DoNotReply@yardi.com". Money readers
 * (Yardi remittances, Interac notices) and the supplier price-list import used to trust it. Now:
 *
 *   1. There is exactly ONE From header and its address — the one in <angle brackets>, or the
 *      whole header when it is a bare address — equals the expected address exactly.
 *   2. AND the mail is authenticated for that address's domain:
 *        - an Authentication-Results header from a TRUSTED receiving server says dmarc=pass, or
 *          dkim=pass for that domain (trusted ids come from the caller / MAIL_TRUSTED_AUTHSERV_IDS;
 *          none by default — see below), or
 *        - we verify a DKIM-Signature ourselves (RFC 6376: rsa-sha256, simple/relaxed, key from DNS)
 *          whose d= is the From domain (or a parent of it), that signs From, and has no l= tag.
 *   Anything else is "not verified" — callers hold it for a person and never auto-record it.
 *
 * Why our own DKIM check: production (mail.mowology.ca, cPanel exim) adds NO Authentication-Results
 * header at all — checked 2026-10-12 against the last 3 Yardi and Interac messages in office@ and
 * info@ (they carry DKIM-Signature d=yardi.com / d=payments.interac.ca, X-Spam-Status, no
 * Authentication-Results / ARC / Received-SPF). So on office@/info@ an Authentication-Results header
 * can only have been written by the SENDER, and is ignored unless its authserv-id is trusted.
 *
 * Fails closed: DNS failure, unknown algorithm, ed25519, a key under 1024 bits, an expired signature,
 * a body hash mismatch — all "not verified". A bug here can only hold good mail, never pass bad mail
 * (every pass needs openssl_verify() === 1 over the signed From and a matching body hash).
 *
 * Pure apart from the DNS lookup, which is injectable for tests. Global namespace: require_once it.
 */
class MailAuth
{
    /** @var array<string, ?string> selector._domainkey.domain → p= key (null = none / revoked) */
    private static array $keyCache = [];

    /**
     * The decision for one message.
     * @param string        $rawHeaders  imap_fetchheader() — the header block as received
     * @param string        $rawBody     imap_body(FT_PEEK) — the undecoded body as received
     * @param string        $expected    the exact sender address required (case-insensitive)
     * @param callable|null $dns         fn(string $name): string[] TXT strings (tests); default DNS
     * @param string[]|null $trustedIds  authserv-ids whose Authentication-Results we believe
     * @return array{ok: bool, method: string, reason: string, from: ?string}
     */
    public static function verify(string $rawHeaders, string $rawBody, string $expected, ?callable $dns = null, ?array $trustedIds = null): array
    {
        $froms = self::headerValues($rawHeaders, 'from');
        if (count($froms) !== 1) {
            return ['ok' => false, 'method' => 'none', 'reason' => count($froms) . ' From headers', 'from' => null];
        }
        $from = self::exactAddress($froms[0]);
        if ($from === null || strcasecmp($from, trim($expected)) !== 0) {
            return ['ok' => false, 'method' => 'none', 'reason' => 'sender is not ' . $expected, 'from' => $from];
        }
        $domain = strtolower(substr($from, strrpos($from, '@') + 1));

        $ar = self::authResults($rawHeaders, $domain, $trustedIds ?? self::trustedIds());
        if ($ar === false) return ['ok' => false, 'method' => 'auth-results', 'reason' => 'receiving server says it failed', 'from' => $from];
        if ($ar === true) return ['ok' => true, 'method' => 'auth-results', 'reason' => 'receiving server verified it', 'from' => $from];

        $dk = self::dkim($rawHeaders, $rawBody, $domain, $dns);
        return ['ok' => $dk['ok'], 'method' => 'dkim', 'reason' => $dk['reason'], 'from' => $from];
    }

    /**
     * The sender address only when it is unambiguous: the address inside <brackets>, or the whole
     * header when it is nothing but an address. A display name never counts. Lower-cased. PURE.
     */
    public static function exactAddress(string $fromHeader): ?string
    {
        $h = trim($fromHeader);
        if (preg_match_all('/<([^<>\s]+@[^<>\s]+)>/', $h, $m) === 1) return strtolower($m[1][0]);
        if (strpos($h, '<') !== false || strpos($h, '>') !== false) return null;
        return preg_match('/^[^\s@"(),;:]+@[A-Za-z0-9.-]+$/', $h) ? strtolower($h) : null;
    }

    /** Authserv-ids to believe, from MAIL_TRUSTED_AUTHSERV_IDS (comma-separated). None by default. */
    public static function trustedIds(): array
    {
        if (!defined('MAIL_TRUSTED_AUTHSERV_IDS')) return [];
        return array_values(array_filter(array_map(fn($s) => strtolower(trim($s)), explode(',', (string)constant('MAIL_TRUSTED_AUTHSERV_IDS')))));
    }

    /**
     * Verdict of trusted Authentication-Results headers for $domain: true = dmarc=pass or
     * dkim=pass for the domain, false = dmarc=fail, null = no trusted verdict. PURE.
     */
    public static function authResults(string $rawHeaders, string $domain, array $trustedIds): ?bool
    {
        if (!$trustedIds) return null;
        $verdict = null;
        foreach (self::headerValues($rawHeaders, 'authentication-results') as $v) {
            $v = strtolower(trim($v));
            $id = trim((string)preg_split('/[;\s]/', $v, 2)[0]);
            if (!in_array($id, $trustedIds, true)) continue;
            if (preg_match('/\bdmarc=fail\b/', $v)) return false;
            if (preg_match('/\bdmarc=pass\b[^;]*header\.from=' . preg_quote($domain, '/') . '\b/', $v)) $verdict = true;
            if (preg_match('/\bdkim=pass\b[^;]*header\.(d|i)=@?' . preg_quote($domain, '/') . '\b/', $v)) $verdict = true;
        }
        return $verdict;
    }

    // ── DKIM (RFC 6376) ─────────────────────────────────────────────────────────

    /**
     * Verify the DKIM signatures that are aligned with $domain; ok on the first that verifies.
     * @return array{ok: bool, reason: string}
     */
    public static function dkim(string $rawHeaders, string $rawBody, string $domain, ?callable $dns = null): array
    {
        $fields = self::fields($rawHeaders);
        $reason = 'no DKIM signature for ' . $domain;
        $tried = 0;
        foreach ($fields as $f) {
            if ($f['name'] !== 'dkim-signature') continue;
            if (++$tried > 5) break;
            $r = self::verifyOne($f['raw'], $fields, $rawBody, $domain, $dns);
            if ($r === '') return ['ok' => true, 'reason' => 'DKIM signature verified'];
            if ($r !== 'not aligned') $reason = 'DKIM: ' . $r;
        }
        return ['ok' => false, 'reason' => $reason];
    }

    /** '' when this one signature verifies, else why not. */
    private static function verifyOne(string $sigField, array $fields, string $rawBody, string $domain, ?callable $dns): string
    {
        $value = substr($sigField, strpos($sigField, ':') + 1);
        $t = self::tags($value);
        foreach (['v', 'a', 'b', 'bh', 'd', 'h', 's'] as $req) {
            if (!isset($t[$req]) || $t[$req] === '') return "missing $req=";
        }
        $d = strtolower($t['d']);
        if (!self::aligned($d, $domain)) return 'not aligned';
        if ($t['v'] !== '1') return 'unknown version';
        if (strtolower($t['a']) !== 'rsa-sha256') return 'unsupported algorithm ' . $t['a'];
        if (isset($t['l'])) return 'signs only part of the body (l=)';
        if (isset($t['x']) && ctype_digit($t['x']) && (int)$t['x'] < time()) return 'signature expired';
        $signed = array_map(fn($h) => strtolower(trim($h)), explode(':', $t['h']));
        if (!in_array('from', $signed, true)) return 'From is not signed';
        [$hc, $bc] = array_pad(explode('/', strtolower($t['c'] ?? 'simple/simple'), 2), 2, 'simple');
        if (!in_array($hc, ['simple', 'relaxed'], true) || !in_array($bc, ['simple', 'relaxed'], true)) return 'unknown canonicalization';

        $bodyCanon = $bc === 'relaxed' ? self::canonBodyRelaxed($rawBody) : self::canonBodySimple($rawBody);
        if (!hash_equals(base64_encode(hash('sha256', $bodyCanon, true)), preg_replace('/\s+/', '', $t['bh']))) return 'body was changed';

        // Signed headers: each name takes the last not-yet-used instance, bottom up.
        $byName = [];
        foreach ($fields as $f) $byName[$f['name']][] = $f['raw'];
        $used = [];
        $data = '';
        foreach ($signed as $name) {
            if ($name === '') continue;
            $list = $byName[$name] ?? [];
            $i = count($list) - 1 - ($used[$name] ?? 0);
            $used[$name] = ($used[$name] ?? 0) + 1;
            if ($i < 0) continue;   // signed as absent
            $data .= $hc === 'relaxed' ? self::canonHeaderRelaxed($list[$i]) : self::canonHeaderSimple($list[$i]);
        }
        $sigNoB = self::stripB($sigField);
        $sigCanon = $hc === 'relaxed' ? self::canonHeaderRelaxed($sigNoB) : self::canonHeaderSimple($sigNoB);
        $data .= substr($sigCanon, 0, -2);   // the signature header itself, without its CRLF

        $key = self::publicKey(strtolower($t['s']) . '._domainkey.' . $d, $dns);
        if ($key === null) return 'no public key in DNS';
        $pk = @openssl_pkey_get_public($key);
        if ($pk === false) return 'public key unreadable';
        $det = openssl_pkey_get_details($pk);
        if (!$det || ($det['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA || ($det['bits'] ?? 0) < 1024) return 'weak or non-RSA key';
        $sig = base64_decode(preg_replace('/\s+/', '', $t['b']), true);
        if ($sig === false) return 'signature unreadable';
        return openssl_verify($data, $sig, $pk, OPENSSL_ALGO_SHA256) === 1 ? '' : 'signature does not verify';
    }

    /** d= covers the From domain: equal, or a parent of it (never a bare TLD). PURE. */
    public static function aligned(string $d, string $fromDomain): bool
    {
        $d = strtolower(trim($d, '. '));
        $f = strtolower(trim($fromDomain, '. '));
        if ($d === '' || strpos($d, '.') === false) return false;
        return $f === $d || substr($f, -strlen($d) - 1) === '.' . $d;
    }

    /** "-----BEGIN PUBLIC KEY-----" PEM from the selector's TXT record, or null. */
    private static function publicKey(string $name, ?callable $dns): ?string
    {
        if (!array_key_exists($name, self::$keyCache) || $dns !== null) {
            $txts = $dns ? (array)$dns($name) : self::dnsTxt($name);
            $p = null;
            foreach ($txts as $txt) {
                $kt = self::tags((string)$txt);
                if (isset($kt['v']) && $kt['v'] !== 'DKIM1') continue;
                if (isset($kt['k']) && strtolower($kt['k']) !== 'rsa') continue;
                if (isset($kt['h']) && !in_array('sha256', array_map('trim', explode(':', strtolower($kt['h']))), true)) continue;
                $raw = preg_replace('/\s+/', '', (string)($kt['p'] ?? ''));
                if ($raw === '') continue;   // revoked
                $p = $raw;
                break;
            }
            if ($dns !== null) return $p === null ? null : self::pem($p);
            self::$keyCache[$name] = $p;
        }
        $p = self::$keyCache[$name];
        return $p === null ? null : self::pem($p);
    }

    private static function pem(string $b64): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($b64, 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** @return string[] TXT strings for a name (each record's strings joined). */
    private static function dnsTxt(string $name): array
    {
        if (!function_exists('dns_get_record')) return [];
        $recs = @dns_get_record($name, DNS_TXT);
        $out = [];
        foreach (is_array($recs) ? $recs : [] as $r) {
            $out[] = isset($r['entries']) && is_array($r['entries']) ? implode('', $r['entries']) : (string)($r['txt'] ?? '');
        }
        return $out;
    }

    /** tag=value list → map (whitespace around tags trimmed). PURE. */
    public static function tags(string $list): array
    {
        $out = [];
        $list = (string)preg_replace("/\r?\n[ \t]+/", ' ', $list);
        foreach (explode(';', $list) as $pair) {
            $pos = strpos($pair, '=');
            if ($pos === false) continue;
            $k = strtolower(trim(substr($pair, 0, $pos)));
            if ($k === '' || isset($out[$k])) continue;
            $out[$k] = trim(substr($pair, $pos + 1));
        }
        return $out;
    }

    /** The signature header with the b= value emptied (bh= untouched). PURE. */
    public static function stripB(string $sigField): string
    {
        $colon = strpos($sigField, ':');
        $name = substr($sigField, 0, $colon + 1);
        $value = substr($sigField, $colon + 1);
        $value = (string)preg_replace('/((?:^|;)[ \t\r\n]*b[ \t\r\n]*=)[^;]*/', '$1', $value);
        return $name . $value;
    }

    // ── Header block parsing + canonicalization ────────────────────────────────

    /**
     * Header fields in order, folding kept: [{name: lower-case, raw: "Name: value\r\n\tmore"}]. PURE.
     * @return array<int, array{name: string, raw: string}>
     */
    public static function fields(string $rawHeaders): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $rawHeaders);
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') { if ($out) break; continue; }   // end of the header block
            if (($line[0] === ' ' || $line[0] === "\t")) {
                if ($out) $out[count($out) - 1]['raw'] .= "\r\n" . $line;
                continue;
            }
            $c = strpos($line, ':');
            if ($c === false) continue;
            $out[] = ['name' => strtolower(rtrim(substr($line, 0, $c))), 'raw' => $line];
        }
        return $out;
    }

    /** Unfolded values of one header, in order. PURE. */
    public static function headerValues(string $rawHeaders, string $name): array
    {
        $out = [];
        foreach (self::fields($rawHeaders) as $f) {
            if ($f['name'] === strtolower($name)) {
                $v = substr($f['raw'], strpos($f['raw'], ':') + 1);
                $out[] = trim((string)preg_replace("/\r\n[ \t]+/", ' ', $v));
            }
        }
        return $out;
    }

    public static function canonHeaderSimple(string $field): string
    {
        return $field . "\r\n";
    }

    public static function canonHeaderRelaxed(string $field): string
    {
        $c = strpos($field, ':');
        $name = strtolower(trim(substr($field, 0, $c)));
        $value = str_replace("\r\n", '', substr($field, $c + 1));
        $value = trim((string)preg_replace('/[ \t]+/', ' ', $value), " \t");
        return $name . ':' . $value . "\r\n";
    }

    /** @return string[] body lines (CRLF-normalised, the final line ending dropped) */
    private static function bodyLines(string $body): array
    {
        $body = (string)preg_replace('/\r?\n|\r/', "\r\n", $body);
        $lines = explode("\r\n", $body);
        if (end($lines) === '') array_pop($lines);
        return $lines;
    }

    public static function canonBodySimple(string $body): string
    {
        $lines = self::bodyLines($body);
        while ($lines && end($lines) === '') array_pop($lines);
        return $lines ? implode("\r\n", $lines) . "\r\n" : "\r\n";
    }

    public static function canonBodyRelaxed(string $body): string
    {
        $lines = array_map(fn($l) => rtrim((string)preg_replace('/[ \t]+/', ' ', $l), " \t"), self::bodyLines($body));
        while ($lines && end($lines) === '') array_pop($lines);
        return $lines ? implode("\r\n", $lines) . "\r\n" : '';
    }
}
