<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Every quote email subject names the property, so a property manager can tell a dozen apart. */
class QuoteSubjectAddressTest extends TestCase
{
    public function test_address_is_appended(): void
    {
        $this->assertSame('Your quote from Mowology - 1003 Wolfe Avenue',
            QuoteService::subjectWithAddress('Your quote from Mowology', '1003 Wolfe Avenue'));
    }

    public function test_not_doubled_when_the_template_already_has_it(): void
    {
        $this->assertSame('Quote for 1003 wolfe avenue',
            QuoteService::subjectWithAddress('Quote for 1003 wolfe avenue', '1003 Wolfe Avenue'));
    }

    public function test_no_address_leaves_the_subject_alone(): void
    {
        $this->assertSame('Your quote', QuoteService::subjectWithAddress('Your quote', null));
        $this->assertSame('Your quote', QuoteService::subjectWithAddress(' Your quote ', '  '));
    }
}
