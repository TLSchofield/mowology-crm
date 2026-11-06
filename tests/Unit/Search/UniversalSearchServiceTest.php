<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UniversalSearchServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY,first_name TEXT,last_name TEXT,email TEXT,phone TEXT,is_active INT)');
        $db->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY,company_name TEXT,company_type TEXT,billing_city TEXT,billing_email TEXT,account_status TEXT)');
        $db->exec('CREATE TABLE search_picks (id INTEGER PRIMARY KEY,user_id INT,prefix TEXT,kind TEXT,entity_id INT,picks INT,last_at TEXT,UNIQUE(user_id,prefix,kind,entity_id))');
        return$db;
    }

    public function test_normalize_tokens_digits_and_prefix(): void
    {
        self::assertSame('Linda Smith', UniversalSearchService::normalize("  Linda   Smith  "));
        self::assertSame([['lindas', 'linda']], UniversalSearchService::tokens('Lindas'));
        self::assertSame('6045551234', UniversalSearchService::digits('(604) 555-1234'));
        self::assertSame('one two three', UniversalSearchService::prefix('ONE two three four'));
    }

    public function test_record_pick_increments_and_flat_web_skips_items_without_urls(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE search_picks (id INTEGER PRIMARY KEY, user_id INT, prefix TEXT, kind TEXT, entity_id INT, picks INT, last_at TEXT, UNIQUE(user_id,prefix,kind,entity_id))');
        $service = new UniversalSearchService($db);
        self::assertTrue($service->recordPick(9, 'Linda quote', 'quote', 4));
        self::assertTrue($service->recordPick(9, 'Linda quote', 'quote', 4));
        self::assertSame(2, (int)$db->query('SELECT picks FROM search_picks')->fetchColumn());

        $flat = UniversalSearchService::flatForWeb([['key'=>'quotes','title'=>'Quotes','items'=>[
            ['label'=>'QUO-1','sublabel'=>'sent','url'=>'/quote/1'],
            ['label'=>'message','sublabel'=>'hello'],
        ]]]);
        self::assertSame([['category'=>'Quotes','icon'=>'file-text','label'=>'QUO-1','sublabel'=>'sent','url'=>'/quote/1']], $flat);
    }

    public function test_search_requires_all_words_and_matches_formatted_phone_digits(): void
    {
        $db=$this->db();
        $db->exec("INSERT INTO contacts VALUES(1,'Linda','Jones','linda@example.test','604-555-1234',1),(2,'Linda','Smith',NULL,NULL,1),(3,'Bob','Jones',NULL,NULL,1)");
        $service=new UniversalSearchService($db);
        $can=static function(string $permission):bool{return $permission==='clients.view';};
        $people=$service->search('Linda Jones',$can,7)['groups'][0];
        self::assertSame([1],array_column($people['items'],'id'));
        $phone=$service->search('(604) 555',$can,7)['groups'][0];
        self::assertSame([1],array_column($phone['items'],'id'));
    }

    public function test_permissions_more_learned_order_and_prefix_isolation(): void
    {
        $db=$this->db();
        for($i=1;$i<=7;$i++)$db->exec("INSERT INTO contacts VALUES($i,'Alex','Person $i',NULL,NULL,1)");
        $service=new UniversalSearchService($db);
        self::assertSame([], $service->search('Alex',static function(string $p):bool{return false;},5)['groups']);
        self::assertTrue($service->recordPick(4,'Alex','contact',7));
        $group=$service->search('Alex',static function(string $p):bool{return $p==='clients.view';},4,5)['groups'][0];
        self::assertTrue($group['more']);
        self::assertSame(7,$group['items'][0]['id']);
        self::assertSame(1,$service->search('Alex P',static function(string $p):bool{return $p==='clients.view';},4,1)['groups'][0]['items'][0]['id']);
    }
}
