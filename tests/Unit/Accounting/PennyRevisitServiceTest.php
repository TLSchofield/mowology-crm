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

    public function testDefaultAccountLineWithOwnerEvidenceIsTierA(): void
    {
        $c = PennyRevisitService::classify($this->line(), $this->advice(), false);
        $this->assertSame('A', $c['tier']);
        $this->assertStringContainsString('Canadian Electric', $c['reason']);
    }

    public function testARuleOnTheDefaultAccountIsAlsoTierA(): void
    {
        $this->assertSame('A', PennyRevisitService::classify($this->line(), $this->advice(['source' => 'rule']), false)['tier']);
    }

    public function testWeakerEvidenceIsTierBEvenOnTheDefaultAccount(): void
    {
        foreach (['learned', 'fact'] as $src) {
            $this->assertSame('B', PennyRevisitService::classify($this->line(), $this->advice(['source' => $src]), false)['tier'], $src);
        }
    }

    public function testALineAlreadyOnARealAccountIsAtMostTierB(): void
    {
        // Canev on 5400 Subcontractors: new evidence says it is wrong, but it was not on a default account: the owner approves.
        $line = $this->line(['account_id' => 54, 'account_code' => '5400']);
        $this->assertSame('B', PennyRevisitService::classify($line, $this->advice(), false)['tier']);
    }

    public function testALockedMonthIsTierCAndNeverMoved(): void
    {
        $this->assertSame('C', PennyRevisitService::classify($this->line(), $this->advice(), true)['tier']);
        $line = $this->line(['account_id' => 54, 'account_code' => '5400']);
        $this->assertSame('C', PennyRevisitService::classify($line, $this->advice(), true)['tier']);
    }

    public function testAnAccountTheOwnerChoseOnPurposeIsLeftAlone(): void
    {
        foreach (['accepted', 'edited', 'bulk', 'approved'] as $outcome) {
            $line = $this->line(['account_id' => 54, 'account_code' => '5400', 'review_outcome' => $outcome, 'review_final' => 54]);
            $this->assertSame('skip', PennyRevisitService::classify($line, $this->advice(), false)['tier'], $outcome);
        }
    }

    public function testAKeptLineOnTheDefaultAccountCanBeRevisited(): void
    {
        // "Kept" means the owner left it on Miscellaneous: new evidence may reopen it.
        $line = $this->line(['review_outcome' => 'kept', 'review_final' => 41]);
        $this->assertSame('A', PennyRevisitService::classify($line, $this->advice(), false)['tier']);
    }

    public function testAReviewedLineWhoseAccountChangedSinceIsNotProtected(): void
    {
        // Reviewed onto 6500, but it sits on 5400 now (moved by a rule or a later edit): the owner's choice no longer applies.
        $line = $this->line(['account_id' => 54, 'account_code' => '5400', 'review_outcome' => 'accepted', 'review_final' => 65]);
        $this->assertSame('B', PennyRevisitService::classify($line, $this->advice(), false)['tier']);
    }

    public function testLinkedVoidAndNonBankLinesAreSkipped(): void
    {
        $this->assertSame('skip', PennyRevisitService::classify($this->line(['matched_expense_id' => 9]), $this->advice(), false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->line(['matched_invoice_id' => 9]), $this->advice(), false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->line(['status' => 'void']), $this->advice(), false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->line(['status' => 'deleted']), $this->advice(), false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->line(['reference_type' => 'expense']), $this->advice(), false)['tier']);
    }

    public function testNoSuggestionOrTheSameAccountOrReceiptAdviceIsSkipped(): void
    {
        $this->assertSame('skip', PennyRevisitService::classify($this->line(), null, false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->line(), $this->advice(['account_id' => 41]), false)['tier']);
        foreach (['found_receipt', 'receipt', 'invoice'] as $src) {
            $this->assertSame('skip', PennyRevisitService::classify($this->line(), $this->advice(['source' => $src]), false)['tier'], $src);
        }
    }

    public function testPayrollLinesBelongToThePayrollImportNotToThis(): void
    {
        // Real hazard found in the first local preview: the desk maps "WAVE PYRL" to wages (5100). Booking the remittance
        // debit there first would count wages twice once the month's Wave report is imported.
        $wave = $this->line(['description' => 'PREAUTHORIZEDPAYMENT(WAVEPYRL)']);
        $this->assertSame('skip', PennyRevisitService::classify($wave, $this->advice(['account_id' => 5, 'code' => '5100', 'source' => 'fact']), false)['tier']);
        // by account alone, whatever the wording
        foreach (['5100', '5110', '2310', '2520'] as $code) {
            $this->assertSame('skip', PennyRevisitService::classify($this->line(), $this->advice(['code' => $code, 'source' => 'learned']), false)['tier'], $code);
        }
        // by wording alone, whatever account is suggested
        $this->assertSame('skip', PennyRevisitService::classify($this->line(['description' => 'Wave PYRL 12345']), $this->advice(), false)['tier']);
        $this->assertSame('skip', PennyRevisitService::classify($this->line(['description' => 'PAYROLL DEPOSIT']), $this->advice(), false)['tier']);
    }

    public function testPayrollWordingDoesNotCatchOrdinaryPayees(): void
    {
        $this->assertSame('A', PennyRevisitService::classify($this->line(['description' => 'ETRANSFER DEBIT (CANEV)']), $this->advice(), false)['tier']);
    }

    public function testALineWithNoAccountCountsAsOnTheDefault(): void
    {
        $line = $this->line(['account_id' => null, 'account_code' => null]);
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
