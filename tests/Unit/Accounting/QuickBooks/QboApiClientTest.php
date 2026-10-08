<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The /v3 client: headers, minorversion, requestid, 401 → refresh once, 429, Fault, paging,
 * upload parts — and that it has no way to delete anything.
 */
class QboApiClientTest extends TestCase
{
    private QboFakeTransport $t;

    private function conn(string $accessExpiresAt = '2099-01-01 00:00:00'): array
    {
        return ['id' => 7, 'realm_id' => '123146', 'status' => 'active',
                'access_token_enc' => SocialEncryption::encrypt('AT-live'), 'refresh_token_enc' => SocialEncryption::encrypt('RT-live'),
                'access_expires_at' => $accessExpiresAt, 'refresh_expires_at' => '2099-01-01 00:00:00'];
    }

    /** A connection service whose DB never speaks (PDO mock) — refresh goes through the fake transport. */
    private function client(array $conn, ?array &$log = null): QboApiClient
    {
        $this->t = new QboFakeTransport();
        $config = new QboConfig('id', 'secret', 'sandbox', 'https://x/cb');
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);
        $conns = new QboConnectionService($pdo, $config, new QboOAuthService($config, $this->t));
        $logger = function (array $row) use (&$log) { $log[] = $row; };
        return new QboApiClient($config, $this->t, $conns, $conn, $logger);
    }

    public function test_get_sends_bearer_accept_json_and_minorversion_75(): void
    {
        $api = $this->client($this->conn());
        $this->t->push(200, ['CompanyInfo' => ['CompanyName' => 'Mowology']]);
        $r = $api->get('companyinfo/123146');
        $req = $this->t->last();
        $this->assertSame('Mowology', $r['CompanyInfo']['CompanyName']);
        $this->assertStringStartsWith('https://sandbox-quickbooks.api.intuit.com/v3/company/123146/companyinfo/123146?', $req['url']);
        $this->assertStringContainsString('minorversion=75', $req['url']);
        $this->assertContains('Authorization: Bearer AT-live', $req['headers']);
        $this->assertContains('Accept: application/json', $req['headers']);
    }

    public function test_a_401_refreshes_once_and_retries_with_the_new_token(): void
    {
        $api = $this->client($this->conn());
        $this->t->push(401, ['Fault' => ['Error' => [['Message' => 'AuthenticationFailed', 'code' => '3200']]]]);
        $this->t->push(200, ['token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => 'RT-new', 'x_refresh_token_expires_in' => 8640000, 'access_token' => 'AT-new']);
        $this->t->push(200, ['Preferences' => ['TaxPrefs' => ['UsingSalesTax' => true]]]);
        $r = $api->get('preferences');
        $this->assertTrue($r['Preferences']['TaxPrefs']['UsingSalesTax']);
        $this->assertCount(3, $this->t->sent);
        $this->assertSame(QboConfig::TOKEN_URL, $this->t->sent[1]['url']);
        $this->assertContains('Authorization: Bearer AT-new', $this->t->sent[2]['headers']);
    }

    public function test_an_expiring_access_token_is_refreshed_before_the_call(): void
    {
        $api = $this->client($this->conn(gmdate('Y-m-d H:i:s', time() + 60)));
        $this->t->push(200, ['token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => 'RT-new', 'x_refresh_token_expires_in' => 8640000, 'access_token' => 'AT-fresh']);
        $this->t->push(200, ['QueryResponse' => ['totalCount' => 3]]);
        $this->assertSame(3, $api->count('Invoice'));
        $this->assertContains('Authorization: Bearer AT-fresh', $this->t->sent[1]['headers']);
    }

    public function test_429_becomes_a_throttled_exception(): void
    {
        $api = $this->client($this->conn());
        $this->t->push(429, 'Too many', ['intuit_tid' => 'gw-1']);
        $this->expectException(QboThrottledException::class);
        $api->get('preferences');
    }

    public function test_a_fault_is_turned_into_intuits_message_with_the_tid(): void
    {
        $api = $this->client($this->conn());
        $this->t->push(400, ['Fault' => ['Error' => [['Message' => 'Invalid Reference Id', 'Detail' => 'Accounts element id 99 not found', 'code' => '2500']], 'type' => 'ValidationFault']], ['intuit_tid' => 'gw-abc']);
        try {
            $api->post('purchase', ['PaymentType' => 'Cash'], 'req-1');
            $this->fail('expected QboApiException');
        } catch (QboApiException $e) {
            $this->assertSame('Invalid Reference Id — Accounts element id 99 not found (code 2500)', $e->getMessage());
            $this->assertSame('gw-abc', $e->intuitTid);
            $this->assertSame('2500', $e->qboCode);
            $this->assertSame(400, $e->httpStatus);
        }
    }

    public function test_post_carries_the_requestid_and_json_body_and_is_logged(): void
    {
        $log = [];
        $api = $this->client($this->conn(), $log);
        $this->t->push(200, ['Purchase' => ['Id' => '501', 'SyncToken' => '0']], ['intuit_tid' => 'gw-9']);
        $r = $api->post('purchase', ['PaymentType' => 'Cash', 'Line' => []], 'expense-12-abcdef');
        $req = $this->t->last();
        $this->assertStringContainsString('requestid=expense-12-abcdef', $req['url']);
        $this->assertContains('Content-Type: application/json', $req['headers']);
        $this->assertSame('Cash', json_decode($req['body'], true)['PaymentType']);
        $this->assertSame('501', $r['Purchase']['Id']);
        $this->assertCount(1, $log);
        $this->assertSame('gw-9', $log[0]['intuit_tid']);
        $this->assertSame('expense-12-abcdef', $log[0]['request_id']);
        $this->assertSame(7, $log[0]['connection_id']);
    }

    public function test_query_all_pages_in_thousands_until_a_short_page(): void
    {
        $api = $this->client($this->conn());
        $page1 = ['QueryResponse' => ['Account' => array_fill(0, 1000, ['Id' => '1']), 'startPosition' => 1, 'maxResults' => 1000]];
        $page2 = ['QueryResponse' => ['Account' => array_fill(0, 7, ['Id' => '2']), 'startPosition' => 1001, 'maxResults' => 7]];
        $this->t->push(200, $page1)->push(200, $page2);
        $rows = $api->queryAll('Account');
        $this->assertCount(1007, $rows);
        $this->assertStringContainsString(rawurlencode('STARTPOSITION 1001'), str_replace('+', '%20', $this->t->last()['url']));
    }

    public function test_upload_sends_metadata_and_file_parts_to_the_upload_endpoint(): void
    {
        $api = $this->client($this->conn());
        $tmp = tempnam(sys_get_temp_dir(), 'qbo');
        file_put_contents($tmp, 'jpegbytes');
        $this->t->push(200, ['AttachableResponse' => [['Attachable' => ['Id' => '9001', 'FileName' => 'receipt.jpg']]]]);
        $att = $api->upload($tmp, 'image/jpeg', 'receipt.jpg', 'Purchase', '501', 'receipt-12-x');
        $req = $this->t->last();
        $this->assertStringStartsWith('https://sandbox-quickbooks.api.intuit.com/v3/company/123146/upload?', $req['url']);
        $this->assertIsArray($req['body']);
        $meta = json_decode($req['body']['file_metadata_01'], true);
        $this->assertSame('Purchase', $meta['AttachableRef'][0]['EntityRef']['type']);
        $this->assertSame('501', $meta['AttachableRef'][0]['EntityRef']['value']);
        $this->assertSame('receipt.jpg', $meta['FileName']);
        $this->assertSame($tmp, $req['body']['file_content_01']['path']);
        $this->assertSame('9001', $att['Id']);
        unlink($tmp);
    }

    public function test_the_client_and_the_push_service_can_never_delete_in_quickbooks(): void
    {
        foreach (['QboApiClient.php', 'QboPushService.php'] as $f) {
            $src = file_get_contents(__DIR__ . '/../../../../app/Modules/Accounting/Services/QuickBooks/' . $f);
            $this->assertStringNotContainsString('operation=delete', $src, $f);
            $this->assertStringNotContainsString('operation=void', $src, $f);
        }
        $this->assertFalse(method_exists(QboApiClient::class, 'delete'));
    }
}
