<?php
/**
 * jobFlow Validators
 *
 * Centralised input validation and sanitisation for the quote funnel.
 * All functions return clean data or null/false on failure.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Allowed value whitelists
// ─────────────────────────────────────────────────────────────────────────────

const VALID_SERVICE_TYPES = [
    'maintenance',
    'cleanup',
    'hedge_trimming',
    'lawn_care',
    'snow_removal',
];

const VALID_PROPERTY_TYPES = [
    'residential',
    'strata',
    'commercial',
];

const VALID_URGENCY_VALUES = [
    'inquiring',
    'soon',
    'asap',
];

const VALID_PREFERRED_CONTACT = [
    'phone',
    'email',
    'text',
];

const VALID_ADDRESS_RELATIONSHIPS = [
    'same_address',
    'new_address',
];

const VALID_LAWN_SIZES = [
    'small',
    'medium',
    'large',
];

// ─────────────────────────────────────────────────────────────────────────────
// Sanitisation helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Clean a human name: strip non-alpha chars, normalise whitespace, title-case.
 * Returns '' if result is empty.
 */
function cleanName(string $name): string {
    $name = preg_replace("/[^a-zA-Z\s\-\']/", '', $name) ?? '';
    $name = preg_replace('/\s+/', ' ', trim($name)) ?? '';
    return ucwords(strtolower($name));
}

/**
 * Format a Canadian phone number to (XXX) XXX-XXXX.
 * Returns null if the digit count is wrong.
 */
function cleanPhone(string $phone): ?string {
    if (empty($phone)) return null;
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) !== 10) return null;
    return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);
}

/**
 * Format a Canadian postal code to "A1A 1A1".
 * Returns null if format is wrong.
 */
function cleanPostalCode(string $postal): ?string {
    if (empty($postal)) return null;
    $postal = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $postal) ?? '');
    if (strlen($postal) !== 6) return null;
    return substr($postal, 0, 3) . ' ' . substr($postal, 3, 3);
}

/**
 * Normalise a street address: title-case, expand common abbreviations.
 */
function cleanAddress(string $address): string {
    $address = trim($address);
    $address = ucwords(strtolower($address));
    $replacements = [
        '/\bSt\b/i'   => 'Street',
        '/\bAve\b/i'  => 'Avenue',
        '/\bRd\b/i'   => 'Road',
        '/\bDr\b/i'   => 'Drive',
        '/\bBlvd\b/i' => 'Boulevard',
        '/\bCt\b/i'   => 'Court',
        '/\bLn\b/i'   => 'Lane',
        '/\bPl\b/i'   => 'Place',
        '/\bCres\b/i' => 'Crescent',
    ];
    foreach ($replacements as $pattern => $replace) {
        $address = preg_replace($pattern, $replace, $address) ?? $address;
    }
    return $address;
}

/**
 * Validate email format using PHP's built-in filter.
 * Returns lowercase email or null if invalid/empty.
 */
function cleanEmail(string $email): ?string {
    $email = strtolower(trim($email));
    if ($email === '') return null;
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
}

/**
 * Validate and cast a coordinate float (lat or lng).
 * Returns null if absent or out of reasonable range.
 */
function cleanCoordinate(?string $value, float $min, float $max): ?float {
    if ($value === null || $value === '') return null;
    $f = filter_var($value, FILTER_VALIDATE_FLOAT);
    if ($f === false) return null;
    return ($f >= $min && $f <= $max) ? $f : null;
}

/**
 * Whitelist a string value against an allowed list.
 * Returns default if value not in list.
 */
function whitelistValue(string $value, array $allowed, string $default): string {
    return in_array($value, $allowed, true) ? $value : $default;
}

/**
 * Filter and whitelist an array of service type values.
 * Silently drops unknown values. Returns [] if nothing valid.
 */
function cleanServiceTypes(array $raw): array {
    $clean = [];
    foreach ($raw as $v) {
        $v = (string)$v;
        if (in_array($v, VALID_SERVICE_TYPES, true)) {
            $clean[] = $v;
        }
    }
    return array_unique($clean);
}

/**
 * Sanitise a freeform text field.
 * Strips tags, normalises whitespace, enforces a max length.
 */
function cleanText(string $text, int $maxLength = 2000): string {
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
    return substr($text, 0, $maxLength);
}

// ─────────────────────────────────────────────────────────────────────────────
// Composite validator: Step 1 form POST
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Validate and sanitise the raw Step 1 POST data.
 *
 * @param array $post  $_POST
 * @return array ['data' => array, 'errors' => array]
 *   data  — cleaned field values (even if errors exist, for repopulating the form)
 *   errors — field-keyed error strings; empty means all valid
 */
function validateQuoteForm(array $post): array {
    $errors = [];

    $firstName    = cleanName((string)($post['first_name'] ?? ''));
    $lastName     = cleanName((string)($post['last_name'] ?? ''));
    $emailRaw     = trim((string)($post['email'] ?? ''));
    $email        = cleanEmail($emailRaw);
    $phone        = cleanPhone((string)($post['phone'] ?? ''));
    $address      = cleanAddress((string)($post['address'] ?? ''));
    $city         = cleanName((string)($post['city'] ?? 'Vancouver')) ?: 'Vancouver';
    $city         = substr($city, 0, 100);
    $postalCode   = cleanPostalCode((string)($post['postal_code'] ?? ''));
    $lat          = cleanCoordinate($post['latitude'] ?? null, -90, 90);
    $lng          = cleanCoordinate($post['longitude'] ?? null, -180, 180);
    $propertyType = whitelistValue((string)($post['property_type'] ?? ''), VALID_PROPERTY_TYPES, 'residential');
    $serviceTypes = cleanServiceTypes((array)($post['service_types'] ?? []));
    $urgency      = whitelistValue((string)($post['urgency'] ?? ''), VALID_URGENCY_VALUES, 'inquiring');
    $preferred    = whitelistValue((string)($post['preferred_contact'] ?? ''), VALID_PREFERRED_CONTACT, 'phone');
    $description  = cleanText((string)($post['description'] ?? ''), 2000);
    $lawnSize     = whitelistValue((string)($post['lawn_size'] ?? ''), VALID_LAWN_SIZES, '');
    $hasIrrigation = !empty($post['has_irrigation']);

    // Required field checks
    if ($firstName === '') $errors['first_name']    = 'Please enter your first name.';
    if ($lastName === '')  $errors['last_name']     = 'Please enter your last name.';
    if ($phone === null)   $errors['phone']         = 'Please enter a valid 10-digit phone number.';
    if ($address === '')   $errors['address']       = 'Please enter your property address.';
    if (empty($serviceTypes)) $errors['service_types'] = 'Please select at least one service.';
    if (empty($post['consent_quote'])) $errors['consent_quote'] = 'You must agree to be contacted.';

    // Optional email: if provided, must be valid
    if ($emailRaw !== '' && $email === null) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    $data = [
        'first_name'    => $firstName,
        'last_name'     => $lastName,
        'name'          => trim($firstName . ' ' . $lastName),
        'email'         => $email ?? '',
        'phone'         => $phone ?? '',
        'address'       => $address,
        'city'          => $city,
        'postal_code'   => $postalCode ?? '',
        'latitude'      => $lat,
        'longitude'     => $lng,
        'property_type' => $propertyType,
        'service_types' => $serviceTypes,
        'urgency'       => $urgency,
        'preferred_contact' => $preferred,
        'description'   => $description,
        'lawn_size'     => $lawnSize,
        'has_irrigation'=> $hasIrrigation,
        'consent_quote'     => true,
        'consent_marketing' => !empty($post['consent_marketing']),
        'consent_sms'       => !empty($post['consent_sms']),
    ];

    return ['data' => $data, 'errors' => $errors];
}
