<?php
declare(strict_types=1);

/**
 * FieldAskService — the "Ask first" half of field recommendations (migration 1180).
 *
 * A crew member (or Tim) on a visit picks a service and takes photos. Instead of a priced
 * quote, "Ask first" sends a short note in Tim's voice, with the photos attached and NO
 * price, to the person who DECIDES the work — the property's on-site contact
 * (property_contacts / OnsiteContactService), else the site contact — asking "would you
 * like us to do this?". The quote then goes to whoever signs off the spend.
 *
 * Rules (owner's decisions, 2026-10-06 — docs/crm/field-ask-first-plan.md):
 *   - Only someone with billing.edit (admin, manager) sends an ask. Crew create a draft.
 *     Nothing here ever sends on its own.
 *   - Plain letter signed by the sender, Mowology's name + address and an unsubscribe link
 *     in a plain footer. Up to 4 photos as 1024px JPEG attachments. sendEmail() only.
 *   - CASL: company-managed property → business-to-business note; otherwise the consent
 *     ledger (Mia) when it exists, else canSendMarketing(). Unsubscribed always blocks.
 *   - Replies go to office@ (Reply-To) → sales_messages → Sam's card: "Build the quote".
 *   - Tim's edits teach the next draft for that service (Sam's learned_body pattern).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class FieldAskService
{
    /** admin + manager hold it (migration 400) — "create, edit, send quotes". */
    public const SEND_PERMISSION = 'billing.edit';
    public const MAX_PHOTOS = 4;
    public const PHOTO_WIDTH = 1024;
    public const MAX_ATTACH_BYTES = 6291456;   // 6 MB across all photos
    /** An ask with no reply after this many days shows as "no reply yet" on Sam's card. */
    public const SILENT_DAYS = 7;
    /** Asks older than this drop off Sam's card. */
    public const MAX_AGE_DAYS = 45;
    public const OFFICE_PHONE = '(778) 846-9273';

    public const DEFAULT_SUBJECT = '{place}: {service} (photos from {day})';
    public const DEFAULT_BODY = "Hi {first_name},\n\n"
        . "We were at {place} {when} and took a few photos of {area}. They're attached.\n\n"
        . "{pitch}\n\n"
        . "Would you like us to go ahead? Just reply \"yes\" and {next_step}.\n\n"
        . "Thanks,\n{owner}\nMowology · {phone}";

    /**
     * Per-service wording, written to the voice card (.agents/product-marketing-context.md §7)
     * and app/Services/Copy/mowology-copy-rules.md: no exclamation marks, no price, one ask.
     * Fall cleanup is Tim's own wording (approved 2026-10-06). Aeration is drafted for his
     * approval. products.field_ask_pitch overrides the pitch for any service.
     */
    public const SERVICES = [
        'fall_cleanup' => [
            'service' => 'fall cleanup',
            'area'    => 'the beds',
            'pitch'   => "They're ready for their fall cleanup: we trim and prune the shrubs, clear out the beds and put them to bed for the winter, so they come back tidy in spring instead of overgrown.",
        ],
        'aeration' => [
            'service' => 'lawn aeration',
            'area'    => 'the lawn',
            'pitch'   => "The soil is packed down hard, which is when aeration earns its keep: we pull small plugs out of the lawn so water, air and feed reach the roots again. The grass thickens up through the fall, and moss has less to work with next spring.",
        ],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Migration 1180 has run. */
    public function ready(): bool
    {
        return $this->hasColumn('field_observations', 'ask_contact_id');
    }

    public function hasTable(string $t): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE " . $this->db->quote(preg_replace('/[^a-z0-9_]/', '', $t)))->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** app/ — APP_ROOT in production, worked out from this file elsewhere (tests). */
    private static function appRoot(): string
    {
        return defined('APP_ROOT') ? (string)constant('APP_ROOT') : dirname(__DIR__, 3);
    }

    private function hasColumn(string $table, string $col): bool
    {
        static $cache = [];
        $k = $table . '.' . $col;
        if (!array_key_exists($k, $cache)) {
            try {
                $cache[$k] = $this->db->query("SHOW COLUMNS FROM `" . preg_replace('/[^a-z0-9_]/', '', $table) . "` LIKE "
                    . $this->db->quote($col))->rowCount() > 0;
            } catch (Throwable $e) {
                $cache[$k] = false;
            }
        }
        return $cache[$k];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Who may send
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * May this user send an ask (and the quote)? Works for session and JWT users alike —
     * it reads the RBAC tables directly instead of the session-only userHasPermission().
     */
    public static function canSend(PDO $db, array $user): bool
    {
        $uid = (int)($user['id'] ?? 0);
        $perms = null;
        if ($uid > 0) {
            try {
                $s = $db->prepare("
                    SELECT DISTINCT p.`key`
                    FROM user_roles ur
                    JOIN role_permissions rp ON rp.role_id = ur.role_id
                    JOIN permissions p ON p.id = rp.permission_id
                    WHERE ur.user_id = ?
                ");
                $s->execute([$uid]);
                $perms = $s->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable $e) {
                $perms = null;   // RBAC not migrated here → legacy role
            }
        }
        return self::decideCanSend((string)($user['role'] ?? ''), $perms);
    }

    /**
     * Admin always; otherwise the user's RBAC permissions decide; with no RBAC roles at all,
     * the legacy users.role does (manager — mirrors _legacyPermissions() in authz.php).
     */
    public static function decideCanSend(string $role, ?array $perms): bool
    {
        $role = strtolower(trim($role));
        if ($role === 'admin') {
            return true;
        }
        if ($perms) {
            return in_array('*', $perms, true) || in_array(self::SEND_PERMISSION, $perms, true);
        }
        return $role === 'manager';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Who gets it
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Who each button goes to for a property.
     * ask   — the on-site contact if they have an email, else the site contact; null if neither.
     * quote — the site/billing contact (the quote itself still routes through QuoteService).
     * @return array{property: ?array, ask: ?array, quote: ?array, same: bool}
     */
    public function recipients(int $propertyId): array
    {
        $out = ['property' => null, 'ask' => null, 'quote' => null, 'same' => false];
        if ($propertyId <= 0) {
            return $out;
        }
        $s = $this->db->prepare("
            SELECT p.id, p.property_name, p.address, p.city, p.site_contact_id,
                   c.id AS cid, c.first_name, c.last_name, c.email
            FROM properties p
            LEFT JOIN contacts c ON c.id = p.site_contact_id
            WHERE p.id = ? LIMIT 1
        ");
        $s->execute([$propertyId]);
        $p = $s->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            return $out;
        }
        $out['property'] = ['id' => (int)$p['id'], 'name' => self::placeName($p)];

        $site = !empty($p['cid']) ? self::person((int)$p['cid'], $p['first_name'], $p['last_name'], $p['email'], 'site') : null;

        $onsite = null;
        try {
            require_once self::appRoot() . '/Modules/Contacts/Services/OnsiteContactService.php';
            $o = (new OnsiteContactService($this->db))->getForProperty($propertyId);
            if ($o && $o['contact_id'] > 0) {
                [$first, $last] = OnsiteContactService::splitName((string)$o['name']);
                $onsite = self::person((int)$o['contact_id'], $first, $last, $o['email'], 'onsite');
            }
        } catch (Throwable $e) {
            $onsite = null;
        }

        $out['ask']   = self::pickAskRecipient($onsite, $site);
        $out['quote'] = $site;
        $out['same']  = $out['ask'] && $site && $out['ask']['contact_id'] === $site['contact_id'];
        return $out;
    }

    private static function person(int $id, $first, $last, $email, string $role): array
    {
        $first = trim((string)$first);
        $last  = trim((string)$last);
        $email = trim((string)$email);
        return [
            'contact_id' => $id,
            'first_name' => $first !== '' ? $first : 'there',
            'name'       => trim($first . ' ' . $last) ?: 'Contact #' . $id,
            'email'      => $email !== '' ? $email : null,
            'role'       => $role,
        ];
    }

    /** On-site contact with an email wins; else the site contact with an email; else nobody. */
    public static function pickAskRecipient(?array $onsite, ?array $site): ?array
    {
        if ($onsite && !empty($onsite['email'])) {
            return $onsite;
        }
        if ($site && !empty($site['email'])) {
            return $site;
        }
        return null;
    }

    /** "Cambridge Apartments", else the street address. */
    public static function placeName(array $p): string
    {
        $n = trim((string)($p['property_name'] ?? ''));
        if ($n !== '') {
            return $n;
        }
        $a = trim((string)($p['address'] ?? ''));
        return $a !== '' ? $a : 'your property';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CASL
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{ok: bool, basis: ?string, reason: string} */
    public function consent(int $contactId, int $propertyId): array
    {
        $email = '';
        $row = [];
        try {
            $s = $this->db->prepare("SELECT * FROM contacts WHERE id = ? LIMIT 1");
            $s->execute([$contactId]);
            $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            $email = strtolower(trim((string)($row['email'] ?? '')));
        } catch (Throwable $e) { /* no row → no consent */ }

        $unsub = false;
        if ($email !== '') {
            try {
                $u = $this->db->prepare("SELECT 1 FROM marketing_unsubscribes WHERE LOWER(TRIM(email)) = ? LIMIT 1");
                $u->execute([$email]);
                $unsub = (bool)$u->fetchColumn();
            } catch (Throwable $e) { /* no unsubscribe table → nobody unsubscribed */ }
        }

        $b2b = $this->companyManaged($propertyId, $contactId);

        $ledger = null;
        $legacy = null;
        if (!$b2b && !$unsub) {
            $f = self::appRoot() . '/Modules/Consent/Services/ConsentLedgerService.php';
            if (is_file($f) && $this->hasTable('consent_ledger')) {
                try {
                    require_once $f;
                    $ledger = (new ConsentLedgerService($this->db))->allows($contactId, 'email');
                } catch (Throwable $e) {
                    $ledger = null;
                }
            }
            if ($ledger === null && $row) {
                require_once self::appRoot() . '/Services/Messaging/MessagingService.php';
                $legacy = function_exists('canSendMarketing') ? canSendMarketing($row, 'email') : false;
            }
        }
        return self::decideConsent($unsub, $b2b, $ledger, $legacy);
    }

    /**
     * The CASL decision (pure, unit tested).
     *  - unsubscribed → never;
     *  - company-managed property → business-to-business note (Electronic Commerce
     *    Protection Regulations s.3(a)(ii));
     *  - else the consent ledger's answer, else the CRM's own implied/express dates.
     */
    public static function decideConsent(bool $unsubscribed, bool $companyManaged, ?array $ledger, ?bool $legacy): array
    {
        if ($unsubscribed) {
            return ['ok' => false, 'basis' => null, 'reason' => 'They unsubscribed from our emails'];
        }
        if ($companyManaged) {
            return ['ok' => true, 'basis' => 'b2b', 'reason' => 'Company-managed property — a business-to-business note'];
        }
        if ($ledger !== null) {
            return !empty($ledger['ok'])
                ? ['ok' => true, 'basis' => (string)($ledger['type'] ?? 'implied'), 'reason' => ucfirst((string)($ledger['reason'] ?? 'consent on file'))]
                : ['ok' => false, 'basis' => null, 'reason' => 'No email consent on file (' . ($ledger['reason'] ?? 'none') . ')'];
        }
        if ($legacy) {
            return ['ok' => true, 'basis' => 'implied', 'reason' => 'Existing customer — consent on file'];
        }
        return ['ok' => false, 'basis' => null, 'reason' => 'No email consent on file'];
    }

    /** Strata / PM / commercial: the property or the person belongs to a company we work for. */
    private function companyManaged(int $propertyId, int $contactId): bool
    {
        $checks = [
            ["SELECT 1 FROM company_properties WHERE property_id = ? LIMIT 1", [$propertyId]],
            ["SELECT 1 FROM contacts WHERE id = ? AND employer_company_id IS NOT NULL AND employer_company_id > 0 LIMIT 1", [$contactId]],
            ["SELECT 1 FROM properties p JOIN companies co ON co.primary_contact_id = p.site_contact_id WHERE p.id = ? AND p.site_contact_id IS NOT NULL LIMIT 1", [$propertyId]],
        ];
        foreach ($checks as [$sql, $args]) {
            try {
                $s = $this->db->prepare($sql);
                $s->execute($args);
                if ($s->fetchColumn()) {
                    return true;
                }
            } catch (Throwable $e) { /* a missing table/column just means "not this way" */ }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Drafting
    // ─────────────────────────────────────────────────────────────────────────

    /** Everything an ask needs about one observation, or null. */
    public function context(int $obsId): ?array
    {
        $pitchCol = $this->hasColumn('products', 'field_ask_pitch') ? 'pr.field_ask_pitch' : 'NULL';
        $s = $this->db->prepare("
            SELECT fo.*, pr.name AS product_name, pr.field_label, {$pitchCol} AS field_ask_pitch,
                   p.property_name, p.address, p.city, p.site_contact_id
            FROM field_observations fo
            LEFT JOIN products pr ON pr.id = fo.recommended_product_id
            LEFT JOIN properties p ON p.id = fo.property_id
            WHERE fo.id = ? LIMIT 1
        ");
        $s->execute([$obsId]);
        $o = $s->fetch(PDO::FETCH_ASSOC);
        if (!$o) {
            return null;
        }
        $o['place'] = self::placeName($o);

        $people = $this->db->prepare("SELECT id, first_name, last_name, email FROM contacts WHERE id = ? LIMIT 1");
        $load = function ($id, string $role) use ($people) {
            if (!$id) return null;
            $people->execute([(int)$id]);
            $r = $people->fetch(PDO::FETCH_ASSOC);
            return $r ? self::person((int)$r['id'], $r['first_name'], $r['last_name'], $r['email'], $role) : null;
        };
        $o['ask_person']     = $load($o['ask_contact_id'] ?? null, (string)($o['ask_role'] ?? 'site'));
        $o['billing_person'] = $load($o['site_contact_id'] ?: ($o['contact_id'] ?? null), 'site');
        return $o;
    }

    /** Which built-in wording a product gets, by its label or name. */
    public static function serviceKey(array $product): ?string
    {
        $t = strtolower(trim(($product['field_label'] ?? '') . ' ' . ($product['product_name'] ?? $product['name'] ?? '')));
        if (strpos($t, 'aerat') !== false) {
            return 'aeration';
        }
        if (strpos($t, 'fall') !== false && strpos($t, 'clean') !== false) {
            return 'fall_cleanup';
        }
        return null;
    }

    /** "this morning", "yesterday", "on Oct 3" — when we were there, relative to now. */
    public static function whenPhrase(string $at, string $now): array
    {
        $t = strtotime($at) ?: strtotime($now);
        $n = strtotime($now);
        $days = (int)round((strtotime(date('Y-m-d', $n)) - strtotime(date('Y-m-d', $t))) / 86400);
        if ($days <= 0) {
            $h = (int)date('G', $t);
            return [$h < 12 ? 'this morning' : ($h < 17 ? 'this afternoon' : 'this evening'), 'today'];
        }
        if ($days === 1) {
            return ['yesterday', 'yesterday'];
        }
        return ['on ' . date('M j', $t), date('M j', $t)];
    }

    /** The values a draft is filled with — and taken back out of the sender's edits. */
    public static function vars(array $ctx, string $owner, string $now): array
    {
        $key  = self::serviceKey($ctx);
        $copy = $key ? self::SERVICES[$key] : null;
        $label = trim((string)($ctx['field_label'] ?? '')) ?: trim((string)($ctx['product_name'] ?? '')) ?: 'this';
        $service = $copy['service'] ?? strtolower($label);
        $pitch = trim((string)($ctx['field_ask_pitch'] ?? ''));
        if ($pitch === '') {
            $pitch = $copy['pitch'] ?? "It's due for " . $service . '.';
        }
        [$when, $day] = self::whenPhrase((string)($ctx['created_at'] ?? $now), $now);

        $ask = $ctx['ask_person'] ?? null;
        $bill = $ctx['billing_person'] ?? null;
        $same = !$bill || !$ask || (int)$bill['contact_id'] === (int)$ask['contact_id'];
        $billFirst = $bill['first_name'] ?? '';
        $next = $same || $billFirst === '' || $billFirst === 'there'
            ? "I'll send you the quote and book a date"
            : "I'll send the quote to " . $billFirst . ' for sign-off and book a date with you';

        return [
            '{first_name}'    => (string)($ask['first_name'] ?? 'there'),
            '{place}'         => (string)($ctx['place'] ?? 'your property'),
            '{service}'       => $service,
            '{area}'          => $copy['area'] ?? 'the property',
            '{pitch}'         => $pitch,
            '{when}'          => $when,
            '{day}'           => $day,
            '{next_step}'     => $next,
            '{billing_first}' => $same ? '' : $billFirst,
            '{owner}'         => $owner !== '' ? $owner : 'Tim',
            '{phone}'         => self::OFFICE_PHONE,
        ];
    }

    public static function fill(string $text, array $vars): string
    {
        return preg_replace("/\n{3,}/", "\n\n", strtr($text, $vars));
    }

    /** Put {placeholders} back where the sender's text has this customer's details, longest first. */
    public static function unfill(string $text, array $vars): string
    {
        $skip = ['there', 'your property', 'the property', 'today', 'Tim', 'this'];
        $pairs = [];
        foreach ($vars as $ph => $val) {
            $val = (string)$val;
            if (mb_strlen(trim($val)) < 3 || in_array($val, $skip, true)) continue;
            $pairs[$val] = $ph;
        }
        uksort($pairs, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        return strtr($text, $pairs);
    }

    /** Edited = anything more than whitespace changed. */
    public static function isEdited(string $suggested, string $final): bool
    {
        $n = fn($s) => trim(preg_replace('/\s+/', ' ', $s));
        return $n($suggested) !== $n($final);
    }

    /** The latest wording the sender used for this product, with placeholders. */
    public function learned(int $productId): ?array
    {
        if ($productId <= 0 || !$this->hasTable('field_ask_messages')) {
            return null;
        }
        try {
            $s = $this->db->prepare("
                SELECT learned_subject, learned_body FROM field_ask_messages
                WHERE product_id = ? AND status = 'edited' AND learned_body IS NOT NULL AND learned_body <> ''
                ORDER BY sent_at DESC, id DESC LIMIT 1
            ");
            $s->execute([$productId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * The draft a manager reads before sending.
     * @return array{ok: bool, error?: string, observation_id?: int, to?: ?array, billing?: ?array, consent?: array,
     *               subject?: string, body?: string, drafted_by?: string, photos?: array, status?: string}
     */
    public function draft(int $obsId, array $user): array
    {
        $ctx = $this->context($obsId);
        if (!$ctx) {
            return ['ok' => false, 'error' => 'That recommendation is gone'];
        }
        $vars = self::vars($ctx, self::ownerName($user), date('Y-m-d H:i:s'));
        $learned = $this->learned((int)($ctx['recommended_product_id'] ?? 0));
        $subjectTpl = ($learned['learned_subject'] ?? '') ?: self::DEFAULT_SUBJECT;
        $bodyTpl    = ($learned['learned_body'] ?? '') ?: self::DEFAULT_BODY;
        $to = $ctx['ask_person'];

        return [
            'ok'             => true,
            'observation_id' => $obsId,
            'status'         => (string)$ctx['status'],
            'service'        => $vars['{service}'],
            'place'          => $vars['{place}'],
            'to'             => $to,
            'billing'        => $ctx['billing_person'],
            'consent'        => $to ? $this->consent((int)$to['contact_id'], (int)$ctx['property_id'])
                                    : ['ok' => false, 'basis' => null, 'reason' => 'Nobody to ask'],
            'subject'        => self::fill($subjectTpl, $vars),
            'body'           => self::fill($bodyTpl, $vars),
            'drafted_by'     => $learned ? 'learned' : 'template',
            'photos'         => array_map(fn($p) => ['id' => (int)$p['id'], 'url' => $p['thumb'] ?: $p['file_path']],
                                          array_slice($this->photos($obsId), 0, self::MAX_PHOTOS)),
            'note'           => (string)($ctx['notes'] ?? ''),
            'can_send'       => self::canSend($this->db, $user),
        ];
    }

    /** First name the sender signs with. */
    public static function ownerName(array $user): string
    {
        $n = trim((string)($user['first_name'] ?? '')) ?: (string)strtok(trim((string)($user['full_name'] ?? $user['name'] ?? '')), ' ');
        $n = trim((string)$n);
        return $n !== '' && strtoupper($n) === $n ? ucfirst(strtolower($n)) : $n;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Photos
    // ─────────────────────────────────────────────────────────────────────────

    /** Photos linked to the observation: original, 1024px-or-smaller JPEG variant, thumb. */
    public function photos(int $obsId): array
    {
        try {
            $s = $this->db->prepare("
                SELECT ma.id, ma.file_path,
                       (SELECT mv.file_path FROM media_variants mv
                         WHERE mv.media_id = ma.id AND mv.variant_type = 'responsive' AND mv.format = 'jpeg'
                           AND mv.width <= " . self::PHOTO_WIDTH . "
                         ORDER BY mv.width DESC LIMIT 1) AS web_path,
                       (SELECT mv.file_path FROM media_variants mv
                         WHERE mv.media_id = ma.id AND mv.variant_type = 'thumb_square' AND mv.format = 'jpeg'
                         LIMIT 1) AS thumb
                FROM media_links ml
                JOIN media_assets ma ON ma.id = ml.media_id AND ma.status <> 'deleted'
                WHERE ml.context_type = 'field_observation' AND ml.context_id = ?
                ORDER BY ml.sort_order ASC, ma.id ASC
            ");
            $s->execute([$obsId]);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Files to attach: [path => "cambridge-apartments-1.jpg"], plus temp files to delete.
     * Prefers the 1024px JPEG variant; otherwise shrinks the original with GD.
     * @return array{0: array<string, string>, 1: string[]}
     */
    public function attachments(int $obsId, string $place): array
    {
        $root = defined('PUBLIC_ROOT') ? (string)constant('PUBLIC_ROOT') : '';
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($place)), '-') ?: 'photo';
        $cands = [];
        $temps = [];
        foreach (array_slice($this->photos($obsId), 0, self::MAX_PHOTOS) as $p) {
            $path = null;
            if (!empty($p['web_path']) && is_file($root . $p['web_path'])) {
                $path = $root . $p['web_path'];
            } elseif (!empty($p['file_path']) && is_file($root . $p['file_path'])) {
                $path = self::shrink($root . $p['file_path'], self::PHOTO_WIDTH);
                if ($path !== $root . $p['file_path']) {
                    $temps[] = $path;
                }
            }
            if ($path) {
                $cands[] = ['path' => $path, 'size' => (int)@filesize($path)];
            }
        }
        $files = [];
        foreach (self::pickAttachments($cands, self::MAX_PHOTOS, self::MAX_ATTACH_BYTES) as $i => $c) {
            $files[$c['path']] = $slug . '-' . ($i + 1) . '.jpg';
        }
        return [$files, $temps];
    }

    /** Up to $max files, in order, never over $maxBytes in total (pure, unit tested). */
    public static function pickAttachments(array $cands, int $max, int $maxBytes): array
    {
        $out = [];
        $total = 0;
        foreach ($cands as $c) {
            if (count($out) >= $max) break;
            $size = (int)($c['size'] ?? 0);
            if ($size <= 0 || $total + $size > $maxBytes) continue;
            $total += $size;
            $out[] = $c;
        }
        return $out;
    }

    /** A JPEG no wider than $width in the temp dir, or the original if GD can't help. */
    private static function shrink(string $src, int $width): string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $src;
        }
        $info = @getimagesize($src);
        if (!$info || $info[0] <= $width && filesize($src) < 1500000) {
            return $src;
        }
        $img = @imagecreatefromstring((string)file_get_contents($src));
        if (!$img) {
            return $src;
        }
        $w = min($width, (int)$info[0]);
        $h = (int)round($info[1] * ($w / max(1, $info[0])));
        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $w, $h, (int)$info[0], (int)$info[1]);
        $out = tempnam(sys_get_temp_dir(), 'mwask') . '.jpg';
        imagejpeg($dst, $out, 82);
        imagedestroy($img);
        imagedestroy($dst);
        return is_file($out) ? $out : $src;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sending
    // ─────────────────────────────────────────────────────────────────────────

    /** The sender's words as a plain letter, with the CASL footer underneath. */
    public static function emailHtml(string $body, array $company, string $unsubUrl, string $place): string
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $paras = preg_split("/\n{2,}/", trim(str_replace("\r", '', $body)));
        $html = '';
        foreach ($paras as $p) {
            $html .= '<p style="margin:0 0 14px">' . nl2br($e($p)) . '</p>';
        }
        $footer = $e($company['name'] ?? 'Mowology') . (!empty($company['address']) ? ' · ' . $e($company['address']) : '')
            . '<br>You are getting this because we look after ' . $e($place) . '. '
            . ($unsubUrl !== '' ? '<a href="' . $e($unsubUrl) . '" style="color:#888">Unsubscribe</a> from notes like this.' : 'Reply "stop" and we won\'t send notes like this.');
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:16px;">'
            . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222;max-width:600px">'
            . $html
            . '<p style="margin:28px 0 0;font-size:12px;line-height:1.4;color:#888">' . $footer . '</p>'
            . '</div></body></html>';
    }

    /**
     * Send the ask. Re-reads the observation, the recipient and the consent on the server;
     * only the wording comes from the client.
     * @return array{ok: bool, message: string}
     */
    public function send(int $obsId, array $user, string $subject, string $body): array
    {
        if (!self::canSend($this->db, $user)) {
            return ['ok' => false, 'message' => 'Only a manager or admin can send this — it is saved for them'];
        }
        if (!$this->ready()) {
            return ['ok' => false, 'message' => 'Ask first is not switched on yet (migration 1180)'];
        }
        $ctx = $this->context($obsId);
        if (!$ctx || ($ctx['intent'] ?? '') !== 'ask') {
            return ['ok' => false, 'message' => 'That ask is gone — refresh'];
        }
        if (($ctx['status'] ?? '') !== 'ask_draft') {
            return ['ok' => false, 'message' => 'Already sent or closed (' . $ctx['status'] . ')'];
        }
        $to = $ctx['ask_person'];
        if (!$to || empty($to['email'])) {
            return ['ok' => false, 'message' => 'No email address for ' . ($to['name'] ?? 'them') . ' — call instead'];
        }
        $consent = $this->consent((int)$to['contact_id'], (int)$ctx['property_id']);
        if (!$consent['ok']) {
            return ['ok' => false, 'message' => "Can't email " . $to['first_name'] . ': ' . $consent['reason']];
        }
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
        $body = trim(str_replace("\r", '', $body));
        if ($subject === '' || $body === '') {
            return ['ok' => false, 'message' => 'Subject and message are both needed'];
        }
        $subject = mb_substr($subject, 0, 200);
        $body = mb_substr($body, 0, 5000);

        $owner = self::ownerName($user);
        $vars = self::vars($ctx, $owner, date('Y-m-d H:i:s'));
        $learned = $this->learned((int)($ctx['recommended_product_id'] ?? 0));
        $sugSubject = self::fill(($learned['learned_subject'] ?? '') ?: self::DEFAULT_SUBJECT, $vars);
        $sugBody = self::fill(($learned['learned_body'] ?? '') ?: self::DEFAULT_BODY, $vars);
        $edited = self::isEdited($sugSubject, $subject) || self::isEdited($sugBody, $body);

        require_once self::appRoot() . '/Services/Messaging/MessagingService.php';
        require_once self::appRoot() . '/Services/Messaging/TemplateRenderer.php';
        $company = function_exists('emailCompanyDetails') ? emailCompanyDetails() : ['name' => 'Mowology', 'address' => ''];
        $unsub = function_exists('generateUnsubscribeUrl') ? generateUnsubscribeUrl((string)$to['email']) : '';
        $html = self::emailHtml($body, $company, $unsub, (string)$ctx['place']);

        [$files, $temps] = $this->attachments($obsId, (string)$ctx['place']);
        $fromName = $owner !== '' ? $owner . ' at Mowology' : 'Mowology';
        try {
            // No photos is still a valid ask — sendEmail() then takes its ordinary path.
            $r = sendEmail((string)$to['email'], $subject, $html, null, $fromName, $files);
        } finally {
            foreach ($temps as $t) @unlink($t);
        }
        $ok = !empty($r['success']);

        $this->record($ctx, $to, $sugSubject, $sugBody, $subject, $body, $edited ? self::unfill($subject, $vars) : null,
            $edited ? self::unfill($body, $vars) : null, $learned ? 'learned' : 'template', count($files),
            $ok ? ($edited ? 'edited' : 'sent') : 'failed', $ok ? null : (string)($r['error'] ?? 'send failed'), (int)($user['id'] ?? 0));

        if (!$ok) {
            return ['ok' => false, 'message' => 'The email did not go: ' . ($r['error'] ?? 'unknown error') . '. It is still saved as a draft.'];
        }

        $this->db->prepare("
            UPDATE field_observations
            SET status = 'asked', asked_at = NOW(), ask_sent_by = ?, email_sent_at = NOW(), updated_at = NOW()
            WHERE id = ? AND status = 'ask_draft'
        ")->execute([(int)($user['id'] ?? 0) ?: null, $obsId]);

        $this->logSalesMessage((int)$to['contact_id'], (string)$to['email'], $subject, $body);
        try {
            $this->db->prepare("
                INSERT INTO activity_log (user_id, contact_id, action, description, created_at)
                VALUES (?, ?, 'field_ask_sent', ?, NOW())
            ")->execute([(int)($user['id'] ?? 0) ?: null, (int)$to['contact_id'],
                         'Asked about ' . $vars['{service}'] . ' at ' . $vars['{place}'] . ' (' . count($files) . ' photos, no price)']);
        } catch (Throwable $e) { /* the log is a bonus */ }

        return ['ok' => true, 'message' => 'Sent to ' . $to['first_name'] . (count($files) ? ' with ' . count($files) . ' photo' . (count($files) === 1 ? '' : 's') : '')
            . '. Their reply shows up on Sam\'s card.'];
    }

    private function record(array $ctx, array $to, string $sugS, string $sugB, string $finS, string $finB, ?string $lS, ?string $lB,
                            string $by, int $photos, string $status, ?string $error, int $userId): void
    {
        if (!$this->hasTable('field_ask_messages')) {
            return;
        }
        try {
            $this->db->prepare("
                INSERT INTO field_ask_messages (observation_id, product_id, contact_id, to_email, drafted_by,
                    suggested_subject, suggested_body, final_subject, final_body, learned_subject, learned_body,
                    photo_count, status, error, sent_by, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([(int)$ctx['id'], (int)($ctx['recommended_product_id'] ?? 0) ?: null, (int)$to['contact_id'], $to['email'], $by,
                         mb_substr($sugS, 0, 255), $sugB, mb_substr($finS, 0, 255), $finB, $lS !== null ? mb_substr($lS, 0, 255) : null, $lB,
                         $photos, $status, $error !== null ? mb_substr($error, 0, 255) : null, $userId ?: null]);
        } catch (Throwable $e) {
            error_log('[FieldAskService] record failed: ' . $e->getMessage());
        }
    }

    /** So Sam sees we wrote, and can tell a reply came after it. */
    private function logSalesMessage(int $contactId, string $to, string $subject, string $body): void
    {
        if (!$this->hasTable('sales_messages')) {
            return;
        }
        try {
            $this->db->prepare("
                INSERT IGNORE INTO sales_messages (mailbox, message_key, direction, channel, contact_id, from_addr, to_addr, subject, snippet, sent_at)
                VALUES ('field_ask', ?, 'outbound', 'email', ?, 'office@mowology.ca', ?, ?, ?, NOW())
            ")->execute(['ask-' . bin2hex(random_bytes(12)), $contactId ?: null, $to, mb_substr($subject, 0, 255), mb_substr($body, 0, 800)]);
        } catch (Throwable $e) { /* history is a bonus — the send already happened */ }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // After the reply (Sam's card)
    // ─────────────────────────────────────────────────────────────────────────

    /** replied | silent | waiting (pure, unit tested). A reply must come after we asked. */
    public static function classifyAsk(string $askedAt, ?string $lastIn, string $now, int $silentDays = self::SILENT_DAYS): string
    {
        if ($lastIn !== null && $lastIn !== '' && $lastIn > $askedAt) {
            return 'replied';
        }
        return strtotime($now) - strtotime($askedAt) >= $silentDays * 86400 ? 'silent' : 'waiting';
    }

    /**
     * What Sam shows: asks that got a reply (Build the quote), asks with none after a week
     * ("no reply yet"), and how many crew drafts wait for a manager.
     * @return array{replied: array, silent: array, drafts: int}
     */
    public function forSam(): array
    {
        $out = ['replied' => [], 'silent' => [], 'drafts' => 0];
        if (!$this->ready()) {
            return $out;
        }
        try {
            $out['drafts'] = (int)$this->db->query("SELECT COUNT(*) FROM field_observations WHERE intent = 'ask' AND status = 'ask_draft'")->fetchColumn();
            $rows = $this->db->query("
                SELECT fo.id, fo.asked_at, fo.ask_contact_id, fo.contact_id, fo.property_id, fo.recommended_product_id,
                       fo.recommended_price, fo.notes,
                       pr.name AS product_name, pr.field_label, p.property_name, p.address, p.site_contact_id,
                       ac.first_name AS ask_first, ac.last_name AS ask_last,
                       bc.first_name AS bill_first, bc.last_name AS bill_last, bc.email AS bill_email
                FROM field_observations fo
                LEFT JOIN products pr ON pr.id = fo.recommended_product_id
                LEFT JOIN properties p ON p.id = fo.property_id
                LEFT JOIN contacts ac ON ac.id = fo.ask_contact_id
                LEFT JOIN contacts bc ON bc.id = COALESCE(p.site_contact_id, fo.contact_id)
                WHERE fo.intent = 'ask' AND fo.status = 'asked' AND fo.asked_at IS NOT NULL
                  AND fo.asked_at >= DATE_SUB(NOW(), INTERVAL " . self::MAX_AGE_DAYS . " DAY)
                ORDER BY fo.asked_at DESC
                LIMIT 40
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $out;
        }
        $hasMsgs = $this->hasTable('sales_messages');
        $reply = $hasMsgs ? $this->db->prepare("
            SELECT contact_id, channel, subject, snippet, sent_at FROM sales_messages
            WHERE direction = 'inbound' AND contact_id IN (?, ?) AND sent_at > ?
            ORDER BY sent_at DESC, id DESC LIMIT 1
        ") : null;
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            $msg = null;
            if ($reply) {
                $reply->execute([(int)$r['ask_contact_id'], (int)($r['site_contact_id'] ?: $r['contact_id']), $r['asked_at']]);
                $msg = $reply->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $kind = self::classifyAsk((string)$r['asked_at'], $msg['sent_at'] ?? null, $now);
            $label = trim((string)($r['field_label'] ?? '')) ?: (string)($r['product_name'] ?? 'Service');
            $askName = trim($r['ask_first'] . ' ' . $r['ask_last']);
            $item = [
                'observation_id' => (int)$r['id'],
                'service'        => $label,
                'place'          => self::placeName($r),
                'asked_name'     => $askName !== '' ? $askName : 'them',
                'asked_first'    => trim((string)$r['ask_first']) ?: 'them',
                'asked_at'       => $r['asked_at'],
                'billing_name'   => trim($r['bill_first'] . ' ' . $r['bill_last']),
                'billing_email'  => $r['bill_email'],
                'reply'          => $msg ? ['at' => $msg['sent_at'], 'channel' => $msg['channel'], 'subject' => $msg['subject'],
                                            'snippet' => $msg['snippet'],
                                            'from_billing' => (int)$msg['contact_id'] !== (int)$r['ask_contact_id']] : null,
                'photos'         => count($this->photos((int)$r['id'])),
            ];
            if ($kind === 'replied') {
                $out['replied'][] = $item;
                try {
                    $this->db->prepare("UPDATE field_observations SET replied_at = ? WHERE id = ? AND replied_at IS NULL")
                        ->execute([$msg['sent_at'], (int)$r['id']]);
                } catch (Throwable $e) { /* stamping is a bonus */ }
            } elseif ($kind === 'silent') {
                $out['silent'][] = $item;
            }
        }
        return $out;
    }

    /**
     * They said yes: build the quote on the same observation (photos come with it). The quote
     * is NOT sent here — the manager checks the price and sends it to the billing contact.
     * @return array{ok: bool, message: string, quote_id?: int, number?: string, amount?: float, sendable?: bool,
     *               to_name?: string, to_email?: ?string, url?: string}
     */
    public function buildQuote(int $obsId, array $user, ?float $price = null): array
    {
        if (!self::canSend($this->db, $user)) {
            return ['ok' => false, 'message' => 'Only a manager or admin can build the quote'];
        }
        $ctx = $this->context($obsId);
        if (!$ctx || ($ctx['intent'] ?? '') !== 'ask' || !in_array($ctx['status'], ['asked', 'ask_yes', 'quote_created'], true)) {
            return ['ok' => false, 'message' => 'That ask is no longer open — refresh'];
        }
        if ($price !== null && $price <= 0) {
            $price = null;
        }
        require_once __DIR__ . '/FieldRecommendationService.php';
        require_once self::appRoot() . '/Modules/Quotes/Services/QuoteService.php';
        $this->db->prepare("UPDATE field_observations SET status = 'ask_yes', updated_at = NOW() WHERE id = ? AND status = 'asked'")->execute([$obsId]);
        $quoteId = (new FieldRecommendationService($this->db))->buildQuote($obsId, (int)($user['id'] ?? 0), $price);

        $qs = new QuoteService($this->db);
        $q = $qs->getWithContact($quoteId) ?: [];
        $c = $q ? $qs->resolveContact($q) : ['name' => '', 'email' => null];
        $sendable = $q && FieldRecommendationService::sendableAmount($q);
        return [
            'ok'       => true,
            'quote_id' => $quoteId,
            'number'   => (string)($q['quote_number'] ?? ''),
            'amount'   => max((float)($q['total_amount'] ?? 0), (float)($q['amount'] ?? 0)),
            'sendable' => $sendable,
            'to_name'  => (string)($c['name'] ?? ''),
            'to_email' => $c['email'] ?? null,
            'url'      => '/crm/quotes/view.php?id=' . $quoteId,
            'message'  => $sendable
                ? 'Quote ' . ($q['quote_number'] ?? '') . ' is ready for ' . ($c['name'] ?? 'the billing contact') . '.'
                : 'Quote ' . ($q['quote_number'] ?? '') . ' was created at $0 — open it and set the price before it goes out.',
        ];
    }

    /** Send the built quote to the billing contact (FieldRecommendationService::send — refuses $0). */
    public function sendQuote(int $obsId, array $user): array
    {
        if (!self::canSend($this->db, $user)) {
            return ['ok' => false, 'message' => 'Only a manager or admin can send the quote'];
        }
        require_once __DIR__ . '/FieldRecommendationService.php';
        $r = (new FieldRecommendationService($this->db))->send($obsId, (int)($user['id'] ?? 0));
        return ['ok' => (bool)$r['success'], 'message' => $r['success'] ? 'Quote sent to ' . $r['email'] . '.' : (string)($r['error'] ?? 'The quote did not go')];
    }

    /** "Not now" on Sam's card, or "Delete draft" on the office page. */
    public function close(int $obsId, array $user, string $reason): array
    {
        if (!self::canSend($this->db, $user)) {
            return ['ok' => false, 'message' => 'Only a manager or admin can close this'];
        }
        $s = $this->db->prepare("
            UPDATE field_observations SET status = 'dismissed', dismissed_reason = ?, updated_at = NOW()
            WHERE id = ? AND intent = 'ask' AND status IN ('ask_draft', 'asked', 'ask_yes')
        ");
        $s->execute([mb_substr($reason, 0, 255), $obsId]);
        return ['ok' => $s->rowCount() > 0, 'message' => $s->rowCount() > 0 ? 'Closed.' : 'Already closed.'];
    }

    /** Crew drafts waiting for a manager, newest first (the office page). */
    public function drafts(int $limit = 30): array
    {
        if (!$this->ready()) {
            return [];
        }
        $s = $this->db->query("
            SELECT fo.id, fo.created_at, fo.notes, pr.name AS product_name, pr.field_label, p.property_name, p.address,
                   c.first_name, c.last_name, u.full_name AS created_by_name
            FROM field_observations fo
            LEFT JOIN products pr ON pr.id = fo.recommended_product_id
            LEFT JOIN properties p ON p.id = fo.property_id
            LEFT JOIN contacts c ON c.id = fo.ask_contact_id
            LEFT JOIN users u ON u.id = fo.created_by
            WHERE fo.intent = 'ask' AND fo.status = 'ask_draft'
            ORDER BY fo.created_at DESC
            LIMIT " . max(1, min(100, $limit)));
        return array_map(fn($r) => [
            'observation_id' => (int)$r['id'],
            'created_at'     => $r['created_at'],
            'service'        => trim((string)$r['field_label']) ?: (string)$r['product_name'],
            'place'          => self::placeName($r),
            'to_name'        => trim($r['first_name'] . ' ' . $r['last_name']),
            'by'             => (string)($r['created_by_name'] ?? ''),
            'note'           => (string)($r['notes'] ?? ''),
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Save the per-service pitch (products.field_ask_pitch). Blank = back to the built-in wording. */
    public function savePitch(int $productId, string $pitch, array $user): array
    {
        if (!self::canSend($this->db, $user)) {
            return ['ok' => false, 'message' => 'Only a manager or admin can change the wording'];
        }
        if (!$this->hasColumn('products', 'field_ask_pitch')) {
            return ['ok' => false, 'message' => 'Migration 1180 has not run yet'];
        }
        $pitch = trim(str_replace("\r", '', $pitch));
        $this->db->prepare("UPDATE products SET field_ask_pitch = ? WHERE id = ?")
            ->execute([$pitch !== '' ? mb_substr($pitch, 0, 1000) : null, $productId]);
        return ['ok' => true, 'message' => 'Saved.'];
    }

    /** Published field services with their current pitch (built-in or saved). */
    public function pitches(): array
    {
        $col = $this->hasColumn('products', 'field_ask_pitch') ? 'field_ask_pitch' : 'NULL AS field_ask_pitch';
        try {
            $rows = $this->db->query("
                SELECT id, name, field_label, {$col} FROM products
                WHERE field_recommendable = 1 AND active = 1 AND is_archived = 0
                ORDER BY field_sort_order, name
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        return array_map(function ($r) {
            $key = self::serviceKey($r);
            return [
                'product_id' => (int)$r['id'],
                'label'      => trim((string)$r['field_label']) ?: (string)$r['name'],
                'pitch'      => (string)($r['field_ask_pitch'] ?? ''),
                'default'    => $key ? self::SERVICES[$key]['pitch'] : '',
            ];
        }, $rows);
    }
}
