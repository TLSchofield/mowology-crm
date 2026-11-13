<?php
/**
 * SupplierContactService — remembers the best human address for supplier quote requests (Tim approved
 * 2026-11-13; plan docs/plans/2026-11-13-supplier-quote-requests.md, step 1; migration 1454).
 *
 * A nursery has a wholesale person and an orders address; a request should go to the one a person answers,
 * and Tim has to see where each address came from (their own mail to us, or their website) before choosing.
 *
 * Supplier addresses are suggestions from mail headers and public supplier pages until a person
 * chooses one. Discovery never sends mail; keeping a default only updates supplier_accounts.email
 * so the existing supplier-domain matching remains the single source of identity.
 *
 * Global-namespace service: require_once the file. Pure rules are static + tested.
 */
declare(strict_types=1);

require_once __DIR__ . '/SupplierDirectoryService.php';
require_once __DIR__ . '/HttpFetch.php';

class SupplierContactService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM supplier_contacts LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function roleOf(string $email, string $context = ''): string
    {
        $local = strtolower((string)strstr($email, '@', true));
        foreach (self::roleWords() as $role => $words) {
            if (preg_match('/(?:^|[^a-z])(?:' . implode('|', $words) . ')(?:[^a-z]|$)/i', $local)) return $role;
        }
        foreach (self::roleWords() as $role => $words) {
            if (preg_match('/\b(?:' . implode('|', $words) . ')\b/i', $context)) return $role;
        }
        return 'general';
    }

    private static function roleWords(): array
    {
        return [
            'wholesale' => ['wholesale', 'trade', 'contractor', 'landscape', 'landscaper'],
            'orders' => ['order', 'orders'],
            'sales' => ['sales'],
            'accounts' => ['accounts', 'ar', 'ap', 'billing', 'invoices', 'accounting'],
        ];
    }

    public static function rank(array $contact): int
    {
        $score = ['wholesale' => 40, 'orders' => 30, 'sales' => 25, 'general' => 10, 'accounts' => 0][$contact['role'] ?? 'general'] ?? 10;
        $sources = explode('|', (string)($contact['sources'] ?? ''));
        if (in_array('mail', $sources, true)) $score += 20;
        if (in_array('website', $sources, true)) $score += 10;
        if (($contact['status'] ?? '') === 'kept') $score += 100;
        if (($contact['status'] ?? '') === 'dropped') $score -= 1000;
        return $score;
    }

    public static function mergeSources(string $have, string $add): string
    {
        $set = array_flip(array_filter(array_merge(explode('|', $have), explode('|', $add))));
        return implode('|', array_values(array_filter(['mail', 'website', 'manual'], fn($s) => isset($set[$s]))));
    }

    public static function emailsInHtml(string $html, string $supplierDomain, string $knownEmail = ''): array
    {
        $domain = SupplierDirectoryService::domainOf($supplierDomain);
        $knownEmail = strtolower(trim($knownEmail));
        $cleanHtml = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $visible = html_entity_decode(strip_tags((string)preg_replace('/<[^>]+>/', ' ', $cleanHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $visible = (string)preg_replace('/\s+/', ' ', $visible);
        $visible = (string)preg_replace('/\s*(?:\[at\]|\(at\))\s*/i', '@', $visible);
        $visible = (string)preg_replace('/\s*\[dot\]\s*/i', '.', $visible);
        $mailtos = '';
        if (preg_match_all('/href\s*=\s*(["\'])mailto:([^"\']+)\1/i', $html, $mm)) $mailtos = implode(' ', $mm[2]);
        $search = html_entity_decode($mailtos, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' ' . $visible;
        preg_match_all('/[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $search, $matches, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($matches[0] as $match) {
            $email = strtolower(trim($match[0], ".,;:()<>\"'"));
            if (isset($out[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            if (preg_match('/(?:@2x\.(?:png|jpe?g|gif|svg|webp)|\.(?:png|jpe?g|gif|svg|webp|css|js))$/i', $email) || $email === '@media') continue;
            $emailDomain = SupplierDirectoryService::domainOf($email);
            $sameDomain = $domain !== '' && ($emailDomain === $domain || self::endsWith($emailDomain, '.' . $domain));
            if (SupplierDirectoryService::isSharedDomain($domain)) $sameDomain = $email === $knownEmail;
            if (!$sameDomain) continue;
            $visiblePos = stripos($visible, $email);
            if ($visiblePos === false) {
                $local = (string)strstr($email, '@', true);
                $visiblePos = stripos($visible, $local);
            }
            $context = $visiblePos === false ? '' : trim(substr($visible, max(0, $visiblePos - 80), min(80, $visiblePos)));
            $person = null;
            if (preg_match('/(?:^|[^A-Za-z])([A-Z][A-Za-z\'’-]+\s+[A-Z][A-Za-z\'’-]+)\s*[,–—|-]?\s*(?:Wholesale|Trade|Contractor|Landscape(?:r)?|Orders?|Sales|Accounts?|Billing|Invoices?|Accounting)(?:\s+(?:Manager|Representative))?\s*[:,-]?\s*$/u', $context, $pm)) {
                $person = $pm[1];
            } elseif (preg_match('/(?:^|[^A-Za-z])([A-Z][A-Za-z\'’-]+\s+[A-Z][A-Za-z\'’-]+)\s*[:,-]?\s*$/u', $context, $pm)
                      && !preg_match('/^(?:Wholesale|Trade|Contractor|Landscape|Orders?|Sales|Accounts?|Billing|Invoices?|Accounting)\b/i', $pm[1])) {
                $person = $pm[1];
            }
            // "General Email", "Contact Us": a label, not a person.
            if ($person !== null && !self::looksLikePerson($person)) $person = null;
            $out[$email] = ['email' => $email, 'person' => $person, 'context' => $context];
        }
        return array_values($out);
    }

    public static function wholesaleLinks(string $html, string $baseUrl): array
    {
        $base = self::normalUrl($baseUrl);
        $baseParts = parse_url($base);
        if (!$baseParts || empty($baseParts['host'])) return [];
        $links = [];
        if (preg_match_all('#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $a) {
                $href = html_entity_decode(trim($a[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = trim(strip_tags(html_entity_decode($a[3], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($href === '' || $href[0] === '#') continue;
                // mailto:, tel:, javascript: (ASP.NET postback links) — only real pages are fetched.
                if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $href) && !preg_match('#^https?://#i', $href)) continue;
                $url = self::absoluteUrl($href, $base);
                $parts = parse_url($url);
                if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) continue;
                if (strtolower((string)$parts['host']) !== strtolower((string)$baseParts['host'])) continue;
                if (!preg_match('/wholesale|trade|contractor|landscaper|professional|contact|about/i', $href . ' ' . $text)) continue;
                if (preg_match('/(?:add-to-cart|account|login|cart|checkout)/i', (string)($parts['query'] ?? ''))) continue;
                $url = preg_replace('/#.*$/', '', $url);
                $priority = preg_match('/wholesale|trade/i', $href . ' ' . $text) ? 0 : 1;
                $links[$url] = min($links[$url] ?? 1, $priority);
            }
        }
        uksort($links, function ($a, $b) use ($links) { return $links[$a] <=> $links[$b]; });
        return array_slice(array_keys($links), 0, 6);
    }

    /** "General Email", "Customer Service", "Greenleaf Ltd": a label or a company, not a person. */
    public static function looksLikePerson(string $name): bool
    {
        $name = trim($name);
        if ($name === '') return false;
        return !preg_match('/\b(?:email|e-mail|phone|fax|contact|general|inquir(?:y|ies)|enquir(?:y|ies)|info|office|store|hours|customer|service|us|team|sales|orders?|accounts?|ltd|inc|corp|llc|limited|co)\b\.?/i', $name);
    }

    private static function normalUrl(string $url): string
    {
        return preg_match('#^https?://#i', $url) ? $url : 'https://' . ltrim($url, '/');
    }

    private static function absoluteUrl(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) return $href;
        $p = parse_url($base);
        $root = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
        if (strpos($href, '//') === 0) return ($p['scheme'] ?? 'https') . ':' . $href;
        if (strpos($href, '/') === 0) return $root . $href;
        $path = (string)($p['path'] ?? '/');
        return $root . rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $href;
    }

    private static function endsWith(string $value, string $suffix): bool
    {
        return $suffix === '' || substr($value, -strlen($suffix)) === $suffix;
    }

    public function forSupplier(int $supplierId): array
    {
        $s = $this->db->prepare('SELECT * FROM supplier_contacts WHERE supplier_account_id = ?');
        $s->execute([$supplierId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        usort($rows, fn($a, $b) => self::rank($b) <=> self::rank($a));
        return $rows;
    }

    public function all(): array
    {
        $rows = $this->db->query('SELECT id, name, email, website, domain, sells, status FROM supplier_accounts ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['contacts'] = $this->forSupplier((int)$row['id']);
        unset($row);
        return $rows;
    }

    public function suggest(int $supplierId, string $email, string $source, array $meta = []): int
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address');
        if (!in_array($source, ['mail', 'website', 'manual'], true)) throw new InvalidArgumentException('Unknown contact source');
        $seen = (string)($meta['seen_at'] ?? date('Y-m-d H:i:s'));
        $s = $this->db->prepare('SELECT * FROM supplier_contacts WHERE supplier_account_id = ? AND email = ?');
        $s->execute([$supplierId, $email]);
        $old = $s->fetch(PDO::FETCH_ASSOC);
        $person = trim((string)($meta['person'] ?? ''));
        $role = in_array($meta['role'] ?? '', ['wholesale', 'orders', 'sales', 'accounts', 'general'], true) ? $meta['role'] : self::roleOf($email);
        if ($old) {
            $person = mb_strlen($person) > mb_strlen((string)$old['person']) ? $person : (string)$old['person'];
            $role = $old['role'] === 'general' && $role !== 'general' ? $role : $old['role'];
            $this->db->prepare('UPDATE supplier_contacts SET sources = ?, person = ?, role = ?, mail_ref = COALESCE(?, mail_ref), web_ref = COALESCE(?, web_ref), first_seen = LEAST(first_seen, ?), last_seen = GREATEST(last_seen, ?) WHERE id = ?')
                ->execute([self::mergeSources((string)$old['sources'], $source), $person ?: null, $role, $meta['mail_ref'] ?? null, $meta['web_ref'] ?? null, $seen, $seen, (int)$old['id']]);
            return (int)$old['id'];
        }
        $this->db->prepare("INSERT INTO supplier_contacts (supplier_account_id, email, person, role, sources, mail_ref, web_ref, first_seen, last_seen, status, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'suggested', 0, NOW())")
            ->execute([$supplierId, $email, $person ?: null, $role, $source, $meta['mail_ref'] ?? null, $meta['web_ref'] ?? null, $seen, $seen]);
        return (int)$this->db->lastInsertId();
    }

    public function keep(int $id, int $userId, bool $default = true): void
    {
        $s = $this->db->prepare('SELECT c.*, a.domain FROM supplier_contacts c JOIN supplier_accounts a ON a.id = c.supplier_account_id WHERE c.id = ?');
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('Contact not found');
        $domain = strtolower(trim((string)$row['domain']));
        $emailDomain = SupplierDirectoryService::domainOf((string)$row['email']);
        if ($default && $domain !== '' && $emailDomain !== $domain && !self::endsWith($emailDomain, '.' . $domain)) {
            throw new InvalidArgumentException("That address is not on the supplier's domain");
        }
        // A supplier on gmail/telus… is matched by its whole address; another free-mail address would change whose mail it is.
        $s = $this->db->prepare('SELECT email FROM supplier_accounts WHERE id = ?');
        $s->execute([(int)$row['supplier_account_id']]);
        $current = strtolower(trim((string)$s->fetchColumn()));
        if ($default && SupplierDirectoryService::isSharedDomain($emailDomain) && $current !== '' && $current !== strtolower((string)$row['email'])) {
            throw new InvalidArgumentException('A free-mail address (gmail, telus…) cannot replace the account address; mail from this supplier is matched by it. Change it on the supplier record if it really has moved.');
        }
        $this->db->beginTransaction();
        try {
            if ($default) $this->db->prepare('UPDATE supplier_contacts SET is_default = 0 WHERE supplier_account_id = ? AND id <> ?')->execute([(int)$row['supplier_account_id'], $id]);
            $this->db->prepare("UPDATE supplier_contacts SET status = 'kept', is_default = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")
                ->execute([$default ? 1 : (int)$row['is_default'], $userId, $id]);
            if ($default) $this->db->prepare('UPDATE supplier_accounts SET email = ? WHERE id = ?')->execute([$row['email'], (int)$row['supplier_account_id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Returns true when it was the default: supplier_accounts.email is left as it is until Tim chooses another. */
    public function drop(int $id, int $userId): bool
    {
        $s = $this->db->prepare('SELECT is_default FROM supplier_contacts WHERE id = ?');
        $s->execute([$id]);
        $was = $s->fetchColumn();
        if ($was === false) throw new InvalidArgumentException('Contact not found');
        $this->db->prepare("UPDATE supplier_contacts SET status = 'dropped', is_default = 0, decided_by = ?, decided_at = NOW() WHERE id = ?")->execute([$userId, $id]);
        return (int)$was === 1;
    }

    /**
     * Only public web pages are fetched: http(s), default port, a host name (no IP literal, no localhost) whose
     * addresses are all public. A supplier's website is typed by staff; this keeps it from reaching the server's own network.
     */
    public static function publicUrl(string $url, ?callable $resolve = null): bool
    {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)) return false;
        if (isset($p['port']) || isset($p['user']) || isset($p['pass'])) return false;
        $host = strtolower((string)($p['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || strpos($host, '.') === false) return false;
        $ips = $resolve ? $resolve($host) : @gethostbynamel($host);
        if (!is_array($ips) || !$ips) return false;
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        }
        return true;
    }

    public function fromWebsite(array $supplier, ?callable $fetch = null, float $budgetSeconds = 20): array
    {
        $customFetch = $fetch !== null;
        $fetch = $fetch ?? fn($u) => HttpFetch::get($u, 10, ['User-Agent: Mowology supplier contacts (office@mowology.ca)']);
        $started = microtime(true);
        $website = self::normalUrl((string)($supplier['website'] ?? ''));
        $domain = (string)($supplier['domain'] ?: SupplierDirectoryService::domainOf($website));
        $known = (string)($supplier['email'] ?? '');
        $queue = [$website];
        $pages = 0;
        $found = [];
        $note = '';
        while ($queue && $pages < 5 && microtime(true) - $started < $budgetSeconds) {
            $url = array_shift($queue);
            if (!$customFetch && !self::publicUrl($url)) { $note = 'Skipped an address that is not a public web page.'; continue; }
            if ($pages > 0 && !$customFetch) sleep(1);
            try { $html = $fetch($url); } catch (Throwable $e) { $html = null; }
            if (!is_string($html) || $html === '') { $note = 'One or more pages could not be read.'; continue; }
            $pages++;
            if ($pages === 1) $queue = array_slice(self::wholesaleLinks($html, $url), 0, 4);
            foreach (self::emailsInHtml($html, $domain, $known) as $contact) {
                $this->suggest((int)$supplier['id'], $contact['email'], 'website', ['person' => $contact['person'], 'role' => self::roleOf($contact['email'], $contact['context']), 'web_ref' => $url]);
                $found[$contact['email']] = true;
            }
        }
        return ['pages' => $pages, 'found' => count($found), 'note' => $note];
    }

    public function fromWebsites(int $limit = 10, float $budgetSeconds = 50): array
    {
        $s = $this->db->prepare("SELECT a.* FROM supplier_accounts a WHERE a.website IS NOT NULL AND a.website <> '' ORDER BY EXISTS (SELECT 1 FROM supplier_contacts c WHERE c.supplier_account_id = a.id AND c.status = 'kept'), a.name LIMIT ?");
        $s->bindValue(1, $limit, PDO::PARAM_INT);
        $s->execute();
        $started = microtime(true);
        $totals = ['suppliers' => 0, 'pages' => 0, 'found' => 0, 'note' => ''];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $supplier) {
            $left = $budgetSeconds - (microtime(true) - $started);
            if ($left <= 0) break;
            $r = $this->fromWebsite($supplier, null, $left);
            $totals['suppliers']++;
            $totals['pages'] += $r['pages'];
            $totals['found'] += $r['found'];
            if ($r['note'] !== '') $totals['note'] = $r['note'];
        }
        return $totals;
    }

    public function importMailSightings(array $rows): array
    {
        $directory = new SupplierDirectoryService($this->db);
        $counts = ['rows' => count($rows), 'matched' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            $email = strtolower(trim((string)($row['email'] ?? '')));
            $local = (string)strstr($email, '@', true);
            $supplier = $directory->forAddress($email);
            if (!$supplier || !SupplierDirectoryService::senderMatches($email, $supplier) || preg_match('/(?:no-?reply|bounce|mailer-daemon|notifications?|newsletter|marketing|mailchimp)/i', $local)) {
                $counts['skipped']++;
                continue;
            }
            $date = (string)($row['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date)) $date = date('Y-m-d H:i:s');
            $person = trim((string)($row['person'] ?? ''));
            if (!self::looksLikePerson($person)) $person = '';
            $this->suggest((int)$supplier['id'], $email, 'mail', ['person' => $person, 'role' => self::roleOf($email, $person), 'mail_ref' => (string)($row['mailbox'] ?? 'other') . ' · ' . substr($date, 0, 10), 'seen_at' => $date]);
            $counts['matched']++;
        }
        return $counts;
    }
}
