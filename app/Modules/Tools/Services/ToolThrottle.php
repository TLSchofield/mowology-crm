<?php
declare(strict_types=1);

/** File-backed request throttles for public calculator endpoints. */
final class ToolThrottle
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? sys_get_temp_dir() . '/ittai-tool-throttle';
    }

    /** Returns true when the key is already at its limit. */
    public function limited(string $key, int $limit, int $seconds, ?int $at = null): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return true;
        }
        $now = $at ?? time();
        if (random_int(1, 50) === 1) {
            $this->clean($now);
        }
        $file = $this->directory . '/' . hash('sha256', $key);
        $handle = @fopen($file, 'c+');
        if (!$handle) {
            return true;
        }
        flock($handle, LOCK_EX);
        $times = [];
        $raw = (string) stream_get_contents($handle);
        foreach (explode("\n", $raw) as $value) {
            if ((int) $value > $now - $seconds) {
                $times[] = (int) $value;
            }
        }
        if (!$times && trim($raw) !== '') {
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($file);
            $handle = @fopen($file, 'c+');
            if (!$handle) {
                return true;
            }
            flock($handle, LOCK_EX);
        }
        $hit = count($times) >= $limit;
        if (!$hit) {
            $times[] = $now;
        }
        if ($times) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, implode("\n", $times));
            fflush($handle);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        if (!$times) {
            @unlink($file);
        }
        return $hit;
    }

    private function clean(int $now): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $now - 172800) {
                @unlink($file);
            }
        }
    }
}
