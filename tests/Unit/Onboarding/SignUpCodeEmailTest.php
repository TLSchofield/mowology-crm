<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SignUpCodeEmailTest extends TestCase
{
    public function test_code_is_in_the_subject_and_preview_unbroken_and_each_digit_in_its_own_indigo_block(): void
    {
        $m = SignUpCodeEmail::build('214597', 15);
        $this->assertSame('214597 is your Ittai code', $m['subject']);
        $this->assertStringContainsString('Your Ittai code is 214597. It expires in 15 minutes.', $m['html']);
        $this->assertSame(6, substr_count($m['html'], 'bgcolor="#233A5E"'));
        foreach (str_split('214597') as $d) {
            $this->assertMatchesRegularExpression('/bgcolor="#233A5E"[^>]*>' . $d . '<\/td>/', $m['html']);
        }
        $this->assertStringContainsString('color:#FFFFFF', $m['html']);
    }

    public function test_it_is_light_only_with_explicit_colours_and_no_images_or_exclamations(): void
    {
        $html = SignUpCodeEmail::build('000111')['html'];
        $this->assertStringContainsString('color-scheme', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('!', str_replace('<!doctype', '', $html));
        $this->assertStringContainsString('It expires in 15 minutes.', $html);
        $this->assertStringContainsString('If you did not ask for this, ignore this email.', $html);
        $this->assertStringContainsString('we will never ask you for it by phone, text or email', $html);
        $this->assertStringContainsString('its own private database', $html);
        $this->assertStringContainsString('stored scrambled', $html);
        $this->assertStringNotContainsStringIgnoringCase('bank-grade', $html);
        $this->assertStringNotContainsStringIgnoringCase('military', $html);
        $this->assertStringNotContainsStringIgnoringCase('100% secure', $html);
    }

    public function test_input_is_cleaned_to_digits_and_minutes_have_a_floor(): void
    {
        $m = SignUpCodeEmail::build(' 12<b>3 ', 0);
        $this->assertSame('123 is your Ittai code', $m['subject']);
        $this->assertStringNotContainsString('<b>', $m['html']);
        $this->assertStringContainsString('It expires in 1 minute.', $m['html']);
    }
}
