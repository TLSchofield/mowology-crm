<?php
declare(strict_types=1);

final class GoogleContactsOAuth
{
    public const REDIRECT_URI = 'https://ittai.ca/api/google-contacts-callback.php';

    private string $clientId;
    private string $clientSecret;
    private $http;
    private int $lastHttpStatus = 0;

    public function __construct(string $clientId, string $clientSecret, ?callable $http = null)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->http = $http ?? static function (string $method, string $url, array $headers, ?string $body): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['status' => $status, 'body' => is_string($response) ? $response : ''];
        };
    }

    public static function authUrl(string $clientId, string $redirectUri, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'https://www.googleapis.com/auth/contacts.readonly',
            'access_type' => 'online',
            'prompt' => 'select_account',
            'include_granted_scopes' => 'false',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function stateSecret(): ?string
    {
        if (!defined('BLUEMOON_JWT_SECRET')) return null;
        $secret = (string)constant('BLUEMOON_JWT_SECRET');
        if (strlen($secret) < 32) return null;
        return hash_hmac('sha256', 'google-contacts-state', $secret);
    }

    public static function signState(array $payload, string $secret): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new InvalidArgumentException('State could not be encoded.');
        $part = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $part . '.' . hash_hmac('sha256', $part, $secret);
    }

    public static function verifyState(string $state, string $secret, int $now): ?array
    {
        $parts = explode('.', $state);
        if (count($parts) !== 2 || $parts[0] === '' || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) return null;
        if (!hash_equals(hash_hmac('sha256', $parts[0], $secret), $parts[1])) return null;
        $encoded = strtr($parts[0], '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding !== 0) $encoded .= str_repeat('=', 4 - $padding);
        $json = base64_decode($encoded, true);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($payload)
            || !isset($payload['b'], $payload['u'], $payload['n'], $payload['e'])
            || !is_string($payload['b']) || $payload['b'] === ''
            || filter_var($payload['u'], FILTER_VALIDATE_INT) === false
            || !is_string($payload['n']) || !preg_match('/^[a-f0-9]{32}$/', $payload['n'])
            || filter_var($payload['e'], FILTER_VALIDATE_INT) === false
            || (int)$payload['e'] <= $now) return null;
        $payload['u'] = (int)$payload['u'];
        $payload['e'] = (int)$payload['e'];
        return $payload;
    }

    public function exchangeCode(string $code, string $redirectUri): ?string
    {
        $body = http_build_query([
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ], '', '&', PHP_QUERY_RFC3986);
        $response = ($this->http)('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], $body);
        $this->lastHttpStatus = (int)($response['status'] ?? 0);
        if ($this->lastHttpStatus !== 200) return null;
        $data = json_decode((string)($response['body'] ?? ''), true);
        $token = is_array($data) ? trim((string)($data['access_token'] ?? '')) : '';
        return $token !== '' ? $token : null;
    }

    public function fetchContacts(string $accessToken): array
    {
        $contacts = [];
        $pageToken = null;
        for ($page = 0; $page < 5; $page++) {
            $query = [
                'personFields' => 'names,emailAddresses,phoneNumbers,addresses,organizations',
                'pageSize' => '1000',
                'sortOrder' => 'FIRST_NAME_ASCENDING',
            ];
            if ($pageToken !== null) $query['pageToken'] = $pageToken;
            $url = 'https://people.googleapis.com/v1/people/me/connections?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $response = ($this->http)('GET', $url, ['Authorization: Bearer ' . $accessToken], null);
            $this->lastHttpStatus = (int)($response['status'] ?? 0);
            if ($this->lastHttpStatus !== 200) throw new RuntimeException('Google People returned HTTP ' . $this->lastHttpStatus);
            $data = json_decode((string)($response['body'] ?? ''), true);
            if (!is_array($data)) throw new RuntimeException('Google People returned HTTP ' . $this->lastHttpStatus);
            foreach ((array)($data['connections'] ?? []) as $person) {
                if (!is_array($person)) continue;
                $mapped = self::mapPerson($person);
                if ($mapped !== null) {
                    $mapped['_display_name'] = trim((string)($person['names'][0]['displayName'] ?? ''));
                    if ($mapped['_display_name'] === '') $mapped['_display_name'] = trim($mapped['first'] . ' ' . $mapped['last'] . ' ' . $mapped['company']);
                    $contacts[] = $mapped;
                }
            }
            $pageToken = isset($data['nextPageToken']) && is_string($data['nextPageToken']) && $data['nextPageToken'] !== '' ? $data['nextPageToken'] : null;
            if ($pageToken === null) break;
        }
        usort($contacts, static function (array $a, array $b): int {
            return strcasecmp($a['_display_name'], $b['_display_name']);
        });
        foreach ($contacts as &$contact) unset($contact['_display_name']);
        unset($contact);
        return $contacts;
    }

    public function lastHttpStatus(): int
    {
        return $this->lastHttpStatus;
    }

    /** The item flagged metadata.primary, else the first array item, else []. */
    private static function primary($items): array
    {
        $first = [];
        foreach ((array)$items as $item) {
            if (!is_array($item)) continue;
            if ($first === []) $first = $item;
            if (($item['metadata']['primary'] ?? false) === true) return $item;
        }
        return $first;
    }

    public static function mapPerson(array $person): ?array
    {
        // Google guarantees no order: the item flagged metadata.primary is the one to use, else the first.
        $name = self::primary($person['names'] ?? []);
        $organization = self::primary($person['organizations'] ?? []);
        $first = trim((string)($name['givenName'] ?? ''));
        $last = trim((string)($name['familyName'] ?? ''));
        $company = trim((string)($organization['name'] ?? ''));
        $emails = [];
        foreach ((array)($person['emailAddresses'] ?? []) as $email) {
            if (!is_array($email)) continue;
            $value = trim((string)($email['value'] ?? ''));
            if ($value !== '' && count($emails) < 5) $emails[] = $value;
        }
        $phones = [];
        foreach ((array)($person['phoneNumbers'] ?? []) as $phone) {
            if (!is_array($phone)) continue;
            $number = trim((string)($phone['value'] ?? ''));
            if ($number === '' || count($phones) >= 5) continue;
            $label = trim((string)($phone['type'] ?? ''));
            if ($label === '') $label = trim((string)($phone['formattedType'] ?? ''));
            $phones[] = ['label' => strtolower($label !== '' ? $label : 'other'), 'number' => $number];
        }
        if ($first === '' && $last === '' && $company === '' && !$emails && !$phones) return null;
        $address = null;
        $raw = self::primary($person['addresses'] ?? []);
        if ($raw !== []) {
            $street = preg_replace('/\s*[\r\n]+\s*/', ', ', trim((string)($raw['streetAddress'] ?? '')));
            $candidate = [
                'street' => is_string($street) ? $street : '',
                'city' => trim((string)($raw['city'] ?? '')),
                'province' => trim((string)($raw['region'] ?? '')),
                'postal' => trim((string)($raw['postalCode'] ?? '')),
            ];
            if (implode('', $candidate) !== '') $address = $candidate;
        }
        return [
            'ref' => (string)($person['resourceName'] ?? ''),
            'first' => $first,
            'last' => $last,
            'company' => $company,
            'emails' => $emails,
            'phones' => $phones,
            'address' => $address,
        ];
    }
}
