<?php
/**
 * MwCsp — per-request nonce Content-Security-Policy for the PUBLIC site and the CUSTOMER portal.
 *
 * Fable #22 (2026-10-12): the site-wide CSP in public/.htaccess allows 'unsafe-inline' and
 * 'unsafe-eval', so any HTML injection becomes script execution. Public pages (head.php) and
 * customer pages (public/customer/*.php) now send this policy from PHP instead: scripts run only
 * from our origin, the listed hosts, or an inline <script nonce="…"> carrying this request's nonce.
 * No inline event handlers (onclick=…) — they must be addEventListener in a nonce'd script.
 *
 * public/.htaccess skips its own header for these URLs (env MW_NONCE_CSP). If both ever arrive the
 * browser enforces both, which is still this stricter policy (its hosts are a subset).
 * The CRM (AppStack) keeps the .htaccess policy for now — follow-up.
 *
 * Usage:  MwCsp::send();                         before any output
 *         <script nonce="<?= MwCsp::nonce() ?>">  every inline script
 *
 * No namespace / no autoloader.
 */
declare(strict_types=1);

if (!class_exists('MwCsp', false)) {
    final class MwCsp
    {
        private static ?string $nonce = null;
        private static bool $sent = false;

        public static function nonce(): string
        {
            if (self::$nonce === null) {
                self::$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            }
            return self::$nonce;
        }

        /** ` nonce="…"` for templates. */
        public static function attr(): string
        {
            return ' nonce="' . self::nonce() . '"';
        }

        public static function policy(?string $nonce = null): string
        {
            $n = $nonce ?? self::nonce();
            return implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$n}' https://js.stripe.com https://maps.googleapis.com https://www.google.com https://www.gstatic.com https://www.recaptcha.net",
                "script-src-attr 'none'",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                "img-src 'self' data: blob: https://maps.googleapis.com https://maps.gstatic.com https://*.google.com https://*.tile.openstreetmap.org https://a.tile.openstreetmap.org https://b.tile.openstreetmap.org https://c.tile.openstreetmap.org https://server.arcgisonline.com",
                "connect-src 'self' https://maps.googleapis.com https://api.open-meteo.com https://api.weather.gc.ca https://api.stripe.com https://server.arcgisonline.com",
                "frame-src 'self' https://www.google.com https://www.recaptcha.net https://js.stripe.com",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'self'",
            ]);
        }

        /** Send the header once. Returns false when output already started (caller may use meta()). */
        public static function send(): bool
        {
            if (self::$sent) return true;
            if (PHP_SAPI === 'cli' || headers_sent()) return false;
            header('Content-Security-Policy: ' . self::policy());
            self::$sent = true;
            return true;
        }

        /** Fallback when headers are gone: a <meta> CSP (enforces script-src; not frame-ancestors). */
        public static function meta(): string
        {
            if (self::$sent) return '';
            $p = preg_replace('/;\s*frame-ancestors[^;]*/', '', self::policy());
            return '<meta http-equiv="Content-Security-Policy" content="' . htmlspecialchars((string)$p, ENT_QUOTES, 'UTF-8') . '">';
        }
    }
}
