<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GoogleContactsOAuthTest extends TestCase
{
    private array $payload = ['b' => 'greenleaf', 'u' => 7, 'n' => '0123456789abcdef0123456789abcdef', 'e' => 2000];

    public function test_state_round_trip_tamper_expiry_and_wrong_secret(): void
    {
        $state = GoogleContactsOAuth::signState($this->payload, 'secret-one');
        $this->assertSame($this->payload, GoogleContactsOAuth::verifyState($state, 'secret-one', 1000));
        $this->assertNull(GoogleContactsOAuth::verifyState($state . 'x', 'secret-one', 1000));
        $this->assertNull(GoogleContactsOAuth::verifyState($state, 'secret-one', 2000));
        $this->assertNull(GoogleContactsOAuth::verifyState($state, 'secret-two', 1000));
    }

    public function test_auth_url_has_the_exact_google_consent_parameters(): void
    {
        $url = GoogleContactsOAuth::authUrl('client', 'https://example.test/callback', 'signed');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', strtok($url, '?'));
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('https://www.googleapis.com/auth/contacts.readonly', $query['scope']);
        $this->assertSame('online', $query['access_type']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertSame('false', $query['include_granted_scopes']);
        $this->assertSame('signed', $query['state']);
    }

    public function test_map_person_including_address_newlines_and_empty_person(): void
    {
        $mapped = GoogleContactsOAuth::mapPerson([
            'resourceName' => 'people/1',
            'names' => [['givenName' => 'Ada', 'familyName' => 'Lovelace']],
            'organizations' => [['name' => 'Analytical Engines']],
            'emailAddresses' => [['value' => 'ada@example.test']],
            'phoneNumbers' => [['formattedType' => 'Main Office', 'value' => '604-555-0101']],
            'addresses' => [['streetAddress' => "1 First St\nSuite 2", 'city' => 'Victoria', 'region' => 'BC', 'postalCode' => 'V1V 1V1']],
        ]);
        $this->assertSame('people/1', $mapped['ref']);
        $this->assertSame('main office', $mapped['phones'][0]['label']);
        $this->assertSame('1 First St, Suite 2', $mapped['address']['street']);
        $this->assertNull(GoogleContactsOAuth::mapPerson(['resourceName' => 'people/empty']));
        $this->assertNotNull(GoogleContactsOAuth::mapPerson(['emailAddresses' => [['value' => 'only@example.test']]]));
    }

    public function test_exchange_success_and_failure_use_injected_http(): void
    {
        $calls = [];
        $ok = new GoogleContactsOAuth('id', 'secret', static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
            $calls[] = [$method, $url, $headers, $body];
            return ['status' => 200, 'body' => '{"access_token":"token-value"}'];
        });
        $this->assertSame('token-value', $ok->exchangeCode('code', 'https://example.test/callback'));
        $this->assertSame('POST', $calls[0][0]);
        $this->assertStringContainsString('client_secret=secret', (string)$calls[0][3]);
        $failed = new GoogleContactsOAuth('id', 'secret', static fn(string $method, string $url, array $headers, ?string $body): array => ['status' => 400, 'body' => '{"error":"bad"}']);
        $this->assertNull($failed->exchangeCode('code', 'https://example.test/callback'));
        $this->assertSame(400, $failed->lastHttpStatus());
    }

    public function test_pagination_follows_tokens_and_stops_after_five_pages(): void
    {
        $urls = [];
        $oauth = new GoogleContactsOAuth('id', 'secret', static function (string $method, string $url, array $headers, ?string $body) use (&$urls): array {
            $urls[] = $url;
            $page = count($urls);
            return ['status' => 200, 'body' => json_encode([
                'connections' => [['resourceName' => 'people/' . $page, 'names' => [['givenName' => 'Name' . $page]]]],
                'nextPageToken' => 'token-' . $page,
            ])];
        });
        $contacts = $oauth->fetchContacts('access');
        $this->assertCount(5, $contacts);
        $this->assertCount(5, $urls);
        $this->assertStringNotContainsString('pageToken=', $urls[0]);
        $this->assertStringContainsString('pageToken=token-1', $urls[1]);
    }

    public function test_people_non_200_throws_status_only(): void
    {
        $oauth = new GoogleContactsOAuth('id', 'secret', static fn(string $method, string $url, array $headers, ?string $body): array => ['status' => 503, 'body' => 'private body']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google People returned HTTP 503');
        $oauth->fetchContacts('access');
    }

    public function test_the_primary_name_organization_and_address_win_over_array_order(): void
    {
        $person = GoogleContactsOAuth::mapPerson([
            'resourceName' => 'people/9',
            'names' => [['givenName' => 'Old', 'familyName' => 'Name'], ['givenName' => 'Ada', 'familyName' => 'Lovelace', 'metadata' => ['primary' => true]]],
            'organizations' => [['name' => 'Past Co'], ['name' => 'Now Co', 'metadata' => ['primary' => true]]],
            'addresses' => [['streetAddress' => 'Old address'], ['streetAddress' => 'Current address', 'metadata' => ['primary' => true]]],
        ]);
        $this->assertSame('Ada', $person['first']);
        $this->assertSame('Now Co', $person['company']);
        $this->assertSame('Current address', $person['address']['street']);
    }

    public function test_one_person_cannot_bring_hundreds_of_numbers(): void
    {
        $phones = [];
        for ($i = 0; $i < 100; $i++) $phones[] = ['value' => '604555' . str_pad((string)$i, 4, '0', STR_PAD_LEFT)];
        $person = GoogleContactsOAuth::mapPerson(['resourceName' => 'people/1', 'names' => [['givenName' => 'A']], 'phoneNumbers' => $phones]);
        $this->assertCount(5, $person['phones']);
    }
}
