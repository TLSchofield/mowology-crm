<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class JobberLineParserTest extends TestCase
{
    public function test_parses_jobber_sample_and_filters_cleanup(): void
    {
        $text = 'Bi-Weekly Lawn Cut Service - 割草 (18, $0.00), MAINTENANCE SPRING CLEAN UP (1, $0.00), Spring Clean Up (1, $950.00), Hanging Baskets (7, $910.00)';
        $lines = JobberLineParser::parse($text);
        $this->assertCount(4, $lines);
        $this->assertSame('Bi-Weekly Lawn Cut Service - 割草', $lines[0]['name']);
        $this->assertSame([0.0, 0.0, 950.0, 910.0], array_column($lines, 'amount'));
        $this->assertCount(2, JobberLineParser::forService($lines, 'cleanup'));
    }

    public function test_thousands_negative_and_complex_name(): void
    {
        $lines = JobberLineParser::parse('Beds, Planters (east) (2.5, $1,250.00), Credit (-1, -$50.00) trailing text');
        $this->assertSame('Beds, Planters (east)', $lines[0]['name']);
        $this->assertSame(2.5, $lines[0]['qty']);
        $this->assertSame(1250.0, $lines[0]['amount']);
        $this->assertSame(-1.0, $lines[1]['qty']);
        $this->assertSame(-50.0, $lines[1]['amount']);
    }

    public function test_empty_and_null_are_empty(): void
    {
        $this->assertSame([], JobberLineParser::parse(''));
        $this->assertSame([], JobberLineParser::parse(null));
    }
}
