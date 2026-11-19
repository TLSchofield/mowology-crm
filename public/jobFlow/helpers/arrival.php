<?php
/**
 * jobFlow arrival helpers (2026-10-26): which page sent the visitor to the quote form, and which outside site, if any.
 *
 * Before this, lead_events.landing_page held the quote-confirm page for every lead (35 of 35) because it logged the
 * referer seen at the LAST step. These read the referer on the FIRST step instead. Pure: no session, no database.
 * Only a path (no query string) of our own pages and only the host of an outside site are kept, never a search query.
 */

if (!function_exists('jfNormalHost')) {
    function jfNormalHost(?string $host): string
    {
        $h = strtolower(trim((string)$host));
        $h = preg_replace('/:\d+$/', '', $h) ?? '';
        return preg_replace('/^www\./', '', $h) ?? '';
    }
}

if (!function_exists('jfCleanUrl')) {
    /** Scheme + host + path of an http(s) URL: no user info, port, query string or fragment (those can hold names and search terms). */
    function jfCleanUrl(?string $url): string
    {
        $p = @parse_url((string)$url);
        if (!is_array($p) || empty($p['host']) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)) { return ''; }
        $host = strtolower((string)$p['host']);
        $path = (string)($p['path'] ?? '');
        if (!preg_match('/^[a-z0-9.\-]{1,100}$/', $host) || ($path !== '' && !preg_match('#^[A-Za-z0-9/_\-.]{1,120}$#', $path))) { return ''; }
        return substr(strtolower((string)$p['scheme']) . '://' . $host . $path, 0, 255);
    }
}

if (!function_exists('jfArrivalFromReferer')) {
    /** @return array{from_page:?string, ref_host:?string} from_page: our own page path; ref_host: an outside site's host */
    function jfArrivalFromReferer(?string $referer, string $ownHost): array
    {
        $out = ['from_page' => null, 'ref_host' => null];
        $parts = @parse_url((string)$referer);
        if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) { return $out; }
        $host = jfNormalHost($parts['host']);
        if ($host === '') { return $out; }
        if ($host === jfNormalHost($ownHost)) {
            $path = (string)($parts['path'] ?? '/');
            if ($path === '' || $path[0] !== '/' || !preg_match('#^[A-Za-z0-9/_\-.]{1,120}$#', $path)) { return $out; }
            if (stripos($path, '/jobflow/') === 0 || stripos($path, '/quote') === 0) { return $out; }   // the form itself (reload, step back): not a source
            $out['from_page'] = $path;
            return $out;
        }
        if (preg_match('/^[a-z0-9.\-]{1,100}$/', $host)) { $out['ref_host'] = $host; }
        return $out;
    }
}

if (!function_exists('jfLandingFor')) {
    /**
     * What to store in lead_events.landing_page: the page that sent them to the form, else the form's own address.
     * @param array $track the session's jf_track
     */
    function jfLandingFor(array $track, string $ownHost, string $fallback): string
    {
        $path = (string)($track['from_page'] ?? '');
        if ($path !== '' && $path[0] === '/' && preg_match('#^[A-Za-z0-9/_\-.]{1,120}$#', $path) && jfNormalHost($ownHost) !== '') {
            return substr('https://' . jfNormalHost($ownHost) . $path, 0, 255);
        }
        return jfCleanUrl($fallback);
    }
}

if (!function_exists('jfSourceFor')) {
    /** utm_source if the link had one, else the outside site they came from, else the default. */
    function jfSourceFor(array $track, string $default): string
    {
        $utm = trim((string)($track['utm_source'] ?? ''));
        if ($utm !== '') { return substr($utm, 0, 100); }
        $host = (string)($track['ref_host'] ?? '');
        return ($host !== '' && preg_match('/^[a-z0-9.\-]{1,100}$/', $host)) ? $host : substr($default, 0, 100);
    }
}
