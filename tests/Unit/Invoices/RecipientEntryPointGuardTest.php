<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fails when recipient logic is rebuilt outside its single entry points (plan "one entry point for
 * invoice recipients", 2026-11-24):
 *  - invoices: only InvoiceRouting.php may CALL the legacy resolvers; everything else goes through
 *    InvoiceRouting::resolveInvoiceRecipients() / ::managedFirmRecipients();
 *  - quotes: only QuoteRecipientService.php holds the quote-recipient ladder.
 * Scans app/ and public/ (tests and scripts, which prove parity, are exempt).
 */
class RecipientEntryPointGuardTest extends TestCase
{
    private const LEGACY = [
        'resolvemanagementbillingrecipient', 'companyroutingrecipients', 'determineinvoicerecipients',
        'resolvecompanybillingrecipient', 'getrecipientlistdirect', 'getrecipientlistwithmanager',
        'getrecipientliststrata', 'getpropertywithcompanies',
    ];
    private const ALLOWED = ['app/Modules/Invoices/Services/InvoiceRouting.php'];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<int,string> repo-relative PHP files under app/ and public/ (no vendor) */
    private function phpFiles(): array
    {
        $out = [];
        foreach (['app', 'public'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root() . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $p = str_replace($this->root() . '/', '', $f->getPathname());
                if ($f->getExtension() !== 'php' || strpos($p, '/vendor/') !== false || strpos($p, 'public/crinum/') === 0) continue;
                $out[] = $p;
            }
        }
        sort($out);
        return $out;
    }

    public function testNothingOutsideInvoiceRoutingCallsTheLegacyResolvers(): void
    {
        $offenders = [];
        foreach ($this->phpFiles() as $rel) {
            if (in_array($rel, self::ALLOWED, true)) continue;
            $src = (string)file_get_contents($this->root() . '/' . $rel);
            if (!preg_match('/' . implode('|', self::LEGACY) . '/i', $src)) continue;   // cheap pre-filter
            $tokens = token_get_all($src);
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || !in_array(strtolower($tokens[$i][1]), self::LEGACY, true)) continue;
                $prev = null; $next = null;
                for ($k = $i - 1; $k >= 0; $k--) { if (!is_array($tokens[$k]) || !in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $prev = $tokens[$k]; break; } }
                for ($k = $i + 1; $k < $n; $k++) { if (!is_array($tokens[$k]) || !in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $next = $tokens[$k]; break; } }
                $isCall = $next === '(' && !(is_array($prev) && in_array($prev[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true));
                if ($isCall) { $offenders[] = $rel . ':' . $tokens[$i][2] . ' ' . $tokens[$i][1] . '()'; }
            }
        }
        $this->assertSame([], $offenders,
            "Recipients must come from InvoiceRouting::resolveInvoiceRecipients() or ::managedFirmRecipients(), not the legacy resolvers:\n" . implode("\n", $offenders));
    }

    public function testTheQuoteRecipientLadderLivesOnlyInQuoteRecipientService(): void
    {
        $offenders = [];
        foreach ($this->phpFiles() as $rel) {
            if ($rel === 'app/Modules/Quotes/Services/QuoteRecipientService.php') continue;
            $src = (string)file_get_contents($this->root() . '/' . $rel);
            if (strpos($src, "'prop_contact_email'") !== false && strpos($src, "'qr_email'") !== false && strpos($src, "'billing_email'") !== false
                && preg_match('/\?\?\s*\$\w+\[\'prop_contact_email\'\]/', $src)) {
                $offenders[] = $rel;
            }
        }
        $this->assertSame([], $offenders, "The quote recipient ladder must live only in QuoteRecipientService::resolveContact():\n" . implode("\n", $offenders));
    }

    public function testInvoiceScreenHasNoClientSideContactShortcut(): void
    {
        $src = (string)file_get_contents($this->root() . '/public/crm/invoices/create.php');
        $this->assertStringNotContainsString('Contact-linked visit', $src);
        $this->assertStringNotContainsString('resolveManagementBillingRecipient', $src);
    }
}
