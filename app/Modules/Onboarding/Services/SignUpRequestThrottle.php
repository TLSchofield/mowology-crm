<?php
declare(strict_types=1);

/** Reserves one signup-code request in the shared attempt throttle before any message is sent. */
final class SignUpRequestThrottle
{
    /**
     * @param callable(string,string):bool $limited
     * @param callable(string,string):void $record
     */
    public static function reserve(string $email, string $ip, callable $limited, callable $record): bool
    {
        $key = 'signup:' . strtolower(trim($email));
        if ($limited($key, $ip)) return false;
        $record($key, $ip);
        return true;
    }
}
