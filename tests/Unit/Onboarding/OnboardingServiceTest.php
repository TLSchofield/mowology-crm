<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Onboarding as modules (owner, 2026-10-20): the order lives in settings and modules move up or down,
 * never before what they need; Done stays last; skipped steps count as finished; each module's state
 * comes from real data.
 */
class OnboardingServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, full_name TEXT, preferred_name TEXT, role TEXT, phone TEXT, contact_channel TEXT, quiet_start TEXT, quiet_end TEXT, quiet_weekends INT DEFAULT 0)");
        $db->exec("CREATE TABLE business_identity (id INTEGER PRIMARY KEY, legal_name TEXT, operating_name TEXT, registration_number TEXT)");
        $db->exec("CREATE TABLE qbo_connections (site_id INT, status TEXT, refresh_token_enc TEXT)");
        $db->exec("CREATE TABLE qbo_onboarding_parts (site_id INT, part TEXT)");
        $db->exec("CREATE TABLE qbo_onboarding_snapshot (site_id INT, kind TEXT, qbo_id TEXT, data TEXT)");
        $db->exec("INSERT INTO users (id, first_name, role) VALUES (1, NULL, 'admin')");
        return $db;
    }

    /** The path as the first phone apps see it (the new steps held back): these tests are about the mechanics, not the new steps. */
    private function svc(PDO $db): OnboardingService
    {
        return new OnboardingService(new OnboardingContext($db, 1), null, []);
    }

    /** The web page: every step. */
    private function webSvc(PDO $db): OnboardingService
    {
        return new OnboardingService(new OnboardingContext($db, 1));
    }

    public function test_the_default_path(): void
    {
        $this->assertSame(['name', 'terms', 'company', 'quickbooks', 'books', 'gst', 'yearend', 'payroll', 'accountant', 'done'], $this->webSvc($this->db())->order());
        $this->assertSame(['name', 'company', 'quickbooks', 'books', 'accountant', 'done'], $this->svc($this->db())->order(), 'an old app never sees the new steps');
    }

    public function test_a_saved_old_order_gets_terms_right_after_name(): void
    {
        $all = ['name', 'terms', 'company', 'quickbooks', 'books', 'gst', 'yearend', 'payroll', 'accountant', 'done'];
        $this->assertSame($all, OnboardingService::normalise(['name', 'company', 'quickbooks', 'books', 'accountant', 'done'], $all, ['done']));
        $this->assertSame(['company', 'name', 'terms', 'quickbooks', 'books', 'gst', 'yearend', 'payroll', 'accountant', 'done'],
            OnboardingService::normalise(['company', 'name', 'quickbooks', 'books', 'accountant'], $all, ['done']), 'after wherever name sits');
    }

    public function test_the_new_steps_are_hidden_from_apps_that_do_not_know_them(): void
    {
        $db = $this->db();
        $ctx = new OnboardingContext($db, 1);
        $this->assertSame([], OnboardingService::knowsFrom(null), 'absent = an old app');
        $this->assertSame([], OnboardingService::knowsFrom(''));
        $this->assertSame(['terms', 'gst'], OnboardingService::knowsFrom(' Terms, gst ,,terms'));
        $this->assertSame(['gst'], OnboardingService::knowsFrom(['gst']));
        $old = new OnboardingService($ctx, null, OnboardingService::knowsFrom(null));
        $this->assertNull($old->module('terms'));
        $this->assertSame('name', $old->next());
        $some = new OnboardingService($ctx, null, ['terms']);
        $this->assertSame(['name', 'terms', 'company', 'quickbooks', 'books', 'accountant', 'done'], $some->order());
        $all = new OnboardingService($ctx, null, OnboardingService::knowsFrom('terms,gst,yearend,payroll'));
        $this->assertSame($this->webSvc($db)->order(), $all->order());
        // readyToFinish ignores the steps the app cannot show, and counts the ones it can.
        $name = $old->module('name');
        $name->save($ctx, 'Tim', 'S', 'Tim'); $name->saveReach($ctx, 'app', ''); $name->saveQuiet($ctx, '21:00', '06:30', true);
        $db->exec("INSERT INTO business_identity VALUES (1, 'X Ltd', 'X', NULL)");
        foreach (['quickbooks', 'books', 'accountant'] as $k) $old->skip($k, true);
        $this->assertTrue((new OnboardingService($ctx, null, []))->readyToFinish());
        $this->assertFalse($this->webSvc($db)->readyToFinish(), 'the web page still needs terms, GST, year-end and payroll');
        $this->assertFalse((new OnboardingService($ctx, null, ['terms']))->readyToFinish());
    }

    public function test_the_new_steps_save_their_answers_and_finish_by_them(): void
    {
        $db = $this->db();
        $db->exec("CREATE TABLE business_settings (id INTEGER PRIMARY KEY, gst_registration TEXT)");
        $db->exec("INSERT INTO business_settings VALUES (1, '')");
        $ctx = new OnboardingContext($db, 1);
        $terms = new OnboardingTermsModule(); $gst = new OnboardingGstModule(); $ye = new OnboardingYearEndModule(); $pay = new OnboardingPayrollModule();
        $this->assertSame('todo', $terms->state($ctx));
        $this->assertFalse($terms->skippable());
        $terms->accept($ctx);
        $this->assertSame('done', $terms->state(new OnboardingContext($db, 1)));

        $this->assertFalse($gst->save($ctx, 'maybe')['ok']);
        $this->assertFalse($gst->save($ctx, 'yes', '12345')['ok']);
        $this->assertFalse($gst->save($ctx, 'yes', '', 'weekly')['ok']);
        $this->assertTrue($gst->save($ctx, 'yes', '123 456 789 rt0001', 'quarterly')['ok']);
        $this->assertSame('123456789RT0001', $db->query('SELECT gst_registration FROM business_settings')->fetchColumn());
        $this->assertSame('Registered for GST · files quarterly.', $gst->summary(new OnboardingContext($db, 1)));
        $this->assertSame('123456789', OnboardingGstModule::gstNumber('123456789'));
        $this->assertTrue($gst->save($ctx, 'no')['ok']);
        $this->assertSame('Not registered for GST — no GST is charged or claimed.', $gst->summary($ctx));
        $this->assertSame('done', $gst->state($ctx));

        $this->assertFalse($ye->save($ctx, 'nobody')['ok']);
        $ye->save($ctx, 'self');
        $this->assertStringContainsString('reviews your books', $ye->question($ctx));
        $this->assertSame('Year-end: you.', $ye->summary($ctx));
        $ye->save($ctx, 'accountant');
        $this->assertSame('Year-end: your accountant.', $ye->summary($ctx));

        $this->assertFalse($pay->save($ctx, 'robots')['ok']);
        $this->assertFalse($pay->save($ctx, 'app', str_repeat('x', 61))['ok']);
        $this->assertTrue($pay->save($ctx, 'app', "  <b>Wave</b>  ")['ok']);
        $this->assertSame('Payroll: Wave.', $pay->summary($ctx));
        $pay->save($ctx, 'none');
        $this->assertSame('', (string)$ctx->setting('onboarding_payroll_app'));
        $this->assertSame('Payroll: no staff on payroll.', $pay->summary($ctx));
        foreach ([$gst, $ye, $pay] as $m) $this->assertTrue($m->skippable());
        $this->assertSame([], $gst->needs());
    }

    /** app/ cannot read public/, so the version lives twice; this fails the day they drift. */
    public function test_the_terms_version_matches_the_live_text(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/public/includes/legal/ittai-legal.php');
        $this->assertSame(1, preg_match("/define\('TERMS_VERSION', '([^']+)'\)/", $src, $m));
        $this->assertSame($m[1], OnboardingTermsModule::TERMS_VERSION);
    }

    public function test_a_saved_order_is_made_whole(): void
    {
        $all = ['name', 'company', 'quickbooks', 'books', 'accountant', 'done'];
        $this->assertSame(['company', 'name', 'quickbooks', 'books', 'accountant', 'done'],
            OnboardingService::normalise(['company', 'name', 'quickbooks', 'books', 'accountant'], $all, ['done']));
        $this->assertSame(['company', 'name', 'quickbooks', 'books', 'accountant', 'done'],
            OnboardingService::normalise(['company', 'gone', 'name', 'name', 'done', 'quickbooks', 'books', 'accountant'], $all, ['done']), 'unknown and repeated keys dropped, done stays last');
        $this->assertSame(['name', 'company', 'jobber', 'quickbooks', 'done'],
            OnboardingService::normalise(['name', 'company', 'quickbooks'], ['name', 'company', 'jobber', 'quickbooks', 'done'], ['done']), 'a new module lands in its default place');
    }

    public function test_moving_up_and_down(): void
    {
        $db = $this->db();
        $s = $this->svc($db);
        $this->assertTrue($s->move('company', -1)['ok']);
        $this->assertSame(['company', 'name', 'quickbooks', 'books', 'accountant', 'done'], $s->order());
        $this->assertTrue($s->move('quickbooks', -1)['ok'], 'QuickBooks moves as one module');
        $this->assertSame(['company', 'quickbooks', 'name', 'books', 'accountant', 'done'], $this->svc($db)->order(), 'kept in settings');
    }

    public function test_a_step_never_comes_before_what_it_needs_and_done_stays_last(): void
    {
        $s = $this->svc($this->db());
        $r = $s->move('books', -1);
        $this->assertFalse($r['ok']);
        $this->assertSame('"Your books" needs "QuickBooks" first.', $r['message']);
        $this->assertFalse($s->move('done', -1)['ok']);
        $this->assertFalse($s->move('accountant', 1)['ok'], 'nothing goes after Done');
        $this->assertFalse($s->move('name', -1)['ok'], 'already first');
    }

    public function test_states_come_from_real_data(): void
    {
        $db = $this->db();
        $s = $this->svc($db);
        $map = array_column($s->map(), 'state', 'key');
        $this->assertSame('next', $map['name']);
        $this->assertSame('name', $s->next());

        $name = $s->module('name');
        $this->assertFalse($name->save($s->context(), '', '', '')['ok']);
        $this->assertSame('Nice to meet you, Tim.', $name->save($s->context(), 'Timothy', 'Schofield', 'Tim')['message']);
        $this->assertSame('next', array_column($this->svc($db)->map(), 'state', 'key')['name'], 'a name alone is one side of three');
        $name->saveReach($s->context(), 'email', '');
        $name->saveQuiet($s->context(), '20:00', '07:00', false);
        $db->exec("INSERT INTO business_identity VALUES (1, 'Mowology Lawns & Landscapes Ltd', 'Mowology', 'BC1234567')");
        $s = $this->svc($db);
        $map = array_column($s->map(), null, 'key');
        $this->assertSame('done', $map['name']['state']);
        $this->assertSame('Penny calls you Tim · by email · quiet 8 pm–7 am.', $map['name']['summary']);
        $this->assertSame('Mowology · BC1234567', $map['company']['summary']);
        $this->assertSame('quickbooks', $s->next());
    }

    public function test_no_quickbooks_brings_in_your_books_and_yes_hides_it(): void
    {
        $db = $this->db();
        $s = $this->svc($db);
        $qb = $s->module('quickbooks');
        $qb->setAnswer($s->context(), false);
        $map = array_column($this->svc($db)->map(), 'state', 'key');
        $this->assertSame('not_needed', $map['quickbooks']);
        $this->assertContains($map['books'], ['next', 'todo']);

        $qb->setAnswer($s->context(), true);
        $map = array_column($this->svc($db)->map(), 'state', 'key');
        $this->assertSame('not_needed', $map['books']);
        $this->assertSame('Uses QuickBooks — not connected yet.', array_column($this->svc($db)->map(), 'summary', 'key')['quickbooks']);
    }

    /** Claudia walk 1 (2026-11-03): "all five accepted" made Continue loop. Connected + read = done. */
    public function test_quickbooks_is_done_once_connected_and_read_without_accepting_parts(): void
    {
        $db = $this->db();
        $s = $this->svc($db);
        $s->module('quickbooks')->setAnswer($s->context(), true);
        $db->exec("INSERT INTO qbo_connections VALUES (1, 'connected', 'enc')");
        $this->assertNotSame('done', array_column($this->svc($db)->map(), 'state', 'key')['quickbooks'], 'connected but not read yet');
        $db->exec("INSERT INTO qbo_onboarding_snapshot VALUES (1, 'customer', '1', '{}')");
        $map = array_column($this->svc($db)->map(), 'state', 'key');
        $this->assertSame('done', $map['quickbooks']);
        $this->assertNotSame('quickbooks', $this->svc($db)->next(), 'Continue moves on');
    }

    public function test_skipping_quickbooks_unanswered_does_not_trap_your_books(): void
    {
        $db = $this->db();
        $s = $this->svc($db);
        $s->skip('quickbooks', true);
        $books = $s->module('books');
        $books->acknowledge($s->context());
        $this->assertSame('done', array_column($this->svc($db)->map(), 'state', 'key')['books'], 'Got it finishes it — it used to loop');
    }

    public function test_finish_waits_for_the_steps_before_it(): void
    {
        $db = $this->db();
        $this->assertFalse($this->svc($db)->readyToFinish());
        $s = $this->svc($db);
        $s->module('name')->save($s->context(), 'Tim', 'S', 'Tim');
        $s->module('name')->saveReach($s->context(), 'app', '');
        $s->module('name')->saveQuiet($s->context(), '21:00', '06:30', true);
        $db->exec("INSERT INTO business_identity VALUES (1, 'X Ltd', 'X', NULL)");
        foreach (['quickbooks', 'books', 'accountant'] as $k) $s->skip($k, true);
        $this->assertTrue($this->svc($db)->readyToFinish());
    }

    public function test_skipping_and_progress(): void
    {
        $db = $this->db();
        $s = $this->svc($db);
        $this->assertFalse($s->skip('name', true)['ok'], 'your name is not skippable');
        $this->assertTrue($s->skip('accountant', true)['ok']);
        $this->assertSame('skipped', array_column($this->svc($db)->map(), 'state', 'key')['accountant']);
        $p = $this->svc($db)->progress();
        $this->assertSame(1, $p['done'], 'a skipped step counts as finished');
        $this->assertTrue($this->svc($db)->skip('accountant', false)['ok']);
        $this->assertSame([], $this->svc($db)->skipped());
    }

    public function test_an_old_app_skipping_a_step_keeps_a_skipped_new_step(): void
    {
        $db = $this->db();
        $this->assertTrue($this->webSvc($db)->skip('gst', true)['ok']);
        $this->assertTrue($this->svc($db)->skip('accountant', true)['ok'], 'an old phone skips a step it knows');
        $this->assertContains('gst', $this->webSvc($db)->skipped(), 'the web page still sees GST skipped');
        $this->assertContains('accountant', $this->webSvc($db)->skipped());
    }

    /** About you, one side at a time (owner, 2026-10-20): name to use · how to reach you · when not to bother you. */
    public function test_about_you_asks_the_next_missing_thing_and_checks_each_answer(): void
    {
        $db = $this->db();
        $s = $this->svc($db); $c = $s->context(); $m = $s->module('name');
        $this->assertSame([false, false, false], $m->known($c));
        $this->assertSame('name', $m->nextPart($c));
        $m->save($c, 'Ada', '', '');
        $this->assertSame([true, false, false], $m->known($c), 'blank "call you" = the first name');
        $this->assertSame('reach', $m->nextPart($c));

        $this->assertFalse($m->saveReach($c, 'pigeon', '')['ok']);
        $this->assertFalse($m->saveReach($c, 'text', '555-0101')['ok'], 'a text needs a full mobile number');
        $this->assertFalse($m->saveReach($c, 'text', 'abc6045550101xyz')['ok'], 'letters are not a phone number (Codex 2026-10-21)');
        $this->assertFalse($m->saveReach($c, 'text', '604555010112345')['ok'], 'too many digits');
        $this->assertTrue($m->saveReach($c, 'text', '+1 604.555.0101')['ok']);
        $this->assertSame('(604) 555-0101', $db->query('SELECT phone FROM users WHERE id = 1')->fetchColumn(), 'stored the one way');
        $db->exec("UPDATE users SET phone = '' WHERE id = 1");
        $this->assertSame([true, false, false], $m->known($c), 'text with no number is not known');
        $m->saveReach($c, 'text', '6045550101');
        $this->assertSame('quiet', $m->nextPart($c));

        $this->assertFalse($m->saveQuiet($c, '25:00', '07:00', false)['ok']);
        $this->assertFalse($m->saveQuiet($c, '07:00', '07:00', false)['ok'], 'from and until the same');
        $this->assertSame('Nothing from us between 10 pm and 6:30 am, or at weekends.', $m->saveQuiet($c, '22:00', '06:30', true)['message']);
        $this->assertSame([true, true, true], $m->known($c));
        $this->assertNull($m->nextPart($c));
    }
}
