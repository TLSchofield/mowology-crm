<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Operations/Services/SupplierContactService.php';

final class SupplierContactsTest extends TestCase
{
    /** @dataProvider roleCases */
    public function testRoleOf(string $email, string $context, string $expected): void
    {
        $this->assertSame($expected, SupplierContactService::roleOf($email, $context));
    }

    public static function roleCases(): array
    {
        return [
            ['wholesale@greenleaf-wholesale.example', '', 'wholesale'],
            ['orders@greenleaf-wholesale.example', '', 'orders'],
            ['sales@greenleaf-wholesale.example', '', 'sales'],
            ['billing@greenleaf-wholesale.example', '', 'accounts'],
            ['jsmith@greenleaf-wholesale.example', 'Jane Doe, Wholesale Manager', 'wholesale'],
            ['hello@greenleaf-wholesale.example', 'Questions', 'general'],
        ];
    }

    public function testRankRewardsUsefulSourcesAndDecisions(): void
    {
        $general = ['role' => 'general', 'sources' => 'website', 'status' => 'suggested'];
        $wholesale = ['role' => 'wholesale', 'sources' => 'mail|website', 'status' => 'suggested'];
        $kept = ['role' => 'accounts', 'sources' => '', 'status' => 'kept'];
        $dropped = ['role' => 'wholesale', 'sources' => 'mail|website', 'status' => 'dropped'];
        $this->assertGreaterThan(SupplierContactService::rank($general), SupplierContactService::rank($wholesale));
        $this->assertGreaterThan(SupplierContactService::rank($wholesale), SupplierContactService::rank($kept));
        $this->assertLessThan(SupplierContactService::rank($general), SupplierContactService::rank($dropped));
    }

    public function testEmailsInHtmlFindsAndFiltersSupplierAddresses(): void
    {
        $html = <<<'HTML'
          <a href="mailto:Sales@greenleaf-wholesale.example">Email sales</a>
          <p>Jane Doe, Wholesale Manager: jdoe@greenleaf-wholesale.example</p>
          <p>Orders: orders [at] shop.greenleaf-wholesale [dot] example</p>
          <p>Other other@bark-and-stone.example logo@2x.png</p>
HTML;
        $rows = SupplierContactService::emailsInHtml($html, 'greenleaf-wholesale.example');
        $byEmail = array_column($rows, null, 'email');
        $emails = array_keys($byEmail);
        sort($emails);
        $this->assertSame(['jdoe@greenleaf-wholesale.example', 'orders@shop.greenleaf-wholesale.example', 'sales@greenleaf-wholesale.example'], $emails);
        $this->assertSame('Jane Doe', $byEmail['jdoe@greenleaf-wholesale.example']['person']);
    }

    public function testSharedDomainOnlyKeepsKnownExactAddress(): void
    {
        $rows = SupplierContactService::emailsInHtml('<p>known@gmail.com other@gmail.com</p>', 'gmail.com', 'known@gmail.com');
        $this->assertSame(['known@gmail.com'], array_column($rows, 'email'));
    }

    public function testWholesaleLinksStayOnHostPrioritizeAndCap(): void
    {
        $html = '<a href="/contact">Contact</a><a href="/about">About</a><a href="/wholesale">Wholesale</a>'
              . '<a href="/trade-program">Trade</a><a href="/professional">Professional</a><a href="/contractor">Contractor</a>'
              . '<a href="/landscaper">Landscaper</a><a href="https://elsewhere.example/wholesale">Elsewhere</a>'
              . '<a href="/?account=login">Account</a><a href="mailto:sales@greenleaf-wholesale.example">Email</a>';
        $links = SupplierContactService::wholesaleLinks($html, 'https://greenleaf-wholesale.example/catalog/page');
        $this->assertCount(6, $links);
        $this->assertSame('https://greenleaf-wholesale.example/wholesale', $links[0]);
        $this->assertSame('https://greenleaf-wholesale.example/trade-program', $links[1]);
        $this->assertNotContains('https://elsewhere.example/wholesale', $links);
        $this->assertStringNotContainsString('account', implode(' ', $links));
    }

    public function testScriptLinksAndLabelsAreNotPagesOrPeople(): void
    {
        $html = '<a href="javascript:__doPostBack(\'ctl00$lnkContactUs\',\'\')">Contact Us</a><a href="tel:6045550100">Call trade desk</a>'
              . '<a href="/contact-us">Contact</a>';
        $this->assertSame(['https://greenleaf-wholesale.example/contact-us'],
            SupplierContactService::wholesaleLinks($html, 'https://greenleaf-wholesale.example'));
        $rows = SupplierContactService::emailsInHtml('<p>General Email: info@greenleaf-wholesale.example</p>', 'greenleaf-wholesale.example');
        $this->assertNull($rows[0]['person']);
    }

    public function testOnlyPublicWebPagesAreFetched(): void
    {
        $public = fn($h) => ['8.8.8.8'];
        $this->assertTrue(SupplierContactService::publicUrl('https://greenleaf-wholesale.example/wholesale', $public));
        $this->assertFalse(SupplierContactService::publicUrl('http://127.0.0.1/admin', $public));
        $this->assertFalse(SupplierContactService::publicUrl('http://169.254.169.254/latest', $public));
        $this->assertFalse(SupplierContactService::publicUrl('http://localhost/', $public));
        $this->assertFalse(SupplierContactService::publicUrl('https://greenleaf-wholesale.example:8080/', $public));
        $this->assertFalse(SupplierContactService::publicUrl('ftp://greenleaf-wholesale.example/', $public));
        $this->assertFalse(SupplierContactService::publicUrl('https://intranet.example/', fn($h) => ['10.0.0.5']));
        $this->assertFalse(SupplierContactService::publicUrl('https://nowhere.example/', fn($h) => false));
    }

    public function testLabelsAndCompaniesAreNotPeople(): void
    {
        $this->assertTrue(SupplierContactService::looksLikePerson('Jane Doe'));
        $this->assertTrue(SupplierContactService::looksLikePerson('Kayla'));
        $this->assertFalse(SupplierContactService::looksLikePerson('Customer Service'));
        $this->assertFalse(SupplierContactService::looksLikePerson('Greenleaf Wholesale Ltd'));
        $this->assertFalse(SupplierContactService::looksLikePerson(''));
    }

    public function testMergeSourcesUsesStableOrderAndNoDuplicates(): void
    {
        $this->assertSame('mail|website|manual', SupplierContactService::mergeSources('manual|mail', 'website|mail'));
    }
}
