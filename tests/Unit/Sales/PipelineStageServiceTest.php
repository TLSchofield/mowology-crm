<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sam's pipeline stages: the pure rules (stageFor / decide) and, against an in-memory SQLite
 * copy of the relevant tables, the fact gathering, recompute, pins and the sweep.
 * Names and numbers are invented.
 */
class PipelineStageServiceTest extends TestCase
{
    private const TODAY = '2026-10-06';

    private function facts(array $f = []): array
    {
        return $f + ['as_of' => self::TODAY];
    }

    // ── stageFor: every rule ─────────────────────────────────────────────

    public function testNothingAtAllIsLead(): void
    {
        $this->assertSame('lead', PipelineStageService::stageFor($this->facts()));
    }

    public function testActiveContractIsClient(): void
    {
        $this->assertSame('client', PipelineStageService::stageFor($this->facts(['active_contract' => true, 'any_contract' => true])));
    }

    public function testContractClientWithNoProductHistoryOrInvoicesIsClient(): void
    {
        // Monica's case: a live monthly contract, nothing in contact_product_history, no paid invoice yet.
        $this->assertSame('client', PipelineStageService::stageFor($this->facts([
            'active_contract' => true, 'any_contract' => true,
            'last_accepted_at' => null, 'last_paid_at' => null, 'open_quotes' => 0, 'closed_quotes' => 0,
        ])));
    }

    public function testRecentAcceptedQuoteIsClient(): void
    {
        $this->assertSame('client', PipelineStageService::stageFor($this->facts(['last_accepted_at' => '2026-05-01 10:00:00'])));
    }

    public function testRecentPaidInvoiceIsClient(): void
    {
        $this->assertSame('client', PipelineStageService::stageFor($this->facts(['last_paid_at' => '2026-09-30'])));
    }

    public function testOpenQuoteIsOpportunity(): void
    {
        $this->assertSame('opportunity', PipelineStageService::stageFor($this->facts(['open_quotes' => 1])));
    }

    public function testClientBeatsOpportunity(): void
    {
        $this->assertSame('client', PipelineStageService::stageFor($this->facts(['open_quotes' => 2, 'active_contract' => true])));
    }

    public function testOpenQuoteForAnInactiveFormerClientIsOpportunity(): void
    {
        $this->assertSame('opportunity', PipelineStageService::stageFor($this->facts(['open_quotes' => 1, 'last_paid_at' => '2024-01-01'])));
    }

    public function testOldPaidInvoiceIsInactive(): void
    {
        $this->assertSame('inactive', PipelineStageService::stageFor($this->facts(['last_paid_at' => '2025-01-15'])));
    }

    public function testOldAcceptedQuoteIsInactive(): void
    {
        $this->assertSame('inactive', PipelineStageService::stageFor($this->facts(['last_accepted_at' => '2024-06-01'])));
    }

    public function testEndedContractWithNothingRecentIsInactive(): void
    {
        $this->assertSame('inactive', PipelineStageService::stageFor($this->facts(['any_contract' => true, 'active_contract' => false])));
    }

    public function testFormerClientWithDeclinedQuotesIsInactiveNotLost(): void
    {
        $this->assertSame('inactive', PipelineStageService::stageFor($this->facts(['closed_quotes' => 3, 'last_paid_at' => '2023-07-01'])));
    }

    public function testAllQuotesDeclinedNeverClientIsLost(): void
    {
        $this->assertSame('lost', PipelineStageService::stageFor($this->facts(['closed_quotes' => 2])));
    }

    public function testDeclinedPlusOpenIsOpportunityNotLost(): void
    {
        $this->assertSame('opportunity', PipelineStageService::stageFor($this->facts(['closed_quotes' => 2, 'open_quotes' => 1])));
    }

    // ── boundary dates ───────────────────────────────────────────────────

    public function testCutoffIsTwelveMonthsBack(): void
    {
        $this->assertSame('2025-10-06', PipelineStageService::cutoff(self::TODAY));
    }

    public function testPaidExactlyTwelveMonthsAgoIsStillClient(): void
    {
        $this->assertSame('client', PipelineStageService::stageFor($this->facts(['last_paid_at' => '2025-10-06 00:00:01'])));
        $this->assertSame('client', PipelineStageService::stageFor($this->facts(['last_paid_at' => '2025-10-06'])));
    }

    public function testPaidTwelveMonthsAndOneDayAgoIsInactive(): void
    {
        $this->assertSame('inactive', PipelineStageService::stageFor($this->facts(['last_paid_at' => '2025-10-05 23:59:59'])));
    }

    public function testAcceptedExactlyTwelveMonthsAgoIsClientAndDayBeforeInactive(): void
    {
        $this->assertSame('client', PipelineStageService::stageFor($this->facts(['last_accepted_at' => '2025-10-06'])));
        $this->assertSame('inactive', PipelineStageService::stageFor($this->facts(['last_accepted_at' => '2025-10-05'])));
    }

    public function testZeroDateIsIgnored(): void
    {
        $this->assertSame('lead', PipelineStageService::stageFor($this->facts(['last_paid_at' => '0000-00-00 00:00:00'])));
    }

    // ── decide: pins and hand-set stages ─────────────────────────────────

    public function testPinnedIsNeverChanged(): void
    {
        $this->assertNull(PipelineStageService::decide('lead', true, 'client', PipelineStageService::CONTACT_MANAGED));
    }

    public function testUnpinnedManagedStageChanges(): void
    {
        $this->assertSame('client', PipelineStageService::decide('lead', false, 'client', PipelineStageService::CONTACT_MANAGED));
    }

    public function testSameStageIsNoChange(): void
    {
        $this->assertNull(PipelineStageService::decide('client', false, 'client', PipelineStageService::CONTACT_MANAGED));
    }

    public function testOldCronKeysAreReplaced(): void
    {
        foreach (['customer', 'repeat', 'at_risk', 'prospect', '', null] as $old) {
            $this->assertSame('client', PipelineStageService::decide($old, false, 'client', PipelineStageService::CONTACT_MANAGED), (string)$old);
        }
    }

    public function testCustomStageIsLeftAlone(): void
    {
        $this->assertNull(PipelineStageService::decide('baited_with_quote', false, 'client', PipelineStageService::CONTACT_MANAGED));
        $this->assertNull(PipelineStageService::decide('won', false, 'client', PipelineStageService::CONTACT_MANAGED));
    }

    public function testCompanyLeadIsWrittenAsProspect(): void
    {
        $this->assertSame('prospect', PipelineStageService::companyKey('lead'));
        $this->assertSame('client', PipelineStageService::companyKey('client'));
        $this->assertNull(PipelineStageService::decide('prospect', false, PipelineStageService::companyKey('lead'), PipelineStageService::COMPANY_MANAGED));
    }

    // ── against a database ───────────────────────────────────────────────

    private function db(bool $withPin = true): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pin = $withPin ? ', lifecycle_pinned INTEGER NOT NULL DEFAULT 0' : '';
        foreach ([
            "CREATE TABLE lifecycle_stages (id INTEGER PRIMARY KEY, stage_key TEXT, is_active INTEGER DEFAULT 1)",
            "CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, is_active INTEGER DEFAULT 1,
                prospect_status TEXT DEFAULT 'prospect', lifecycle_stage TEXT DEFAULT 'lead'{$pin})",
            "CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT, primary_contact_id INTEGER,
                billing_contact_id INTEGER, quote_contact_id INTEGER, lifecycle_stage TEXT DEFAULT 'prospect'{$pin})",
            "CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, site_contact_id INTEGER,
                quote_contact_id INTEGER, property_manager_id INTEGER)",
            "CREATE TABLE property_contacts (id INTEGER PRIMARY KEY, property_id INTEGER, contact_id INTEGER, contact_role TEXT)",
            "CREATE TABLE contracts (id INTEGER PRIMARY KEY, contract_number TEXT, property_id INTEGER, contact_id INTEGER, status TEXT)",
            "CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INTEGER, company_id INTEGER, status TEXT)",
            "CREATE TABLE quotes (id INTEGER PRIMARY KEY, property_id INTEGER, contact_id INTEGER, company_id INTEGER,
                status TEXT, accepted_at TEXT, updated_at TEXT, created_at TEXT)",
            "CREATE TABLE invoices (id INTEGER PRIMARY KEY, property_id INTEGER, contact_id INTEGER, company_id INTEGER,
                status TEXT, paid_at TEXT, created_at TEXT)",
        ] as $sql) {
            $db->exec($sql);
        }
        foreach (['lead', 'opportunity', 'won', 'lost', 'prospect', 'qualified', 'client', 'inactive', 'customer', 'repeat', 'at_risk'] as $k) {
            $db->prepare("INSERT INTO lifecycle_stages (stage_key) VALUES (?)")->execute([$k]);
        }
        return $db;
    }

    private function svc(PDO $db): PipelineStageService
    {
        return new PipelineStageService($db, self::TODAY . ' 09:00:00');
    }

    private function stage(PDO $db, int $id, string $table = 'contacts'): string
    {
        return (string)$db->query("SELECT lifecycle_stage FROM {$table} WHERE id = {$id}")->fetchColumn();
    }

    public function testContractClientWithZeroProductHistoryBecomesClient(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts (id, first_name, lifecycle_stage) VALUES (7, 'Mona', 'lead')");
        $db->exec("INSERT INTO properties (id, address, site_contact_id) VALUES (70, '1 Test Rd', 7)");
        $db->exec("INSERT INTO contracts (id, contract_number, property_id, contact_id, status) VALUES (1, 'CTR-2026-0004', 70, 7, 'active')");

        $this->assertSame(['from' => 'lead', 'to' => 'client'], $this->svc($db)->recompute(7));
        $this->assertSame('client', $this->stage($db, 7));
        $this->assertSame('client', (string)$db->query("SELECT prospect_status FROM contacts WHERE id = 7")->fetchColumn());
        $this->assertNull($this->svc($db)->recompute(7), 'second run is a no-op');
    }

    public function testSiteContactOfAContractedPropertyIsClient(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts (id, first_name) VALUES (1, 'Owner'), (2, 'Site')");
        $db->exec("INSERT INTO properties (id, address, site_contact_id) VALUES (10, '2 Test Rd', 2)");
        $db->exec("INSERT INTO contracts (id, property_id, contact_id, status) VALUES (1, 10, 1, 'active')");
        $this->assertSame('client', $this->svc($db)->recompute(2)['to']);
    }

    public function testStrataManagerAndFirmBillingContactAreClients(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts (id, first_name) VALUES (1, 'Rep'), (2, 'Accounts'), (3, 'Bystander')");
        $db->exec("INSERT INTO companies (id, company_name, billing_contact_id) VALUES (5, 'Test Strata Ltd', 2)");
        $db->exec("INSERT INTO properties (id, address, property_manager_id) VALUES (10, '3 Test Rd', 5)");
        $db->exec("INSERT INTO property_contacts (property_id, contact_id, contact_role) VALUES (10, 1, 'manager'), (10, 3, 'emergency')");
        $db->exec("INSERT INTO job_plans (id, property_id, status) VALUES (1, 10, 'active')");
        $svc = $this->svc($db);
        $this->assertSame('client', $svc->recompute(1)['to']);
        $this->assertSame('client', $svc->recompute(2)['to']);
        $this->assertNull($svc->recompute(3), 'an emergency contact is not the account');
        $this->assertSame('client', $svc->recomputeCompany(5)['to']);
        $this->assertSame('client', $this->stage($db, 5, 'companies'));
    }

    public function testQuoteLifecycleThroughTheDatabase(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts (id, first_name) VALUES (1, 'Pat')");
        $db->exec("INSERT INTO quotes (id, contact_id, status, created_at) VALUES (1, 1, 'draft', '2026-09-01')");
        $svc = $this->svc($db);
        $this->assertNull($svc->recompute(1), 'a draft is not a quote yet');

        $db->exec("UPDATE quotes SET status = 'sent' WHERE id = 1");
        $this->assertSame('opportunity', $svc->recompute(1)['to']);

        $db->exec("UPDATE quotes SET status = 'declined' WHERE id = 1");
        $this->assertSame('lost', $svc->recompute(1)['to']);

        $db->exec("INSERT INTO quotes (id, contact_id, status, accepted_at, created_at) VALUES (2, 1, 'accepted', '2026-09-20 12:00:00', '2026-09-10')");
        $this->assertSame('client', $svc->recompute(1)['to']);
    }

    public function testPaidInvoiceBoundaryThroughTheDatabase(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts (id, first_name, lifecycle_stage) VALUES (1, 'A', 'customer'), (2, 'B', 'repeat')");
        $db->exec("INSERT INTO invoices (contact_id, status, paid_at) VALUES (1, 'paid', '2025-10-06 08:00:00'), (2, 'paid', '2025-10-05 18:00:00')");
        $db->exec("INSERT INTO invoices (contact_id, status, paid_at) VALUES (2, 'sent', '2026-10-01 08:00:00')");
        $svc = $this->svc($db);
        $this->assertSame('client', $svc->recompute(1)['to']);
        $this->assertSame('inactive', $svc->recompute(2)['to'], 'an unpaid invoice does not count');
    }

    public function testPinnedContactIsNeverTouched(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts (id, first_name, lifecycle_stage, lifecycle_pinned) VALUES (1, 'Pinned', 'lead', 1)");
        $db->exec("INSERT INTO contracts (id, contact_id, status) VALUES (1, 1, 'active')");
        $svc = $this->svc($db);
        $this->assertNull($svc->recompute(1));
        $this->assertSame('lead', $this->stage($db, 1));

        $r = $svc->sweep();
        $this->assertSame(1, $r['contacts']['pinned']);
        $this->assertSame(0, $r['contacts']['changed']);
        $this->assertSame('lead', $this->stage($db, 1));

        $db->exec("UPDATE contacts SET lifecycle_pinned = 0 WHERE id = 1");   // "Unpin (let Sam manage it)"
        $this->assertSame('client', $svc->recompute(1)['to']);
    }

    public function testCustomStageIsReportedNotChanged(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts (id, first_name, lifecycle_stage) VALUES (1, 'Custom', 'baited_with_quote')");
        $db->exec("INSERT INTO contracts (id, contact_id, status) VALUES (1, 1, 'active')");
        $r = $this->svc($db)->sweep();
        $this->assertSame(1, $r['contacts']['custom']);
        $this->assertSame(['baited_with_quote' => 1], $r['contacts']['custom_stages']);
        $this->assertSame('baited_with_quote', $this->stage($db, 1));
    }

    public function testMissingStageKeyIsBlockedNotWritten(): void
    {
        $db = $this->db();
        $db->exec("DELETE FROM lifecycle_stages WHERE stage_key = 'inactive'");
        $db->exec("INSERT INTO contacts (id, first_name, lifecycle_stage) VALUES (1, 'Old', 'customer')");
        $db->exec("INSERT INTO invoices (contact_id, status, paid_at) VALUES (1, 'paid', '2023-01-01')");
        $r = $this->svc($db)->sweep();
        $this->assertSame(1, $r['contacts']['blocked']);
        $this->assertSame('customer', $this->stage($db, 1));
    }

    public function testDryRunSweepCountsTransitionsAndSamplesWithoutWriting(): void
    {
        $db = $this->db();
        for ($i = 1; $i <= 35; $i++) {
            $db->exec("INSERT INTO contacts (id, first_name, lifecycle_stage) VALUES ({$i}, 'C{$i}', 'lead')");
            $db->exec("INSERT INTO contracts (contact_id, status) VALUES ({$i}, 'active')");
        }
        $db->exec("INSERT INTO contacts (id, first_name, lifecycle_stage) VALUES (99, 'Quiet', 'lead')");
        $db->exec("INSERT INTO contacts (id, first_name, lifecycle_stage, is_active) VALUES (100, 'Gone', 'lead', 0)");
        $db->exec("INSERT INTO contracts (contact_id, status) VALUES (100, 'active')");

        $r = $this->svc($db)->sweep(null, true);
        $this->assertTrue($r['dry_run']);
        $this->assertSame(36, $r['contacts']['examined'], 'inactive contacts are skipped');
        $this->assertSame(35, $r['contacts']['changed']);
        $this->assertSame(1, $r['contacts']['unchanged']);
        $this->assertSame(['lead→client' => 35], $r['contacts']['transitions']);
        $this->assertCount(30, $r['contacts']['samples']['lead→client']);
        $this->assertSame('lead', $this->stage($db, 1), 'dry run writes nothing');
        $this->assertStringContainsString('would change', PipelineStageService::summarize($r));

        $live = $this->svc($db)->sweep(10);
        $this->assertSame(10, $live['contacts']['examined']);
        $this->assertSame('client', $this->stage($db, 1));
        $this->assertSame('lead', $this->stage($db, 11), 'the limit is honoured');
    }

    public function testSweepWorksBeforeThePinMigrationRuns(): void
    {
        $db = $this->db(false);
        $db->exec("INSERT INTO contacts (id, first_name, lifecycle_stage) VALUES (1, 'Pre', 'lead')");
        $db->exec("INSERT INTO quotes (contact_id, status, created_at) VALUES (1, 'viewed', '2026-10-01')");
        $r = $this->svc($db)->sweep();
        $this->assertFalse($r['pin_column']);
        $this->assertSame('opportunity', $this->stage($db, 1));
    }

    public function testOnEventFromAContractMovesItsPeopleAndNeverThrows(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts (id, first_name) VALUES (1, 'Signer'), (2, 'Site')");
        $db->exec("INSERT INTO companies (id, company_name, primary_contact_id) VALUES (5, 'Test Co', 1)");
        $db->exec("INSERT INTO properties (id, address, site_contact_id) VALUES (10, '4 Test Rd', 2)");
        $db->exec("INSERT INTO contracts (id, property_id, contact_id, status) VALUES (3, 10, 1, 'active')");

        PipelineStageService::onEvent($db, 'contract', 3);
        $this->assertSame('client', $this->stage($db, 1));
        $this->assertSame('client', $this->stage($db, 2));
        $this->assertSame('client', $this->stage($db, 5, 'companies'));

        PipelineStageService::onEvent($db, 'nonsense', 3);
        PipelineStageService::onEvent(new PDO('sqlite::memory:'), 'invoice', 1);   // no tables at all
        $this->assertTrue(true, 'never throws');
    }
}
