<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ContractTermsService — which terms apply, and what was actually signed.
 *
 * The thing worth pinning down is the snapshot. A template is editable; a
 * signed contract is not. If resolution ever reached through to the live
 * template for a contract already signed, revising the snow wording in March
 * would silently rewrite what every client agreed to in November — and the
 * whole reason those clauses exist is to be provable after an incident.
 *
 * Runs against in-memory SQLite. The schema mirrors migration 1119 closely
 * enough to exercise the real SQL rather than mock it away.
 */
class ContractTermsServiceTest extends TestCase
{
    private PDO $db;
    private ContractTermsService $svc;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE contract_terms_templates (
            id INTEGER PRIMARY KEY, name TEXT, slug TEXT, scope TEXT, service_type TEXT,
            body TEXT, version INT DEFAULT 1, season_start TEXT, season_end TEXT,
            forces_no_auto_renew INT DEFAULT 0, is_active INT DEFAULT 1,
            is_default INT DEFAULT 0, sort_order INT DEFAULT 0, created_by INT)");
        $this->db->exec("CREATE TABLE contracts (id INTEGER PRIMARY KEY, terms_template_id INT)");
        $this->db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, contract_id INT, service_type TEXT)");
        $this->db->exec("CREATE TABLE contract_versions (id INTEGER PRIMARY KEY, contract_id INT,
            version_number INT, terms_template_id INT, terms_template_version INT, terms_body TEXT)");

        $this->template(1, 'Snow & Ice', 'snow-ice', 'service_type', 'snow_removal',
            'The Company assumes no responsibility for slip and fall accidents.',
            ['season_start' => '11-01', 'season_end' => '03-31',
             'forces_no_auto_renew' => 1, 'sort_order' => 10]);
        $this->template(2, 'Grounds (default)', 'grounds', 'global', null,
            'Payment is due within 30 days.', ['is_default' => 1, 'sort_order' => 100]);
        $this->template(3, 'Mowing', 'mowing', 'service_type', 'lawn_mowing',
            'Work is carried out weather permitting.', ['sort_order' => 50]);

        $this->svc = new ContractTermsService($this->db);
    }

    private function template(int $id, string $name, string $slug, string $scope,
                              ?string $serviceType, string $body, array $opts = []): void
    {
        $this->db->prepare("INSERT INTO contract_terms_templates
            (id, name, slug, scope, service_type, body, version, season_start, season_end,
             forces_no_auto_renew, is_active, is_default, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $id, $name, $slug, $scope, $serviceType, $body,
            $opts['version'] ?? 1, $opts['season_start'] ?? null, $opts['season_end'] ?? null,
            $opts['forces_no_auto_renew'] ?? 0, $opts['is_active'] ?? 1,
            $opts['is_default'] ?? 0, $opts['sort_order'] ?? 0,
        ]);
    }

    private function contract(int $id, ?int $templateId = null): void
    {
        $this->db->prepare("INSERT INTO contracts (id, terms_template_id) VALUES (?,?)")
                 ->execute([$id, $templateId]);
    }

    private function plan(int $id, int $contractId, string $serviceType): void
    {
        $this->db->prepare("INSERT INTO job_plans (id, contract_id, service_type) VALUES (?,?,?)")
                 ->execute([$id, $contractId, $serviceType]);
    }

    // ── Resolution ──────────────────────────────────────────────────────────

    public function testExplicitTemplateOnContractWins(): void
    {
        $this->contract(1, 1);
        $this->plan(1, 1, 'lawn_mowing');          // would otherwise resolve to Mowing
        $this->assertSame('snow-ice', $this->svc->resolveForContract(1)['slug']);
    }

    public function testResolvesByServiceTypeOfItsPlans(): void
    {
        $this->contract(2);
        $this->plan(2, 2, 'snow_removal');
        $this->assertSame('snow-ice', $this->svc->resolveForContract(2)['slug']);
    }

    public function testMixedServiceContractTakesTheStricterTerms(): void
    {
        // A contract covering both mowing and snow gets the snow terms, because
        // sort_order puts them first. The stricter liability position should
        // govern the document — the reverse would leave winter work uncovered.
        $this->contract(3);
        $this->plan(3, 3, 'lawn_mowing');
        $this->plan(4, 3, 'snow_removal');
        $this->assertSame('snow-ice', $this->svc->resolveForContract(3)['slug']);
    }

    public function testFallsBackToGlobalDefault(): void
    {
        $this->contract(4);
        $this->plan(5, 4, 'gutter_cleaning');       // no template for this
        $this->assertSame('grounds', $this->svc->resolveForContract(4)['slug']);
    }

    public function testInactiveTemplateIsNotResolved(): void
    {
        $this->db->exec("UPDATE contract_terms_templates SET is_active = 0 WHERE id = 1");
        $this->contract(5);
        $this->plan(6, 5, 'snow_removal');
        $this->assertSame('grounds', $this->svc->resolveForContract(5)['slug']);
    }

    // ── Snapshot: the part that matters ─────────────────────────────────────

    public function testSnapshotWritesTheBodyOntoTheVersion(): void
    {
        $this->contract(6);
        $this->plan(7, 6, 'snow_removal');
        $this->db->exec("INSERT INTO contract_versions (contract_id, version_number) VALUES (6, 1)");

        $this->svc->snapshotOntoVersion(6, 1);

        $row = $this->db->query("SELECT * FROM contract_versions WHERE contract_id = 6")
                        ->fetch(PDO::FETCH_ASSOC);
        $this->assertStringContainsString('slip and fall', $row['terms_body']);
        $this->assertSame(1, (int)$row['terms_template_id']);
        $this->assertSame(1, (int)$row['terms_template_version']);
    }

    public function testEditingATemplateDoesNotRewriteASignedVersion(): void
    {
        $this->contract(7);
        $this->plan(8, 7, 'snow_removal');
        $this->db->exec("INSERT INTO contract_versions (contract_id, version_number) VALUES (7, 1)");
        $this->svc->snapshotOntoVersion(7, 1);

        // Someone revises the snow terms months later.
        $this->svc->saveTemplate([
            'id' => 1, 'name' => 'Snow & Ice', 'scope' => 'service_type',
            'service_type' => 'snow_removal',
            'body' => 'COMPLETELY DIFFERENT WORDING.',
        ]);

        $sealed = $this->svc->termsForVersion(7, 1);
        $this->assertTrue($sealed['snapshot'], 'must report it came from the snapshot');
        $this->assertStringContainsString('slip and fall', $sealed['body']);
        $this->assertStringNotContainsString('COMPLETELY DIFFERENT', $sealed['body']);
    }

    public function testFallsBackToLiveResolutionForPreMigrationContracts(): void
    {
        // No contract_versions row at all — a contract signed before 1119.
        $this->contract(8);
        $this->plan(9, 8, 'snow_removal');
        $sealed = $this->svc->termsForVersion(8, 1);
        $this->assertFalse($sealed['snapshot'], 'must admit this is a live guess, not a record');
        $this->assertStringContainsString('slip and fall', $sealed['body']);
    }

    public function testBodyEditBumpsVersionButRenameDoesNot(): void
    {
        $this->svc->saveTemplate(['id' => 3, 'name' => 'Mowing renamed', 'scope' => 'service_type',
            'service_type' => 'lawn_mowing', 'body' => 'Work is carried out weather permitting.']);
        $this->assertSame(1, (int)$this->svc->getTemplate(3)['version'], 'rename must not bump');

        $this->svc->saveTemplate(['id' => 3, 'name' => 'Mowing renamed', 'scope' => 'service_type',
            'service_type' => 'lawn_mowing', 'body' => 'New wording entirely.']);
        $this->assertSame(2, (int)$this->svc->getTemplate(3)['version'], 'body change must bump');
    }

    public function testHashIgnoresReflowButNotWordChanges(): void
    {
        $a = ContractTermsService::hashBody("One line.\nTwo   line.");
        $b = ContractTermsService::hashBody("One line. Two line.");
        $c = ContractTermsService::hashBody("One line. Two lines.");
        $this->assertSame($a, $b, 'whitespace reflow is not a different document');
        $this->assertNotSame($a, $c, 'a changed word is');
    }

    // ── Seasonal window ─────────────────────────────────────────────────────

    public function testSeasonWindowSpansTheYearEnd(): void
    {
        $tpl = $this->svc->getTemplate(1);
        $w = $this->svc->seasonWindow($tpl, '2026-10-15');   // before it opens
        $this->assertSame('2026-11-01', $w['start']);
        $this->assertSame('2027-03-31', $w['end'], 'March belongs to the following year');
    }

    public function testSeasonWindowFromInsideTheTail(): void
    {
        // January is the tail of the season that opened last November, not a
        // wait for the one opening this November. Getting this backwards ends
        // the contract four months before it starts.
        $tpl = $this->svc->getTemplate(1);
        $w = $this->svc->seasonWindow($tpl, '2027-01-20');
        $this->assertSame('2026-11-01', $w['start']);
        $this->assertSame('2027-03-31', $w['end']);
    }

    public function testNonSeasonalTemplateHasNoWindow(): void
    {
        $this->assertNull($this->svc->seasonWindow($this->svc->getTemplate(2)));
    }

    public function testSnowTemplateForcesNoAutoRenew(): void
    {
        $this->assertTrue($this->svc->forcesNoAutoRenew($this->svc->getTemplate(1)));
        $this->assertFalse($this->svc->forcesNoAutoRenew($this->svc->getTemplate(2)));
    }

    // ── Admin guards ────────────────────────────────────────────────────────

    public function testEmptyBodyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->saveTemplate(['name' => 'Blank', 'body' => "   \n "]);
    }

    public function testOnlyOneDefaultSurvives(): void
    {
        $this->svc->saveTemplate(['name' => 'New default', 'scope' => 'global',
            'body' => 'Some terms.', 'is_default' => 1]);
        $count = (int)$this->db->query(
            "SELECT COUNT(*) FROM contract_terms_templates WHERE is_default = 1"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }
}
