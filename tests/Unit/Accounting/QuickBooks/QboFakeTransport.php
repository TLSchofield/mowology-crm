<?php
declare(strict_types=1);

/**
 * Canned Intuit responses for the QuickBooks unit tests — no network, no credentials.
 * Queue responses in order with push(); every request is recorded in $sent.
 */
class QboFakeTransport implements QboTransport
{
    /** @var array<int, array{status:int,headers:array,body:string}> */
    private array $queue = [];
    /** @var array<int, array{method:string,url:string,headers:array,body:mixed}> */
    public array $sent = [];

    public function push(int $status, $body, array $headers = []): self
    {
        $this->queue[] = ['status' => $status, 'headers' => $headers, 'body' => is_string($body) ? $body : json_encode($body)];
        return $this;
    }

    public function send(string $method, string $url, array $headers, $body = null): array
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if (!$this->queue) {
            throw new RuntimeException('QboFakeTransport: no canned response left for ' . $method . ' ' . $url);
        }
        return array_shift($this->queue);
    }

    public function last(): array
    {
        return $this->sent[count($this->sent) - 1];
    }
}

if (!defined('SOCIAL_ENCRYPTION_KEY')) {
    // Test-only key: the QuickBooks services encrypt tokens with the same helper the social accounts use.
    define('SOCIAL_ENCRYPTION_KEY', base64_encode(str_repeat("\x42", 32)));
}
