<?php
/**
 * PdfStore — where each business keeps its generated PDFs.
 *
 * Security review 2026-11-06 #1: every business wrote to the same STORAGE_ROOT/pdfs/<folder>/ with names that
 * repeat between businesses (INV-2026-0001_v1.pdf, salt_{id}_v{n}.pdf …), so one business could overwrite or be
 * served another's file. The default business (Mowology) keeps the original folders and stored paths; any other
 * business gets STORAGE_ROOT/pdfs/t/<key>/<folder>/ and a stored path carrying the same "t/<key>/" segment.
 *
 * Pure helpers plus one mkdir. No namespace / no autoloader.
 */
require_once __DIR__ . '/Tenant.php';
require_once __DIR__ . '/StoredPdfPath.php';

final class PdfStore
{
    /** Folders a PDF may be written to: the downloadable ones plus statements (emailed, never downloaded). */
    private static function folderAllowed(string $folder): bool
    {
        return in_array($folder, StoredPdfPath::FOLDERS, true) || $folder === 'statements' || $folder === 'contracts';
    }

    /** '' for the default business, 't/<key>/' for any other. */
    public static function segment(): string
    {
        if (Tenant::isDefault()) return '';
        $key = Tenant::key();
        if (!preg_match('/^[a-z0-9-]+$/', $key)) {
            throw new RuntimeException('Invalid business key for PDF storage.');
        }
        return 't/' . $key . '/';
    }

    /** Absolute folder for this business's PDFs of one kind, created 0750 if missing (no mkdir when $create is false). */
    public static function dir(string $folder, bool $create = true): string
    {
        if (!self::folderAllowed($folder)) {
            throw new InvalidArgumentException('Unknown PDF folder.');
        }
        $root = defined('STORAGE_ROOT') ? rtrim(STORAGE_ROOT, '/') : '';
        $dir  = $root . '/pdfs/' . self::segment() . $folder;
        if ($create && !is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /** Stored pdf_path: "<prefix>/pdfs/<segment><folder>/<file>" ($prefix 'storage' or 'app/Storage', or '' for a leading-slash style handled by callers). */
    public static function relative(string $folder, string $file, string $prefix = 'storage'): string
    {
        if (!self::folderAllowed($folder)) {
            throw new InvalidArgumentException('Unknown PDF folder.');
        }
        return trim($prefix, '/') . '/pdfs/' . self::segment() . $folder . '/' . $file;
    }

    /** Reader side: stored path -> absolute file for the CURRENT business, or null (wrong business, bad shape, missing). */
    public static function absoluteFromStored(string $stored, bool $mustExist = true): ?string
    {
        $root = defined('STORAGE_ROOT') ? STORAGE_ROOT : '';
        if ($root === '') return null;
        return StoredPdfPath::resolve($stored, $root, $mustExist, Tenant::key());
    }
}
