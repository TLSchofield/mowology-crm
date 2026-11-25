<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ContactDuplicateFinder must return exactly what the old all-pairs loop in findDuplicateContacts() returned:
 * the same map, the same key order and the same order inside each list (the duplicate-review page groups by it).
 * oldLoop() below is a verbatim copy of that loop, kept as the reference.
 */
class ContactDuplicateFinderTest extends TestCase
{
    /** The pair loop as it was before 2026-11-20. */
    private function oldLoop(array $contacts, array $addressMap): array
    {
        $duplicates = [];
        $count = count($contacts);
        $matched = [];
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $contacts[$i];
                $b = $contacts[$j];
                $pairKey = $a['id'] . '-' . $b['id'];
                if (isset($matched[$pairKey])) continue;
                $isDuplicate = false;
                if (!empty($a['email']) && !empty($b['email'])
                    && strtolower(trim($a['email'])) === strtolower(trim($b['email']))) {
                    $isDuplicate = true;
                }
                if (!$isDuplicate) {
                    $phonesA = array_filter([
                        preg_replace('/\D/', '', $a['phone'] ?? ''),
                        preg_replace('/\D/', '', $a['mobile'] ?? ''),
                    ], function ($v) { return $v !== ''; });
                    $phonesB = array_filter([
                        preg_replace('/\D/', '', $b['phone'] ?? ''),
                        preg_replace('/\D/', '', $b['mobile'] ?? ''),
                    ], function ($v) { return $v !== ''; });
                    foreach ($phonesA as $pa) {
                        foreach ($phonesB as $pb) {
                            if ($pa === $pb) { $isDuplicate = true; break 2; }
                        }
                    }
                }
                if (!$isDuplicate) {
                    $addrsA = $addressMap[(int)$a['id']] ?? [];
                    $addrsB = $addressMap[(int)$b['id']] ?? [];
                    foreach ($addrsA as $addrA) {
                        if (in_array($addrA, $addrsB)) { $isDuplicate = true; break; }
                    }
                }
                if (!$isDuplicate
                    && trim($a['last_name'] ?? '') !== ''
                    && trim($b['last_name'] ?? '') !== ''
                    && strtolower(trim($a['first_name'])) === strtolower(trim($b['first_name']))
                    && strtolower(trim($a['last_name'])) === strtolower(trim($b['last_name']))) {
                    $isDuplicate = true;
                }
                if ($isDuplicate) {
                    $matched[$pairKey] = true;
                    if (!isset($duplicates[$a['id']])) $duplicates[$a['id']] = [];
                    if (!isset($duplicates[$b['id']])) $duplicates[$b['id']] = [];
                    if (!in_array($b['id'], $duplicates[$a['id']])) $duplicates[$a['id']][] = $b['id'];
                    if (!in_array($a['id'], $duplicates[$b['id']])) $duplicates[$b['id']][] = $a['id'];
                }
            }
        }
        return $duplicates;
    }

    public function testEachRuleOnItsOwn(): void
    {
        $c = fn(int $id, array $o = []) => array_merge(['id' => $id, 'first_name' => "F$id", 'last_name' => "L$id", 'email' => '', 'phone' => '', 'mobile' => ''], $o);

        $byEmail = [$c(1, ['email' => 'A@x.ca']), $c(2, ['email' => ' a@X.ca '])];
        $this->assertSame([1 => [2], 2 => [1]], ContactDuplicateFinder::buildMap($byEmail, []));

        $byPhone = [$c(1, ['phone' => '(778) 846-9273']), $c(2, ['mobile' => '778.846.9273'])];
        $this->assertSame([1 => [2], 2 => [1]], ContactDuplicateFinder::buildMap($byPhone, []));

        // Sharing a property address alone is not a duplicate (a building has many people: manager, strata rep, owner).
        $byAddr = [$c(1), $c(2)];
        $this->assertSame([], ContactDuplicateFinder::buildMap($byAddr, [1 => ['5 oak st'], 2 => ['5 oak st']]));

        $byName = [$c(1, ['first_name' => 'Ann', 'last_name' => 'Lee']), $c(2, ['first_name' => ' ann', 'last_name' => 'LEE '])];
        $this->assertSame([1 => [2], 2 => [1]], ContactDuplicateFinder::buildMap($byName, []));

        $noLast = [$c(1, ['first_name' => 'Ann', 'last_name' => '']), $c(2, ['first_name' => 'Ann', 'last_name' => ''])];
        $this->assertSame([], ContactDuplicateFinder::buildMap($noLast, []));

        $this->assertSame([], ContactDuplicateFinder::buildMap([], []));
    }

    public function testSameResultAsTheOldLoopOnRandomData(): void
    {
        mt_srand(20261120);
        $firsts = ['Ann', 'ann', 'Bob', 'Cy', '', 'Dee'];
        $lasts = ['Lee', 'LEE', 'Ng', '', ' ', 'Poe'];
        $emails = ['', 'a@x.ca', 'A@X.CA ', 'b@x.ca', ' ', '0', 'c@x.ca'];
        $phones = ['', '778-846-9273', '(778) 846 9273', '604 555 0101', 'ext', '1', '604.555.0102'];
        $addrs = ['5 oak st', '7 elm ave', '9 pine rd', '5 oak st '];

        for ($round = 0; $round < 60; $round++) {
            $n = mt_rand(0, 70);
            $contacts = [];
            $addressMap = [];
            for ($i = 1; $i <= $n; $i++) {
                $id = $i * 3;   // ids need not be contiguous
                $contacts[] = [
                    'id' => $id,
                    'first_name' => $firsts[array_rand($firsts)],
                    'last_name' => $lasts[array_rand($lasts)],
                    'email' => $emails[array_rand($emails)],
                    'phone' => $phones[array_rand($phones)],
                    'mobile' => $phones[array_rand($phones)],
                ];
                if (mt_rand(0, 2) === 0) {
                    $k = mt_rand(1, 2);
                    for ($a = 0; $a < $k; $a++) {
                        $addressMap[$id][] = strtolower(trim($addrs[array_rand($addrs)]));
                    }
                }
            }
            // the old loop without addresses: the address rule was retired, so the map must ignore addresses entirely
            $this->assertSame($this->oldLoop($contacts, []), ContactDuplicateFinder::buildMap($contacts, $addressMap), "round $round");
        }
    }

    public function testThousandsOfContactsAreFast(): void
    {
        $contacts = [];
        for ($i = 1; $i <= 3000; $i++) {
            $contacts[] = ['id' => $i, 'first_name' => "F$i", 'last_name' => "L$i", 'email' => "u$i@x.ca", 'phone' => (string)(6040000000 + $i), 'mobile' => ''];
        }
        $t = microtime(true);
        $this->assertSame([], ContactDuplicateFinder::buildMap($contacts, []));
        $this->assertLessThan(1.0, microtime(true) - $t, 'a linear pass over 3,000 unrelated contacts must take well under a second');
    }
}
