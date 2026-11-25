<?php
declare(strict_types=1);

require_once __DIR__ . '/CrawlResult.php';

class Report
{
    /** @var CrawlResult[] */
    private array $results = [];

    /** @var array<int, array{name:string, status:string, note:string}> */
    private array $mutationResults = [];

    public function add(CrawlResult $r): void
    {
        $this->results[] = $r;
    }

    public function addMutation(string $name, string $status, string $note = ''): void
    {
        $this->mutationResults[] = ['name' => $name, 'status' => $status, 'note' => $note];
    }

    public function printTable(): void
    {
        foreach ($this->results as $r) {
            $consoleCount = count($r->jsConsoleErrors);
            $a11yCount = count($r->a11yIssues);
            $warnCount = count($r->resourceWarnings);
            printf(
                "[%-4s] %-55s %3d  console:%d  a11y:%d  warn:%d%s\n",
                $r->verdict,
                $r->url,
                $r->httpStatus,
                $consoleCount,
                $a11yCount,
                $warnCount,
                $r->note !== '' ? "  <- {$r->note}" : ''
            );
        }

        if ($this->mutationResults) {
            echo "\n-- Mutation flows --\n";
            foreach ($this->mutationResults as $m) {
                printf("[%-4s] %-30s%s\n", $m['status'], $m['name'], $m['note'] !== '' ? "  <- {$m['note']}" : '');
            }
        }

        $this->printSummary();
    }

    private function printSummary(): void
    {
        $total = count($this->results);
        $passed = count(array_filter($this->results, fn($r) => $r->verdict === CrawlResult::PASS));
        $failed = count(array_filter($this->results, fn($r) => $r->verdict === CrawlResult::FAIL));
        $skipped = count(array_filter($this->results, fn($r) => $r->verdict === CrawlResult::SKIP));

        echo "\n";
        printf("Total: %d, Passed: %d, Failed: %d, Skipped (expected-gate): %d\n", $total, $passed, $failed, $skipped);

        if ($this->mutationResults) {
            $mFailed = count(array_filter($this->mutationResults, fn($m) => $m['status'] === CrawlResult::FAIL));
            printf("Mutation flows: %d run, %d failed\n", count($this->mutationResults), $mFailed);
        }
    }

    public function hasFailures(): bool
    {
        $pageFail = (bool)array_filter($this->results, fn($r) => $r->verdict === CrawlResult::FAIL);
        $mutationFail = (bool)array_filter($this->mutationResults, fn($m) => $m['status'] === CrawlResult::FAIL);
        return $pageFail || $mutationFail;
    }

    /** Writes a machine-readable JSON report (failures only) for a later Claude Code triage pass. */
    public function writeJson(string $path): void
    {
        $failures = [];
        foreach ($this->results as $r) {
            if ($r->verdict !== CrawlResult::FAIL) continue;
            $failures[] = [
                'url'         => $r->url,
                'label'       => $r->label,
                'failureType' => $this->classify($r),
                'httpStatus'  => $r->httpStatus,
                'detail'      => $r->note,
                'phpErrors'   => $r->phpErrors,
                'jsConsoleErrors' => $r->jsConsoleErrors,
                'resourceWarnings' => $r->resourceWarnings,
                'a11yIssues'  => $r->a11yIssues,
            ];
        }
        foreach ($this->mutationResults as $m) {
            if ($m['status'] !== CrawlResult::FAIL) continue;
            $failures[] = [
                'url'         => null,
                'label'       => 'mutation flow: ' . $m['name'],
                'failureType' => 'mutation',
                'httpStatus'  => null,
                'detail'      => $m['note'],
                'phpErrors'   => [],
                'jsConsoleErrors' => [],
                'a11yIssues'  => [],
            ];
        }

        $payload = [
            'generatedAt' => date('c'),
            'totals' => [
                'total'   => count($this->results),
                'passed'  => count(array_filter($this->results, fn($r) => $r->verdict === CrawlResult::PASS)),
                'failed'  => count(array_filter($this->results, fn($r) => $r->verdict === CrawlResult::FAIL)),
                'skipped' => count(array_filter($this->results, fn($r) => $r->verdict === CrawlResult::SKIP)),
            ],
            'failures' => $failures,
        ];

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function classify(CrawlResult $r): string
    {
        if (!$r->stillLoggedIn) return 'auth';
        if ($r->httpStatus >= 500) return 'http';
        if ($r->phpErrors) return 'php';
        if ($r->jsConsoleErrors) return 'console';
        return 'unknown';
    }
}
