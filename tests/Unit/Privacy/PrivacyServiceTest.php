<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PrivacyServiceTest extends TestCase
{
    private function makeStmt(
        mixed $fetchReturn    = false,
        array $fetchAllReturn = [],
        mixed $fetchColReturn = 0,
        int   $rowCount       = 0
    ): PDOStatement {
        $s = $this->createMock(PDOStatement::class);
        $s->method('execute')->willReturn(true);
        $s->method('fetch')->willReturn($fetchReturn);
        $s->method('fetchAll')->willReturn($fetchAllReturn);
        $s->method('fetchColumn')->willReturn($fetchColReturn);
        $s->method('rowCount')->willReturn($rowCount);
        return $s;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // generateDataExport
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function generate_data_export_returns_null_contact_when_not_found(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));

        $svc    = new PrivacyService($db);
        $result = $svc->generateDataExport(999);

        $this->assertNull($result['contact']);
        $this->assertSame(999, $result['contact_id']);
        $this->assertArrayHasKey('generated_at', $result);
    }

    /** @test */
    public function generate_data_export_returns_expected_keys(): void
    {
        $contact = [
            'id' => 1, 'first_name' => 'Jane', 'last_name' => 'Doe',
            'email' => 'jane@example.com', 'phone' => '', 'mobile' => '',
            'receive_sms' => 0, 'receive_marketing' => 0,
            'consent_sms' => 0, 'consent_marketing_email' => 0,
            'consent_timestamp' => null, 'consent_source' => null,
            'consent_ip_address' => null,
            'stripe_customer_id' => null, 'stripe_card_brand' => null,
            'stripe_card_last4' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];

        $db = $this->createMock(PDO::class);
        // All queries return empty / base contact
        $db->method('prepare')->willReturn($this->makeStmt($contact, [], 0));

        $svc    = new PrivacyService($db);
        $result = $svc->generateDataExport(1);

        $expectedKeys = [
            'generated_at', 'contact_id', 'contact', 'properties', 'quotes',
            'invoices', 'job_plans', 'consent_log', 'stripe_payments',
            'visit_photos', 'privacy_requests',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame('Jane', $result['contact']['first_name']);
    }

    /** @test */
    public function generate_data_export_returns_empty_arrays_when_no_related_data(): void
    {
        $contact = ['id' => 2, 'first_name' => 'Bob', 'last_name' => 'Smith',
                    'email' => 'b@c.com', 'phone' => '', 'mobile' => '',
                    'receive_sms' => 0, 'receive_marketing' => 0,
                    'consent_sms' => 0, 'consent_marketing_email' => 0,
                    'consent_timestamp' => null, 'consent_source' => null,
                    'consent_ip_address' => null,
                    'stripe_customer_id' => null, 'stripe_card_brand' => null,
                    'stripe_card_last4' => null,
                    'created_at' => '2026-01-01', 'updated_at' => '2026-01-01'];

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt($contact, []));

        $svc    = new PrivacyService($db);
        $result = $svc->generateDataExport(2);

        $this->assertSame([], $result['properties']);
        $this->assertSame([], $result['quotes']);
        $this->assertSame([], $result['consent_log']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // anonymizeContact
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function anonymize_contact_throws_when_not_found(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));

        $svc = new PrivacyService($db);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $svc->anonymizeContact(999, 1);
    }

    /** @test */
    public function anonymize_contact_throws_when_already_anonymized(): void
    {
        $contact = ['id' => 1, 'first_name' => '[Anonymized]', 'email' => 'x@deleted.invalid'];

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt($contact));

        $svc = new PrivacyService($db);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already anonymised/');
        $svc->anonymizeContact(1, 1);
    }

    /** @test */
    public function anonymize_contact_commits_transaction_for_valid_contact(): void
    {
        $contact  = ['id' => 5, 'first_name' => 'Jane', 'email' => 'jane@example.com'];
        $photoStmt = $this->makeStmt(false, []); // no photos

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn(
            $this->makeStmt($contact, []) // re-used for all subsequent calls
        );
        $db->expects($this->once())->method('beginTransaction')->willReturn(true);
        $db->expects($this->once())->method('commit')->willReturn(true);
        $db->expects($this->never())->method('rollBack');

        $svc = new PrivacyService($db);
        $svc->anonymizeContact(5, 1, 'Test erasure');
        $this->addToAssertionCount(1);
    }

    /** @test */
    public function anonymize_contact_rolls_back_on_db_error(): void
    {
        $contact = ['id' => 5, 'first_name' => 'Jane', 'email' => 'jane@example.com'];

        $contactStmt = $this->makeStmt($contact, []);
        $photoStmt   = $this->makeStmt(false, []);
        $errorStmt   = $this->createMock(PDOStatement::class);
        $errorStmt->method('execute')->willThrowException(new PDOException('DB error'));

        $db = $this->createMock(PDO::class);
        $db->expects($this->once())->method('beginTransaction')->willReturn(true);
        $db->expects($this->once())->method('rollBack')->willReturn(true);
        $db->expects($this->never())->method('commit');
        $db->method('prepare')
           ->willReturnOnConsecutiveCalls($contactStmt, $photoStmt, $errorStmt);

        $svc = new PrivacyService($db);
        $this->expectException(PDOException::class);
        $svc->anonymizeContact(5, 1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // purgeExpiredLocationData
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function purge_expired_location_data_returns_expected_keys(): void
    {
        $settingsRows = [
            ['data_type' => 'crew_location_history', 'retention_days' => 90,  'description' => '', 'updated_at' => null],
            ['data_type' => 'visit_gps_points',      'retention_days' => 180, 'description' => '', 'updated_at' => null],
            ['data_type' => 'visit_audit_log',        'retention_days' => 730, 'description' => '', 'updated_at' => null],
        ];

        $settingsStmt = $this->makeStmt(false, $settingsRows, 0, 0);
        $deleteStmt   = $this->makeStmt(false, [], 0, 5); // rowCount = 5

        $db = $this->createMock(PDO::class);
        $db->method('prepare')
           ->willReturnOnConsecutiveCalls(
               $settingsStmt,  // getRetentionSettings
               $deleteStmt,    // DELETE crew_location_history
               $deleteStmt,    // DELETE visit_gps_points
               $deleteStmt     // DELETE visit_audit_log
           );

        $svc    = new PrivacyService($db);
        $result = $svc->purgeExpiredLocationData();

        $this->assertArrayHasKey('crew_location_deleted',  $result);
        $this->assertArrayHasKey('visit_gps_deleted',      $result);
        $this->assertArrayHasKey('audit_log_deleted',      $result);
        $this->assertArrayHasKey('purged_at',              $result);
        $this->assertArrayHasKey('retention_days',         $result);
        $this->assertSame(90,  $result['retention_days']['crew_location_history']);
        $this->assertSame(180, $result['retention_days']['visit_gps_points']);
    }

    /** @test */
    public function purge_returns_zero_deleted_when_no_old_rows(): void
    {
        $settingsRows = [
            ['data_type' => 'crew_location_history', 'retention_days' => 90,  'description' => '', 'updated_at' => null],
            ['data_type' => 'visit_gps_points',      'retention_days' => 180, 'description' => '', 'updated_at' => null],
            ['data_type' => 'visit_audit_log',        'retention_days' => 730, 'description' => '', 'updated_at' => null],
        ];

        $settingsStmt = $this->makeStmt(false, $settingsRows);
        $deleteStmt   = $this->makeStmt(false, [], 0, 0); // rowCount = 0

        $db = $this->createMock(PDO::class);
        $db->method('prepare')
           ->willReturnOnConsecutiveCalls($settingsStmt, $deleteStmt, $deleteStmt, $deleteStmt);

        $svc    = new PrivacyService($db);
        $result = $svc->purgeExpiredLocationData();

        $this->assertSame(0, $result['crew_location_deleted']);
        $this->assertSame(0, $result['visit_gps_deleted']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // updateRetentionSetting
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function update_retention_setting_throws_for_invalid_days(): void
    {
        $db  = $this->createMock(PDO::class);
        $svc = new PrivacyService($db);

        $this->expectException(InvalidArgumentException::class);
        $svc->updateRetentionSetting('crew_location_history', 0, 1);
    }

    /** @test */
    public function update_retention_setting_throws_for_excessive_days(): void
    {
        $db  = $this->createMock(PDO::class);
        $svc = new PrivacyService($db);

        $this->expectException(InvalidArgumentException::class);
        $svc->updateRetentionSetting('crew_location_history', 9999, 1);
    }

    /** @test */
    public function update_retention_setting_executes_update_for_valid_days(): void
    {
        $stmt = $this->makeStmt();
        $db   = $this->createMock(PDO::class);
        $db->expects($this->once())->method('prepare')->willReturn($stmt);

        $svc = new PrivacyService($db);
        $svc->updateRetentionSetting('crew_location_history', 60, 1);
        $this->addToAssertionCount(1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // createRequest + updateRequestStatus
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function create_request_returns_inserted_id(): void
    {
        $stmt = $this->makeStmt();
        $db   = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('42');

        $svc = new PrivacyService($db);
        $id  = $svc->createRequest('access', 'Jane Doe', 'jane@example.com');
        $this->assertSame(42, $id);
    }

    /** @test */
    public function update_request_status_throws_for_invalid_status(): void
    {
        $db  = $this->createMock(PDO::class);
        $svc = new PrivacyService($db);

        $this->expectException(InvalidArgumentException::class);
        $svc->updateRequestStatus(1, 'invalid_status', 1);
    }
}
