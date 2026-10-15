<?php
/**
 * VendorIdentity — canonical, cross-tenant identity for a vendor.
 *
 * The commons (shared vendor knowledge) is keyed by things printed on the vendor's own
 * paper, never by a tenant's row id: the GST/HST (business) number, the phone's digits,
 * the web domain, and a normalised name key. Pure functions, no DB, trade-neutral.
 *
 * No namespace / no autoloader in production: require_once and static calls.
 */
declare(strict_types=1);

final class VendorIdentity
{
    /** Words that carry no identity: legal suffixes, articles, store-number noise. */
    private const NOISE = [
        'inc', 'incorporated', 'ltd', 'limited', 'llc', 'corp', 'corporation', 'co', 'company',
        'the', 'and', '&', 'of', 'store', 'stores', 'location', 'canada', 'bc', 'ab', 'on',
    ];

    /** "Home Depot #7128" and "THE HOME DEPOT, Store 7128" → "home depot". */
    public static function nameKey(?string $name): ?string
    {
        if ($name === null) return null;
        $s = mb_strtolower(trim($name));
        if ($s === '') return null;
        $s = preg_replace('/[#№]\s*\d+|\bstore\s*\d+\b|\bno\.?\s*\d+\b/u', ' ', $s);   // store numbers
        $s = preg_replace('/[^\p{L}\p{N}& ]+/u', ' ', $s);                           // punctuation
        $words = array_filter(preg_split('/\s+/', $s), static function ($w) {
            return $w !== '' && !in_array($w, self::NOISE, true) && !preg_match('/^\d{3,}$/', $w);
        });
        $key = trim(implode(' ', $words));
        return $key === '' ? null : mb_substr($key, 0, 160);
    }

    /**
     * Words that can't identify a vendor on their own: receipt labels, payment words, place words. An alias
     * built only from these ("date:", "amount", "the", "city of", "w. broadway", "transaction red") matched
     * nearly every receipt and filed it under the wrong vendor (2026-10-15).
     */
    private const NOT_A_NAME = ['the', 'and', 'of', 'for', 'a', 'an', 'to', 'at', 'on', 'in', 'by', 'de', 'la', 'le', 'des',
        'date', 'time', 'amount', 'total', 'subtotal', 'sub', 'tax', 'gst', 'pst', 'hst', 'store', 'tel', 'phone', 'fax',
        'transaction', 'trans', 'push', 'approved', 'approval', 'debit', 'credit', 'card', 'cash', 'change', 'balance',
        'invoice', 'receipt', 'customer', 'copy', 'thank', 'thanks', 'you', 'red', 'sale', 'sales', 'item', 'items', 'qty',
        'price', 'auth', 'ref', 'visa', 'mastercard', 'interac', 'chip', 'tip', 'merchant', 'terminal', 'operator', 'cashier',
        'register', 'reg', 'account', 'acct', 'type', 'signature', 'required', 'purchase', 'payment', 'paid', 'number', 'welcome',
        'city', 'street', 'avenue', 'road', 'drive', 'broadway', 'north', 'south', 'east', 'west', 'canada', 'british', 'columbia',
        'vancouver', 'richmond', 'burnaby', 'surrey', 'delta', 'ladner', 'coquitlam', 'langley', 'kitsilano'];

    /**
     * Can this alias (or vendor name) identify a vendor on a receipt? It needs a word of 4+ letters that isn't a
     * receipt or place word, or a store number (5+ digits in a row) or phone number (10+ digits). Pure.
     */
    public static function usableAlias(?string $alias): bool
    {
        $s = mb_strtolower(trim((string)$alias));
        if ($s === '') return false;
        // A store number (an unbroken run of 5+ digits) or a phone number (10+ digits); a street number like
        // "320-507" is neither.
        if (preg_match('/\d{5,}/', $s) || strlen(preg_replace('/\D+/', '', $s)) >= 10) return true;
        foreach (preg_split('/[^\p{L}]+/u', $s) as $w) {
            if (mb_strlen($w) >= 4 && !in_array($w, self::NOT_A_NAME, true)) return true;
        }
        return false;
    }

    /** Does the text carry this alias as whole words ("rona" in "RONA+ Vancouver", never in "Verona")? Pure. */
    public static function textHas(string $text, string $alias): bool
    {
        $a = trim(mb_strtolower($alias));
        if ($a === '') return false;
        return (bool)preg_match('/(?<![\p{L}\p{N}])' . preg_quote($a, '/') . '(?![\p{L}\p{N}])/iu', $text);
    }

    /** Last 10 digits of a North American number; null when there aren't 10. */
    public static function phoneDigits(?string $phone): ?string
    {
        if ($phone === null) return null;
        $d = preg_replace('/\D+/', '', $phone);
        if (strlen($d) < 10) return null;
        return substr($d, -10);
    }

    /** "https://www.lawnboy.ca/shop" or "info@lawnboy.ca" → "lawnboy.ca". */
    public static function domainOf(?string $urlOrEmail): ?string
    {
        if ($urlOrEmail === null) return null;
        $s = strtolower(trim($urlOrEmail));
        if ($s === '') return null;
        if (strpos($s, '@') !== false) $s = substr($s, strrpos($s, '@') + 1);
        $s = preg_replace('#^[a-z]+://#', '', $s);
        $s = preg_replace('#[/?:].*$#', '', $s);
        $s = preg_replace('/^www\./', '', $s);
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $s)) return null;
        return mb_substr($s, 0, 120);
    }

    /**
     * Canadian GST/HST registration as printed on receipts: "123456789 RT0001",
     * "GST# 123456789RT0001", "BN 123456789". Returns "123456789RT0001" or the bare 9-digit BN.
     */
    public static function gstNumber(?string $text): ?string
    {
        if ($text === null || $text === '') return null;
        if (preg_match('/\b(\d{9})\s?[-]?\s?(RT)\s?(\d{4})\b/i', $text, $m)) {
            return $m[1] . 'RT' . $m[3];
        }
        if (preg_match('/\b(?:gst|hst|gst\/hst|tps|tvh|bn|business\s*(?:no|number|#))\s*(?:reg(?:istration)?|no|number|#|:)*\s*[:#]?\s*(\d{9})\b/i', $text, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Everything identifying we can read from a receipt. $contact is ReceiptParser's
     * extractVendorContact() output when available (phone/website/address).
     * @return array{gst_number:?string, phone_digits:?string, domain:?string, name_key:?string, display_name:?string}
     */
    public static function fromReceipt(?string $ocrText, ?string $vendorHint, array $contact = []): array
    {
        $domain = self::domainOf($contact['website'] ?? null);
        if ($domain === null && $ocrText && preg_match('/\b(?:www\.)?([a-z0-9-]+\.(?:ca|com|net|org|co|io|shop))\b/i', $ocrText, $m)) {
            $domain = self::domainOf($m[1]);
        }
        $phone = self::phoneDigits($contact['phone'] ?? null);
        if ($phone === null && $ocrText && preg_match('/\(?\b\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/', $ocrText, $m)) {
            $phone = self::phoneDigits($m[0]);
        }
        return [
            'gst_number'   => self::gstNumber($ocrText),
            'phone_digits' => $phone,
            'domain'       => $domain,
            'name_key'     => self::nameKey($vendorHint),
            'display_name' => $vendorHint !== null ? trim($vendorHint) : null,
        ];
    }

    /** Stable hash for a fact value (any scalar/array), so facts dedupe across tenants. */
    public static function valueHash($value): string
    {
        if (is_array($value)) { ksort($value); $value = json_encode($value, JSON_UNESCAPED_UNICODE); }
        return substr(sha1(mb_strtolower(trim((string)$value))), 0, 40);
    }

    /** Tenants are identified in the commons only by a one-way hash of their key. */
    public static function tenantHash(string $tenantKey): string
    {
        return substr(hash('sha256', 'mw-commons|' . $tenantKey), 0, 40);
    }
}
