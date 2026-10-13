<?php
/**
 * StoredPdfPath — which stored files /crm/documents/download.php may stream.
 *
 * Fable #8 (2026-10-12): the page served ANY file under STORAGE_ROOT (Trackimo token cache,
 * schema snapshots, supplier/company documents, backup dumps) to a billing.view login.
 * Now only *.pdf directly inside one of the document folders below.
 *
 * Pure. No namespace / no autoloader.
 */
final class StoredPdfPath
{
    /** storage/pdfs/<folder>/ — the folders the CRM writes customer/office PDFs to. */
    public const FOLDERS = ['invoices', 'quotes', 'salt-reports', 'trip-reports', 'pow', 'zone-report'];

    /**
     * Map a stored pdf_path ("storage/pdfs/salt-reports/salt_42_v1.pdf", optionally with a leading
     * "/"; trip reports store "app/Storage/pdfs/trip-reports/…") to an absolute file under $storageRoot, or null when it is not an allowed document.
     * $mustExist = false skips the realpath check (tests).
     */
    public static function resolve(string $raw, string $storageRoot, bool $mustExist = true): ?string
    {
        if (strpos($raw, "\0") !== false || strpos($raw, '\\') !== false) return null;
        $raw = ltrim(trim($raw), '/');
        if ($raw === '') return null;
        if (!preg_match('#^(?:storage|app/Storage)/pdfs/([a-z0-9_-]+)/([A-Za-z0-9][A-Za-z0-9._-]*\.pdf)$#', $raw, $m)) return null;
        if (!in_array($m[1], self::FOLDERS, true)) return null;
        if (strpos($m[2], '..') !== false) return null;

        $root = rtrim($storageRoot, '/');
        $abs  = $root . '/pdfs/' . $m[1] . '/' . $m[2];
        if (!$mustExist) return $abs;

        $realDir = realpath($root . '/pdfs/' . $m[1]);
        $realAbs = realpath($abs);
        if ($realDir === false || $realAbs === false || !is_file($realAbs)) return null;
        if (dirname($realAbs) !== $realDir) return null; // symlink out of the folder
        return $realAbs;
    }
}
