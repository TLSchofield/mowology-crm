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
require_once __DIR__ . '/Tenant.php';

final class StoredPdfPath
{
    /** storage/pdfs/<folder>/ — the folders the CRM writes customer/office PDFs to. */
    public const FOLDERS = ['invoices', 'quotes', 'salt-reports', 'trip-reports', 'pow', 'zone-report'];

    /**
     * Map a stored pdf_path ("storage/pdfs/salt-reports/salt_42_v1.pdf", optionally with a leading
     * "/"; trip reports store "app/Storage/pdfs/trip-reports/…") to an absolute file under $storageRoot, or null when it is not an allowed document.
     * $mustExist = false skips the realpath check (tests).
     */
    public static function resolve(string $raw, string $storageRoot, bool $mustExist = true, ?string $tenantKey = null): ?string
    {
        // Per-business folders (2026-11-06): the default business keeps the flat folders and accepts no "t/"
        // segment; any other business accepts only its own "t/<key>/" and never the flat (default) folders.
        if ($tenantKey === null) $tenantKey = Tenant::key();
        $isDefault = ($tenantKey === Tenant::DEFAULT);
        if (strpos($raw, "\0") !== false || strpos($raw, '\\') !== false) return null;
        $raw = ltrim(trim($raw), '/');
        if ($raw === '') return null;
        if (!preg_match('#^(?:storage|app/Storage)/pdfs/(?:t/([a-z0-9-]+)/)?([a-z0-9_-]+)/([A-Za-z0-9][A-Za-z0-9._-]*\.pdf)$#', $raw, $m)) return null;
        $segKey = $m[1];
        if ($isDefault ? $segKey !== '' : $segKey !== $tenantKey) return null;
        $folder = $m[2];
        $file   = $m[3];
        if (!in_array($folder, self::FOLDERS, true)) return null;
        if (strpos($file, '..') !== false) return null;

        $sub  = $isDefault ? '' : 't/' . $tenantKey . '/';
        $root = rtrim($storageRoot, '/');
        $abs  = $root . '/pdfs/' . $sub . $folder . '/' . $file;
        if (!$mustExist) return $abs;

        $realDir = realpath($root . '/pdfs/' . $sub . $folder);
        $realAbs = realpath($abs);
        if ($realDir === false || $realAbs === false || !is_file($realAbs)) return null;
        if (dirname($realAbs) !== $realDir) return null; // symlink out of the folder
        return $realAbs;
    }
}
