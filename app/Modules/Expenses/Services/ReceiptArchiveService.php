<?php
/**
 * ReceiptArchiveService — reclaim live web-disk space used by expense receipt images.
 *
 * Confirmed receipts (expenses.status IN 'approved','forwarded') are bundled into ZIP
 * file(s), emailed to the configured recipients (owner / accountant / bookkeeping inbox),
 * and — only once every configured recipient has received the bundle — the full-resolution
 * original is removed from /uploads/receipts/ and replaced by a small thumbnail. The DB
 * row, a manifest, and the audit batch are kept so the receipt is never silently lost.
 *
 * Why delete (not just "move")? On cPanel shared hosting app/Storage/ sits on the SAME
 * physical disk as public/uploads/, so moving a file there reclaims nothing. The genuine
 * space saving comes from deleting the full-res original and keeping only a ~thumbnail on
 * disk; the retained copy is the emailed ZIP (off-server, in the recipients' inboxes).
 * That is why purge is gated on a successful send to ALL configured recipients — we never
 * destroy the only copy before an off-server copy is confirmed delivered.
 *
 * Ordering mirrors PrivacyService: build the thumbnail first (needs the original), commit
 * the DB pointer change, THEN unlink the original. A failure after commit leaves
 * archived_at set with the original still present for later reconciliation — never the
 * reverse.
 *
 * Config lives in ops_settings (the same key-value home as the existing receipt-forwarding
 * config) under receipt_* keys — see loadSettings(). No business_settings columns needed.
 *
 * The pure decision/path helpers (isConfirmedStatus, archiveRelPath, planZipSplit,
 * recipientList) are static and DB-free so they are unit-tested without a database.
 */
class ReceiptArchiveService
{
    /** Expense statuses that count as a "confirmed" receipt eligible for archival. */
    public const CONFIRMED_STATUSES = ['approved', 'forwarded'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Pure, unit-testable helpers (no DB, no filesystem)
    // ──────────────────────────────────────────────────────────────────────

    /** Only approved/forwarded expenses are archivable. Drafts/cancelled are not. */
    public static function isConfirmedStatus(?string $status): bool
    {
        return in_array((string) $status, self::CONFIRMED_STATUSES, true);
    }

    /**
     * APP_ROOT-relative archive location for a receipt, partitioned by the expense month.
     * e.g. ('2026-01-15', 'receipt-x.jpg') => 'Storage/receipt-archive/2026/01/receipt-x.jpg'.
     */
    public static function archiveRelPath(string $expenseDate, string $storedFilename): string
    {
        $ts = strtotime($expenseDate) ?: time();
        return 'Storage/receipt-archive/' . date('Y/m', $ts) . '/' . basename($storedFilename);
    }

    /**
     * Greedily split items into chunks whose summed 'size' stays within $maxBytes.
     * A single item larger than the cap is emitted alone (never dropped). Pure.
     *
     * @param array $items each an array with an integer 'size' key
     * @return array<int,array> list of chunks (each a list of the original items)
     */
    public static function planZipSplit(array $items, int $maxBytes): array
    {
        if ($maxBytes <= 0) {
            return $items ? [array_values($items)] : [];
        }
        $chunks = [];
        $current = [];
        $running = 0;
        foreach ($items as $item) {
            $size = (int) ($item['size'] ?? 0);
            if ($current && ($running + $size) > $maxBytes) {
                $chunks[] = $current;
                $current = [];
                $running = 0;
            }
            $current[] = $item;
            $running += $size;
        }
        if ($current) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /**
     * Build the de-duplicated, validated recipient list from a settings array.
     * Reads owner + accountant + bookkeeping (the existing receipt_accounting_email).
     *
     * @return string[] valid, lower-cased, unique recipient addresses
     */
    public static function recipientList(array $settings): array
    {
        $candidates = [
            $settings['receipt_export_email_owner'] ?? '',
            $settings['receipt_export_email_accountant'] ?? '',
            $settings['receipt_accounting_email'] ?? '',
        ];
        $out = [];
        foreach ($candidates as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $out, true)) {
                $out[] = $email;
            }
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Settings (ops_settings, receipt_* keys)
    // ──────────────────────────────────────────────────────────────────────

    /** Load archival config with sane defaults. */
    public function loadSettings(): array
    {
        $defaults = [
            'receipt_archive_enabled'        => true,
            'receipt_archive_after_days'     => 90,
            'receipt_archive_keep_thumb'     => true,
            'receipt_archive_max_zip_mb'     => 25,
            'receipt_export_email_owner'     => '',
            'receipt_export_email_accountant'=> '',
            'receipt_accounting_email'       => '',
            'receipt_from_name'              => 'Mowology Receipts',
        ];
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key LIKE 'receipt_%'");
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $rows = [];
        }
        $boolish = static function ($v, bool $def): bool {
            if ($v === null) return $def;
            $v = strtolower(trim((string) $v));
            return !in_array($v, ['0', 'false', '', 'no', 'off'], true);
        };
        return [
            'receipt_archive_enabled'         => $boolish($rows['receipt_archive_enabled'] ?? null, true),
            'receipt_archive_after_days'      => max(0, (int) ($rows['receipt_archive_after_days'] ?? $defaults['receipt_archive_after_days'])),
            'receipt_archive_keep_thumb'      => $boolish($rows['receipt_archive_keep_thumb'] ?? null, true),
            'receipt_archive_max_zip_mb'      => max(1, (int) ($rows['receipt_archive_max_zip_mb'] ?? $defaults['receipt_archive_max_zip_mb'])),
            'receipt_export_email_owner'      => $rows['receipt_export_email_owner'] ?? '',
            'receipt_export_email_accountant' => $rows['receipt_export_email_accountant'] ?? '',
            'receipt_accounting_email'        => $rows['receipt_accounting_email'] ?? '',
            'receipt_from_name'               => $rows['receipt_from_name'] ?? $defaults['receipt_from_name'],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Selection
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Confirmed receipts with a media file, not yet archived, in [from,to], older than
     * $afterDays. Pass from=null/to=null for an open range. afterDays=0 disables the age gate.
     *
     * @return array<int,array> rows: expense_id, expense_date, vendor, total, media_id,
     *                          file_path, stored_filename, mime_type, file_size
     */
    public function selectArchivable(?string $from, ?string $to, int $afterDays): array
    {
        $sql = "
            SELECT e.id AS expense_id, e.expense_date,
                   COALESCE(v.name, e.vendor_name_raw, 'Unknown') AS vendor,
                   e.total,
                   m.id AS media_id, m.file_path, m.stored_filename, m.mime_type, m.file_size
            FROM expenses e
            JOIN media_assets m ON m.id = e.receipt_media_id
            LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE e.status IN ('approved','forwarded')
              AND m.archived_at IS NULL
              AND m.file_path IS NOT NULL AND m.file_path <> ''
        ";
        $params = [];
        if ($from !== null) { $sql .= " AND e.expense_date >= ? "; $params[] = $from; }
        if ($to !== null)   { $sql .= " AND e.expense_date <= ? "; $params[] = $to; }
        if ($afterDays > 0) {
            $sql .= " AND e.expense_date <= DATE_SUB(CURDATE(), INTERVAL ? DAY) ";
            $params[] = $afterDays;
        }
        $sql .= " ORDER BY e.expense_date ASC, e.id ASC ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ──────────────────────────────────────────────────────────────────────
    // ZIP build
    // ──────────────────────────────────────────────────────────────────────

    /** Absolute filesystem root of the (web-denied) archive working area. */
    private function archiveRoot(): string
    {
        return rtrim(APP_ROOT, '/') . '/Storage/receipt-archive';
    }

    /**
     * Build ZIP bundle(s) for the given rows, partitioned by expense month then by the
     * max-zip-size cap. Each ZIP carries a MANIFEST.csv and README.txt. Rows whose source
     * file is missing on disk are reported and excluded from both the ZIP and from purge.
     *
     * @return array{zips:array<int,string>, included:array<int,array>, missing:array<int,array>, total_bytes:int}
     */
    public function buildArchive(array $rows, int $batchId, int $maxZipMb): array
    {
        $tmpDir = $this->archiveRoot() . '/_tmp';
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Cannot create archive tmp dir: ' . $tmpDir);
        }

        // Resolve files + partition by month, dropping missing-on-disk rows.
        $byMonth = [];
        $missing = [];
        foreach ($rows as $r) {
            $abs = PUBLIC_ROOT . '/' . ltrim((string) $r['file_path'], '/');
            if (!is_file($abs)) {
                $missing[] = $r;
                continue;
            }
            $month = date('Y-m', strtotime($r['expense_date']) ?: time());
            $r['_abs']  = $abs;
            $r['size']  = (int) (@filesize($abs) ?: ($r['file_size'] ?? 0));
            $byMonth[$month][] = $r;
        }
        ksort($byMonth);

        $maxBytes   = $maxZipMb * 1024 * 1024;
        $zips       = [];
        $included   = [];
        $totalBytes = 0;

        foreach ($byMonth as $month => $monthRows) {
            $chunks = self::planZipSplit($monthRows, $maxBytes);
            foreach ($chunks as $partIdx => $chunk) {
                $partLabel = count($chunks) > 1 ? ('-part' . ($partIdx + 1)) : '';
                $zipName   = sprintf('receipts-%s%s-b%d-%s.zip', $month, $partLabel, $batchId, bin2hex(random_bytes(3)));
                $zipPath   = $tmpDir . '/' . $zipName;

                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('Failed to create ZIP: ' . $zipPath);
                }

                $manifest = "expense_id,date,vendor,total,filename\n";
                foreach ($chunk as $i => $r) {
                    $ext      = strtolower(pathinfo($r['stored_filename'] ?: $r['_abs'], PATHINFO_EXTENSION)) ?: 'jpg';
                    $entry    = sprintf('%03d_%s_%s.%s', $i + 1, self::slug($r['expense_date']), self::slug($r['vendor']), $ext);
                    $zip->addFile($r['_abs'], $entry);
                    $manifest .= sprintf(
                        "%d,%s,\"%s\",%s,%s\n",
                        (int) $r['expense_id'],
                        $r['expense_date'],
                        str_replace('"', "'", (string) $r['vendor']),
                        number_format((float) $r['total'], 2, '.', ''),
                        $entry
                    );
                    $included[]  = $r;
                    $totalBytes += (int) $r['size'];
                }
                $zip->addFromString('MANIFEST.csv', $manifest);
                $zip->addFromString('README.txt', self::readmeText($month, count($chunk)));
                $zip->close();

                $zips[] = $zipPath;
            }
        }

        return ['zips' => $zips, 'included' => $included, 'missing' => $missing, 'total_bytes' => $totalBytes];
    }

    /** README placed inside each ZIP — states what this is and the retention obligation. */
    private static function readmeText(string $month, int $count): string
    {
        return "Mowology — Expense Receipt Archive\n"
            . "Period: {$month}\n"
            . "Receipts in this archive: {$count}\n\n"
            . "This ZIP is the retained off-server copy of these expense receipts. The CRM keeps\n"
            . "a thumbnail and the structured data (vendor, date, total, tax) for each receipt; the\n"
            . "full-resolution image lives here. Keep this file for your records — Canadian tax rules\n"
            . "(CRA) require expense documentation to be retained for 6 years.\n\n"
            . "MANIFEST.csv lists every receipt: expense id, date, vendor, total, and the image filename.\n";
    }

    /** Filesystem-safe slug for filenames. */
    private static function slug(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9._-]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s !== '' ? substr($s, 0, 40) : 'x';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Email
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Email each ZIP to every recipient. Succeeds only if EVERY (recipient × zip) send
     * succeeds — that guarantee is what makes the subsequent purge safe.
     *
     * @return array{ok:bool, sent:int, failed:int, errors:array<int,string>}
     */
    public function emailArchive(array $zipPaths, array $recipients, array $meta): array
    {
        if (!$recipients || !$zipPaths) {
            return ['ok' => false, 'sent' => 0, 'failed' => 0, 'errors' => ['no recipients or no zips']];
        }
        if (!function_exists('sendEmail') && defined('CRM_INCLUDES') && is_file(CRM_INCLUDES . '/messaging.php')) {
            require_once CRM_INCLUDES . '/messaging.php';
        }
        if (!function_exists('sendEmail')) {
            return ['ok' => false, 'sent' => 0, 'failed' => 0, 'errors' => ['sendEmail() unavailable']];
        }

        $fromName = $meta['from_name'] ?? 'Mowology Receipts';
        $sent = 0; $failed = 0; $errors = [];
        $total = count($zipPaths);

        foreach ($zipPaths as $idx => $zipPath) {
            $partInfo = $total > 1 ? sprintf(' (part %d of %d)', $idx + 1, $total) : '';
            $subject  = sprintf('Mowology Receipt Archive — %s%s', $meta['range_label'] ?? 'export', $partInfo);
            $body     = $this->buildEmailBody($meta, basename($zipPath), $idx + 1, $total);
            foreach ($recipients as $to) {
                $res = sendEmail($to, $subject, $body, $zipPath, $fromName);
                if (!empty($res['success'])) {
                    $sent++;
                } else {
                    $failed++;
                    $errors[] = $to . ': ' . ($res['error'] ?? 'unknown');
                }
            }
        }
        return ['ok' => $failed === 0 && $sent > 0, 'sent' => $sent, 'failed' => $failed, 'errors' => $errors];
    }

    private function buildEmailBody(array $meta, string $zipName, int $part, int $total): string
    {
        $count = (int) ($meta['count'] ?? 0);
        $sum   = number_format((float) ($meta['total_amount'] ?? 0), 2);
        $range = htmlspecialchars((string) ($meta['range_label'] ?? 'export'));
        $partLine = $total > 1 ? "<p><strong>Part {$part} of {$total}</strong> — multiple ZIPs were sent for this period.</p>" : '';
        return "<h2 style=\"font-family:Arial,sans-serif;color:#1A5F4A\">Mowology Receipt Archive</h2>"
            . "<p style=\"font-family:Arial,sans-serif\">Attached: <strong>" . htmlspecialchars($zipName) . "</strong></p>"
            . $partLine
            . "<table style=\"font-family:Arial,sans-serif;border-collapse:collapse\">"
            . "<tr><td style=\"padding:4px 12px 4px 0\">Period</td><td><strong>{$range}</strong></td></tr>"
            . "<tr><td style=\"padding:4px 12px 4px 0\">Receipts</td><td><strong>{$count}</strong></td></tr>"
            . "<tr><td style=\"padding:4px 12px 4px 0\">Total</td><td><strong>\${$sum}</strong></td></tr>"
            . "</table>"
            . "<p style=\"font-family:Arial,sans-serif;font-size:13px;color:#555\">This is the retained copy of these "
            . "receipts. The CRM keeps a thumbnail and the structured data for each; the full-resolution images are in "
            . "the attached ZIP. Please keep this email for your records (CRA: 6-year retention). See MANIFEST.csv inside.</p>";
    }

    // ──────────────────────────────────────────────────────────────────────
    // Purge (only after a confirmed successful send)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * For each included row: (1) build a thumbnail from the original [needs the original],
     * (2) commit the media_assets pointer change, (3) AFTER commit unlink the full-res
     * original. If keep_thumb is on but the thumbnail can't be made, the row is skipped
     * (left viewable) rather than deleting an image we can no longer preview.
     *
     * @return array{archived:int, skipped:int, freed_bytes:int}
     */
    public function archiveAndPurge(array $rows, int $batchId, bool $keepThumb): array
    {
        $archived = 0; $skipped = 0; $freed = 0;

        foreach ($rows as $r) {
            $mediaId    = (int) $r['media_id'];
            $originalAbs = $r['_abs'] ?? (PUBLIC_ROOT . '/' . ltrim((string) $r['file_path'], '/'));
            if (!is_file($originalAbs)) { $skipped++; continue; }

            // 1) Thumbnail first (best-effort) — must exist before the DB points at it.
            $thumbWeb = null;
            if ($keepThumb) {
                $thumbWeb = $this->makeThumbnail($originalAbs, (string) $r['stored_filename'], (string) $r['mime_type']);
                if ($thumbWeb === null) {
                    // Could not preview-ize; do not delete the only viewable copy.
                    $skipped++;
                    continue;
                }
            }

            // 2) Commit the pointer change.
            try {
                $this->db->beginTransaction();
                $stmt = $this->db->prepare("
                    UPDATE media_assets
                    SET archived_at = NOW(), archive_path = ?, archive_batch_id = ?,
                        original_removed = 1, thumb_path = ?
                    WHERE id = ? AND archived_at IS NULL
                ");
                $stmt->execute([
                    self::archiveRelPath((string) $r['expense_date'], (string) $r['stored_filename']),
                    $batchId,
                    $thumbWeb,
                    $mediaId,
                ]);
                $this->db->commit();
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) { $this->db->rollBack(); }
                error_log('[ReceiptArchiveService] pointer update failed for media ' . $mediaId . ': ' . $e->getMessage());
                $skipped++;
                continue;
            }

            // 3) AFTER commit — remove the full-res original. Best-effort; never throw.
            $size = (int) (@filesize($originalAbs) ?: ($r['size'] ?? 0));
            if (@unlink($originalAbs)) {
                $freed += $size;
            } else {
                error_log('[ReceiptArchiveService] could not unlink archived original: ' . $originalAbs);
            }
            $archived++;
        }

        return ['archived' => $archived, 'skipped' => $skipped, 'freed_bytes' => $freed];
    }

    /**
     * Create a small JPEG thumbnail next to the original (suffix -thumb.jpg) and return its
     * web path, or null on failure. Imagick first (handles PDF page 1 + HEIC), GD fallback.
     */
    private function makeThumbnail(string $originalAbs, string $storedFilename, string $mime): ?string
    {
        $maxDim    = 500;
        $thumbName = preg_replace('/\.[^.]+$/', '', basename($storedFilename)) . '-thumb.jpg';
        $thumbAbs  = dirname($originalAbs) . '/' . $thumbName;
        $thumbWeb  = '/uploads/receipts/' . $thumbName;
        $isPdf     = (strtolower($mime) === 'application/pdf') || (strtolower(pathinfo($originalAbs, PATHINFO_EXTENSION)) === 'pdf');

        // Imagick path (preferred — handles PDF + HEIC + everything GD can't).
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick();
                if ($isPdf) {
                    $im->setResolution(120, 120);
                    $im->readImage($originalAbs . '[0]');
                    $im->setImageBackgroundColor('white');
                    $im = $im->flattenImages();
                } else {
                    $im->readImage($originalAbs);
                }
                $im->setImageFormat('jpeg');
                $im->thumbnailImage($maxDim, $maxDim, true);
                $im->setImageCompressionQuality(70);
                $im->writeImage($thumbAbs);
                $im->destroy();
                if (is_file($thumbAbs)) { return $thumbWeb; }
            } catch (\Throwable $e) {
                error_log('[ReceiptArchiveService] Imagick thumbnail failed: ' . $e->getMessage());
                // fall through to GD
            }
        }

        // GD fallback for common raster images.
        $info = @getimagesize($originalAbs);
        if (!$info) { return null; }
        [$w, $h] = $info;
        $src = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($originalAbs); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($originalAbs);  break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($originalAbs);  break;
            case IMAGETYPE_WEBP: if (function_exists('imagecreatefromwebp')) { $src = @imagecreatefromwebp($originalAbs); } break;
        }
        if (!$src || $w < 1 || $h < 1) { if ($src) { imagedestroy($src); } return null; }

        $scale = min($maxDim / $w, $maxDim / $h, 1);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = imagejpeg($dst, $thumbAbs, 70);
        imagedestroy($src);
        imagedestroy($dst);

        return ($ok && is_file($thumbAbs)) ? $thumbWeb : null;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Orchestrator (used by both the cron and the manual API)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Full archival run. from/to null => open range (cron). $afterDaysOverride lets the
     * manual endpoint pass 0 to allow recent receipts; null uses the configured value.
     *
     * @return array a structured summary (also suitable for cron_runs_log output)
     */
    public function run(?string $from, ?string $to, string $triggerType, ?int $userId, ?int $afterDaysOverride = null): array
    {
        $settings = $this->loadSettings();
        if (!$settings['receipt_archive_enabled']) {
            return ['status' => 'disabled', 'archived' => 0];
        }

        $afterDays  = $afterDaysOverride !== null ? max(0, $afterDaysOverride) : (int) $settings['receipt_archive_after_days'];
        $recipients = self::recipientList($settings);
        $rows       = $this->selectArchivable($from, $to, $afterDays);

        $rangeLabel = ($from || $to)
            ? trim(($from ?: '…') . ' to ' . ($to ?: 'today'))
            : 'all confirmed receipts older than ' . $afterDays . 'd';

        $batchId = $this->insertBatch($triggerType, $from, $to, $recipients, $userId);

        if (!$rows) {
            $this->updateBatch($batchId, ['receipt_count' => 0, 'email_status' => 'skipped', 'purge_status' => 'skipped']);
            return ['status' => 'nothing_to_archive', 'batch_id' => $batchId, 'archived' => 0];
        }

        // No recipients => no off-server retained copy => we must NOT delete originals.
        if (!$recipients) {
            $this->updateBatch($batchId, [
                'receipt_count' => count($rows),
                'email_status'  => 'skipped',
                'purge_status'  => 'skipped',
                'error'         => 'No export recipients configured; nothing purged.',
            ]);
            return ['status' => 'no_recipients', 'batch_id' => $batchId, 'eligible' => count($rows), 'archived' => 0];
        }

        $built = $this->buildArchive($rows, $batchId, (int) $settings['receipt_archive_max_zip_mb']);
        $cleanup = function () use ($built) {
            foreach ($built['zips'] as $z) { @unlink($z); }
        };

        if (!$built['zips']) {
            $cleanup();
            $this->updateBatch($batchId, [
                'receipt_count' => 0,
                'email_status'  => 'skipped',
                'purge_status'  => 'skipped',
                'error'         => 'No source files on disk (' . count($built['missing']) . ' missing).',
            ]);
            return ['status' => 'no_files', 'batch_id' => $batchId, 'missing' => count($built['missing']), 'archived' => 0];
        }

        $totalAmount = array_sum(array_map(static fn($r) => (float) $r['total'], $built['included']));
        $meta = [
            'range_label'  => $rangeLabel,
            'count'        => count($built['included']),
            'total_amount' => $totalAmount,
            'from_name'    => $settings['receipt_from_name'],
        ];

        $email = $this->emailArchive($built['zips'], $recipients, $meta);

        $result = [
            'status'      => 'archived',
            'batch_id'    => $batchId,
            'eligible'    => count($rows),
            'bundled'     => count($built['included']),
            'missing'     => count($built['missing']),
            'zips'        => count($built['zips']),
            'recipients'  => $recipients,
            'email'       => $email,
        ];

        if (!$email['ok']) {
            $cleanup();
            $this->updateBatch($batchId, [
                'receipt_count' => count($built['included']),
                'zip_count'     => count($built['zips']),
                'total_bytes'   => (int) $built['total_bytes'],
                'email_status'  => 'failed',
                'purge_status'  => 'skipped',
                'error'         => 'Email failed; originals kept. ' . implode('; ', array_slice($email['errors'], 0, 5)),
            ]);
            $result['status'] = 'email_failed';
            $result['archived'] = 0;
            return $result;
        }

        // Email confirmed delivered to all recipients → safe to free disk.
        $purge = $this->archiveAndPurge($built['included'], $batchId, (bool) $settings['receipt_archive_keep_thumb']);
        $cleanup();

        $this->updateBatch($batchId, [
            'receipt_count' => $purge['archived'],
            'zip_count'     => count($built['zips']),
            'total_bytes'   => (int) $purge['freed_bytes'],
            'email_status'  => 'sent',
            'purge_status'  => $purge['skipped'] > 0 ? 'partial' : 'done',
            'error'         => $purge['skipped'] > 0 ? ($purge['skipped'] . ' rows skipped (thumb/move).') : null,
        ]);

        $result['archived']    = $purge['archived'];
        $result['skipped']     = $purge['skipped'];
        $result['freed_bytes'] = $purge['freed_bytes'];
        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Batch audit rows
    // ──────────────────────────────────────────────────────────────────────

    private function insertBatch(string $triggerType, ?string $from, ?string $to, array $recipients, ?int $userId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO receipt_archive_batches (trigger_type, date_from, date_to, recipients, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            in_array($triggerType, ['cron', 'manual'], true) ? $triggerType : 'manual',
            $from,
            $to,
            $recipients ? substr(implode(', ', $recipients), 0, 1024) : null,
            $userId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function updateBatch(int $batchId, array $fields): void
    {
        if (!$fields) { return; }
        $cols = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            $cols[] = "$k = ?";
            $vals[] = $v;
        }
        $vals[] = $batchId;
        try {
            $this->db->prepare("UPDATE receipt_archive_batches SET " . implode(', ', $cols) . " WHERE id = ?")->execute($vals);
        } catch (Throwable $e) {
            error_log('[ReceiptArchiveService] updateBatch failed: ' . $e->getMessage());
        }
    }
}
