<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for OptinResendService — the "Oops" opt-in resend.
 *
 * Focus: the safety-critical pure logic — the send window gate, the
 * fixed confirmation URL (must NOT regress to the broken /crm/api path),
 * the Oops email content, and fresh-token issuance.
 */
class OptinResendServiceTest extends TestCase
{
    private function svc(?PDO $db = null): OptinResendService
    {
        return new OptinResendService($db ?? $this->createMock(PDO::class));
    }

    // ── Send window ───────────────────────────────────────────────────────

    public function testWindowOpenAt10AmAndClosedAt2Pm(): void
    {
        $s = $this->svc();
        $this->assertFalse($s->isWithinSendWindow(new DateTimeImmutable('2026-05-16 09:59:00')));
        $this->assertTrue($s->isWithinSendWindow(new DateTimeImmutable('2026-05-16 10:00:00')));
        $this->assertTrue($s->isWithinSendWindow(new DateTimeImmutable('2026-05-16 13:59:00')));
        $this->assertFalse($s->isWithinSendWindow(new DateTimeImmutable('2026-05-16 14:00:00')));
        $this->assertFalse($s->isWithinSendWindow(new DateTimeImmutable('2026-05-16 23:00:00')));
    }

    public function testNextWindowOpenIsTodayWhenBeforeTenElseTomorrow(): void
    {
        $s = $this->svc();

        $before = new DateTimeImmutable('2026-05-16 08:00:00');
        $this->assertSame('2026-05-16 10:00:00', $s->nextWindowOpen($before)->format('Y-m-d H:i:s'));

        $after = new DateTimeImmutable('2026-05-16 15:00:00');
        $this->assertSame('2026-05-17 10:00:00', $s->nextWindowOpen($after)->format('Y-m-d H:i:s'));
    }

    // ── Confirm URL — the bug this whole effort exists to fix ─────────────

    public function testConfirmUrlUsesCanonicalHandlerNotBrokenShim(): void
    {
        $url = $this->svc()->confirmUrl('abc123');
        $this->assertStringContainsString('/optin-confirm.php?token=abc123', $url);
        $this->assertStringNotContainsString('/crm/api/optin-confirm.php', $url);
    }

    public function testConfirmUrlUrlEncodesToken(): void
    {
        $url = $this->svc()->confirmUrl('a b/c+d');
        $this->assertStringContainsString('token=a+b%2Fc%2Bd', $url);
    }

    // ── Oops email content ────────────────────────────────────────────────

    public function testOopsEmailContainsOopsAndWorkingLink(): void
    {
        $html = $this->svc()->buildOopsEmail('Jane', 'https://mowology.ca/optin-confirm.php?token=xyz');
        $this->assertStringContainsStringIgnoringCase('Oops', $html);
        $this->assertStringContainsString('https://mowology.ca/optin-confirm.php?token=xyz', $html);
        $this->assertStringNotContainsString('/crm/api/optin-confirm.php', $html);
        $this->assertStringContainsString('Jane', $html);
    }

    public function testOopsEmailFallsBackToFriendlyNameWhenBlank(): void
    {
        $html = $this->svc()->buildOopsEmail('', 'https://mowology.ca/optin-confirm.php?token=x');
        $this->assertStringContainsString('Hi there', $html);
    }

    public function testOopsEmailEscapesName(): void
    {
        $html = $this->svc()->buildOopsEmail('<script>x</script>', 'https://mowology.ca/optin-confirm.php?token=x');
        $this->assertStringNotContainsString('<script>x</script>', $html);
    }

    // ── Fresh token issuance ──────────────────────────────────────────────

    public function testIssueFreshTokenInsertsWhenNoPendingRowExists(): void
    {
        $select = $this->createMock(PDOStatement::class);
        $select->method('execute')->willReturn(true);
        $select->method('fetchColumn')->willReturn(false); // no existing pending row

        $boundParams = null;
        $insert = $this->createMock(PDOStatement::class);
        $insert->method('execute')->willReturnCallback(
            function (array $p) use (&$boundParams): bool { $boundParams = $p; return true; }
        );

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($select, $insert): PDOStatement {
                return stripos($sql, 'INSERT INTO marketing_optin_tokens') !== false ? $insert : $select;
            }
        );

        $token = $this->svc($db)->issueFreshToken(42, 'a@b.com');
        $this->assertSame(64, strlen($token), 'returned token is the RAW 32-byte hex token');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        // Stored value must be the sha256 HASH of the raw token, never the raw.
        $this->assertNotNull($boundParams);
        $this->assertSame(hash('sha256', $token), $boundParams[2], 'token column stores sha256(raw)');
        $this->assertNotSame($token, $boundParams[2], 'raw token must NOT be stored at rest');
    }

    public function testIssueFreshTokenUpdatesExistingPendingRow(): void
    {
        $select = $this->createMock(PDOStatement::class);
        $select->method('execute')->willReturn(true);
        $select->method('fetchColumn')->willReturn('777'); // existing pending row id

        $boundParams = null;
        $update = $this->createMock(PDOStatement::class);
        $update->method('execute')->willReturnCallback(
            function (array $p) use (&$boundParams): bool { $boundParams = $p; return true; }
        );

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($select, $update): PDOStatement {
                return stripos($sql, 'UPDATE marketing_optin_tokens') !== false ? $update : $select;
            }
        );

        $token = $this->svc($db)->issueFreshToken(42, 'a@b.com');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        // UPDATE branch must also persist the hash, not the raw token.
        $this->assertNotNull($boundParams);
        $this->assertSame(hash('sha256', $token), $boundParams[0], 'token column stores sha256(raw)');
        $this->assertNotSame($token, $boundParams[0], 'raw token must NOT be stored at rest');
    }

    // ── RFC 8058 one-click unsubscribe headers ────────────────────────────

    public function testListUnsubscribeHeadersAreWellFormed(): void
    {
        if (!function_exists('listUnsubscribeHeaders')) {
            $this->markTestSkipped('TemplateRenderer not loaded');
        }
        $url = 'https://mowology.ca/unsubscribe.php?email=a%40b.com&token=abc&sid=7';
        $h = listUnsubscribeHeaders($url);

        $this->assertArrayHasKey('List-Unsubscribe', $h);
        $this->assertArrayHasKey('List-Unsubscribe-Post', $h);
        $this->assertSame('List-Unsubscribe=One-Click', $h['List-Unsubscribe-Post']);
        $this->assertStringContainsString('<' . $url . '>', $h['List-Unsubscribe']);
        $this->assertStringContainsString('<mailto:', $h['List-Unsubscribe']);
        // No CRLF — header-injection safety once passed through sender.
        $this->assertStringNotContainsString("\n", $h['List-Unsubscribe']);
        $this->assertStringNotContainsString("\r", $h['List-Unsubscribe']);
    }

    // ── Hard-bounce suppression (must NOT mis-suppress valid customers) ───

    public function testHardBounceSuppressesOnPermanentFailureOnly(): void
    {
        if (!function_exists('suppressIfHardBounce')) {
            $this->markTestSkipped('TemplateRenderer not loaded');
        }

        // Permanent → suppress (one INSERT into marketing_unsubscribes).
        $ins = $this->createMock(PDOStatement::class);
        $ins->expects($this->once())->method('execute')->with(['bad@x.com'])->willReturn(true);
        $dbP = $this->createMock(PDO::class);
        $dbP->expects($this->once())->method('prepare')
            ->with($this->stringContains('INSERT IGNORE INTO marketing_unsubscribes'))
            ->willReturn($ins);
        $this->assertTrue(suppressIfHardBounce($dbP, 'bad@x.com', '550 5.1.1 <bad@x.com>: Recipient address rejected: User unknown'));

        // Transient → do NOT suppress, never touch the DB.
        $dbT = $this->createMock(PDO::class);
        $dbT->expects($this->never())->method('prepare');
        $this->assertFalse(suppressIfHardBounce($dbT, 'maybe@x.com', '451 4.7.1 Greylisted, please try again later'));
        $this->assertFalse(suppressIfHardBounce($dbT, 'maybe@x.com', 'Connection timed out'));

        // Invalid email → never suppress.
        $dbI = $this->createMock(PDO::class);
        $dbI->expects($this->never())->method('prepare');
        $this->assertFalse(suppressIfHardBounce($dbI, 'not-an-email', '550 user unknown'));
    }

    // ── Mid-campaign suppression (CASL-critical) ──────────────────────────

    public function testProcessBatchSuppressesUnsubscribedRecipientAndDoesNotSend(): void
    {
        $pendingSel = $this->createMock(PDOStatement::class);
        $pendingSel->method('execute')->willReturn(true);
        $pendingSel->method('fetchAll')->willReturn([
            ['id' => 1, 'contact_id' => 5, 'email' => 'gone@x.com', 'first_name' => 'A'],
        ]);

        $supSel = $this->createMock(PDOStatement::class);
        $supSel->method('execute')->willReturn(true);
        $supSel->method('fetchAll')->willReturn(['gone@x.com']); // on do-not-contact list

        $unsubUpd = $this->createMock(PDOStatement::class);
        $unsubUpd->expects($this->once())->method('execute')->willReturn(true);

        $generic = $this->createMock(PDOStatement::class);
        $generic->method('execute')->willReturn(true);
        $generic->method('fetchColumn')->willReturn(0);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($pendingSel, $supSel, $unsubUpd, $generic): PDOStatement {
                if (stripos($sql, "FROM campaign_sends cs") !== false)            return $pendingSel;
                if (stripos($sql, "FROM marketing_unsubscribes") !== false)        return $supSel;
                if (stripos($sql, "status='unsubscribed'") !== false)              return $unsubUpd;
                return $generic;
            }
        );
        $countStmt = $this->createMock(PDOStatement::class);
        $countStmt->method('fetchColumn')->willReturn(0);
        $db->method('query')->willReturn($countStmt);

        $sent = 0;
        $result = $this->svc($db)->processBatch(99, 25, function () use (&$sent): array {
            $sent++; // must never be called for a suppressed recipient
            return ['success' => true];
        });

        $this->assertSame(0, $sent, 'sender must NOT be invoked for a suppressed recipient');
        $this->assertSame(1, $result['suppressed']);
        $this->assertSame(0, $result['sent']);
    }
}
