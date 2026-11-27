<?php
declare(strict_types=1);

/**
 * SignUpCodeEmail — the one email a new owner gets with their sign-up code (Tim, 2026-11-26: "very hard to read").
 *
 * Quiet by design: one idea, the code, in white-on-indigo blocks (11:1 contrast), how long it lasts, and one line for
 * the person who did not ask. Table layout and inline styles only (mail clients ignore everything else); explicit
 * colours on every cell so dark mode cannot invert it into grey on grey; no images (blocked by default in many clients).
 * The code also sits contiguous in the subject and the hidden preview line, because the blocks split the digits and
 * phones offer "copy code" only for an unbroken number. Pure: no database, no sending (sendCrmEmail does that).
 *
 * No namespace / no autoloader in production: require_once.
 */
final class SignUpCodeEmail
{
    public const BRAND = '#233A5E';
    public const GOLD = '#A8843A';

    /** @return array{subject:string, html:string} */
    public static function build(string $code, int $minutes = 15): array
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        $minutes = max(1, $minutes);
        $safe = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $subject = $code . ' is your Ittai code';

        $cells = '';
        foreach (str_split($code) as $i => $digit) {
            if ($i > 0) $cells .= '<td width="8" style="width:8px;font-size:0;line-height:0;">&nbsp;</td>';
            $cells .= '<td align="center" width="46" height="60" bgcolor="' . self::BRAND . '" style="background-color:' . self::BRAND
                . ';color:#FFFFFF;font-family:Arial,Helvetica,sans-serif;font-size:34px;line-height:60px;font-weight:700;border-radius:8px;width:46px;height:60px;">'
                . htmlspecialchars($digit, ENT_QUOTES, 'UTF-8') . '</td>';
        }

        $unit = $minutes === 1 ? 'minute' : 'minutes';
        $preview = 'Your Ittai code is ' . $safe . '. It expires in ' . $minutes . ' ' . $unit . '.';
        $font = 'font-family:Arial,Helvetica,sans-serif;';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light only">'
            . '<title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title></head>'
            . '<body bgcolor="#F4F5F3" style="margin:0;padding:0;background-color:#F4F5F3;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#F4F5F3;font-size:1px;line-height:1px;">' . $preview . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F4F5F3" style="background-color:#F4F5F3;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFFFFF" style="max-width:420px;background-color:#FFFFFF;border-radius:12px;"><tr><td style="padding:32px 24px 28px 24px;' . $font . 'color:#131D17;">'
            . '<p style="margin:0 0 20px 0;' . $font . 'font-size:22px;line-height:28px;font-weight:700;color:' . self::BRAND . ';letter-spacing:-0.5px;">ittai</p>'
            . '<p style="margin:0 0 18px 0;' . $font . 'font-size:20px;line-height:28px;font-weight:700;color:#131D17;">Your code</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="left" style="margin:0 0 20px 0;"><tr>' . $cells . '</tr></table>'
            . '<div style="clear:both;height:1px;line-height:1px;font-size:1px;">&nbsp;</div>'
            . '<p style="margin:0 0 14px 0;' . $font . 'font-size:18px;line-height:26px;color:#131D17;">It expires in ' . $minutes . ' ' . $unit . '.</p>'
            . '<p style="margin:0 0 14px 0;' . $font . 'font-size:16px;line-height:24px;color:#131D17;"><strong>Keep it to yourself.</strong> It works once, and we will never ask you for it by phone, text or email.</p>'
            . '<p style="margin:0 0 14px 0;' . $font . 'font-size:16px;line-height:24px;color:#48534C;">Ittai keeps every business in its own private database. Your password is stored scrambled, so nobody at Ittai can read it.</p>'
            . '<p style="margin:0 0 14px 0;' . $font . 'font-size:16px;line-height:24px;color:#48534C;">If you did not ask for this, ignore this email. The code stops working on its own.</p>'
            . '<p style="margin:0 0 22px 0;' . $font . 'font-size:16px;line-height:24px;color:#48534C;">Stuck? Reply to this email. A human reads every reply.</p>'
            . '<p style="margin:0;' . $font . 'font-size:15px;line-height:22px;color:#48534C;">Every i dotted. Every t crossed.</p>'
            . '</td></tr></table></td></tr></table></body></html>';

        return ['subject' => $subject, 'html' => $html];
    }
}
