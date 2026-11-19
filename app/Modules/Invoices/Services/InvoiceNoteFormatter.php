<?php
declare(strict_types=1);

/**
 * InvoiceNoteFormatter — the customer-facing invoice note as safe HTML with clickable links.
 *
 * The note is plain text typed (or inserted by Mia) in invoices/create.php and edit.php. Every place a
 * customer or the owner reads it (customer portal invoice, CRM invoice view, the PDF) used to escape it
 * and show a link as dead text. html() escapes FIRST, then turns http(s) addresses into <a> tags, so no
 * markup typed in a note ever reaches the page. Only http and https are linked; trailing punctuation
 * (".", ",", ")") stays outside the link. Line breaks are kept as <br> (the callers that use
 * white-space:pre-line pass $breaks = false).
 *
 * Pure. No namespace / no autoloader in production: require_once and call.
 */
class InvoiceNoteFormatter
{
    public static function html(?string $note, bool $breaks = true): string
    {
        $raw = (string)$note;
        $e = fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $out = '';
        $pos = 0;
        // Link on the RAW text, escape every piece separately: a typed entity can never be cut in half.
        if (preg_match_all('~https?://[^\s<>"\']+~i', $raw, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$url, $at]) {
                $tail = '';
                while ($url !== '' && strpos('.,;:!?)]', substr($url, -1)) !== false) {
                    $tail = substr($url, -1) . $tail;
                    $url = substr($url, 0, -1);
                }
                $out .= $e(substr($raw, $pos, $at - $pos));
                $out .= '<a href="' . $e($url) . '" target="_blank" rel="noopener noreferrer">' . $e($url) . '</a>' . $e($tail);
                $pos = $at + strlen($url) + strlen($tail);
            }
        }
        $out .= $e(substr($raw, $pos));
        return $breaks ? nl2br($out) : $out;
    }
}
