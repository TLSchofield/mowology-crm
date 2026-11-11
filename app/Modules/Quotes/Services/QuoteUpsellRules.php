<?php
/**
 * QuoteUpsellRules — what a client may add from the customer quote page
 * (public/customer/api/quote-upsell.php, token auth, no login).
 *
 * Fable review 2026-10-12 (LOW): add-upsell accepted ANY active product id, priced at the
 * bundled (85 %) rate from whatever product_upsells row mentioned it, and kept working after
 * approval. Now (Tim approved the remediation plan):
 *   - only an upsell offered (product_upsells, active) for a base product that is on THIS quote,
 *     priced from that offer row;
 *   - nothing is added once the quote is accepted (ALLOW_AFTER_ACCEPT = false) — the client calls
 *     the office instead. Flip the constant to restore post-approval extras at regular price.
 *
 * Pure. No namespace / no autoloader.
 */
final class QuoteUpsellRules
{
    public const ALLOW_AFTER_ACCEPT = true;   // Tim keeps post-approval extras (office is notified); only real upsells of quoted items are allowed

    public static function addAllowed(string $status, bool $isContract = false): bool
    {
        if ($status === 'sent') return true;
        return $status === 'accepted' && !$isContract && self::ALLOW_AFTER_ACCEPT;
    }

    /** Should the page offer upsell cards at all for this status? */
    public static function offerCards(string $status, bool $isContract = false): bool
    {
        return self::addAllowed($status, $isContract);
    }

    /**
     * The offer row (product_upsells, already limited to active rows whose base product is on the
     * quote) that matches the requested product or bundle, or null.
     *
     * @param array<int,array{upsell_product_id:mixed,upsell_bundle_id?:mixed,base_product_id?:mixed,bundled_price?:mixed}> $offers
     */
    public static function matchOffer(array $offers, int $productId, int $bundleId): ?array
    {
        foreach ($offers as $o) {
            if ($bundleId > 0 && (int)($o['upsell_bundle_id'] ?? 0) === $bundleId) return $o;
            if ($bundleId <= 0 && $productId > 0 && (int)($o['upsell_product_id'] ?? 0) === $productId) return $o;
        }
        return null;
    }
}
