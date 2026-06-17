<?php
/**
 * EmailWrapper — Branded HTML Email Shell
 *
 * Wraps any email body content in the Mowology branded email template.
 * Uses table-based layout with inline CSS for maximum email client compatibility.
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
 *
 *   $html = EmailWrapper::wrap(
 *       $bodyHtml,
 *       'View & Accept Quote',
 *       'https://mowology.ca/customer/quote.php?token=xxx',
 *       EmailWrapper::getCompanyInfo()
 *   );
 */

declare(strict_types=1);

class EmailWrapper
{
    /**
     * Wrap email body content in the branded Mowology email shell.
     *
     * @param string      $bodyHtml   HTML body content (use textToHtml() to convert plain text)
     * @param string|null $ctaLabel   CTA button label (null = no button)
     * @param string|null $ctaUrl     CTA button URL
     * @param array       $company    Keys: company_name, company_phone, company_email, company_website
     * @return string                 Complete HTML email string
     */
    public static function wrap(
        string $bodyHtml,
        ?string $ctaLabel = null,
        ?string $ctaUrl   = null,
        array $company    = []
    ): string {
        // Brand colours — local vars so they interpolate cleanly in heredoc
        $colGreen    = '#2D8659';
        $colDark     = '#1A5F4A';
        $colForest   = '#0D3B2E';
        $colTextMid  = '#4a6b5d';
        $colBgSub    = '#f8fffe';
        $colBorder   = '#e5ede9';
        $colTextLt   = '#7a9e8c';

        // Company info
        $companyName    = htmlspecialchars($company['company_name']    ?? 'Mowology Landscaping');
        $companyPhone   = htmlspecialchars($company['company_phone']   ?? '(778) 846-9273');
        $companyEmail   = htmlspecialchars($company['company_email']   ?? 'office@mowology.ca');
        $companyWebsite = $company['company_website'] ?? 'mowology.ca';
        $websiteDisplay = preg_replace('#^https?://#', '', $companyWebsite);
        $websiteUrl     = (strpos($companyWebsite, 'http') === 0) ? $companyWebsite : 'https://' . $companyWebsite;

        $year = date('Y');

        // CTA button block
        $ctaBlock = '';
        if ($ctaLabel && $ctaUrl) {
            $safeUrl   = htmlspecialchars($ctaUrl);
            $safeLabel = htmlspecialchars($ctaLabel);
            $ctaBlock = <<<HTML
                <!-- CTA Button -->
                <tr>
                    <td align="center" style="padding: 28px 40px 8px;">
                        <a href="{$safeUrl}"
                           style="display:inline-block;background-color:{$colGreen};color:#ffffff;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:15px;font-weight:700;letter-spacing:0.3px;text-decoration:none;padding:14px 32px;border-radius:8px;text-align:center;">
                            {$safeLabel} &rarr;
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 6px 40px 0; text-align:center;">
                        <p style="margin:0;font-size:12px;color:{$colTextLt};font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
                            Or copy this link:
                            <a href="{$safeUrl}" style="color:{$colGreen};text-decoration:underline;">{$safeUrl}</a>
                        </p>
                    </td>
                </tr>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>Mowology</title>
</head>
<body style="margin:0;padding:0;background-color:#f2f4f3;-webkit-font-smoothing:antialiased;">

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
       style="background-color:#f2f4f3;">
    <tr>
        <td align="center" style="padding:32px 16px 48px;">

            <!-- Accent top bar -->
            <table role="presentation" cellspacing="0" cellpadding="0" border="0"
                   style="width:100%;max-width:600px;">
                <tr>
                    <td style="height:4px;background-color:{$colGreen};border-radius:4px 4px 0 0;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
            </table>

            <!-- Main card -->
            <table role="presentation" cellspacing="0" cellpadding="0" border="0"
                   style="width:100%;max-width:600px;background-color:#ffffff;border-radius:0 0 12px 12px;">

                <!-- Header -->
                <tr>
                    <td style="background-color:{$colDark};padding:22px 40px;">
                        <span style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.5px;">Mowology</span>
                        <span style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:12px;font-weight:400;color:rgba(255,255,255,0.6);margin-left:10px;letter-spacing:0.8px;text-transform:uppercase;">Landscaping</span>
                    </td>
                </tr>

                <!-- Body -->
                <tr>
                    <td style="padding:36px 40px 8px;">
                        {$bodyHtml}
                    </td>
                </tr>

                {$ctaBlock}

                <!-- Divider -->
                <tr>
                    <td style="padding:28px 40px 0;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td style="border-top:1px solid {$colBorder};font-size:0;line-height:0;">&nbsp;</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style="background-color:{$colBgSub};padding:22px 40px 28px;border-radius:0 0 12px 12px;">
                        <p style="margin:0 0 4px;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:13px;font-weight:700;color:{$colForest};">
                            {$companyName}
                        </p>
                        <p style="margin:0;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:12px;color:{$colTextMid};line-height:1.9;">
                            <a href="tel:{$companyPhone}" style="color:{$colTextMid};text-decoration:none;">{$companyPhone}</a>
                            &nbsp;&middot;&nbsp;
                            <a href="mailto:{$companyEmail}" style="color:{$colTextMid};text-decoration:none;">{$companyEmail}</a>
                            &nbsp;&middot;&nbsp;
                            <a href="{$websiteUrl}" style="color:{$colTextMid};text-decoration:none;">{$websiteDisplay}</a>
                        </p>
                        <p style="margin:10px 0 0;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:11px;color:{$colTextLt};">
                            &copy; {$year} {$companyName}. All rights reserved.
                        </p>
                    </td>
                </tr>

            </table>
            <!-- /Main card -->

        </td>
    </tr>
</table>

</body>
</html>
HTML;
    }

    /**
     * Convert plain text (double-newline paragraphs) to email-safe HTML <p> tags.
     *
     * @param string $text  Plain text body
     * @return string       HTML with <p> tags and inline styles
     */
    public static function textToHtml(string $text): string
    {
        $pStyle = "margin:0 0 16px;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:15px;line-height:1.75;color:#0D3B2E;";

        // Normalise line endings
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        // Split on blank lines
        $paragraphs = preg_split('/\n{2,}/', $text);

        $html = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            // Escape HTML entities, then convert single newlines to <br>
            $escaped = nl2br(htmlspecialchars($para, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $html .= '<p style="' . $pStyle . '">' . $escaped . '</p>' . "\n";
        }

        return $html;
    }

    /**
     * Load company info from business_settings for use in the footer.
     * Returns safe defaults if the DB query fails.
     *
     * @return array
     */
    public static function getCompanyInfo(): array
    {
        try {
            $db  = getDB();
            $row = $db->query(
                "SELECT company_name, company_phone, company_email, company_website
                 FROM business_settings LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);

            return $row ?: self::defaultCompanyInfo();
        } catch (Throwable $e) {
            return self::defaultCompanyInfo();
        }
    }

    /**
     * @return array
     */
    private static function defaultCompanyInfo(): array
    {
        return [
            'company_name'    => 'Mowology Landscaping',
            'company_phone'   => '(778) 846-9273',
            'company_email'   => 'office@mowology.ca',
            'company_website' => 'mowology.ca',
        ];
    }

    /**
     * Render a "How to Pay" block from business_settings.invoice_payment_instructions
     * for invoice emails. Single source of truth — same text shown on the invoice
     * PDF and the customer portal. Returns '' when no instructions are configured.
     */
    public static function paymentInstructionsHtml(): string
    {
        try {
            $val = (string) (getDB()
                ->query("SELECT invoice_payment_instructions FROM business_settings LIMIT 1")
                ->fetchColumn() ?: '');
        } catch (Throwable $e) {
            return '';
        }
        return self::renderPaymentInstructions($val);
    }

    /** Pure renderer for the "How to Pay" block. Returns '' for empty/whitespace input. */
    public static function renderPaymentInstructions(string $val): string
    {
        $val = trim($val);
        if ($val === '') {
            return '';
        }
        return '<div style="margin:0 0 20px;padding:14px 16px;background:#E8F3F0;border-radius:8px;'
             . 'font-size:14px;color:#0D3B2E;line-height:1.6;font-family:\'Helvetica Neue\',Arial,sans-serif;">'
             . '<strong style="display:block;margin-bottom:4px;">How to Pay</strong>'
             . nl2br(htmlspecialchars($val))
             . '</div>';
    }
}
