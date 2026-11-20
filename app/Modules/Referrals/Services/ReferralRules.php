<?php
/**
 * ReferralRules — who can count as a referral (pure).
 *
 * Fable review 2026-10-12: a client could refer themselves (their own email/phone through their
 * own link) and the same person could be referred again and again. A referral now counts only for
 * a different, new person, once.
 *
 * No namespace / no autoloader.
 */
final class ReferralRules
{
    public static function normEmail(?string $e): string
    {
        return strtolower(trim((string)$e));
    }

    /** Last 10 digits — "(778) 846-9273" and "+1 778 846 9273" are one phone. */
    public static function normPhone(?string $p): string
    {
        $d = preg_replace('/\D+/', '', (string)$p);
        return strlen($d) >= 10 ? substr($d, -10) : '';
    }

    /**
     * Why an inbound referral must not be recorded, or null when it may.
     *
     * @param array $referrer       contact row of the link's owner (email, phone, mobile)
     * @param array $referred       what the new person typed (email, phone)
     * @param bool  $alreadyClient  the referred email already belongs to a contact with a completed visit
     * @param bool  $alreadyReferred a referral for this email/phone already exists (any referrer)
     */
    public static function inboundRefusal(array $referrer, array $referred, bool $alreadyClient, bool $alreadyReferred): ?string
    {
        $email = self::normEmail($referred['email'] ?? '');
        $phone = self::normPhone($referred['phone'] ?? '');
        if ($email === '' && $phone === '') return 'no_contact_details';
        if ($email !== '' && $email === self::normEmail($referrer['email'] ?? '')) return 'self_referral';
        if ($phone !== '') {
            foreach (['phone', 'mobile'] as $k) {
                if ($phone === self::normPhone($referrer[$k] ?? '')) return 'self_referral';
            }
        }
        if ($alreadyClient) return 'already_a_client';
        if ($alreadyReferred) return 'already_referred';
        return null;
    }

    /** At award time: the referred contact is someone else, and this was their first completed visit. */
    public static function awardRefusal(int $referrerContactId, int $referredContactId, int $earlierCompletedVisits): ?string
    {
        if ($referredContactId <= 0) return 'no_referred_contact';
        if ($referredContactId === $referrerContactId) return 'self_referral';
        if ($earlierCompletedVisits > 0) return 'not_a_new_client';
        return null;
    }
}
