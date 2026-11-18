<?php
declare(strict_types=1);

require_once __DIR__ . '/ContactService.php';

/** Imports only the phone contacts the owner selected during onboarding. */
final class ContactImportService
{
    private PDO $db;
    private ContactService $contacts;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->contacts = new ContactService($db);
    }

    /** @return list<array{ref:string,outcome:string,contact_id:?int}> */
    public function import(array $rows, string $source = 'phone'): array
    {
        $seen = [];
        $results = [];
        foreach ($rows as $raw) {
            $row = self::clean(is_array($raw) ? $raw : []);
            $ref = $row['ref'];
            if ($row['first'] === '' && $row['last'] === '' && $row['company'] === '' && !$row['emails'] && !$row['phones']) {
                $results[] = ['ref' => $ref, 'outcome' => 'skipped', 'contact_id' => null];
                continue;
            }

            $keys = self::dedupeKeys($row);
            $duplicateId = null;
            foreach ($keys as $key) {
                if (array_key_exists($key, $seen)) { $duplicateId = $seen[$key]; break; }
            }
            if ($duplicateId !== null) {
                $results[] = ['ref' => $ref, 'outcome' => 'matched', 'contact_id' => $duplicateId];
                continue;
            }

            // One bad card must not fail the batch: contacts already made are committed one by one, so a whole-request
            // error would tell the phone nothing changed and a retry would make them twice (Codex review 2026-11-11).
            try {
                [$outcome, $id] = $this->importRow($row, $source);
            } catch (Throwable $e) {
                error_log('Contact import row failed: ' . get_class($e));
                $results[] = ['ref' => $ref, 'outcome' => 'skipped', 'contact_id' => null];
                continue;
            }
            foreach ($keys as $key) $seen[$key] = $id;
            $results[] = ['ref' => $ref, 'outcome' => $outcome, 'contact_id' => $id];
        }
        return $results;
    }

    /** @return array{0:string,1:int} outcome ('matched'|'created') and the contact id */
    private function importRow(array $row, string $source): array
    {
        $id = $this->match($row);
        if ($id !== null) return ['matched', $id];

        $companyOnly = $row['first'] === '' && $row['last'] === '' && $row['company'] !== '';
        $first = $companyOnly ? $row['company'] : $row['first'];
        $last = $companyOnly ? '' : $row['last'];
        if ($first === '' && $last !== '') { $first = $last; $last = ''; }
        [$mobile, $phone] = self::labelNumbers($row['phones']);
        $address = $row['address'];
        $id = $this->contacts->createContact([
            'first_name' => $first, 'last_name' => $last, 'email' => $row['emails'][0] ?? '',
            'mobile' => $mobile, 'phone' => $phone, 'notes' => self::importNote($source),
            'receive_marketing' => 0, 'receive_sms' => 0, 'consent_quote_followup' => 0,
            'property_address' => '', 'property_city' => '', 'property_postal_code' => '',
            'property_latitude' => null, 'property_longitude' => null,
            'link_company' => $companyOnly, 'company_mode' => 'new', 'company_name' => $row['company'],
            'company_type' => 'business', 'billing_address' => '', 'billing_city' => '',
            'billing_province' => '', 'billing_postal_code' => '', 'account_status' => 'active',
            'payment_terms' => 'Net 30', 'pref_attach_pdf' => 0,
        ]);
        if ($address !== null && $address['street'] !== '') {
            try {
                $this->contacts->addOwnPropertyToContact($id, [
                    'address' => $address['street'], 'city' => $address['city'],
                    'province' => $address['province'], 'postal_code' => $address['postal'],
                    'property_name' => self::cap(trim($first . ' ' . $last) . ' Property', 255),
                ]);
            } catch (Throwable $e) { /* the contact is still a successful import */ }
        }
        return ['created', $id];
    }

    private function match(array $row): ?int
    {
        foreach ($row['emails'] as $email) {
            $s = $this->db->prepare('SELECT id FROM contacts WHERE LOWER(TRIM(email)) = ? LIMIT 1');
            $s->execute([strtolower($email)]);
            if (($id = $s->fetchColumn()) !== false) return (int)$id;
        }
        $wanted = [];
        foreach ($row['phones'] as $phone) {
            $digits = self::normalisePhone($phone['number']);
            if ($digits !== null) $wanted[$digits] = true;
        }
        if ($wanted) {
            // Stored numbers are in any format, so compare digits in PHP, but only on rows that contain the last four
            // digits: the old whole-table read could scan every contact for every card in a batch.
            $likes = []; $params = [];
            foreach (array_keys($wanted) as $digits) {
                $likes[] = '(mobile LIKE ? OR phone LIKE ?)';
                $params[] = $params[] = '%' . substr((string)$digits, -4) . '%';
            }
            $s = $this->db->prepare('SELECT id, mobile, phone FROM contacts WHERE ' . implode(' OR ', $likes));
            $s->execute($params);
            while ($existing = $s->fetch(PDO::FETCH_ASSOC)) {
                foreach ([(string)$existing['mobile'], (string)$existing['phone']] as $number) {
                    $digits = self::normalisePhone($number);
                    if ($digits !== null && isset($wanted[$digits])) return (int)$existing['id'];
                }
            }
        }
        $street = $row['address']['street'] ?? '';
        // A card with nothing but a name gives a retry (the first answer was lost on the way back) nothing else to
        // recognise it by, so the same exact name counts as the same person; it only ever matches, never changes a client.
        if ($row['first'] !== '' && $street === '' && !$row['emails'] && !$row['phones']) {
            $s = $this->db->prepare('SELECT id FROM contacts WHERE LOWER(TRIM(first_name)) = ? AND LOWER(TRIM(last_name)) = ? LIMIT 1');
            $s->execute([strtolower($row['first']), strtolower($row['last'])]);
            if (($id = $s->fetchColumn()) !== false) return (int)$id;
        }
        if ($row['first'] !== '' && $street !== '') {
            $s = $this->db->prepare('SELECT c.id FROM contacts c JOIN properties p ON p.site_contact_id = c.id
                                     WHERE LOWER(c.first_name) = ? AND LOWER(c.last_name) = ? AND LOWER(p.address) = ? LIMIT 1');
            $s->execute([strtolower($row['first']), strtolower($row['last']), strtolower($street)]);
            if (($id = $s->fetchColumn()) !== false) return (int)$id;
        }
        return null;
    }

    public static function normalisePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', trim($raw));
        if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
        return strlen($digits) === 10 ? $digits : null;
    }

    public static function importNote(string $source): string
    {
        return $source === 'google' ? 'Imported from Google contacts.' : 'Imported from phone contacts.';
    }

    /** @return array{0:string,1:string} */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /** @return array{0:string,1:string} mobile, phone */
    public static function labelNumbers(array $phones): array
    {
        $mobile = ''; $others = [];
        foreach ($phones as $phone) {
            if (!is_array($phone)) continue;
            $number = trim((string)($phone['number'] ?? ''));
            if ($number === '') continue;
            $label = strtolower(trim((string)($phone['label'] ?? '')));
            if ($mobile === '' && in_array($label, ['mobile', 'iphone'], true)) $mobile = $number;
            else $others[] = $number;
        }
        if ($mobile === '') $mobile = (string)array_shift($others);
        return [self::cap($mobile, 50), self::cap((string)($others[0] ?? ''), 50)];
    }

    /** @return list<string> */
    public static function dedupeKeys(array $row): array
    {
        $keys = [];
        foreach ((array)($row['emails'] ?? []) as $email) {
            $email = strtolower(trim((string)$email));
            if ($email !== '') $keys['email:' . $email] = true;
        }
        foreach ((array)($row['phones'] ?? []) as $phone) {
            $number = is_array($phone) ? (string)($phone['number'] ?? '') : (string)$phone;
            if (($digits = self::normalisePhone($number)) !== null) $keys['phone:' . $digits] = true;
        }
        return array_keys($keys);
    }

    /** @return list<int> indexes whose email or phone appeared earlier in the batch */
    public static function duplicateIndexes(array $rows): array
    {
        $seen = []; $duplicates = [];
        foreach ($rows as $i => $row) {
            $duplicate = false;
            foreach (self::dedupeKeys(is_array($row) ? $row : []) as $key) {
                if (isset($seen[$key])) $duplicate = true;
                $seen[$key] = true;
            }
            if ($duplicate) $duplicates[] = $i;
        }
        return $duplicates;
    }

    private static function clean(array $raw): array
    {
        $emails = [];
        foreach ((array)($raw['emails'] ?? []) as $email) {
            if (!is_scalar($email)) continue;
            $email = self::cap(strtolower(trim((string)$email)), 255);
            if ($email !== '') $emails[] = $email;
        }
        $phones = [];
        foreach ((array)($raw['phones'] ?? []) as $phone) {
            if (!is_array($phone)) continue;
            $number = self::cap(trim((string)($phone['number'] ?? '')), 50);
            if ($number !== '') $phones[] = ['label' => self::cap(trim((string)($phone['label'] ?? '')), 50), 'number' => $number];
        }
        $address = is_array($raw['address'] ?? null) ? [
            'street' => self::cap(trim((string)($raw['address']['street'] ?? '')), 255),
            'city' => self::cap(trim((string)($raw['address']['city'] ?? '')), 100),
            'province' => self::cap(trim((string)($raw['address']['province'] ?? '')), 50),
            'postal' => self::cap(trim((string)($raw['address']['postal'] ?? '')), 20),
        ] : null;
        $first = self::cap(trim((string)($raw['first'] ?? '')), 100);
        $last = self::cap(trim((string)($raw['last'] ?? '')), 100);
        if ($first === '' && $last !== '') [$first, $last] = self::splitName($last);
        return ['ref' => (string)($raw['ref'] ?? ''),
            'first' => self::cap($first, 100),
            'last' => self::cap($last, 100),
            'company' => self::cap(trim((string)($raw['company'] ?? '')), 255),
            'emails' => array_values(array_unique($emails)), 'phones' => $phones, 'address' => $address];
    }

    private static function cap(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
