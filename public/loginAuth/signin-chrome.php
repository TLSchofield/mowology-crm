<?php
/**
 * Ittai sign-in chrome (Design System v1.2, 2026-10-23): the <head> links and the lockup shared by
 * login.php, forgot_password.php and reset_password.php, on every business's address.
 * Styles: /crm/css/ittai-signin.css on top of ittai-tokens.css. Defines functions only — prints nothing itself.
 */
require_once APP_ROOT . '/Services/BusinessIdentity.php';
require_once APP_ROOT . '/Core/Tenant.php';

if (!function_exists('itSigninBizName')) {
    /** The business's own name; never another business's. */
    function itSigninBizName(): string
    {
        return (string)(BusinessIdentity::get()['name'] ?? '') ?: 'Ittai';
    }

    /**
     * Body classes. Only the original business has generated colour tokens today (ittai-tokens.css); every other
     * business gets Ittai's neutral navy action, never another business's colour.
     */
    function itSigninBodyClass(): string
    {
        return 'it-design it-signin' . (Tenant::isDefault() ? '' : ' it-signin--neutral');
    }

    /** Everything in <head> after the opening tag. */
    function itSigninHead(string $title): void
    {
        $v = function (string $path): string {
            $f = PUBLIC_ROOT . $path;
            return $path . '?v=' . (is_file($f) ? filemtime($f) : '1');
        };
        echo '<meta charset="UTF-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n"
            . '<meta name="robots" content="noindex">' . "\n"
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>' . "\n"
            . '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
            . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Zen+Kaku+Gothic+New:wght@500&display=swap" rel="stylesheet">' . "\n"
            . '<link href="' . $v('/crm/css/ittai-tokens.css') . '" rel="stylesheet">' . "\n"
            . '<link href="' . $v('/crm/css/ittai-signin.css') . '" rel="stylesheet">' . "\n";
    }

    /**
     * Lockup B: the IttaiSymbol (v1.2 frozen, 64 row — drawn at 64 px) in currentColor + "Ittai 一体".
     * Geometry copied unchanged from assets/symbol/ittai-symbol-64.svg.
     */
    function itSigninLockup(): void
    {
        echo <<<'SVG'
<div class="it-signin-lockup">
  <svg class="it-signin-symbol" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
    <g fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-dasharray="75.34 24.66" stroke-dashoffset="-12.33">
      <path pathLength="100" d="M18.96 9.42 A6 6 0 0 1 21.96 8.62 L42.04 8.62 A6 6 0 0 1 45.04 9.42"/>
      <path pathLength="100" d="M45.04 9.42 A6 6 0 0 1 47.23 11.62 L57.27 29 A6 6 0 0 1 58.07 32"/>
      <path pathLength="100" d="M58.07 32 A6 6 0 0 1 57.27 35 L47.23 52.38 A6 6 0 0 1 45.04 54.58"/>
      <path pathLength="100" d="M45.04 54.58 A6 6 0 0 1 42.04 55.38 L21.96 55.38 A6 6 0 0 1 18.96 54.58"/>
      <path pathLength="100" d="M18.96 54.58 A6 6 0 0 1 16.77 52.38 L6.73 35 A6 6 0 0 1 5.93 32"/>
      <path pathLength="100" d="M5.93 32 A6 6 0 0 1 6.73 29 L16.77 11.62 A6 6 0 0 1 18.96 9.42"/>
    </g>
    <g stroke="currentColor" stroke-width="1.3"><line x1="32" y1="20" x2="32" y2="14"/><line x1="42.39" y1="26" x2="47.59" y2="23"/><line x1="42.39" y1="38" x2="47.59" y2="41"/><line x1="32" y1="44" x2="32" y2="50"/><line x1="21.61" y1="38" x2="16.41" y2="41"/><line x1="21.61" y1="26" x2="16.41" y2="23"/></g>
    <circle cx="32" cy="32" r="9" fill="none" stroke="currentColor" stroke-width="2.5"/>
  </svg>
  <span class="it-signin-word">Ittai<span lang="ja">一体</span></span>
</div>
SVG;
    }
}
