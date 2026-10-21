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
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, full_name TEXT, preferred_name TEXT, role TEXT)");
        $db->exec("CREATE TABLE business_identity (id INTEGER PRIMARY KEY, legal_name TEXT, operating_name TEXT, registration_number TEXT)");
        $db->exec("CREATE TABLE qbo_connections (site_id INT, status TEXT, refresh_token_enc TEXT)");
        $db->exec("CREATE TABLE qbo_onboarding_parts (site_id INT, part TEXT)");
        $db->exec("INSERT INTO users (id, first_name, role) VALUES (1, NULL, 'admin')");
        return $db;
    }

    private function svc(PDO $db): OnboardingService
    {
        return new OnboardingService(new OnboardingContext($db, 1));
    }

    public function test_the_default_path(): void
    {
        $this->assertSame(['name', 'company', 'quickbooks', 'books', 'accountant', 'done'], $this->svc($this->db())->order());
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
        $db->exec("INSERT INTO business_identity VALUES (1, 'Mowology Lawns & Landscapes Ltd', 'Mowology', 'BC1234567')");
        $s = $this->svc($db);
        $map = array_column($s->map(), null, 'key');
        $this->assertSame('done', $map['name']['state']);
        $this->assertSame('Penny calls you Tim.', $map['name']['summary']);
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
}
