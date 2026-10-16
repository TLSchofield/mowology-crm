<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APP_ROOT . '/Modules/Schedule/Services/AppointmentService.php';

/** Quote visits booked from an enquiry, shown on the schedule beside the job stops (Anita, Fri 8:15 — 2026-10-15). */
class AppointmentServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE appointments (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL DEFAULT 'quote_visit',
            quote_request_id INT, contact_id INT, property_id INT, appt_date TEXT NOT NULL, appt_time TEXT NOT NULL,
            duration_minutes INT NOT NULL DEFAULT 45, assigned_to INT, status TEXT NOT NULL DEFAULT 'booked', notes TEXT,
            confirmed_at TEXT, created_by INT, created_at TEXT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE quote_requests (id INTEGER PRIMARY KEY, contact_id INT, property_id INT)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)");
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, phone TEXT, email TEXT)");
        $this->db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, latitude REAL, longitude REAL)");
        $this->db->exec("INSERT INTO quote_requests VALUES (72, 1542, 1522), (73, 9, 9)");
        $this->db->exec("INSERT INTO users VALUES (1, 'Tim Schofield'), (5, 'Nigel')");
        $this->db->exec("INSERT INTO contacts VALUES (1542, 'Anita', 'Venier', '604-615-8077', 'anita@example.com')");
        $this->db->exec("INSERT INTO properties VALUES (1522, '2- 3036 West 14th Avenue', 'Vancouver', 49.26, -123.17), (9, 'Somewhere', '', 0, 0)");
    }

    public function testWhenIsCleaned(): void
    {
        $this->assertSame(['2026-10-16', '08:15:00'], AppointmentService::cleanWhen('2026-10-16', '8:15'));
        $this->assertSame(['2026-10-16', '14:05:00'], AppointmentService::cleanWhen('2026-10-16', '14:05:00'));
        $this->assertNull(AppointmentService::cleanWhen('2026-02-31', '8:15'), 'not a real day');
        $this->assertNull(AppointmentService::cleanWhen('2026-10-16', '25:00'));
        $this->assertNull(AppointmentService::cleanWhen('', ''));
        $this->assertSame('Fri Oct 16, 8:15 am', AppointmentService::label('2026-10-16', '08:15:00'));
    }

    public function testBookThenBookAgainMovesIt(): void
    {
        $svc = new AppointmentService($this->db);
        $r = $svc->bookForRequest(72, ['date' => '2026-10-16', 'time' => '8:15'], 1);
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['moved']);
        $a = $svc->forRequest(72);
        $this->assertSame('08:15:00', $a['appt_time']);
        $this->assertSame(1542, (int)$a['contact_id'], 'client and property come from the request');
        $this->assertSame(1, (int)$a['assigned_to'], 'the person booking goes, unless someone else is picked');

        $svc->markConfirmed($r['id']);
        $m = $svc->bookForRequest(72, ['date' => '2026-10-17', 'time' => '9:00', 'assigned_to' => 5], 1);
        $this->assertTrue($m['moved']);
        $this->assertSame($r['id'], $m['id'], 'one booked visit per request');
        $a = $svc->forRequest(72);
        $this->assertSame('2026-10-17', $a['appt_date']);
        $this->assertNull($a['confirmed_at'], 'a moved visit needs confirming again');
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM appointments')->fetchColumn());

        $svc->bookForRequest(72, ['date' => '2026-10-17', 'time' => '9:00', 'notes' => 'Gate code 1234'], 1);
        $svc->bookForRequest(72, ['date' => '2026-10-17', 'time' => '9:00'], 1);
        $this->assertSame('Gate code 1234', $svc->forRequest(72)['notes'], 'no note sent: kept');
        $svc->bookForRequest(72, ['date' => '2026-10-17', 'time' => '9:00', 'notes' => ''], 1);
        $this->assertNull($svc->forRequest(72)['notes'], 'an emptied note clears the old one (Codex)');

        $this->assertFalse($svc->bookForRequest(999, ['date' => '2026-10-16', 'time' => '8:15'], 1)['ok']);
        $this->assertFalse($svc->bookForRequest(72, ['date' => 'tomorrow', 'time' => '8:15'], 1)['ok']);
    }

    public function testScheduleShowsBookedOnlyAndCrewSeeTheirOwn(): void
    {
        $svc = new AppointmentService($this->db);
        $a = $svc->bookForRequest(72, ['date' => '2026-10-16', 'time' => '8:15'], 1)['id'];
        $b = $svc->bookForRequest(73, ['date' => '2026-10-16', 'time' => '7:00', 'assigned_to' => 5], 1)['id'];

        $day = $svc->forRange('2026-10-16', '2026-10-16')['2026-10-16'];
        $this->assertSame([$b, $a], array_column($day, 'appointment_id'), 'in time order');
        $anita = $day[1];
        $this->assertSame('Anita Venier', $anita['contact_name']);
        $this->assertSame('8:15 am', $anita['time_label']);
        $this->assertSame('/crm/quote-workflow.php?request_id=72', $anita['open_url']);
        $this->assertNull($day[0]['latitude'], '0,0 is no location');

        $this->assertSame([$b], array_column($svc->forRange('2026-10-16', '2026-10-16', 5)['2026-10-16'], 'appointment_id'));
        $this->assertSame([], $svc->forRange('2026-10-16', '2026-10-16', 7));

        $this->assertTrue($svc->setStatus($a, 'cancelled'));
        $this->assertFalse($svc->setStatus($a, 'cancelled'), 'no change');
        $this->assertFalse($svc->setStatus($a, 'gone'));
        $this->assertSame([$b], array_column($svc->forRange('2026-10-16', '2026-10-16')['2026-10-16'], 'appointment_id'));
        $this->assertNull($svc->forRequest(72), 'a cancelled visit no longer shows on the request');
        $this->assertFalse($svc->setStatus($a, 'done'), 'a cancelled visit stays cancelled (Codex)');
        $this->assertTrue($svc->setStatus($b, 'done'));
        $this->assertFalse($svc->setStatus($b, 'cancelled'), 'a done visit stays done');
        $this->assertFalse($svc->setStatus($b, 'booked'), 'never back to booked by status');
    }

    public function testConfirmationTextHasNoLinksAndFits(): void
    {
        $svc = new AppointmentService($this->db);
        $id = $svc->bookForRequest(72, ['date' => '2026-10-16', 'time' => '8:15'], 1)['id'];
        $a = $svc->find($id);
        $sms = AppointmentService::confirmationSms($a, 'Mowology Inc.');
        $this->assertLessThanOrEqual(160, strlen($sms));
        $this->assertStringContainsString('Fri Oct 16 at 8:15 am', $sms);
        $this->assertStringContainsString('(778) 846-9273', $sms);
        $this->assertDoesNotMatchRegularExpression('/\w\.\w/', $sms, 'nothing a gateway could read as a domain');

        $long = $a; $long['contact_name'] = str_repeat('Bartholomew', 8);
        $this->assertLessThanOrEqual(160, strlen(AppointmentService::confirmationSms($long, str_repeat('Landscaping', 5))));

        $dot = $a; $dot['contact_name'] = 'example.com Smith';
        $this->assertDoesNotMatchRegularExpression('/\w\.\w/', AppointmentService::confirmationSms($dot, 'Mowology Inc.'), 'a name is never a link (Codex)');
        $this->assertStringContainsString('Hi examplecom,', AppointmentService::confirmationSms($dot));
        $uni = $a; $uni['contact_name'] = 'Zoë';
        $this->assertStringContainsString('Hi Zoë,', AppointmentService::confirmationSms($uni));

        $mail = AppointmentService::confirmationEmail($a);
        $this->assertSame('Quote visit confirmed: Fri Oct 16, 8:15 am', $mail['subject']);
        $this->assertStringContainsString('Friday, October 16 at 8:15 am', $mail['html']);
        $this->assertStringContainsString('2- 3036 West 14th Avenue, Vancouver', $mail['html']);
    }
}
