<?php
/**
 * WeatherActionGuard — who may change a visit from the weather action list.
 *
 * /crm/api/weather-actions.php used to accept approve_move / keep / dismiss / run-guard
 * from any logged-in user with no CSRF token, so a crew login (or a forged request
 * from another tab) could move or clear a client's visit. Every POST now needs a valid
 * CSRF token and the jobs.edit permission; reading the list (GET) is unchanged.
 *
 * No namespace / no autoloader in production: require_once, then call statically.
 */
class WeatherActionGuard
{
    public const PERMISSION = 'jobs.edit';

    /**
     * Pure. Null when the request may go ahead, else [http status, error message].
     * @return array{0: int, 1: string}|null
     */
    public static function reject(string $method, bool $csrfOk, bool $canEdit): ?array
    {
        if (strtoupper($method) !== 'POST') return null;
        if (!$csrfOk) return [403, 'Invalid CSRF token — reload the page and try again'];
        if (!$canEdit) return [403, 'You need permission to change the schedule (' . self::PERMISSION . ')'];
        return null;
    }
}
