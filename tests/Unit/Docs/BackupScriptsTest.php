<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Security audit 2026-10-12 #6: backups must never leave a copy on the server, the file mirror
 * must never delete anything (either side), and restore must only ever touch a LOCAL MySQL.
 */
final class BackupScriptsTest extends TestCase
{
    private static function src(string $rel): string
    {
        $f = dirname(__DIR__, 3) . '/' . $rel;
        self::assertFileExists($f);
        return (string) file_get_contents($f);
    }

    public function testDbBackupStreamsAndWritesNothingOnTheServer(): void
    {
        $s = implode("\n", array_filter(explode("\n", self::src('scripts/prod-backup.sh')), fn($l) => !preg_match('/^\s*#/', $l)));
        $this->assertStringContainsString('deflate_init', $s, 'dump must be streamed in the response');
        $this->assertStringNotContainsString('gzopen', $s, 'no dump file on the server');
        $this->assertStringNotContainsString('Storage/backups', $s);
        $this->assertStringContainsString("HTTP_X_ONCE_TOKEN", $s, 'token travels in a header, not the URL');
        $this->assertStringContainsString('@unlink(__FILE__)', $s);
        $this->assertStringContainsString("'^-- end$'", $s, 'an incomplete dump is refused');
    }

    public function testFileMirrorOnlyDownloadsAndNeverDeletes(): void
    {
        $s = self::src('scripts/prod-backup-files.sh');
        $code = implode("\n", array_filter(explode("\n", $s), fn($l) => !preg_match('/^\s*#/', $l)));
        $this->assertStringContainsString('mirror ', $code);
        $lftp = implode("\n", preg_grep('/mirror |mw_lftp/', explode("\n", $code)));
        foreach (['--delete', '-R ', '--reverse', '--Remove-source', ' rm ', 'mrm '] as $flag) {
            $this->assertStringNotContainsString($flag, $lftp, "prod-backup-files.sh lftp commands must not use {$flag}");
        }
        $this->assertStringContainsString('chmod 700', $code);
    }

    public function testRestoreRefusesNonLocalHostsAndTheProductionDb(): void
    {
        $s = self::src('scripts/prod-restore.sh');
        $this->assertMatchesRegularExpression('/""\|localhost\|127\.0\.0\.1\|::1\) ;;/', $s);
        $this->assertStringContainsString('is not local', $s);
        $this->assertStringContainsString('mowology_landscape_crm', $s);
        $this->assertStringContainsString('--replace', $s);
    }

    public function testNightlyAgentRunsBothBackups(): void
    {
        $s = self::src('scripts/backup-nightly.sh');
        $this->assertStringContainsString('scripts/prod-backup.sh', $s);
        $this->assertStringContainsString('scripts/prod-backup-files.sh', $s);
        $i = self::src('scripts/install-backup-agent.sh');
        $this->assertStringContainsString('<key>Hour</key><integer>2</integer>', $i);
        $this->assertStringContainsString('<key>Minute</key><integer>30</integer>', $i);
        $this->assertStringContainsString('ca.mowology.backup', $i);
    }
}
