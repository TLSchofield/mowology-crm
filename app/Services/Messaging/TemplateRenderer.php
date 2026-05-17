<?php
/**
 * TemplateRenderer — Merge-field email template engine
 *
 * Renders email templates by replacing {{field_name}} merge variables
 * and wrapping output in a branded Mowology email layout.
 *
 * Supports:
 *   - Simple merge: {{first_name}} → "John"
 *   - Conditional blocks: {{#product}}...{{/product}} — renders if product_name is set
 *   - Branded wrapper: Mowology header + footer with unsubscribe link
 *
 * @package Mowology\Services\Messaging
 */

declare(strict_types=1);

/**
 * Render a template string with merge data.
 *
 * @param string $templateHtml  HTML with {{merge_fields}}
 * @param array  $data          Key-value pairs (without {{ }})
 * @return string               Rendered HTML
 */
function renderTemplate(string $templateHtml, array $data): string
{
    // 1. Process conditional blocks: {{#key}}content{{/key}}
    $rendered = preg_replace_callback(
        '/\{\{#(\w+)\}\}(.*?)\{\{\/\1\}\}/s',
        function ($matches) use ($data) {
            $key = $matches[1];
            $content = $matches[2];
            // Show block if the key exists and is truthy
            if (!empty($data[$key])) {
                // Recursively replace merge fields inside the block
                return str_replace(
                    array_map(function ($k) { return '{{' . $k . '}}'; }, array_keys($data)),
                    array_values($data),
                    $content
                );
            }
            return ''; // Hide block
        },
        $templateHtml
    );

    // 2. Simple merge-field replacement
    foreach ($data as $key => $value) {
        $rendered = str_replace('{{' . $key . '}}', (string)$value, $rendered);
    }

    // 3. Clean up any remaining unreplaced merge fields
    $rendered = preg_replace('/\{\{[a-z_]+\}\}/', '', $rendered);

    return $rendered;
}

/**
 * Wrap rendered email content in the branded Mowology email layout.
 *
 * @param string $bodyHtml       Rendered email body
 * @param string $unsubscribeUrl Full unsubscribe URL (or empty to omit)
 * @return string                Complete branded HTML email
 */
/**
 * Company identity for email footers — sourced from the business
 * settings the tenant manages on the CRM settings page
 * (`business_settings` row, edited via /crm/settings.php), NOT
 * hardcoded. This is the single source of truth so future tenants
 * get their own footer without code changes.
 *
 * Resilient by design: this runs in cron and web contexts; if the
 * settings row or DB is unavailable the email must still send, so we
 * fall back to known-good Mowology values (incl. a valid CASL mailing
 * address) rather than fatal or omit the legally-required address.
 *
 * @return array{name:string,address:string,phone:string,email:string}
 */
function emailCompanyDetails(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // Known-good fallbacks (used if business_settings is empty/unreadable).
    $d = [
        'name'    => 'Mowology Landscaping',
        'address' => '2845 West 15th Ave, Vancouver, BC V6K 3A1',
        'phone'   => '(604) 358-1818',
        'email'   => 'info@mowology.ca',
    ];

    try {
        if (function_exists('getDB')) {
            $row = getDB()
                ->query("SELECT company_name, company_address, company_phone, company_email FROM business_settings WHERE id = 1")
                ->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!empty($row['company_name']))    $d['name']    = trim((string)$row['company_name']);
                if (!empty($row['company_address']))  $d['address'] = trim((string)$row['company_address']);
                if (!empty($row['company_phone']))    $d['phone']   = trim((string)$row['company_phone']);
                if (!empty($row['company_email']))    $d['email']   = trim((string)$row['company_email']);
            }
        }
    } catch (\Throwable $e) {
        // Non-fatal: keep fallbacks, never block an email send on settings.
        error_log('emailCompanyDetails: settings read failed, using fallbacks — ' . $e->getMessage());
    }

    $cached = $d;
    return $cached;
}

function wrapInBrandedEmail(string $bodyHtml, string $unsubscribeUrl = ''): string
{
    $co = emailCompanyDetails();
    $coName    = htmlspecialchars($co['name'], ENT_QUOTES, 'UTF-8');
    $coAddress = htmlspecialchars($co['address'], ENT_QUOTES, 'UTF-8');
    $coPhone   = htmlspecialchars($co['phone'], ENT_QUOTES, 'UTF-8');
    $coEmail   = htmlspecialchars($co['email'], ENT_QUOTES, 'UTF-8');

    $unsubscribeBlock = '';
    if (!empty($unsubscribeUrl)) {
        $safeUrl = htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8');
        $unsubscribeBlock = '<p style="margin:0;"><a href="' . $safeUrl . '" style="color:#8fa89c;text-decoration:underline;">Unsubscribe</a></p>';
    }

    return '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:20px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">

<!-- Header -->
<tr>
  <td style="background:#1A5F4A;padding:24px 32px;text-align:center;">
    <h1 style="margin:0;color:#fff;font-size:22px;font-weight:700;letter-spacing:-0.3px;">Mowology</h1>
    <p style="margin:4px 0 0;color:#7FD858;font-size:13px;letter-spacing:0.5px;">LANDSCAPING</p>
  </td>
</tr>

<!-- Body -->
<tr>
  <td style="padding:32px;color:#333;font-size:15px;line-height:1.6;">
    ' . $bodyHtml . '
  </td>
</tr>

<!-- Footer -->
<tr>
  <td style="background:#0D3B2E;padding:20px 32px;text-align:center;color:#8fa89c;font-size:12px;">
    <p style="margin:0 0 4px;">' . $coName . '</p>
    <p style="margin:0 0 4px;">' . $coAddress . '</p>
    <p style="margin:0 0 4px;">📞 ' . $coPhone . ' &bull; 📧 ' . $coEmail . '</p>
    ' . $unsubscribeBlock . '
  </td>
</tr>

</table>
</td></tr>
</table>
</body>
</html>';
}

/**
 * Build standard merge data for a contact + optional product.
 *
 * @param array       $contact  Contact record (first_name, last_name, email, etc.)
 * @param array|null  $property Property record (address, city, etc.)
 * @param array|null  $product  Product record (name, price, description, etc.)
 * @param string      $unsubscribeUrl
 * @return array                Merge data keyed by field name (no {{ }})
 */
function buildMergeData(
    array $contact,
    ?array $property = null,
    ?array $product = null,
    string $unsubscribeUrl = ''
): array {
    $address = '';
    if ($property) {
        $parts = array_filter([
            $property['address'] ?? $property['property_address'] ?? '',
            $property['city'] ?? $property['property_city'] ?? '',
        ]);
        $address = implode(', ', $parts);
    }

    $data = [
        'first_name'       => htmlspecialchars($contact['first_name'] ?? '', ENT_QUOTES, 'UTF-8'),
        'last_name'        => htmlspecialchars($contact['last_name'] ?? '', ENT_QUOTES, 'UTF-8'),
        'email'            => htmlspecialchars($contact['email'] ?? '', ENT_QUOTES, 'UTF-8'),
        'property_address' => htmlspecialchars($address, ENT_QUOTES, 'UTF-8'),
        'company_name'     => 'Mowology Landscaping',
        'company_phone'    => '(604) 358-1818',
        'unsubscribe_url'  => htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8'),
        'cta_url'          => 'https://mowology.ca/quote',
        'review_url'       => 'https://g.page/r/mowology/review',
    ];

    if ($product) {
        $data['product_name']        = htmlspecialchars($product['name'] ?? '', ENT_QUOTES, 'UTF-8');
        $data['product_price']       = !empty($product['base_price']) ? '$' . number_format((float)$product['base_price'], 2) : '';
        $data['product_description'] = htmlspecialchars($product['description'] ?? '', ENT_QUOTES, 'UTF-8');
    }

    return $data;
}

/**
 * Generate an HMAC-based unsubscribe URL for a contact email.
 *
 * @param string $email
 * @param int    $sendId  campaign_sends.id for tracking
 * @return string
 */
function generateUnsubscribeUrl(string $email, int $sendId = 0): string
{
    $secret = defined('UNSUBSCRIBE_SECRET') ? UNSUBSCRIBE_SECRET : 'mowology-unsub-2026';
    $token = hash_hmac('sha256', strtolower(trim($email)), $secret);
    $params = http_build_query([
        'email' => $email,
        'token' => $token,
        'sid'   => $sendId,
    ]);
    return 'https://mowology.ca/unsubscribe.php?' . $params;
}
