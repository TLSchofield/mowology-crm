<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Receipt facts — what is printed on a receipt (migration 1227).
 * Fixture: expense #63, City of Vancouver Landfill, $50, 2026-03-25 (Ticket #: 43176009,
 * Time In 12:22 PM / Time Out 12:39 PM); #119 / #228 are the same vendor/date/total, never OCR'd.
 */
class ReceiptFactsServiceTest extends TestCase
{
    public const LANDFILL_63 = "CITY OF VANCOUVER\nVANCOUVER LANDFILL\n5400 72 ST DELTA BC\nTicket #: 43176009\nDate: 03/25/2026\n"
        . "Time In: 12:22 PM\nTime Out: 12:39 PM\nVehicle: PF8865\nGross 4.20 t Tare 3.80 t Net 0.40 t\nTotal: \$50.00\nPayment: VISA ending 1234";

    public const CHEVRON = "CHEVRON #207055\n4598 MAIN ST\nVANCOUVER BC\n2026-10-07 14:32:10\nPUMP 04 REGULAR\n45.211 L @ 1.789/L\n"
        . "FUEL TOTAL \$80.88\nVISA ************4821\nAUTH # 093417\nINVOICE # 0098812\nTERMINAL ID: 77104452\nTHANK YOU";

    public const HOME_DEPOT = "THE HOME DEPOT\n#7054 MARINE WAY\nBURNABY, BC\n7054 00045 63210 10/07/26 10:18 AM\nCASHIER: *****8388 SAZIYA\n"
        . "2X4X8 SPF 4 @ 5.28 21.12\nSUBTOTAL 45.12\nGST 2.26\nTOTAL \$47.38\nXXXXXXXXXXXX5512 MASTERCARD\nAUTH CODE 012345/1234567\n"
        . "STR 7054 TRM 45 TRN 63210";

    public const LAWNBOY_SLIP = "LAWN BOY LANDSCAPE SUPPLY\nInvoice No 88120\nOct 7, 2026\n2 yd black mulch\nTotal 128.10\nCash";

    // ── Parser fixtures ─────────────────────────────────────────────────

    public function test_landfill_scale_ticket_63(): void
    {
        $f = ReceiptFactsService::parse(self::LANDFILL_63, '2026-03-25');
        $this->assertSame('2026-03-25', $f['printed_date']);
        $this->assertSame('12:22', $f['time_first']);
        $this->assertSame('12:39', $f['time_last']);
        $this->assertSame('43176009', $f['doc_number']);
        $this->assertSame('ticket', $f['doc_kind']);
        $this->assertSame('1234', $f['card_last4']);
        $this->assertSame('visa', $f['card_brand']);
        $this->assertSame('12:22–12:39 · ticket 43176009 · ••1234', ReceiptFactsService::line($f));
    }

    public function test_chevron_till_receipt_time_and_card(): void
    {
        $f = ReceiptFactsService::parse(self::CHEVRON, '2026-10-07');
        $this->assertSame('2026-10-07', $f['printed_date']);
        $this->assertSame('14:32', $f['time_first']);
        $this->assertNull($f['time_last'], 'a till receipt has one time');
        $this->assertSame('4821', $f['card_last4']);
        $this->assertSame('visa', $f['card_brand']);
        $this->assertSame('0098812', $f['doc_number'], 'the invoice number beats the auth code');
        $this->assertSame('invoice', $f['doc_kind']);
        $this->assertSame('77104452', $f['terminal']);
        $this->assertSame('207055', $f['store_number']);
    }

    public function test_home_depot_transaction_store_terminal(): void
    {
        $f = ReceiptFactsService::parse(self::HOME_DEPOT, '2026-10-07');
        $this->assertSame('2026-10-07', $f['printed_date'], 'MM/DD vs DD/MM settled by the expense date');
        $this->assertSame('10:18', $f['time_first']);
        $this->assertSame('63210', $f['doc_number']);
        $this->assertSame('transaction', $f['doc_kind']);
        $this->assertSame('5512', $f['card_last4'], 'the cashier line is masked too — skipped');
        $this->assertSame('mastercard', $f['card_brand']);
        $this->assertSame('7054', $f['store_number']);
        $this->assertSame('45', $f['terminal']);
    }

    public function test_lawnboy_handwritten_slip_has_no_time(): void
    {
        $f = ReceiptFactsService::parse(self::LAWNBOY_SLIP, '2026-10-07');
        $this->assertSame('2026-10-07', $f['printed_date']);
        $this->assertNull($f['time_first']);
        $this->assertNull($f['time_last']);
        $this->assertSame('88120', $f['doc_number']);
        $this->assertSame('invoice', $f['doc_kind']);
        $this->assertNull($f['card_last4']);
        $this->assertSame('invoice 88120', ReceiptFactsService::line($f));
    }

    public function test_empty_text_and_stored_formats(): void
    {
        $this->assertNull(ReceiptFactsService::parse('')['doc_number']);
        $this->assertSame('', ReceiptFactsService::line(null));
        $this->assertSame('abc', ReceiptFactsService::ocrText(json_encode(['text' => 'abc', 'parsed' => []])));
        $this->assertSame('xyz', ReceiptFactsService::ocrText(json_encode(['responses' => [['fullTextAnnotation' => ['text' => 'xyz']]]])));
        $this->assertNull(ReceiptFactsService::pickDate(['2026-10-07', '2026-07-10'], null), 'ambiguous without the expense date');
    }

    public function test_a_date_is_not_an_invoice_number(): void
    {
        $f = ReceiptFactsService::parse("ACME\nInvoice: 10/07/2026\nTotal 5.00");
        $this->assertNull($f['doc_number']);
    }

    // ── Duplicate rules ─────────────────────────────────────────────────

    private function facts(array $f): array
    {
        return $f + ['printed_date' => null, 'time_first' => null, 'time_last' => null, 'doc_number' => null, 'doc_kind' => null];
    }

    public function test_same_doc_number_is_a_duplicate(): void
    {
        $v = ReceiptFactsService::duplicateVerdict(
            $this->facts(['doc_number' => '43176009', 'doc_kind' => 'ticket']),
            $this->facts(['doc_number' => '43176009', 'doc_kind' => 'ticket', 'time_first' => '12:25'])
        );
        $this->assertSame('same', $v['verdict']);
        $this->assertSame('same ticket 43176009', $v['why']);
    }

    public function test_different_times_or_numbers_are_not_duplicates(): void
    {
        $this->assertSame('different', ReceiptFactsService::duplicateVerdict(
            $this->facts(['time_first' => '12:22', 'time_last' => '12:39']),
            $this->facts(['time_first' => '15:05', 'time_last' => '15:20'])
        )['verdict']);
        $this->assertSame('different', ReceiptFactsService::duplicateVerdict(
            $this->facts(['doc_number' => '43176009']), $this->facts(['doc_number' => '43180122'])
        )['verdict']);
        // One misread digit is not proof of two tickets — and the same printed time decides nothing.
        $this->assertSame('unknown', ReceiptFactsService::duplicateVerdict(
            $this->facts(['doc_number' => '43176009', 'time_first' => '12:22']), $this->facts(['doc_number' => '43176008', 'time_first' => '12:22'])
        )['verdict']);
    }

    public function test_unknown_facts_leave_the_old_rule_alone(): void
    {
        $this->assertSame('unknown', ReceiptFactsService::duplicateVerdict(null, $this->facts(['doc_number' => '43176009']))['verdict']);
        $this->assertSame('unknown', ReceiptFactsService::duplicateVerdict($this->facts([]), $this->facts(['time_first' => '12:22']))['verdict']);

        $mine = [['id' => 119, 'receipt_media_id' => 5], ['id' => 228, 'receipt_media_id' => 6]];
        $cands = [119 => [['id' => 63, 'status' => 'approved'], ['id' => 228, 'status' => 'draft']], 228 => [['id' => 63, 'status' => 'approved']]];
        $out = ReceiptFactsService::sortCandidates($mine, $cands, []);
        $this->assertSame($cands, $out['candidates'], 'no facts → candidates unchanged');
        $this->assertSame([], $out['dismiss']);
    }

    public function test_sort_candidates_dismisses_different_and_labels_same(): void
    {
        $mine = [['id' => 119, 'receipt_media_id' => 5]];
        $cands = [119 => [['id' => 63, 'status' => 'approved'], ['id' => 228, 'status' => 'draft'], ['id' => 300, 'status' => 'draft']]];
        $facts = [
            119 => $this->facts(['doc_number' => '43176009', 'doc_kind' => 'ticket', 'time_first' => '12:22']),
            63  => $this->facts(['doc_number' => '43176009', 'doc_kind' => 'ticket', 'time_first' => '12:22']),
            228 => $this->facts(['doc_number' => '43180122', 'doc_kind' => 'ticket', 'time_first' => '15:05']),
        ];
        $out = ReceiptFactsService::sortCandidates($mine, $cands, $facts);
        $this->assertSame([63, 300], array_map(fn($c) => $c['id'], $out['candidates'][119]), '#228 dropped; #300 unknown stays');
        $this->assertSame([[119, 228, 'different ticket/time: ticket 43176009 vs 43180122']], $out['dismiss']);
        $this->assertSame(['63-119' => 'same ticket 43176009'], $out['why']);
    }

    public function test_same_photo_is_never_dismissed_by_facts(): void
    {
        $mine = [['id' => 408, 'receipt_media_id' => 1073]];
        $cands = [408 => [['id' => 410, 'status' => 'draft', 'receipt_media_id' => 1073]]];
        $facts = [408 => $this->facts(['time_first' => '09:00']), 410 => $this->facts(['time_first' => '11:00'])];
        $out = ReceiptFactsService::sortCandidates($mine, $cands, $facts);
        $this->assertCount(1, $out['candidates'][408]);
        $this->assertSame([], $out['dismiss']);
    }

    public function test_groups_carry_the_why_label(): void
    {
        $r = fn($id) => ['id' => $id, 'status' => 'draft'];
        $g = DuplicateReceiptService::groups([['a' => $r(63), 'b' => $r(119), 'why' => 'same ticket 43176009'], ['a' => $r(119), 'b' => $r(228)]]);
        $this->assertSame(['same ticket 43176009'], $g[0]['why']);
    }

    // ── Bank signal ─────────────────────────────────────────────────────

    public function test_bank_signal_card_and_printed_date(): void
    {
        $f = $this->facts(['card_last4' => '4821', 'printed_date' => '2026-10-07', 'time_first' => '14:32']);
        $s = ReceiptFactsService::bankSignal($f, '2026-10-07', 'CHEVRON 207055 VANCOUVER', '4520000000004821', '2026-10-08');
        $this->assertSame(25, $s['bonus']);
        $this->assertContains('Paid with card ••4821 — this account', $s['reasons']);
        $this->assertSame(0, ReceiptFactsService::bankSignal($f, '2026-10-07', 'CHEVRON', '000123', '2026-10-07')['bonus'],
            'another account and the date already agreed — nothing extra');
        $this->assertSame(10, ReceiptFactsService::bankSignal($f, '2026-10-07', 'CHEVRON 14:35', null, '2026-10-07')['bonus']);
        $this->assertSame(0, ReceiptFactsService::bankSignal(null, '2026-10-07', 'x', null)['bonus']);
    }

    // ── Backfill selection (SQLite) ─────────────────────────────────────

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, status TEXT, raw_ocr_json TEXT, receipt_media_id INT, total REAL,
                   expense_date TEXT, vendor_name_raw TEXT, vendor_id INT, created_by INT)");
        $db->exec("CREATE TABLE media_assets (id INTEGER PRIMARY KEY, mime_type TEXT, file_path TEXT)");
        $db->exec("CREATE TABLE expense_ocr_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, media_id INT, expense_id INT, user_id INT,
                   status TEXT DEFAULT 'pending', parsed_vendor TEXT, parsed_total REAL, parsed_date TEXT, parsed_raw_text TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE receipt_facts (expense_id INTEGER PRIMARY KEY, printed_date TEXT, time_first TEXT, time_last TEXT,
                   doc_number TEXT, doc_kind TEXT, card_last4 TEXT, card_brand TEXT, terminal TEXT, store_number TEXT, parsed_at TEXT, source TEXT)");
        $ins = $db->prepare("INSERT INTO expenses VALUES (?,?,?,?,?,?,?,?,?)");
        $ins->execute([63, 'approved', self::LANDFILL_63, 1, 50.00, '2026-03-25', 'City of Vancouver Landfill', 12, 6]);
        $ins->execute([119, 'draft', null, 2, 50.00, '2026-03-25', 'City of Vancouver Landfill', 12, 6]);
        $ins->execute([228, 'approved', '', 3, 50.00, '2026-03-25', 'City of Vancouver Landfill', 12, 6]);
        $ins->execute([300, 'draft', null, null, 0, '2026-03-26', '', null, 6]);          // no photo
        $ins->execute([301, 'rejected', null, 4, 50.00, '2026-03-25', 'x', null, 6]);     // rejected
        $ins->execute([302, 'draft', null, 5, 0, null, '', null, 6]);                       // a PDF
        $ins->execute([303, 'draft', null, 6, 0, null, '', null, 6]);                       // empty, photo, waiting
        $m = $db->prepare("INSERT INTO media_assets VALUES (?,?,?)");
        foreach ([[1, 'image/jpeg'], [2, 'image/jpeg'], [3, 'image/JPEG'], [4, 'image/jpeg'], [5, 'application/pdf'], [6, 'image/png']] as [$id, $mime]) {
            $m->execute([$id, $mime, '/uploads/receipts/' . $id . '.jpg']);
        }
        return $db;
    }

    public function test_backfill_parses_text_and_requeues_unread_photos_once(): void
    {
        $db = $this->db();
        $svc = new ReceiptFactsService($db);
        $this->assertTrue($svc->ready());
        $this->assertSame([63], $svc->unparsedIds(40));

        $res = $svc->backfill(40, 10);
        $this->assertSame(1, $res['parsed']);
        $this->assertSame('43176009', $svc->forExpense(63)['doc_number']);
        $this->assertSame([303, 228, 119], $res['queued'], 'empty OCR + an image → queued; no photo / rejected / PDF skipped');
        $this->assertSame(3, $svc->queuedToday());

        $again = $svc->backfill(40, 10);
        $this->assertSame(0, $again['parsed']);
        $this->assertSame([], $again['queued'], 'already queued — never twice');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $db = $this->db();
        $res = (new ReceiptFactsService($db))->backfill(40, 10, true);
        $this->assertSame(1, $res['parsed']);
        $this->assertCount(3, $res['queued']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM receipt_facts")->fetchColumn());
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM expense_ocr_jobs")->fetchColumn());
    }

    public function test_finished_ocr_never_overwrites_an_approved_receipt(): void
    {
        $db = $this->db();
        $svc = new ReceiptFactsService($db);
        $svc->backfill(40, 10);
        // The worker finished all three: it read a different total/vendor than what was approved.
        $db->exec("UPDATE expense_ocr_jobs SET status = 'complete', parsed_total = 99.99, parsed_vendor = 'MISREAD',
                   parsed_date = '2026-01-01', parsed_raw_text = 'CITY OF VANCOUVER\nTicket #: 43180122\nTime In: 3:05 PM\nTime Out: 3:20 PM'");
        $adopted = $svc->adoptFinishedJobs();
        sort($adopted);
        $this->assertSame([119, 228, 303], $adopted);

        $e228 = $db->query("SELECT * FROM expenses WHERE id = 228")->fetch();
        $this->assertSame(50.0, (float)$e228['total'], 'approved: total untouched');
        $this->assertSame('City of Vancouver Landfill', $e228['vendor_name_raw'], 'approved: vendor untouched');
        $this->assertSame('2026-03-25', $e228['expense_date']);
        $this->assertStringContainsString('43180122', $e228['raw_ocr_json'], 'the text itself is kept');
        $this->assertSame('15:05', $svc->forExpense(228)['time_first']);

        $e119 = $db->query("SELECT * FROM expenses WHERE id = 119")->fetch();
        $this->assertSame(50.0, (float)$e119['total'], 'draft: a set total is not replaced');
        $this->assertSame('City of Vancouver Landfill', $e119['vendor_name_raw']);

        $e303 = $db->query("SELECT * FROM expenses WHERE id = 303")->fetch();
        $this->assertSame(99.99, (float)$e303['total'], 'draft with an empty total: OCR fills it');
        $this->assertSame('MISREAD', $e303['vendor_name_raw']);
        $this->assertSame('2026-01-01', $e303['expense_date']);

        $this->assertSame([], $svc->adoptFinishedJobs(), 'adopted once');
    }

    public function test_fill_from_ocr_rules(): void
    {
        $job = ['parsed_raw_text' => 'TEXT', 'parsed_total' => 12.5, 'parsed_date' => '2026-10-07', 'parsed_vendor' => 'ACME'];
        $this->assertSame(['raw_ocr_json' => 'TEXT'], ReceiptFactsService::fillFromOcr(['status' => 'forwarded', 'total' => 0], $job));
        $this->assertSame([], ReceiptFactsService::fillFromOcr(['status' => 'draft', 'raw_ocr_json' => 'already'], $job), 'has text already');
        $this->assertSame([], ReceiptFactsService::fillFromOcr(['status' => 'draft'], ['parsed_raw_text' => '  ']));
    }

    public function test_stop_evidence_uses_stored_facts_first(): void
    {
        $row = ['id' => 63, 'vendor' => 'City of Vancouver Landfill', 'total' => 50, 'raw_ocr_json' => null,
                'rf_time_first' => '12:22', 'rf_time_last' => '12:39', 'rf_printed_date' => '2026-03-25'];
        $f = StopEvidenceService::receiptFacts($row, '2026-03-25');
        $this->assertSame(strtotime('2026-03-25 12:22:00'), $f['in']);
        $this->assertSame(strtotime('2026-03-25 12:39:00'), $f['out']);
        $f2 = StopEvidenceService::receiptFacts($row, '2026-03-26');
        $this->assertNull($f2['in'], 'a printed date that is not this day: no times');
    }
}
