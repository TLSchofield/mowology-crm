<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LineItemFitTest extends TestCase
{
    public function test_adds_up_to_amount_or_total_and_includes_tolerance_edges(): void
    {
        $items = [['line_total' => 4.00], ['amount' => '5.95']];
        $this->assertTrue(LineItemFit::addsUp($items, 10.00, 20.00));
        // Tax-in prices (fuel): the items match the total although the subtotal differs.
        $this->assertTrue(LineItemFit::addsUp($items, 9.48, 9.95));
        $this->assertFalse(LineItemFit::addsUp($items, 10.01, 10.20));
        $this->assertTrue(LineItemFit::addsUp($items, null, 10.00));
        $this->assertTrue(LineItemFit::addsUp([['amount' => 9.95]], 10.00, null));
        $this->assertFalse(LineItemFit::addsUp([['amount' => 9.94]], 10.00, null));
        $this->assertFalse(LineItemFit::addsUp([], 10.00, 10.00));
    }

    public function test_removed_lines_are_ignored(): void
    {
        $items = [['amount' => 7.00], ['line_total' => 99.00, 'removed' => true], ['line_total' => 3.00]];
        $this->assertSame(10.0, LineItemFit::sum($items));
        $this->assertTrue(LineItemFit::addsUp($items, 10.00, null));
    }

    public function test_read_stored_recognises_each_shape_and_empty(): void
    {
        $vision = ['responses' => [['fullTextAnnotation' => ['text' => "Vision\ntext"], 'textAnnotations' => []]]];
        $this->assertSame(['text' => "Vision\ntext", 'raw_response' => $vision, 'format' => 'vision_raw'], LineItemFit::readStored(json_encode($vision)));
        $this->assertSame(['text' => 'Inbox text', 'raw_response' => null, 'format' => 'inbox'],
                          LineItemFit::readStored(json_encode(['text' => 'Inbox text', 'parsed' => [], 'source' => 'body'])));
        $this->assertSame(['text' => 'Encoded text', 'raw_response' => null, 'format' => 'text'], LineItemFit::readStored(json_encode('Encoded text')));
        $this->assertSame(['text' => 'plain text', 'raw_response' => null, 'format' => 'text'], LineItemFit::readStored('plain text'));
        $this->assertSame(['text' => '', 'raw_response' => null, 'format' => 'none'], LineItemFit::readStored(null));
        $this->assertSame('none', LineItemFit::readStored('  ')['format']);
    }
}
