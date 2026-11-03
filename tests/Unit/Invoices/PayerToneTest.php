<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Tone from payment history (plugin bands), one email per payer, one call to action. */
final class PayerToneTest extends TestCase
{
    private const TODAY = '2026-11-03';

    private function hist(array $pairs): array
    {
        return array_map(static function (array $p): array { return ['due_date' => $p[0], 'paid' => $p[1]]; }, $pairs);
    }

    public function testGentleWhenThreeQuartersOnTime(): void
    {
        $t = PayerTone::classify($this->hist([['2026-01-10', '2026-01-09'], ['2026-02-10', '2026-02-10 14:00:00'], ['2026-03-10', '2026-03-20'], ['2026-04-10', '2026-04-01']]));
        self::assertSame('gentle', $t['tone']);
        self::assertSame(0.75, $t['on_time_share']);
        self::assertSame('3 of 4 paid on time (75%)', $t['reason']);
    }

    public function testNeutralWhenLateInAQuarterToHalfOrTooFewInvoices(): void
    {
        self::assertSame('neutral', PayerTone::classify($this->hist([['2026-01-10', '2026-01-20'], ['2026-02-10', '2026-02-10'], ['2026-03-10', '2026-03-20'], ['2026-04-10', '2026-04-01']]))['tone']); // 50% late
        self::assertSame('neutral', PayerTone::classify($this->hist([['2026-01-10', '2026-01-09'], ['2026-02-10', '2026-02-09']]))['tone']);        // 2 invoices, both on time
        $none = PayerTone::classify([]);
        self::assertSame('neutral', $none['tone']);
        self::assertSame('no paid invoices on record', $none['reason']);
        self::assertNull($none['on_time_share']);
    }

    public function testFirmWhenLateMoreThanHalfTheTime(): void
    {
        $t = PayerTone::classify($this->hist([['2026-01-10', '2026-01-20'], ['2026-02-10', '2026-02-25'], ['2026-03-10', '2026-03-09']]));
        self::assertSame('firm', $t['tone']);
        self::assertSame(2, $t['late']);
    }

    public function testRowsWithoutDatesAreNotCounted(): void
    {
        $t = PayerTone::classify($this->hist([['2026-01-10', ''], ['', '2026-02-09'], ['2026-03-10', '2026-03-09']]));
        self::assertSame(1, $t['n']);
    }

    public function testSingleInvoiceWordingPerToneHasOneCallToAction(): void
    {
        $inv = [['invoice_number' => 'INV-2026-0400', 'balance_due' => 525.06, 'due_date' => '2026-10-20']];
        $g = PayerTone::overdueEmail('gentle', $inv, 'Ann', self::TODAY);
        self::assertSame('Quick reminder: Invoice INV-2026-0400 for $525.06', $g['subject']);
        self::assertStringContainsString('I know things get busy', $g['html']);
        self::assertStringContainsString('14 days ago', $g['html']);
        self::assertStringNotContainsString('remit by', $g['html']);

        $n = PayerTone::overdueEmail('neutral', $inv, '', self::TODAY);
        self::assertSame('Following up: Invoice INV-2026-0400 — $525.06 past due', $n['subject']);
        self::assertStringContainsString('Hi there,', $n['html']);
        self::assertStringNotContainsString('things get busy', $n['html']);

        $f = PayerTone::overdueEmail('firm', $inv, 'Ann', self::TODAY);
        self::assertSame('Past due notice: Invoice INV-2026-0400 — $525.06 (14 days overdue)', $f['subject']);
        self::assertStringContainsString('Please remit by <strong>November 10, 2026</strong>', $f['html']);
        self::assertSame(1, substr_count($f['html'], 'button below'));          // one call to action
        self::assertStringNotContainsString('reply and tell us', $f['html']);     // no second ask
    }

    public function testManyInvoicesAreOneEmailWithTheCombinedTotal(): void
    {
        $inv = [['invoice_number' => 'INV-1', 'balance_due' => 100, 'due_date' => '2026-10-01', 'url' => 'https://x.test/i?token=a&b'],
                ['invoice_number' => 'INV-2', 'balance_due' => 250.5, 'due_date' => '2026-10-20']];
        $e = PayerTone::overdueEmail('firm', $inv, 'Pat', self::TODAY);
        self::assertSame('Past due notice: 2 invoices — $350.50 (33 days overdue)', $e['subject']);
        self::assertStringContainsString('<li>Invoice <strong>INV-1</strong>', $e['html']);
        self::assertStringContainsString('Together that is <strong>$350.50</strong>', $e['html']);
        self::assertStringContainsString('<a href="https://x.test/i?token=a&amp;b">view</a>', $e['html']);
        $e = PayerTone::overdueEmail('gentle', $inv, 'Pat', self::TODAY);
        self::assertSame('Quick reminder: 2 invoices totalling $350.50', $e['subject']);
    }

    public function testHtmlIsEscaped(): void
    {
        $e = PayerTone::overdueEmail('neutral', [['invoice_number' => 'X<b>', 'balance_due' => 1, 'due_date' => '2026-10-01']], '<script>', self::TODAY);
        self::assertStringNotContainsString('<script>', $e['html']);
        self::assertStringContainsString('X&lt;b&gt;', $e['html']);
    }

    public function testSmsHasNoUrlAndFits(): void
    {
        $one = PayerTone::overdueSms([['invoice_number' => 'INV-2026-0400', 'balance_due' => 525.06]]);
        self::assertSame('Mowology: Invoice INV-2026-0400 ($525.06) overdue. Check your email to pay. Questions? (778) 846-9273.', $one);
        $two = PayerTone::overdueSms([['invoice_number' => 'A', 'balance_due' => 1], ['invoice_number' => 'B', 'balance_due' => 2]]);
        self::assertStringContainsString('2 invoices ($3.00)', $two);
        self::assertLessThanOrEqual(160, strlen($two));
        self::assertDoesNotMatchRegularExpression('/https?:|www\.|\.ca\b|\.com\b/', $two);
    }
}
