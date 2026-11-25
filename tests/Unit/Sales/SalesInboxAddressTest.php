<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fable review 2026-10-12: the sender is the address in <brackets>, never the display name. */
class SalesInboxAddressTest extends TestCase
{
    public function test_display_name_cannot_pose_as_a_contact(): void
    {
        $this->assertSame(['x@evil.com'], SalesInboxService::addresses('client@theirco.ca <x@evil.com>'));
        $this->assertSame(['x@evil.com'], SalesInboxService::addresses('"client@theirco.ca" <x@evil.com>'));
        $this->assertNull(SalesInboxService::classify('client@theirco.ca <x@evil.com>', 'office@mowology.ca', ['client@theirco.ca' => 5]));
        $this->assertSame(5, SalesInboxService::classify('Jodi <Client@TheirCo.ca>', 'office@mowology.ca', ['client@theirco.ca' => 5])['contact_id']);
    }

    public function test_lists_split_on_commas_and_semicolons_outside_quotes(): void
    {
        $this->assertSame(['a@b.ca', 'c@d.com'], SalesInboxService::addresses('Jodi <A@B.ca>, c@d.com'));
        $this->assertSame(['a@b.ca', 'e@f.org'], SalesInboxService::addresses('Smith, Jodi <a@b.ca>; e@f.org'));
        $this->assertSame(['real@x.ca'], SalesInboxService::addresses('"Smith, J <j@k.ca>" <real@x.ca>'));
        $this->assertSame([], SalesInboxService::addresses('bad <a@b.ca> more'));
        $this->assertSame(['x@y.ca'], SalesInboxService::addresses('x@y.ca (Comment z@q.ca)'));
    }
}
