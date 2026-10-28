<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * "Offer autopay" on Yui's overdue-account card. The email is a draft Tim reads and sends; what must hold is WHO may be offered
 * it (an individual client who is the invoice's own contact, not on autopay, with an email and a live invoice link), what the
 * draft says (honest about the open balance, no pressure, no exclamation marks), that a text never carries the link, and that
 * Tim's edited version can never store one client's private link as the next client's draft. Names and numbers are invented.
 */
class YuiAutopayOfferTest extends TestCase
{
    private const NOW = '2026-10-28 09:00:00';

    private function in(array $over = []): array
    {
        return array_replace_recursive([
            'payer_contact_id' => 37,
            'invoices' => [
                ['id' => 407, 'contact_id' => 37, 'company_id' => null, 'access_token' => 'tokOLD', 'token_expires_at' => null],
                ['id' => 418, 'contact_id' => 37, 'company_id' => null, 'access_token' => 'tokNEW', 'token_expires_at' => '2026-12-01 00:00:00'],
            ],
            'contact' => ['autopay_enabled' => 0, 'brand' => 'visa', 'last4' => '5760'],
            'live_mode' => true, 'to_contact_id' => 37, 'to_email' => 'pat@example.test', 'now' => self::NOW, 'base_url' => 'https://example.test/',
        ], $over);
    }

    public function testAnIndividualOverdueClientIsOffered(): void
    {
        $r = YuiAutopayOffer::decide($this->in());
        $this->assertTrue($r['offer']);
        $this->assertSame('https://example.test/customer/autopay.php?token=tokNEW', $r['link'], 'the newest invoice with a live link');
        $this->assertSame('Visa ending 5760', $r['card']);
    }

    public function testANewerOpenInvoiceSuppliesTheLinkWhenTheOldOnesHaveExpired(): void
    {
        // The overdue account lists only the 60-day-old invoices, whose links have expired; the client's newer open invoice has a live one.
        $in = $this->in();
        $in['invoices'] = [['id' => 100, 'contact_id' => 37, 'company_id' => null, 'access_token' => 'tokDEAD', 'token_expires_at' => '2026-09-01 00:00:00']];
        $in['token_candidates'] = array_merge($in['invoices'], [['id' => 500, 'contact_id' => 37, 'company_id' => null, 'access_token' => 'tokLIVE', 'token_expires_at' => '2026-12-01 00:00:00']]);
        $r = YuiAutopayOffer::decide($in);
        $this->assertTrue($r['offer']);
        $this->assertStringContainsString('tokLIVE', $r['link']);
        // ...but never a link that belongs to a different contact.
        $in['token_candidates'][1]['contact_id'] = 99;
        $this->assertFalse(YuiAutopayOffer::decide($in)['offer']);
    }

    public function testAnExpiredLinkIsSkippedAndNoneLeftMeansNoOffer(): void
    {
        $in = $this->in();
        $in['invoices'][1]['token_expires_at'] = '2026-10-01 00:00:00';
        $this->assertStringContainsString('tokOLD', YuiAutopayOffer::decide($in)['link']);
        $in['invoices'][0]['access_token'] = '';
        $r = YuiAutopayOffer::decide($in);
        $this->assertFalse($r['offer']);
        $this->assertStringContainsString('live invoice link', $r['why']);
    }

    /** @dataProvider refusals */
    public function testWhoIsNotOffered(array $over, string $why): void
    {
        $r = YuiAutopayOffer::decide(array_replace($this->in(), $over));
        $this->assertFalse($r['offer']);
        $this->assertNull($r['link']);
        $this->assertStringContainsString($why, (string)$r['why']);
    }

    public static function refusals(): array
    {
        $co = [['id' => 1, 'contact_id' => 37, 'company_id' => 9, 'access_token' => 't', 'token_expires_at' => null]];
        $mixed = [['id' => 1, 'contact_id' => 37, 'company_id' => null, 'access_token' => 't', 'token_expires_at' => null],
                  ['id' => 2, 'contact_id' => 38, 'company_id' => null, 'access_token' => 't', 'token_expires_at' => null]];
        return [
            'a company or strata payer'      => [['payer_contact_id' => 0], 'company, strata or property manager'],
            'billed to a company'            => [['invoices' => $co], 'billed to a company'],
            'invoices for two people'        => [['invoices' => $mixed], 'more than one person'],
            'already on autopay'             => [['contact' => ['autopay_enabled' => 1, 'brand' => 'visa', 'last4' => '5760']], 'Already on autopay'],
            'charging switched off'          => [['live_mode' => false], 'switched off'],
            'charging state unknown'         => [['live_mode' => null], 'switched off'],
            'note goes to someone else'      => [['to_contact_id' => 99], 'someone other than'],
            'no email'                       => [['to_email' => ' '], 'No email address'],
            'contact missing'                => [['contact' => null], 'could not be found'],
            'no invoices'                    => [['invoices' => []], 'No open invoices'],
        ];
    }

    // ── The draft ───────────────────────────────────────────────────────────

    private function item(array $more = []): array
    {
        return $more + ['template' => 'autopay', 'to' => ['first_name' => 'Pat', 'name' => 'Pat Rivera', 'contact_id' => 37],
                'place' => '12 Example Avenue', 'invoices' => [['number' => 'INV-1'], ['number' => 'INV-2'], ['number' => 'INV-3']], 'total' => 151.2,
                'oldest_due' => '2026-08-22', 'autopay' => ['offer' => true, 'link' => 'https://example.test/customer/autopay.php?token=SECRETtok123']];
    }

    public function testTheEmailSaysWhatWillHappen(): void
    {
        $d = YuiRules::draft($this->item(), 'Tim');
        $this->assertSame('Autopay for 12 Example Avenue', $d['subject']);
        $b = $d['body'];
        $this->assertStringContainsString('Hi Pat,', $b);
        $this->assertStringContainsString('3 open invoices ($151.20)', $b);
        $this->assertStringContainsString('The $151.20 that\'s open now is taken at our next billing run', $b, 'honest: enrolling also takes the open balance');
        $this->assertStringContainsString("Saving the card doesn't charge it on the spot", $b);
        $this->assertStringContainsString('stop it any time by calling me on (778) 846-9273', $b);
        $this->assertStringContainsString('https://example.test/customer/autopay.php?token=SECRETtok123', $b);
        $this->assertStringEndsWith("Thanks,\nTim", $b);
        $this->assertStringNotContainsString('!', $b, 'house rule: no exclamation marks');
        $this->assertDoesNotMatchRegularExpression('/just checking in|touching base|circling back|\{/i', $b . $d['subject']);
    }

    public function testASingleInvoiceReadsRight(): void
    {
        $b = YuiRules::draft($this->item(['invoices' => [['number' => 'INV-1']], 'total' => 50.4]), 'Tim')['body'];
        $this->assertStringContainsString('about an open invoice ($50.40)', $b);
    }

    public function testTheTextNeverCarriesTheLink(): void
    {
        $d = YuiRules::draft($this->item(), 'Tim');
        $this->assertSame([], YuiRules::smsProblems($d['sms']));
        $this->assertStringNotContainsString('http', $d['sms']);
        $this->assertStringNotContainsString('SECRETtok123', $d['sms']);
        $this->assertStringContainsString('autopay', $d['sms']);
        $this->assertLessThanOrEqual(160, strlen($d['sms']));
    }

    public function testAnEditedVersionDoesNotStoreThisClientsPrivateLink(): void
    {
        $item = $this->item();
        $edited = YuiRules::draft($item, 'Tim')['body'] . "\n\nPS the fence is done.";
        $learned = YuiRules::learnFrom($edited, $item, 'Tim');
        $this->assertStringNotContainsString('SECRETtok123', $learned);
        $this->assertStringContainsString('{autopay_link}', $learned);
        // Reused for the next client, it gets THEIR link, not Pat's.
        $next = $this->item(['to' => ['first_name' => 'Lee', 'name' => 'Lee Wu', 'contact_id' => 40],
                              'autopay' => ['offer' => true, 'link' => 'https://example.test/customer/autopay.php?token=OTHERtok999']]);
        $b = YuiRules::draft($next, 'Tim', $learned)['body'];
        $this->assertStringContainsString('OTHERtok999', $b);
        $this->assertStringNotContainsString('SECRETtok123', $b);
        $this->assertStringContainsString('Hi Lee,', $b);
    }

    public function testSendRefusesAnAutopayOfferThatTheServerDoesNotAllow(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $svc = new YuiDraftService($db);
        $item = $this->item(['template' => 'arrears', 'autopay' => ['offer' => false, 'why' => 'Already on autopay.']]);
        $r = $svc->send($item, ['template' => 'autopay', 'channel' => 'email', 'body' => 'x'], ['id' => 1], 'Tim');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString("can't offer autopay", $r['message']);
        $this->assertStringContainsString('Already on autopay', $r['message']);
    }

    public function testEditingTheLinkNeverLeaksItIntoTheLearnedTemplate(): void
    {
        $item = $this->item();
        foreach ([
            'https://example.test/customer/autopay.php?token=SECRETtok123',   // untouched
            'example.test/customer/autopay.php?token=SECRETtok123',           // https:// deleted
            'https://exampel.test/customer/autopay.php?token=SECRETtok123',   // hostname typo
            'https://example.test/customer/autopay.php?token=SECRETtok123.', // stray punctuation
        ] as $variant) {
            $body = "Hi Pat,\n\nSet it up here: $variant\n\nThanks,\nTim";
            $learned = YuiRules::learnFrom($body, $item, 'Tim');
            $this->assertStringNotContainsString('SECRETtok123', $learned, $variant);
            $this->assertStringContainsString('{autopay_link}', $learned, $variant);
        }
        $this->assertStringNotContainsString('SECRETtok123', YuiRules::learnFrom("Use token=SECRETtok123 please", $item, 'Tim'));
    }

    public function testAnotherClientsLinkInTheBodyIsRefusedBeforeAnythingIsStored(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $svc = new YuiDraftService($db);
        $item = $this->item(['template' => 'arrears']);   // server-derived link for THIS client: ...token=SECRETtok123
        $body = "Hi Pat,\n\nHere: https://example.test/customer/autopay.php?token=SOMEONEelse456\n\nTim";
        $r = $svc->send($item, ['template' => 'autopay', 'channel' => 'email', 'subject' => 's', 'body' => $body], ['id' => 1], 'Tim');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString("isn't this client's", $r['message']);
        // And a body carrying BOTH links is refused too.
        $both = $body . "\nhttps://example.test/customer/autopay.php?token=SECRETtok123";
        $this->assertFalse($svc->send($item, ['template' => 'autopay', 'channel' => 'email', 'subject' => 's', 'body' => $both], ['id' => 1], 'Tim')['ok']);
    }

    public function testTheStoredCopyOfTheEmailHasNoLiveToken(): void
    {
        $red = YuiDraftService::redactAutopayToken("Set it up: https://example.test/customer/autopay.php?token=SECRETtok123\nThanks");
        $this->assertStringNotContainsString('SECRETtok123', $red);
        $this->assertStringContainsString('/customer/autopay.php?token=[link]', $red);
        $this->assertSame('Nothing to hide here.', YuiDraftService::redactAutopayToken('Nothing to hide here.'));
    }
}
