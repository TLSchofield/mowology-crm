<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The article generator must never be told to invent a case study (Ittai audit 2026-10-27;
 * conflicts with the honesty position of the Terms of Service). With no real project the
 * prompt tells the model to leave case_study null; with one it passes the real facts.
 */
class ArticleGeneratorHonestyTest extends TestCase
{
    private function prompts(string $projectDetails): array
    {
        $svc = (new ReflectionClass(ArticleGeneratorService::class))->newInstanceWithoutConstructor();
        $sys = new ReflectionMethod($svc, 'buildSystemPrompt');
        $usr = new ReflectionMethod($svc, 'buildUserPrompt');
        $sys->setAccessible(true);
        $usr->setAccessible(true);
        return [
            $sys->invoke($svc),
            $usr->invoke($svc, 'lawn aeration', 'Vancouver', 'lawn aeration', 'fall', $projectDetails, 1800, []),
        ];
    }

    public function testSystemPromptNeverAsksToInvent(): void
    {
        [$sys] = $this->prompts('');
        $this->assertStringNotContainsString('create a realistic', $sys);
        $this->assertStringContainsString('never invent', $sys);
    }

    public function testNoProjectMeansNoCaseStudy(): void
    {
        [, $usr] = $this->prompts('');
        $this->assertStringNotContainsString('invent a realistic', $usr);
        $this->assertStringContainsString('do NOT write a case study', $usr);
    }

    public function testRealProjectIsPassedThrough(): void
    {
        [, $usr] = $this->prompts('Strata lot, 4,200 sq ft, aerated Oct 3');
        $this->assertStringContainsString('Real project to use as case study: Strata lot', $usr);
        $this->assertStringNotContainsString('do NOT write a case study', $usr);
    }
}
