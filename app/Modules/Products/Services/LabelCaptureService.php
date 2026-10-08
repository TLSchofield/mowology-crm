<?php
/**
 * LabelCaptureService — "Product / machine label" mode of the receipt capture.
 *
 * Same media upload as a receipt (validation rules from ReceiptIntakeService, /uploads/receipts/
 * which is deny-all and served by the auth-gated /crm/api/serve-receipt.php, EXIF stripped,
 * media_assets row) and the same OCR (Google Vision when configured — a label is not a
 * receipt, so Tesseract's receipt score is not used to skip it; Tesseract text is the
 * fallback). The photo is flagged media_assets.context_type = 'label' and recorded in
 * label_captures. It NEVER creates an expense.
 *
 * Then LabelReaderService reads the text (free) and ProductProposalService turns it into a
 * proposal on Penny's (product) or Otto's (machine) card. Nothing is written to products or
 * equipment until Tim taps Add.
 *
 * Used by the web/Android capture (/crm/api/label-products.php ?mode=capture) and iOS
 * (/api/expenses/label-upload, JWT). No namespace / no autoloader: require_once and `new`.
 */
require_once __DIR__ . '/LabelReaderService.php';
require_once __DIR__ . '/ProductProposalService.php';
require_once dirname(__DIR__, 2) . '/Expenses/Services/ReceiptIntakeService.php';

class LabelCaptureService
{
    private PDO $db;
    /** @var callable|null fn(string $path): array{success: bool, text: string, source: string} — tests inject */
    private $ocr;

    public function __construct(PDO $db, ?callable $ocr = null)
    {
        $this->db = $db;
        $this->ocr = $ocr;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM label_captures LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array $file $_FILES shape
     * @return array{success: bool, error?: string, http_code?: int, capture_id?: int, media_id?: int, kind?: string, title?: string, message?: string, read?: array}
     */
    public function capture(int $userId, array $file, ?float $lat, ?float $lng): array
    {
        if (!$this->ready()) return ['success' => false, 'error' => 'Label photos need migration 1225.', 'http_code' => 503];
        if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'No photo received.', 'http_code' => 400];
        }
        $mime = (string)mime_content_type($file['tmp_name']);
        if (!ReceiptIntakeService::isAllowedMimeType($mime)) {
            return ['success' => false, 'error' => 'Only image files are accepted (JPEG, PNG, WebP, HEIC).', 'http_code' => 415];
        }
        if ((int)$file['size'] > ReceiptIntakeService::MAX_FILE_SIZE_BYTES) {
            return ['success' => false, 'error' => 'File too large (max 10MB).', 'http_code' => 413];
        }
        $dir = PUBLIC_ROOT . '/uploads/receipts/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext = ReceiptIntakeService::resolveStoredExtension((string)$file['name']);
        $stored = 'label-' . date('Ymd-His') . '-' . uniqid() . '.' . $ext;
        $path = $dir . $stored;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return ['success' => false, 'error' => 'Failed to save the photo.', 'http_code' => 500];
        }
        // Strip EXIF by re-encoding (the receipt pipeline does the same).
        if (extension_loaded('gd') && $mime === 'image/jpeg' && ($im = @imagecreatefromjpeg($path))) { imagejpeg($im, $path, 92); imagedestroy($im); }
        elseif (extension_loaded('gd') && $mime === 'image/png' && ($im = @imagecreatefrompng($path))) { imagesavealpha($im, true); imagepng($im, $path, 6); imagedestroy($im); }
        $w = $h = null;
        if ($info = @getimagesize($path)) { [$w, $h] = $info; }
        $this->db->prepare("INSERT INTO media_assets (original_filename, stored_filename, file_path, file_type, mime_type, file_size, image_width, image_height, gps_lat, gps_lng, sha256, alt_text, context_type, created_by)
                            VALUES (?, ?, ?, 'image', ?, ?, ?, ?, ?, ?, ?, 'Product / machine label photo', 'label', ?)")
             ->execute([(string)$file['name'], $stored, '/uploads/receipts/' . $stored, $mime, (int)$file['size'], $w, $h, $lat, $lng, hash_file('sha256', $path) ?: null, $userId]);
        $mediaId = (int)$this->db->lastInsertId();

        $ocr = $this->runOcr($path);
        return $this->record($userId, $mediaId, $lat, $lng, $ocr['text'], $ocr['source']);
    }

    /** Read + store + propose. Split from capture() so tests run it without a file upload. */
    public function record(int $userId, ?int $mediaId, ?float $lat, ?float $lng, string $text, string $source, ?string $at = null): array
    {
        $read = LabelReaderService::read($text);
        $this->db->prepare("INSERT INTO label_captures (media_id, captured_by, captured_at, lat, lng, ocr_text, ocr_source, parsed_json, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')")
             ->execute([$mediaId, $userId ?: null, $at ?? date('Y-m-d H:i:s'), $lat, $lng, $text !== '' ? $text : null, $source,
                        json_encode($read, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $captureId = (int)$this->db->lastInsertId();
        if (trim($text) === '') {
            return ['success' => true, 'capture_id' => $captureId, 'media_id' => $mediaId, 'kind' => null, 'title' => null,
                    'message' => 'Photo saved, but I could not read any text on it. Try again closer, in good light.'];
        }
        $p = (new ProductProposalService($this->db, null, $at ? substr($at, 0, 10) : null))->proposeFromLabel($captureId);
        return ['success' => true, 'capture_id' => $captureId, 'media_id' => $mediaId, 'kind' => $read['kind'],
                'title' => $p['title'] ?? $read['display_name'], 'message' => $p['message'] ?? 'Saved.', 'existing' => $p['existing'] ?? false,
                'read' => array_intersect_key($read, array_flip(['kind', 'brand', 'name', 'display_name', 'sku', 'model', 'serial', 'size_label', 'batch']))];
    }

    /** Vision first (labels are not receipts), Tesseract text as the fallback. */
    private function runOcr(string $path): array
    {
        if ($this->ocr) return ($this->ocr)($path);
        try {
            require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
            $v = extractTextFromImage($path);
            if (!empty($v['success']) && trim((string)$v['text']) !== '') return ['text' => (string)$v['text'], 'source' => 'vision'];
        } catch (Throwable $e) {
            error_log('Label OCR (vision): ' . $e->getMessage());
        }
        try {
            require_once APP_ROOT . '/Services/Receipts/TesseractPreScreen.php';
            $t = tesseractPreScreen($path);
            if (trim((string)($t['text'] ?? '')) !== '') return ['text' => (string)$t['text'], 'source' => 'tesseract'];
        } catch (Throwable $e) {
            error_log('Label OCR (tesseract): ' . $e->getMessage());
        }
        return ['text' => '', 'source' => 'none'];
    }
}
