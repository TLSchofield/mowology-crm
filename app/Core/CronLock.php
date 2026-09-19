<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Non-blocking flock-based mutex for cron jobs.
 *
 * Prevents overlapping runs when a cron exceeds its schedule interval.
 * If another instance holds the lock, this process exits with code 0 and
 * logs a notice. The lock is released on script shutdown.
 *
 * Usage (top of cron, after bootstrap):
 *   require_once APP_ROOT . '/Core/CronLock.php';
 *   use App\Core\CronLock;
 *   $lock = CronLock::acquire('weather_schedule_guard');
 */
final class CronLock
{
    /** @var resource|null */
    private $handle;
    private string $path;

    public static function acquire(string $name): self
    {
        $lock = new self();
        $lock->path = sys_get_temp_dir()
            . '/mowology_cron_'
            . preg_replace('/[^a-z0-9_]/i', '_', $name)
            . '.lock';

        $handle = fopen($lock->path, 'c');
        if ($handle === false) {
            error_log("CronLock: failed to open lockfile {$lock->path}; continuing without lock.");
            $lock->handle = null;
            return $lock;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            error_log("CronLock: another instance of {$name} is running; exiting.");
            exit(0);
        }

        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid());
        fflush($handle);

        $lock->handle = $handle;
        register_shutdown_function([$lock, 'release']);
        return $lock;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            @unlink($this->path);
            $this->handle = null;
        }
    }
}
