<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The nightly ledger sync runs once more on MySQL 1615 (shared host's table cache; 9 failures Sep 4 → Oct 13). */
class ReprepareRetryTest extends TestCase
{
    public function testKnowsThe1615Error(): void
    {
        $msg = 'SQLSTATE[HY000]: General error: 1615 Prepared statement needs to be re-prepared';
        $this->assertTrue(AccountingService::isReprepareError($msg));
        $this->assertTrue(AccountingService::isReprepareError(new RuntimeException($msg)));
        $pdo = new PDOException($msg);
        $pdo->errorInfo = ['HY000', 1615, 'Prepared statement needs to be re-prepared'];
        $this->assertTrue(AccountingService::isReprepareError($pdo));
        $this->assertTrue(AccountingService::isReprepareError('Journal sync failed: ' . $msg), 'the journal half reports it as text');
        $this->assertFalse(AccountingService::isReprepareError('SQLSTATE[42S22]: Column not found: 1054 Unknown column'));
        $this->assertFalse(AccountingService::isReprepareError('Invoice #1615 could not be posted'));
    }
}
