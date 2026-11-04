<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Services/BusinessIdentity.php';

/**
 * Puts a known BusinessIdentity in place for a test (the real one reads the database).
 * Always BusinessIdentity::reset() in tearDown.
 */
final class TestIdentity
{
    /** Mowology's own details, as its settings hold them. */
    public static function mowology(): array
    {
        return self::set(BusinessIdentity::from(
            ['company_name' => 'Mowology Landscaping', 'company_phone' => '778 846 9273', 'company_email' => 'office@mowology.ca', 'company_website' => 'https://mowology.ca', 'company_address' => '2845 West 15th Ave, Vancouver, BC V6K 3A1'],
            ['entered_name' => 'Mowology', 'legal_name' => 'MOWOLOGY LAWNS & LANDSCAPES LTD.', 'jurisdiction' => 'BC'],
            ['business_trade' => 'landscaping and property-maintenance', 'business_city' => 'Vancouver', 'mail_verified_domains' => 'mowology.ca'],
            ['id' => 1, 'first_name' => 'TIM', 'preferred_name' => 'Tim'], 3));
    }

    /** A different business: nothing in it may ever show Mowology. */
    public static function greenleaf(): array
    {
        return self::set(BusinessIdentity::from(
            ['company_name' => 'Greenleaf Gardens', 'company_phone' => '604 555 0142', 'company_email' => 'hello@greenleaf.ca', 'company_website' => 'https://greenleaf.ca'],
            ['entered_name' => 'Greenleaf', 'jurisdiction' => 'BC'],
            ['business_trade' => 'garden-care', 'business_city' => 'Burnaby'],
            ['id' => 2, 'first_name' => 'CLAUDIA', 'preferred_name' => 'Claudia'], 2));
    }

    public static function set(array $identity): array
    {
        BusinessIdentity::use($identity);
        return $identity;
    }
}
