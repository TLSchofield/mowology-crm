<?php
use PHPUnit\Framework\TestCase;

class WeatherActionGuardTest extends TestCase
{
    public function testReadingTheListIsUnchanged(): void
    {
        $this->assertNull(WeatherActionGuard::reject('GET', false, false));
    }

    public function testAPostWithoutAValidTokenIsRefused(): void
    {
        $this->assertSame(403, WeatherActionGuard::reject('POST', false, true)[0]);
    }

    public function testAPostWithoutJobsEditIsRefused(): void
    {
        $r = WeatherActionGuard::reject('post', true, false);
        $this->assertSame(403, $r[0]);
        $this->assertStringContainsString('jobs.edit', $r[1]);
    }

    public function testAValidPostFromAnEditorGoesAhead(): void
    {
        $this->assertNull(WeatherActionGuard::reject('POST', true, true));
    }
}
