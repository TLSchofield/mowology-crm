<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Tim's "not a lead" is remembered: twice for a sender, three times for an organisation (2026-10-15). */
class LeadLessonsTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE head_lessons (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT DEFAULT 1, head TEXT, subject_kind TEXT, subject_key TEXT,
                   field TEXT, said TEXT, correct TEXT, source TEXT, ref_type TEXT, ref_id INT, corrected_by INT, reason TEXT, created_at TEXT)");
        return $db;
    }

    private function no(PDO $db, string $kind, string $key, string $at): void
    {
        $db->prepare("INSERT INTO head_lessons (head, subject_kind, subject_key, field, said, correct, source, created_at) VALUES ('sam', ?, ?, 'is_lead', 'maybe', 'no', 't', ?)")
           ->execute([$kind, $key, $at]);
    }

    public function test_a_sender_twice_and_an_organisation_three_times(): void
    {
        $db = $this->db();
        $svc = new EmailLeadService($db);
        $this->no($db, 'sender', 'promo@seo-agency.com', date('Y-m-d H:i:s', time() - 86400));
        $this->assertFalse($svc->learnedNotLead('promo@seo-agency.com'), 'once is a hint');
        $this->no($db, 'sender', 'promo@seo-agency.com', date('Y-m-d H:i:s'));
        $this->assertTrue($svc->learnedNotLead('Promo <promo@seo-agency.com>'));

        $this->no($db, 'sender_org', 'spam.ca', date('Y-m-d H:i:s', time() - 7200));
        $this->no($db, 'sender_org', 'spam.ca', date('Y-m-d H:i:s', time() - 3600));
        $this->assertFalse($svc->learnedNotLead('new@spam.ca'), 'a whole organisation needs three');
        $this->no($db, 'sender_org', 'spam.ca', date('Y-m-d H:i:s'));
        $this->assertTrue($svc->learnedNotLead('new@spam.ca'));
        $this->assertFalse($svc->learnedNotLead('someone@gmail.com'), 'free mail has no organisation');
    }
}
