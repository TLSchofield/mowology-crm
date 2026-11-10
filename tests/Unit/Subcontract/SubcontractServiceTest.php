<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SubcontractServiceTest extends TestCase
{
    private PDO $db;
    private array $calls = [];
    private bool $moveWorks = true;
    private bool $completeWorks = true;
    public bool $billFails = false;
    private SubcontractService $svc;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->sqliteCreateFunction('NOW', static fn () => '2026-11-10 12:00:00', 0);
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, status TEXT, scheduled_date TEXT)");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, title TEXT, property_id INT)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, latitude REAL, longitude REAL, site_contact_id INT)");
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, phone TEXT)");
        $db->exec("CREATE TABLE plan_line_items (id INTEGER PRIMARY KEY, plan_id INT, description TEXT, service_type TEXT, sort_order INT DEFAULT 0)");
        $db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT, default_accounting_category TEXT, phone TEXT)");
        $db->exec("CREATE TABLE subcontractors (id INTEGER PRIMARY KEY, company_name TEXT UNIQUE, contact_name TEXT, email TEXT, phone TEXT, vendor_id INT, on_ittai INT DEFAULT 0, is_active INT DEFAULT 1, created_by INT, created_at TEXT DEFAULT '2026-11-10')");
        $db->exec("CREATE TABLE subcontract_jobs (id INTEGER PRIMARY KEY, subcontractor_id INT, visit_id INT, plan_id INT, token_hash TEXT UNIQUE, token_preview TEXT, expires_at TEXT, revoked_at TEXT, last_opened_at TEXT, open_count INT DEFAULT 0, agreed_amount REAL, note_to_sub TEXT, share_client_name INT DEFAULT 1, share_client_phone INT DEFAULT 1, after_the_fact INT DEFAULT 0, work_status TEXT DEFAULT 'sent', status_changed_at TEXT DEFAULT '2026-11-10 12:00:00', planned_date TEXT, started_at TEXT, finished_at TEXT, work_accepted_at TEXT, work_accepted_by INT, bill_status TEXT DEFAULT 'none', bill_amount REAL, bill_gst REAL, bill_invoice_no TEXT, bill_expense_id INT, bill_paid_at TEXT, created_by INT, created_at TEXT DEFAULT '2026-11-10')");
        $db->exec("CREATE TABLE subcontract_events (id INTEGER PRIMARY KEY, job_id INT, visit_id INT, event_type TEXT, actor TEXT, user_id INT, note TEXT, latitude REAL, longitude REAL, accuracy_m INT, on_site INT, created_at TEXT)");
        $db->exec("CREATE TABLE subcontract_files (id INTEGER PRIMARY KEY, job_id INT, kind TEXT, stored_name TEXT, original_name TEXT, mime_type TEXT, size_bytes INT, created_at TEXT)");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, status TEXT)");
        $db->exec("INSERT INTO contacts VALUES (5, 'Pat', 'Lee', '604-555-0100')");
        $db->exec("INSERT INTO properties VALUES (3, '12 Oak St', 'Vancouver', 49.2828, -123.1208, 5)");
        $db->exec("INSERT INTO job_plans VALUES (2, 'Fall cleanup', 3)");
        $db->exec("INSERT INTO job_visits VALUES (10, 2, 'scheduled', '2026-11-14'), (11, 2, 'scheduled', '2026-11-21'), (12, 2, 'completed', '2026-10-01')");
        $db->exec("INSERT INTO plan_line_items (plan_id, description, service_type) VALUES (2, 'Rake beds', 'cleanup'), (2, '', 'haul')");
        $this->db = $db;

        $test = $this;
        $this->svc = new SubcontractService($db, new class ($test) extends SubcontractHooks {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function visitInProgress(int $visitId, int $ownerId): void { $this->t->called('in_progress', $visitId); }
            public function moveVisit(int $visitId, string $date, int $ownerId): bool { $this->t->called('move', $date); return $this->t->canMove(); }
            public function completeVisit(int $visitId, int $userId): bool { $this->t->called('complete', $visitId); return $this->t->canComplete(); }
            public function push(array $userIds, string $title, string $body, array $data): void { $this->t->called('push', $body); }
            public function visitBackToScheduled(int $visitId, int $userId): void { $this->t->called('back', $visitId); }
            public function refreshVisitCost(int $visitId, int $userId): void { $this->t->called('recost', $visitId); }
            public function recordBill(array $changes, int $ownerId): int { $this->t->called('bill', $changes); if ($this->t->billFails) throw new RuntimeException('no'); return 501; }
        });
    }

    public function called(string $what, $arg): void { $this->calls[] = [$what, $arg]; }
    public function canMove(): bool { return $this->moveWorks; }
    public function canComplete(): bool { return $this->completeWorks; }

    private function send(int $visit = 10, array $opts = []): array
    {
        $company = $this->svc->saveCompany(['company_name' => 'Green Co', 'contact_name' => 'C', 'email' => 'c@example.com'], 1);
        $sent = $this->svc->create($visit, $company, 1, $opts + ['agreed_amount' => '250', 'note' => 'Gate on the left']);
        $this->db->exec("INSERT INTO subcontract_files (job_id, kind, stored_name, mime_type, size_bytes) VALUES ({$sent['job_id']}, 'photo', 'a.jpg', 'image/jpeg', 10)");
        return $sent;
    }

    private function fresh(string $token): array
    {
        return $this->svc->resolve($token);
    }

    private function callsNamed(string $name): array
    {
        return array_values(array_filter($this->calls, static fn ($c) => $c[0] === $name));
    }

    private function eventTypes(int $jobId): array
    {
        return array_column($this->svc->timeline($jobId), 'event_type');
    }

    public function testOnlyTheHashIsStored(): void
    {
        $sent = $this->send();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sent['token']);
        $row = $this->db->query("SELECT token_hash, token_preview FROM subcontract_jobs")->fetch();
        $this->assertSame(hash('sha256', $sent['token']), $row['token_hash']);
        $this->assertNotSame($sent['token'], $row['token_hash']);
        $this->assertSame(8, strlen($row['token_preview']));
    }

    public function testBadLinksOpenNothing(): void
    {
        $sent = $this->send();
        $this->assertNull($this->svc->resolve('not-a-token'));
        $this->assertNull($this->svc->resolve(str_repeat('a', 64)));
        $this->assertNull($this->svc->resolve(hash('sha256', $sent['token'])), 'the stored hash is not a key');
    }

    public function testExpiredAndRevokedLinksCannotAct(): void
    {
        $sent = $this->send();
        $this->db->exec("UPDATE subcontract_jobs SET expires_at = '2026-01-01 00:00:00'");
        $job = $this->fresh($sent['token']);
        $this->assertSame('expired', $job['link_state']);
        $this->assertFalse($this->svc->act($job, 'accept')['ok']);

        $this->db->exec("UPDATE subcontract_jobs SET expires_at = '2027-01-01 00:00:00'");
        $this->svc->cancel($sent['job_id'], 1);
        $job = $this->fresh($sent['token']);
        $this->assertSame('revoked', $job['link_state']);
        $this->assertFalse($this->svc->act($job, 'accept')['ok']);
    }

    public function testALinkOnlyReachesItsOwnJob(): void
    {
        $one = $this->send(10);
        $two = $this->send(11);
        $this->assertTrue($this->svc->act($this->fresh($one['token']), 'accept')['ok']);
        $status = fn ($id) => $this->db->query("SELECT work_status FROM subcontract_jobs WHERE id = {$id}")->fetchColumn();
        $this->assertSame('accepted', $status($one['job_id']));
        $this->assertSame('sent', $status($two['job_id']));
    }

    public function testNoSecondJobOnARunningVisitAndNotOnAClosedOne(): void
    {
        $this->send(10);
        try {
            $this->send(10);
            $this->fail('second job on the same visit');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already with a subcontractor', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->send(12);
    }

    public function testADeclinedVisitCanBeOfferedAgain(): void
    {
        $sent = $this->send(10);
        $this->assertTrue($this->svc->act($this->fresh($sent['token']), 'decline')['ok']);
        $this->assertGreaterThan(0, $this->send(10)['job_id']);
    }

    public function testTheWholeWalkKeepsTheVisitOpenUntilTheOwnerAccepts(): void
    {
        $sent = $this->send();
        $this->svc->recordOpened($this->fresh($sent['token']));
        $this->svc->recordOpened($this->fresh($sent['token']));

        $this->assertTrue($this->svc->act($this->fresh($sent['token']), 'accept')['ok']);
        $this->assertTrue($this->svc->act($this->fresh($sent['token']), 'pick_day', ['date' => '2026-11-14'])['ok']);
        $this->assertSame([], $this->callsNamed('move'), 'same day, no move');
        $this->assertTrue($this->svc->act($this->fresh($sent['token']), 'on_my_way')['ok']);
        $this->assertTrue($this->svc->act($this->fresh($sent['token']), 'start', ['lat' => 49.2827, 'lng' => -123.1207, 'accuracy' => 15])['ok']);
        $this->assertCount(1, $this->callsNamed('in_progress'));
        $this->assertTrue($this->svc->act($this->fresh($sent['token']), 'finish', ['note' => 'All done'])['ok']);

        $this->assertSame([], $this->callsNamed('complete'), 'Finish alone must not complete the visit');
        $this->assertTrue($this->svc->acceptWork($sent['job_id'], 1)['ok']);
        $this->assertSame([['complete', 10]], $this->callsNamed('complete'));
        $this->assertFalse($this->svc->acceptWork($sent['job_id'], 1)['ok'], 'second accept does not complete twice');
        $this->assertCount(1, $this->callsNamed('complete'));

        $this->assertSame(['sent', 'opened', 'accepted', 'scheduled', 'on_way', 'started', 'finished', 'work_accepted'], $this->eventTypes($sent['job_id']));
        $this->assertSame(4, count($this->callsNamed('push')), 'accepted, on way, started, finished');
        $started = $this->db->query("SELECT on_site, latitude FROM subcontract_events WHERE event_type = 'started'")->fetch();
        $this->assertSame(1, (int)$started['on_site']);
        $this->assertNotNull($started['latitude']);
    }

    public function testWorkAlreadyDoneAsksOnlyForProofAndTheBill(): void
    {
        $sent = $this->send(12, ['already_done' => true]);
        $job = $this->fresh($sent['token']);
        $page = $this->svc->page($job);
        $this->assertSame('send_proof', $page['next_step']);
        $this->assertTrue($page['after_the_fact']);
        $this->assertSame('', $page['client_phone'], 'nothing to arrange, so no number');

        $this->assertFalse($this->svc->act($job, 'finish', ['done_on' => '2026-12-01'])['ok'], 'not a future day');
        $this->assertFalse($this->svc->act($job, 'finish')['ok'], 'the day is required');
        $this->assertTrue($this->svc->act($this->fresh($sent['token']), 'finish', ['done_on' => '2026-11-02', 'note' => 'Blew out 6 zones'])['ok']);

        $job = $this->fresh($sent['token']);
        $this->assertSame('finished', $job['work_status']);
        $this->assertSame('2026-11-02', $job['planned_date']);
        $this->assertSame('send_bill', $this->svc->page($job)['next_step']);
        $this->assertSame([], $this->callsNamed('in_progress'), 'a finished visit is not reopened');
        $this->assertSame([['push', 'Green Co sent proof of work for 12 Oak St']], $this->callsNamed('push'));

        $this->assertTrue($this->svc->acceptWork($sent['job_id'], 1)['ok']);
    }

    public function testFinishNeedsProof(): void
    {
        $sent = $this->send();
        foreach (['accept', 'start'] as $a) $this->svc->act($this->fresh($sent['token']), $a);
        $this->db->exec("DELETE FROM subcontract_files");
        $result = $this->svc->act($this->fresh($sent['token']), 'finish', ['note' => 'Trust me, it is all done']);
        $this->assertFalse($result['ok']);
        $this->assertSame('started', $this->fresh($sent['token'])['work_status']);
    }

    public function testAJobDoneOffTheSystemCanProveItWithANote(): void
    {
        $sent = $this->send(12, ['already_done' => true]);
        $this->db->exec("DELETE FROM subcontract_files");
        $this->assertFalse($this->svc->act($this->fresh($sent['token']), 'finish', ['done_on' => '2026-11-02'])['ok']);
        $this->assertTrue($this->svc->act($this->fresh($sent['token']), 'finish', ['done_on' => '2026-11-02', 'note' => 'Blew out 6 zones'])['ok']);
    }

    private function finished(): array
    {
        $sent = $this->send();
        foreach (['accept', 'start', 'finish'] as $a) $this->svc->act($this->fresh($sent['token']), $a);
        return $sent;
    }

    public function testBillBecomesADraftOnTheJobAndCannotBeSentTwice(): void
    {
        $sent = $this->finished();
        $this->assertFalse($this->svc->submitBill($this->fresh($sent['token']), ['amount' => '0'])['ok']);
        $this->assertTrue($this->svc->submitBill($this->fresh($sent['token']), ['amount' => '250', 'gst' => '12.50', 'invoice_no' => 'A7'])['ok']);
        $this->assertFalse($this->svc->submitBill($this->fresh($sent['token']), ['amount' => '250', 'gst' => '12.50'])['ok']);

        $bills = $this->callsNamed('bill');
        $this->assertCount(1, $bills);
        $this->assertSame('draft', $bills[0][1]['status']);
        $this->assertSame('Subcontractors', $bills[0][1]['accounting_category']);
        $this->assertSame(262.5, $bills[0][1]['total']);
        $this->assertSame(2, $bills[0][1]['job_id']);
        $this->assertSame(3, $bills[0][1]['property_id']);
        $this->assertSame(1, $bills[0][1]['created_by']);
        $this->assertSame('bill_waiting', $this->svc->page($this->fresh($sent['token']))['next_step']);
    }

    public function testAFailedBillCanBeSentAgain(): void
    {
        $sent = $this->finished();
        $this->billFails = true;
        $this->assertFalse($this->svc->submitBill($this->fresh($sent['token']), ['amount' => '250', 'gst' => '0'])['ok']);
        $this->billFails = false;
        $this->assertSame('none', $this->fresh($sent['token'])['bill_status']);
        $this->assertTrue($this->svc->submitBill($this->fresh($sent['token']), ['amount' => '250', 'gst' => '0'])['ok']);
    }

    public function testPennysDecisionShowsOnHerPageAndPaidNeedsBothApprovals(): void
    {
        $sent = $this->finished();
        $this->svc->submitBill($this->fresh($sent['token']), ['amount' => '250', 'gst' => '12.50']);
        $this->db->exec("INSERT INTO expenses VALUES (501, 'draft')");

        $this->assertFalse($this->svc->markPaid($sent['job_id'], 1)['ok'], 'work not accepted');
        $this->svc->acceptWork($sent['job_id'], 1);
        $this->assertFalse($this->svc->markPaid($sent['job_id'], 1)['ok'], 'bill not approved');

        $this->db->exec("UPDATE expenses SET status = 'approved'");
        $this->assertSame('payment_waiting', $this->svc->page($this->fresh($sent['token']))['next_step']);
        $this->assertTrue($this->svc->markPaid($sent['job_id'], 1)['ok']);
        $this->assertFalse($this->svc->markPaid($sent['job_id'], 1)['ok']);
        $this->assertSame('done', $this->svc->page($this->fresh($sent['token']))['next_step']);
    }

    public function testARejectedBillGoesBackToHer(): void
    {
        $sent = $this->finished();
        $this->svc->submitBill($this->fresh($sent['token']), ['amount' => '250', 'gst' => '12.50']);
        $this->db->exec("INSERT INTO expenses VALUES (501, 'rejected')");
        $this->assertSame('send_bill', $this->svc->page($this->fresh($sent['token']))['next_step']);
    }

    public function testAskingAboutIttaiIsCountedOncePerJob(): void
    {
        $sent = $this->finished();
        $job = $this->fresh($sent['token']);
        $this->svc->markPanelSeen($job);
        $this->svc->markPanelSeen($job);
        $this->svc->askAboutIttai($job);
        $this->svc->askAboutIttai($job);
        $this->assertSame(['invited' => 1, 'opened' => 0, 'panel_seen' => 1, 'asked' => 1], $this->svc->marketingCounts());
        $this->assertCount(1, array_filter($this->callsNamed('push'), static fn ($c) => strpos($c[1], 'Ittai') !== false));
    }

    public function testCancellingAStartedJobFreesTheVisit(): void
    {
        $sent = $this->send();
        foreach (['accept', 'start'] as $a) $this->svc->act($this->fresh($sent['token']), $a);
        $this->svc->cancel($sent['job_id'], 1);
        $this->assertSame([['back', 10]], $this->callsNamed('back'));
    }

    public function testABillAfterAcceptanceRefreshesTheVisitCost(): void
    {
        $sent = $this->finished();
        $this->svc->acceptWork($sent['job_id'], 1);
        $this->svc->submitBill($this->fresh($sent['token']), ['amount' => '300', 'gst' => '15']);
        $this->assertSame([['recost', 10]], $this->callsNamed('recost'));
    }

    public function testAnInvoiceAlreadyInHandAsksOnlyForPhotos(): void
    {
        $sent = $this->send(12, ['already_done' => true, 'bill_received' => true]);
        $job = $this->fresh($sent['token']);
        $page = $this->svc->page($job);
        $this->assertTrue($page['bill_received']);
        $this->assertSame('send_proof', $page['next_step']);

        $this->db->exec("DELETE FROM subcontract_files");
        $this->assertFalse($this->svc->act($job, 'finish', ['note' => 'Trust me, it is all done'])['ok'], 'a note is not a photo here');
        $this->db->exec("INSERT INTO subcontract_files (job_id, kind, stored_name, mime_type, size_bytes) VALUES ({$sent['job_id']}, 'photo', 'a.jpg', 'image/jpeg', 10)");
        $this->assertTrue($this->svc->act($this->fresh($sent['token']), 'finish')['ok'], 'no day needed, their invoice has it');

        $job = $this->fresh($sent['token']);
        $this->assertSame('bill_waiting', $this->svc->page($job)['next_step']);
        $this->assertFalse($this->svc->submitBill($job, ['amount' => '100', 'gst' => '5'])['ok'], 'no second bill');
        $this->assertFalse($this->svc->markPaid($sent['job_id'], 1)['ok'], 'accept first');
        $this->svc->acceptWork($sent['job_id'], 1);
        $this->assertTrue($this->svc->markPaid($sent['job_id'], 1)['ok']);
    }

    public function testTheBoardShowsEachJobItsStageAndNoClientDetails(): void
    {
        $this->db->exec("ALTER TABLE job_plans ADD COLUMN price_per_visit REAL");
        $this->db->exec("UPDATE job_plans SET price_per_visit = 999");
        $a = $this->finished();
        $b = $this->send(11);
        $board = $this->svc->board();
        $this->assertSame([$a['job_id'], $b['job_id']], array_column($board, 'job_id'), 'waiting on you first');
        $this->assertSame('accept', $board[0]['action']);
        $this->assertSame('Finished', $board[0]['at']);
        $this->assertSame('Green Co', $board[0]['company']);
        $this->assertSame('12 Oak St', $board[0]['address']);
        $this->assertCount(1, $board[0]['photos']);
        $flat = json_encode($board);
        $this->assertStringNotContainsString('999', $flat);
        $this->assertStringNotContainsString('604-555-0100', $flat);
        $this->assertStringNotContainsString('token', $flat);
    }

    public function testADeclinedJobLeavesTheBoardOnceCleared(): void
    {
        $sent = $this->send();
        $this->svc->act($this->fresh($sent['token']), 'decline');
        $this->assertSame('dismiss', $this->svc->board()[0]['action']);
        $this->assertFalse($this->svc->dismiss($this->send(11)['job_id'], 1)['ok'], 'a live job is not cleared');
        $this->assertTrue($this->svc->dismiss($sent['job_id'], 1)['ok']);
        $this->assertNotContains($sent['job_id'], array_column($this->svc->board(), 'job_id'));
    }

    public function testLocationIsOnlyKeptAtStartAndFinish(): void
    {
        $sent = $this->send();
        $this->svc->act($this->fresh($sent['token']), 'accept', ['lat' => 49.1, 'lng' => -123.1]);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM subcontract_events WHERE latitude IS NOT NULL")->fetchColumn());
    }

    public function testATapAlreadyMadeDoesNothing(): void
    {
        $sent = $this->send();
        $stale = $this->fresh($sent['token']);
        $this->assertTrue($this->svc->act($stale, 'accept')['ok']);
        $second = $this->svc->act($stale, 'decline');
        $this->assertFalse($second['ok']);
        $this->assertSame('accepted', $this->fresh($sent['token'])['work_status']);
    }

    public function testADifferentDayMovesTheVisitAndARefusedMoveChangesNothing(): void
    {
        $sent = $this->send();
        $this->svc->act($this->fresh($sent['token']), 'accept');
        $this->assertFalse($this->svc->act($this->fresh($sent['token']), 'pick_day', ['date' => 'tomorrow'])['ok']);
        $this->assertFalse($this->svc->act($this->fresh($sent['token']), 'pick_day', ['date' => '2026-11-09'])['ok']);

        $this->moveWorks = false;
        $this->assertFalse($this->svc->act($this->fresh($sent['token']), 'pick_day', ['date' => '2026-11-16'])['ok']);
        $this->assertSame('accepted', $this->fresh($sent['token'])['work_status']);

        $this->moveWorks = true;
        $this->assertTrue($this->svc->act($this->fresh($sent['token']), 'pick_day', ['date' => '2026-11-16'])['ok']);
        $job = $this->fresh($sent['token']);
        $this->assertSame('scheduled', $job['work_status']);
        $this->assertSame('2026-11-16', $job['planned_date']);
    }

    public function testAFailedCompletionLeavesTheJobWaiting(): void
    {
        $sent = $this->send();
        foreach (['accept', 'start', 'finish'] as $a) $this->svc->act($this->fresh($sent['token']), $a);
        $this->completeWorks = false;
        $this->assertFalse($this->svc->acceptWork($sent['job_id'], 1)['ok']);
        $this->assertNull($this->fresh($sent['token'])['work_accepted_at']);
    }

    public function testFinishCanBeTakenBackThenNotAfterAcceptance(): void
    {
        $sent = $this->send();
        foreach (['accept', 'start', 'finish'] as $a) $this->svc->act($this->fresh($sent['token']), $a);
        $this->assertTrue($this->svc->act($this->fresh($sent['token']), 'undo')['ok']);
        $job = $this->fresh($sent['token']);
        $this->assertSame('started', $job['work_status']);
        $this->assertNull($job['finished_at']);

        $this->svc->act($job, 'finish');
        $this->svc->acceptWork($sent['job_id'], 1);
        $this->assertFalse($this->svc->act($this->fresh($sent['token']), 'undo')['ok']);
    }

    public function testNewLinkKillsTheOldOne(): void
    {
        $sent = $this->send();
        $new = $this->svc->newLink($sent['job_id'], 1);
        $this->assertNull($this->svc->resolve($sent['token']));
        $this->assertSame($sent['job_id'], (int)$this->fresh($new)['id']);
    }

    public function testPageCarriesTheWorkAndNotThePrice(): void
    {
        $sent = $this->send();
        $page = $this->svc->page($this->fresh($sent['token']));
        $this->assertSame('12 Oak St, Vancouver', $page['address']);
        $this->assertSame('Pat Lee', $page['client_name']);
        $this->assertSame(['Rake beds', 'haul'], $page['work_items']);
        $this->assertSame(250.0, $page['agreed_amount']);
    }

    public function testACompanyIsBookedAgainstOneVendorAndCanBeRefreshed(): void
    {
        $this->db->exec("INSERT INTO vendors (name) VALUES ('GREEN CO')");
        $first = $this->svc->saveCompany(['company_name' => 'Green Co'], 1);
        $second = $this->svc->saveCompany(['company_name' => 'Green Co', 'email' => 'new@example.com'], 1);
        $this->assertSame($first, $second);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM vendors")->fetchColumn());
        $this->assertSame('new@example.com', $this->svc->listCompanies()[0]['email']);

        $this->expectException(InvalidArgumentException::class);
        $this->svc->saveCompany(['company_name' => 'X', 'email' => 'nope'], 1);
    }
}
