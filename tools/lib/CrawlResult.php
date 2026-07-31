<?php
declare(strict_types=1);

class CrawlResult
{
    public const PASS = 'PASS';
    public const FAIL = 'FAIL';
    public const SKIP = 'SKIP';

    public string $url;
    public string $label;
    public int $httpStatus = 0;
    public bool $stillLoggedIn = true;
    /** @var string[] */
    public array $phpErrors = [];
    /** @var string[] */
    public array $jsConsoleErrors = [];
    /** @var string[] */
    public array $a11yIssues = [];
    public bool $expectedGate = false;
    public string $verdict = self::PASS;
    public string $note = '';

    public function __construct(string $url, string $label)
    {
        $this->url = $url;
        $this->label = $label;
    }

    public function finalize(): void
    {
        $bounced = !$this->stillLoggedIn;

        if ($bounced && $this->expectedGate) {
            $this->verdict = self::SKIP;
            $this->note = 'auth-gated as expected (role lacks permission)';
            return;
        }

        if ($bounced) {
            $this->verdict = self::FAIL;
            $this->note = 'unexpectedly bounced to login — session dropped or permission missing';
            return;
        }

        if ($this->httpStatus >= 500) {
            $this->verdict = self::FAIL;
            $this->note = "HTTP {$this->httpStatus}";
            return;
        }

        if ($this->phpErrors) {
            $this->verdict = self::FAIL;
            $this->note = 'PHP error in body: ' . implode('; ', $this->phpErrors);
            return;
        }

        $severeConsole = array_values(array_filter($this->jsConsoleErrors));
        if ($severeConsole) {
            $this->verdict = self::FAIL;
            $this->note = count($severeConsole) . ' JS console error(s)';
            return;
        }

        $this->verdict = self::PASS;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'url'           => $this->url,
            'label'         => $this->label,
            'verdict'       => $this->verdict,
            'httpStatus'    => $this->httpStatus,
            'stillLoggedIn' => $this->stillLoggedIn,
            'phpErrors'     => $this->phpErrors,
            'jsConsoleErrors' => $this->jsConsoleErrors,
            'a11yIssues'    => $this->a11yIssues,
            'note'          => $this->note,
        ];
    }
}
