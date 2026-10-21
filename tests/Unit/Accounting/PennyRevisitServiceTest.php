<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PennyRevisitService - the pure rules: what may be proposed (classify), how proposals group by payee, and what the
 * accountant is told. Scanning and moving need a database and are not exercised here.
 */
class PennyRevisitServiceTest extends TestCase
{
    /** An unlinked imported bank expense on 6900 Miscellaneous, never reviewed. */
    private function line(array $over = []): array
    {
        return array_merge([
            'reference_type' => 'bank_import', 'type' => 'expense', 'status' => 'cleared',
            'matched_expense_id' => null, 'matched_invoice_id' => null,
            'account_id' => 41, 'account_code' => '6900', 'review_outcome' => null, 'review_final' => null,
        ], $over);
    }

    private function advice(array $over = []): array
    {
        return array_merge(['account_id' => 36, 'code' => '6120', 'name' => 'Vehicle Maintenance', 'source' => 'vendor',
                            'reason' => 'Canadian Electric Vehicles is a vendor you book as Vehicle'], $over);
    }

    // ── classify ──────────────────────────────────────────────────────────────────────────
    // Policy (from the first run on real data, 2026-10-20): revisit reconsiders a MACHINE's choice, never a person's,
    // and only on evidence the owner authored (a vendor they added, an active rule). Everything else is the desk's.

    /** A line the owner deliberately left on 6900 ("kept"). */
    private function kept(array $over = []): array
    {
        return $this->line(array_merge(['review_outcome' => 'kept', 'review_final' => 41], $over));
    }

    /** A line a rule or the import filed on a real account (5400), nobody reviewed. */
    private function machineFiled(array $over = []): array
    {
        return $this->line(array_merge(['account_id' => 54, 'account_code' => '5400', 'is_auto' => 1], $over));
    }

    public function testAKeptDefaultLineWithOwnerEvidenceIsTierA(): void
    {
        $c = PennyRevisitService::classify($this->kept(), $this->advice(), false);
        $this->assertSame('A', $c['tier']);
        $this->assertStringContainsString('Canadian Electric', $c['reason']);
        $this->assertSame('A', PennyRevisitService::classify($this->kept(), $this->advice(['source' => 'rule']), false)['tier']);
    }

    public function testAnUnreviewedDefaultLineInAnOpenMonthIsLeftToHerDesk(): void
    {
        // It is already on her card with this same advice; listing it again is noise.
        $this->assertSame('skip', PennyRevisitService::classify($this->line(), $this->advice(), false)['tier']);
    }

    public function testAMachineFiledLineOnARealAccountIsTierB(): void
    {
        // Canev on 5400 Subcontractors, put there by a rule: the new vendor says it is truck repair.
        $this->assertSame('B', PennyRevisitService::classify($this->machineFiled(), $this->advice(), false)['tier']);
    }

    public function testALineAPersonChoseIsNeverReconsidered(): void
    {
        // is_auto_categorized = 0 on a real account: a person set it (transactions screen, owner decision).
        $this->assertSame('skip', PennyRevisitService::classify($this->machineFiled(['is_auto' => 0]), $this->advice(), false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->machineFiled(['is_auto' => null]), $this->advice(), true)['tier']);   // even in a locked month
    }

    public function testOnlyOwnerAuthoredEvidenceCounts(): void
    {
        // Shell fuel proposed as Meals (a 'learned' payee with one decision) and restaurants (a 'fact') on the first real run.
        foreach (['learned', 'fact'] as $src) {
            $this->assertSame('skip', PennyRevisitService::classify($this->kept(), $this->advice(['source' => $src]), false)['tier'], $src . ' (default)');
            $this->assertSame('skip', PennyRevisitService::classify($this->machineFiled(), $this->advice(['source' => $src]), false)['tier'], $src . ' (real account)');
            $this->assertSame('skip', PennyRevisitService::classify($this->kept(), $this->advice(['source' => $src]), true)['tier'], $src . ' (locked)');
        }
    }

    public function testALockedMonthIsTierCAndNeverMoved(): void
    {
        $this->assertSame('C', PennyRevisitService::classify($this->line(), $this->advice(), true)['tier']);          // default, never on her desk (locked)
        $this->assertSame('C', PennyRevisitService::classify($this->machineFiled(), $this->advice(), true)['tier']);  // machine-filed real account
    }

    public function testAnAccountTheOwnerChoseOnPurposeIsLeftAlone(): void
    {
        foreach (['accepted', 'edited', 'bulk', 'approved'] as $outcome) {
            $line = $this->machineFiled(['review_outcome' => $outcome, 'review_final' => 54]);
            $this->assertSame('skip', PennyRevisitService::classify($line, $this->advice(), false)['tier'], $outcome);
        }
    }

    public function testAReviewedLineWhoseAccountChangedSinceIsNotProtectedByTheOldReview(): void
    {
        // Reviewed onto 6500, but a rule has since put it on 5400: the old decision no longer applies, the machine's choice may be revisited.
        $line = $this->machineFiled(['review_outcome' => 'accepted', 'review_final' => 65]);
        $this->assertSame('B', PennyRevisitService::classify($line, $this->advice(), false)['tier']);
    }

    public function testLinkedVoidAndNonBankLinesAreSkipped(): void
    {
        $this->assertSame('skip', PennyRevisitService::classify($this->kept(['matched_expense_id' => 9]), $this->advice(), false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->kept(['matched_invoice_id' => 9]), $this->advice(), false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->kept(['status' => 'void']), $this->advice(), false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->kept(['status' => 'deleted']), $this->advice(), false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->kept(['reference_type' => 'expense']), $this->advice(), false)['tier']);
    }

    public function testNoSuggestionOrTheSameAccountOrReceiptAdviceIsSkipped(): void
    {
        $this->assertSame('skip', PennyRevisitService::classify($this->kept(), null, false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->kept(), $this->advice(['account_id' => 41]), false)['tier']);
        foreach (['found_receipt', 'receipt', 'invoice'] as $src) {
            $this->assertSame('skip', PennyRevisitService::classify($this->kept(), $this->advice(['source' => $src]), false)['tier'], $src);
        }
    }

    public function testPayrollLinesBelongToThePayrollImportNotToThis(): void
    {
        // Real hazard found in the first local preview: the desk maps "WAVE PYRL" to wages (5100). Booking the remittance
        // debit there first would count wages twice once the month's Wave report is imported.
        $wave = $this->kept(['description' => 'PREAUTHORIZEDPAYMENT(WAVEPYRL)']);
        $this->assertSame('skip', PennyRevisitService::classify($wave, $this->advice(['account_id' => 5, 'code' => '5100']), false)['tier']);
        foreach (['5100', '5110', '2310', '2520'] as $code) {
            $this->assertSame('skip', PennyRevisitService::classify($this->kept(), $this->advice(['code' => $code]), false)['tier'], $code);
        }
        $this->assertSame('skip', PennyRevisitService::classify($this->kept(['description' => 'Wave PYRL 12345']), $this->advice(), false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->kept(['description' => 'PAYROLL DEPOSIT']), $this->advice(), false)['tier']);
    }

    public function testPayrollWordingDoesNotCatchOrdinaryPayees(): void
    {
        $this->assertSame('A', PennyRevisitService::classify($this->kept(['description' => 'ETRANSFER DEBIT (CANEV)']), $this->advice(), false)['tier']);
    }

    public function testALineWithNoAccountCountsAsOnTheDefault(): void
    {
        $line = $this->kept(['account_id' => null, 'account_code' => null]);
        $this->assertSame('A', PennyRevisitService::classify($line, $this->advice(), false)['tier']);
    }

    // ── groupProposals ────────────────────────────────────────────────────────────────────

    private function row(int $id, string $payee, int $to, string $tier, float $amt, string $date, string $from = '6900 Miscellaneous'): array
    {
        return ['id' => $id, 'payee_key' => $payee, 'to_account_id' => $to, 'to_label' => '6120 Vehicle Maintenance', 'from_label' => $from,
                'tier' => $tier, 'source' => 'vendor', 'reason' => 'a vendor you book as Vehicle', 'amount' => $amt, 'line_date' => $date,
                'description' => strtoupper($payee) . ' e-Transfer'];
    }

    public function testProposalsGroupByPayeeAndTargetAndSortBiggestFirst(): void
    {
        $g = PennyRevisitService::groupProposals([
            $this->row(1, 'canev', 36, 'A', 695.0, '2026-03-18'),
            $this->row(2, 'canev', 36, 'A', 250.0, '2026-02-01'),
            $this->row(3, 'shell', 30, 'B', 200.0, '2026-05-02'),
        ]);
        $this->assertCount(2, $g);
        $this->assertSame('canev', $g[0]['payee_key']);
        $this->assertSame(945.0, $g[0]['total']);
        $this->assertSame(2, $g[0]['count']);
        $this->assertSame([1, 2], $g[0]['ids']);
        $this->assertSame('2026-02-01', $g[0]['first']);
        $this->assertSame('2026-03-18', $g[0]['last']);
    }

    public function testAGroupIsOnlyAsSureAsItsLeastSureLine(): void
    {
        $g = PennyRevisitService::groupProposals([
            $this->row(1, 'canev', 36, 'A', 100.0, '2026-03-01'),
            $this->row(2, 'canev', 36, 'B', 100.0, '2026-03-02', '5400 Subcontractors'),
        ]);
        $this->assertSame('B', $g[0]['tier']);
        $this->assertSame(['6900 Miscellaneous', '5400 Subcontractors'], $g[0]['from_labels']);
    }

    public function testTheSamePayeeToTwoAccountsIsTwoDecisions(): void
    {
        $g = PennyRevisitService::groupProposals([
            $this->row(1, 'canev', 36, 'B', 100.0, '2026-03-01'),
            $this->row(2, 'canev', 54, 'B', 100.0, '2026-03-02'),
        ]);
        $this->assertCount(2, $g);
    }

    // ── grouping by the matched vendor (the tidy-up after the first real run looked messy) ──

    public function testMatchedNameIsReadFromHerReason(): void
    {
        $this->assertSame('City of Vancouver Vancouver Landfill', PennyRevisitService::matchedName('City of Vancouver Vancouver Landfill is a vendor you book as Disposal/Dump'));
        $this->assertSame('Canadian Tire', PennyRevisitService::matchedName('Canadian Tire is a vendor you book as Tools/Equipment'));
        $this->assertSame('Fuel stops', PennyRevisitService::matchedName('Your rule "Fuel stops" says so'));
        $this->assertNull(PennyRevisitService::matchedName('It matches your receipt #4'));
        $this->assertNull(PennyRevisitService::matchedName(''));
    }

    public function testDifferentBankWordingsOfOneVendorAreOneDecision(): void
    {
        // Real case: "CITY OF VANCOUVER - TR" (10 lines) and "CITY OF VANCOUVER - TR CITY OF VANCOUVE" (2 lines) showed as two payees.
        $mk = function (int $id, string $key, string $desc, float $amt): array {
            return ['id' => $id, 'payee_key' => $key, 'to_account_id' => 5, 'to_label' => '5000 Cost of Services', 'from_label' => '6500 Office & Administration',
                    'tier' => 'B', 'source' => 'vendor', 'reason' => 'City of Vancouver Vancouver Landfill is a vendor you book as Disposal/Dump',
                    'amount' => $amt, 'line_date' => '2026-03-28', 'description' => $desc];
        };
        $g = PennyRevisitService::groupProposals([
            $mk(1, 'city of vancouver tr', 'Point of sale CITY OF VANCOUVER - TR', 20.0),
            $mk(2, 'city of vancouver tr city of vancouve', 'Point of sale CITY OF VANCOUVER - TR CITY OF VANCOUVE', 15.0),
        ]);
        $this->assertCount(1, $g);
        $this->assertSame('City of Vancouver Vancouver Landfill', $g[0]['label']);
        $this->assertSame(2, $g[0]['count']);
        $this->assertSame(35.0, $g[0]['total']);
        $this->assertCount(2, $g[0]['examples']);   // the real bank wordings, so a wrong match shows
    }

    public function testExamplesAreCappedAndDeduplicated(): void
    {
        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['id' => $i, 'payee_key' => 'k', 'to_account_id' => 5, 'to_label' => 'x', 'from_label' => 'y', 'tier' => 'B', 'source' => 'vendor',
                       'reason' => 'ACME is a vendor you book as Materials', 'amount' => 1.0, 'line_date' => '2026-03-0' . $i, 'description' => 'WORDING ' . ($i % 4)];
        }
        $g = PennyRevisitService::groupProposals($rows);
        $this->assertCount(3, $g[0]['examples']);
        $this->assertSame(array_unique($g[0]['examples']), $g[0]['examples']);
    }

    public function testAccountantLinesGroupByVendorAndTheFalseMatchIsVisible(): void
    {
        // Real false match: "H MART DUNBAR VANCOUVER" matched the vendor Dunbar Lumber on the word "Dunbar". Grouped under the vendor,
        // with the real bank wording listed, the odd one out is easy to spot.
        $mk = function (string $desc, float $amt, string $date): array {
            return ['line_date' => $date, 'amount' => $amt, 'description' => $desc, 'from_label' => '6900 Miscellaneous', 'to_label' => '5200 Materials & Supplies',
                    'reason' => 'Dunbar Lumber is a vendor you book as Materials'];
        };
        $groups = PennyRevisitService::accountantGroups([
            $mk('POINT OF SALE (DUNBAR LUMBER SUPPLY VANCOUVER)', 42.0, '2025-03-01'),
            $mk('POINT OF SALE (H MART DUNBAR VANCOUVER BCCA)', 27.97, '2025-11-30'),
        ]);
        $this->assertCount(1, $groups);
        $this->assertSame('Dunbar Lumber', $groups[0]['label']);
        $this->assertSame(2, $groups[0]['count']);
        $this->assertSame(69.97, $groups[0]['total']);
        $this->assertSame('2025-03-01', $groups[0]['first']);
        $text = PennyRevisitService::accountantText([
            $mk('POINT OF SALE (H MART DUNBAR VANCOUVER BCCA)', 27.97, '2025-11-30'),
        ]);
        $this->assertStringContainsString('Dunbar Lumber: 1 line, $27.97', $text);
        $this->assertStringContainsString('H MART DUNBAR', $text);
    }

    // ── accountant text and memo ──────────────────────────────────────────────────────────

    public function testTheAccountantListSaysNothingWasMovedAndWhy(): void
    {
        $t = PennyRevisitService::accountantText([[
            'line_date' => '2025-02-14', 'amount' => 286.19, 'description' => 'ETRANSFER DEBIT (CANEV)',
            'from_label' => '5400 Subcontractors', 'to_label' => '6120 Vehicle Maintenance', 'reason' => 'invoice CA22542 is truck parts',
        ]]);
        $this->assertStringContainsString('Nothing was moved', $t);
        $this->assertStringContainsString('2025-02-14', $t);
        $this->assertStringContainsString('$286.19', $t);
        $this->assertStringContainsString('5400 Subcontractors', $t);
        $this->assertStringContainsString('invoice CA22542', $t);
    }

    public function testAnEmptyAccountantListSaysSo(): void
    {
        $this->assertStringContainsString('Nothing for the accountant', PennyRevisitService::accountantText([]));
    }

    public function testMemoNamesTheDateAndTheReason(): void
    {
        $this->assertSame('Penny revisit 2026-10-21: a vendor you book as Vehicle', PennyRevisitService::memo('  a vendor you book as Vehicle ', '2026-10-21'));
    }
}
