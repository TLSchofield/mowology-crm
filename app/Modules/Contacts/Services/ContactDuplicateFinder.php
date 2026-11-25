<?php
declare(strict_types=1);

/**
 * Finds potential duplicate contacts in one pass.
 *
 * Two contacts are potential duplicates when they share any of:
 *   1. a non-empty email (case-insensitive, trimmed),
 *   2. a phone or mobile number (digits only),
 *   3. first + last name (case-insensitive, trimmed; the last name must be present).
 *
 * This replaces an all-pairs loop (about 1.2 million comparisons for 1,500 contacts, 5.9 s on
 * production, run on every clients page load). Contacts are grouped by each key instead, so the cost
 * grows with the number of contacts plus the number of real duplicate pairs.
 *
 * The result is byte-for-byte what the old loop returned: the same map, the same key order
 * (first appearance in id order) and the same id order inside each list. ContactDuplicateFinderTest
 * keeps a copy of the old loop and compares the two on randomised data.
 *
 * A shared property address is NOT a duplicate signal (2026-11-24): a building has many people (a property manager,
 * a strata representative, a signatory, tenants), and offering to merge them was wrong. $addressMap is still accepted
 * so callers need no change, but it is ignored.
 */
final class ContactDuplicateFinder
{
    /**
     * @param array<int, array<string, mixed>> $contacts   rows with id, first_name, last_name, email, phone, mobile, in id order
     * @param array<int, array<int, string>>   $addressMap contact_id => lower-cased trimmed property addresses
     * @return array<int, array<int, int>>     contact_id => [duplicate contact ids]
     */
    public static function buildMap(array $contacts, array $addressMap): array
    {
        $contacts = array_values($contacts);
        $n = count($contacts);

        // key => list of contact positions sharing it (positions ascend because we walk in order)
        $buckets = [];
        for ($i = 0; $i < $n; $i++) {
            $c = $contacts[$i];
            $keys = [];

            $email = $c['email'] ?? '';
            if (!empty($email)) {
                $keys['e' . strtolower(trim((string)$email))] = true;
            }

            foreach ([$c['phone'] ?? '', $c['mobile'] ?? ''] as $phone) {
                $digits = preg_replace('/\D/', '', (string)$phone);
                if ($digits !== '') {
                    $keys['p' . $digits] = true;
                }
            }

            $last = strtolower(trim((string)($c['last_name'] ?? '')));
            if ($last !== '') {
                $keys['n' . strtolower(trim((string)($c['first_name'] ?? ''))) . "\0" . $last] = true;
            }

            foreach (array_keys($keys) as $k) {
                $buckets[$k][] = $i;
            }
        }

        // Every pair that shares a key, once, as position pair (i, j) with i < j.
        $pairs = [];
        foreach ($buckets as $members) {
            $m = count($members);
            if ($m < 2) {
                continue;
            }
            for ($x = 0; $x < $m - 1; $x++) {
                for ($y = $x + 1; $y < $m; $y++) {
                    $pairs[$members[$x] * $n + $members[$y]] = true;
                }
            }
        }
        ksort($pairs);   // (i, j) order: the order the old loop met them

        $duplicates = [];
        foreach (array_keys($pairs) as $code) {
            $aId = $contacts[intdiv($code, $n)]['id'];
            $bId = $contacts[$code % $n]['id'];
            if (!isset($duplicates[$aId])) {
                $duplicates[$aId] = [];
            }
            if (!isset($duplicates[$bId])) {
                $duplicates[$bId] = [];
            }
            $duplicates[$aId][] = $bId;
            $duplicates[$bId][] = $aId;
        }

        return $duplicates;
    }
}
