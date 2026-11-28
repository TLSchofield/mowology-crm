<?php
declare(strict_types=1);

/**
 * CompanyDetailsService — the "Check your company details" card (plan: docs/plans/2026-11-28-company-details-card-plan.md).
 *
 * A form-style owner question (OwnerQuestionService, key 'company_details'). No new column, no migration:
 *   identity rows   business_identity (legal_name, operating_name, registration_number, legal_structure, jurisdiction)
 *                   each with its *_provenance: registry_verified → source 'registry', owner_provided → 'you'
 *   gap fields      business_settings (company_website, company_email, company_phone, company_address, fiscal_year_end, gst_registration)
 *   "charges GST"   ops_settings 'charges_gst' = yes | no, with the number typed by the owner (format check only)
 *   "checked"       ops_settings 'company_details_checked' = yes; Looks right also sets business_identity.owner_confirmed_at
 *
 * Nothing is looked up and nothing is called verified. The GST number is the owner's own typing, checked only for
 * the usual shape (nine digits, RT, 0001). No card or bank numbers anywhere. No tax advice in the wording.
 * An edit writes only the rows that changed, so an untouched registry row keeps its registry provenance.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class CompanyDetailsService
{
    public const IDENTITY = [
        'legal_name' => 'Legal name', 'operating_name' => 'Operating name', 'registration_number' => 'Registration number',
        'legal_structure' => 'Legal structure', 'jurisdiction' => 'Where registered',
    ];
    /** field key → [label, type, business_settings column, max length] */
    public const GAPS = [
        'website'        => ['Website address', 'url', 'company_website', 255],
        'email'          => ['Email', 'email', 'company_email', 190],
        'phone'          => ['Phone', 'phone', 'company_phone', 20],
        'address'        => ['Business address', 'text', 'company_address', 500],
        'fiscal_year_end' => ['Year end (month and day)', 'day_month', 'fiscal_year_end', 5],
    ];
    public const CHECKED = 'company_details_checked';
    public const CHARGES_GST = 'charges_gst';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Pure: the usual shape of a GST/HST number: nine digits then RT and four digits. Never says confirmed. */
    public static function gstFormatOk(string $v): bool
    {
        return (bool)preg_match('/^\d{9}\s?RT\s?\d{4}$/i', trim($v));
    }

    /** Pure: provenance word → the source the card shows. */
    public static function sourceOf(?string $provenance): ?string
    {
        if ($provenance === 'registry_verified') return 'registry';
        if ($provenance === 'owner_provided') return 'you';
        return null;
    }

    /** Pure: one typed value, cleaned, or an error. @return array{0:?string,1:?string} [value, error] */
    public static function clean(string $field, string $raw): array
    {
        $v = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $raw));
        $max = isset(self::GAPS[$field]) ? self::GAPS[$field][3] : 255;
        if (mb_strlen($v, 'UTF-8') > $max) return [null, 'That is too long.'];
        $type = self::GAPS[$field][1] ?? 'text';
        if ($v === '') return ['', null];
        if ($type === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) return [null, 'That email does not look right.'];
        if ($type === 'url') {
            if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
            if (!filter_var($v, FILTER_VALIDATE_URL)) return [null, 'That website address does not look right.'];
            if (mb_strlen($v, 'UTF-8') > $max) return [null, 'That is too long.'];
        }
        if ($type === 'phone' && strlen((string)preg_replace('/\D+/', '', $v)) < 10) return [null, 'Enter the phone number with its area code.'];
        if ($type === 'day_month') {
            if (!preg_match('/^(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[1], (int)$m[2], 2024)) return [null, 'Use month and day, like 12-31.'];
        }
        if ($field === 'legal_name' && mb_strlen($v, 'UTF-8') < 2) return [null, 'The legal name is too short.'];
        return [$v, null];
    }

    /** Filed receipts (approved or forwarded: ExpenseGate::POSTED). */
    public function filedReceipts(): int
    {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM expenses WHERE status IN ('approved', 'forwarded')")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Has the owner said "Looks right"? A GST report or an accountant export can ask this too. */
    public function checked(): bool
    {
        return $this->setting(self::CHECKED) === 'yes';
    }

    /**
     * The form as the apps draw it:
     * {rows: [{key,label,value,source:'registry'|'you'|null,type:'text'}],
     *  fields: [{key,label,type:text|email|phone|url|day_month|yes_no,value, show_if?:{key,equals}}]}
     * Submit values for only the keys that changed.
     */
    public function form(): array
    {
        $id = $this->identity();
        $rows = [];
        foreach (self::IDENTITY as $k => $label) {
            $v = trim((string)($id[$k] ?? ''));
            if ($v === '' && !$id) continue;   // no identity row yet: nothing to check
            $rows[] = ['key' => $k, 'label' => $label, 'value' => $v, 'source' => self::sourceOf($id[$k . '_provenance'] ?? null), 'type' => 'text'];
        }
        $bs = $this->businessSettings();
        $fields = [];
        foreach (self::GAPS as $k => [$label, $type, $col]) {
            $fields[] = ['key' => $k, 'label' => $label, 'type' => $type, 'value' => trim((string)($bs[$col] ?? ''))];
        }
        $number = trim((string)($bs['gst_registration'] ?? ''));
        $charges = $this->setting(self::CHARGES_GST) ?? ($number !== '' ? 'yes' : '');
        $fields[] = ['key' => 'charges_gst', 'label' => 'Do you charge GST?', 'type' => 'yes_no', 'value' => $charges];
        $fields[] = ['key' => 'gst_number', 'label' => 'GST number', 'type' => 'text', 'value' => $number,
                     'show_if' => ['key' => 'charges_gst', 'equals' => 'yes']];
        return ['rows' => $rows, 'fields' => $fields];
    }

    /**
     * "Looks right" with whatever the owner changed. Validates everything first, then writes in one transaction.
     * @param array<string,string> $values only the changed keys (identity rows, gap fields, charges_gst, gst_number)
     * @return array{ok:bool,message:string,errors?:array<string,string>}
     */
    public function confirm(array $values, int $userId): array
    {
        $errors = [];
        $identity = [];
        $settings = [];
        foreach ($values as $k => $raw) {
            $k = (string)$k;
            if (!is_scalar($raw)) { $errors[$k] = 'That does not look right.'; continue; }
            if (isset(self::IDENTITY[$k])) {
                [$v, $e] = self::clean($k, (string)$raw);
                if ($e !== null) { $errors[$k] = $e; continue; }
                if ($v !== '') $identity[$k] = $v;   // an empty box leaves the row as it is
            } elseif (isset(self::GAPS[$k])) {
                [$v, $e] = self::clean($k, (string)$raw);
                if ($e !== null) { $errors[$k] = $e; continue; }
                if ($v !== '') $settings[self::GAPS[$k][2]] = $v;
            } elseif ($k === 'charges_gst' || $k === 'gst_number') {
                continue;   // handled together below
            } else {
                $errors[$k] = 'Unknown field.';
            }
        }
        $charges = isset($values['charges_gst']) ? strtolower(trim((string)$values['charges_gst'])) : null;
        if ($charges !== null && !in_array($charges, ['yes', 'no'], true)) $errors['charges_gst'] = 'Choose yes or no.';
        $number = isset($values['gst_number']) ? trim((string)$values['gst_number']) : null;
        if ($number !== null && $number !== '' && $charges !== 'no' && !self::gstFormatOk($number)) {
            $errors['gst_number'] = 'That does not look like the usual format (nine digits, RT, 0001). Check what you typed.';
        }
        if ($charges === 'yes' && ($number === null || $number === '')) {
            $stored = trim((string)($this->businessSettings()['gst_registration'] ?? ''));
            if ($stored === '') $errors['gst_number'] = 'Enter your GST number.';
        }
        if ($errors) return ['ok' => false, 'message' => 'Please check the details marked.', 'errors' => $errors];

        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            if ($identity) $this->writeIdentity($identity, $userId);
            if ($charges === 'no') {
                $settings['gst_registration'] = null;
            } elseif ($number !== null && $number !== '') {
                $settings['gst_registration'] = strtoupper(preg_replace('/\s+/', '', $number));
            }
            if ($settings) $this->writeSettings($settings);
            if ($charges !== null) $this->save(self::CHARGES_GST, $charges, 'Company details: does the business charge GST (the owner\'s answer)');
            $this->confirmIdentity($userId);
            $this->save(self::CHECKED, 'yes', 'Company details card answered (CompanyDetailsService)');
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            error_log('[company details] ' . $e->getMessage());
            if (!$own) throw $e;   // the transaction's owner rolls everything back
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['ok' => false, 'message' => 'Could not save that. Try again.'];
        }
        return ['ok' => true, 'message' => 'Thanks. Penny will use these details.'];
    }

    // ── storage ────────────────────────────────────────────────────────────────────────────────────

    private function identity(): array
    {
        try {
            $r = $this->db->query('SELECT * FROM business_identity WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            return $r ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function businessSettings(): array
    {
        try {
            $r = $this->db->query('SELECT * FROM business_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            return $r ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Only rows that changed; each gets provenance owner_provided and the confirmation is cleared (confirmIdentity sets it after). */
    private function writeIdentity(array $changes, int $userId): void
    {
        $now = $this->identity();
        if (!$now) return;   // no identity row: the owner enters it in setup first
        $sets = [];
        $params = [];
        foreach ($changes as $k => $v) {
            if (trim((string)($now[$k] ?? '')) === $v) continue;
            $sets[] = "`$k` = ?";
            $params[] = $v;
            $sets[] = "`{$k}_provenance` = ?";
            $params[] = 'owner_provided';
        }
        if (!$sets) return;
        $sets[] = 'owner_confirmed_at = NULL';
        $sets[] = 'updated_by = ?';
        $params[] = $userId;
        $this->db->prepare('UPDATE business_identity SET ' . implode(', ', $sets) . ' WHERE id = 1')->execute($params);
    }

    private function confirmIdentity(int $userId): void
    {
        if (!$this->identity()) return;
        $this->db->prepare('UPDATE business_identity SET owner_confirmed_at = ?, updated_by = ? WHERE id = 1')->execute([date('Y-m-d H:i:s'), $userId]);
    }

    private function writeSettings(array $cols): void
    {
        $sets = [];
        $params = [];
        foreach ($cols as $c => $v) { $sets[] = "`$c` = ?"; $params[] = $v; }
        $this->db->prepare('UPDATE business_settings SET ' . implode(', ', $sets) . ' WHERE id = 1')->execute($params);
    }

    private function setting(string $key): ?string
    {
        try {
            $st = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? null : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function save(string $key, string $value, string $why): void
    {
        $u = $this->db->prepare('UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?');
        $u->execute([$value, $key]);
        $st = $this->db->prepare('SELECT COUNT(*) FROM ops_settings WHERE setting_key = ?');
        $st->execute([$key]);
        if ((int)$st->fetchColumn() === 0) {
            $this->db->prepare('INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)')->execute([$key, $value, $why]);
        }
    }
}
