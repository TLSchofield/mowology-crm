<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APP_ROOT . '/Modules/Plants/Services/PlantCareService.php';

/** The plant care library — Claude, GBIF and Anthropic batches are always fakes here. */
class PlantCareServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE plant_care_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, scientific_name TEXT, climate TEXT DEFAULT 'pnw-coastal', level TEXT DEFAULT 'species',
                   gbif_key INT, genus TEXT, plant_group TEXT, common_name TEXT, card TEXT, sources TEXT, status TEXT DEFAULT 'queued', origin TEXT DEFAULT 'library',
                   batch_id TEXT, model TEXT, searches INT DEFAULT 0, cost_usd REAL, drafted_by INT, drafted_at TEXT, approved_by INT, approved_at TEXT, created_at TEXT, updated_at TEXT,
                   UNIQUE (scientific_name, climate, level))");
        $db->exec('CREATE TABLE plant_name_keys (name TEXT PRIMARY KEY, gbif_key INT, accepted_name TEXT, genus TEXT, family TEXT, looked_up_at TEXT)');
        return $db;
    }

    /** GBIF as seen on production 2026-10-15: Dendranthema grandiflorum is a synonym of Chrysanthemum × morifolium. */
    private function gbif(PDO $db): GbifNames
    {
        $answers = [
            'chrysanthemum × morifolium' => ['matchType' => 'EXACT', 'confidence' => 98, 'rank' => 'SPECIES', 'status' => 'ACCEPTED', 'usageKey' => 3150767, 'speciesKey' => 3150767,
                                             'species' => 'Chrysanthemum × morifolium', 'genus' => 'Chrysanthemum', 'family' => 'Asteraceae'],
            'dendranthema grandiflorum' => ['matchType' => 'EXACT', 'confidence' => 98, 'rank' => 'SPECIES', 'status' => 'SYNONYM', 'usageKey' => 3091555, 'acceptedUsageKey' => 3150767,
                                            'species' => 'Chrysanthemum × morifolium', 'genus' => 'Chrysanthemum', 'family' => 'Asteraceae'],
            'hydrangea serrata' => ['matchType' => 'EXACT', 'confidence' => 97, 'rank' => 'SPECIES', 'status' => 'ACCEPTED', 'usageKey' => 77, 'speciesKey' => 77,
                                    'species' => 'Hydrangea serrata', 'genus' => 'Hydrangea'],
            'clematis montana' => ['matchType' => 'EXACT', 'confidence' => 97, 'rank' => 'SPECIES', 'status' => 'ACCEPTED', 'usageKey' => 88, 'speciesKey' => 88, 'species' => 'Clematis montana', 'genus' => 'Clematis'],
        ];
        return new GbifNames($db, function (string $url) use ($answers) {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
            return $answers[mb_strtolower($q['name'])] ?? ['matchType' => 'NONE'];
        });
    }

    private static function claude(array $card, array $cites = [['url' => 'https://www.rhs.org.uk/plants/x', 'title' => 'RHS']]): array
    {
        return ['content' => [['type' => 'text', 'text' => 'Here is the card.', 'citations' => array_map(fn($c) => $c + ['type' => 'web_search_result_location'], $cites)],
                              ['type' => 'text', 'text' => "```json\n" . json_encode($card) . "\n```"]],
                'usage' => ['input_tokens' => 20000, 'output_tokens' => 800, 'server_tool_use' => ['web_search_requests' => 3]], 'stop_reason' => 'end_turn'];
    }

    private const MUM = ['common_name' => 'Garden mum', 'group' => 'perennial', 'tasks' => [['task' => 'Pinch tips', 'from' => 5, 'to' => 7, 'how' => 'Pinch to keep bushy'],
                         ['task' => 'Cut back to 10 cm', 'from' => 11, 'to' => 12]], 'toxic_pets' => true, 'toxic_note' => 'Toxic to dogs and cats', 'sun' => 'Full sun'];

    public function testAnyNameFindsTheSpeciesCardAndGenusIsTheFallback(): void
    {
        $db = $this->db();
        $svc = new PlantCareService($db, fn() => ['code' => 500, 'body' => ''], $this->gbif($db));
        $id = $svc->queue('Chrysanthemum × morifolium', 'Garden mum', 'perennial');
        $this->assertSame($id, $svc->queue('Dendranthema grandiflorum', '', ''), 'the old name is the same plant, not queued twice');
        $this->assertSame('species', $svc->forPlant('Dendranthema grandiflorum')['match']);
        $svc->queue('Hydrangea', 'Hydrangea', 'shrub', 'genus');
        $this->assertSame('genus', $svc->forPlant('Hydrangea serrata')['match'], 'not in the library: the general card');
        $this->assertNull($svc->forPlant('Clematis montana'));
    }

    public function testABatchIsSentAndCollectedIntoDrafts(): void
    {
        $db = $this->db();
        $sent = null;
        $http = function (string $method, string $url, ?array $body) use (&$sent) {
            if ($method === 'POST') { $sent = $body; return ['code' => 200, 'body' => json_encode(['id' => 'msgbatch_1', 'processing_status' => 'in_progress'])]; }
            if (str_ends_with($url, '/msgbatch_1')) return ['code' => 200, 'body' => json_encode(['processing_status' => 'ended', 'results_url' => 'https://api.anthropic.com/results/1'])];
            return ['code' => 200, 'body' => json_encode(['custom_id' => 'pcc-1', 'result' => ['type' => 'succeeded', 'message' => self::claude(self::MUM)]]) . "\n"
                                           . json_encode(['custom_id' => 'pcc-2', 'result' => ['type' => 'errored']])];
        };
        $svc = new PlantCareService($db, $http, $this->gbif($db));
        $svc->queue('Chrysanthemum × morifolium', 'Garden mum', 'perennial');
        $svc->queue('Clematis', 'Clematis', 'vine', 'genus');
        $r = $svc->submitBatch();
        $this->assertSame(2, $r['sent']);
        $this->assertSame('pcc-1', $sent['requests'][0]['custom_id']);
        $this->assertSame(PlantCareService::TRUSTED, $sent['requests'][0]['params']['tools'][0]['allowed_domains']);
        $this->assertSame(['drafted' => 1, 'failed' => 1, 'pending' => 0], $svc->collect());
        $mum = $svc->byId(1);
        $this->assertSame(['draft', 'Garden mum'], [$mum['status'], $mum['common_name']]);
        $this->assertSame('https://www.rhs.org.uk/plants/x', $mum['sources'][0]['url']);
        $this->assertEqualsWithDelta(0.066, (float)$mum['cost_usd'], 0.0005, 'batch tokens at half price (0.036) + 3 searches (0.03)');
        $this->assertSame('failed', $svc->byId(2)['status']);
        $this->assertSame(1, $svc->retryFailed());
    }

    public function testApproveEditsAndGroups(): void
    {
        $db = $this->db();
        $svc = new PlantCareService($db, fn() => ['code' => 200, 'body' => json_encode(self::claude(self::MUM))], $this->gbif($db));
        $r = $svc->draftNow('Dendranthema grandiflorum', 'Florists\' chrysanthemum', 7);
        $this->assertTrue($r['ok']);
        $this->assertSame('Chrysanthemum × morifolium', $r['card']['scientific_name'], 'stored under the accepted name');
        $this->assertSame('identified', $r['card']['origin']);
        $id = (int)$r['card']['id'];
        $this->assertFalse($svc->decide($id, ['card' => ['tasks' => []]], 7)['ok'], 'a card needs a task');
        $ok = $svc->decide($id, ['common_name' => 'Garden mum', 'card' => self::MUM + ['notes' => 'Client likes them tight']], 7);
        $this->assertSame('approved', $ok['card']['status']);
        $this->assertSame('Client likes them tight', $ok['card']['card']['notes']);
        $this->assertArrayNotHasKey('common_name', $ok['card']['card']);
        $this->assertSame(0, $svc->approveGroup('perennial', 7), 'nothing left in draft');
    }

    public function testCleanAndWindow(): void
    {
        $c = PlantCareService::clean(['tasks' => [['task' => 'Prune', 'from' => 13], ['task' => 'Shear', 'from' => 11, 'to' => 2], ['task' => '', 'from' => 3]], 'toxic_pets' => 'false']);
        $this->assertSame([['task' => 'Shear', 'from' => 11, 'to' => 2, 'how' => '']], $c['tasks']);
        $this->assertFalse($c['toxic_pets']);
        $this->assertSame('Nov–Feb', PlantCareService::window(11, 2));
        $this->assertSame('Oct', PlantCareService::window(10, 10));
        $this->assertNull(PlantCareService::parse(['content' => [['type' => 'text', 'text' => 'no json']]])['card']);
    }

    public function testGbifMatchRules(): void
    {
        $this->assertNull(GbifNames::fromMatch(['matchType' => 'HIGHERRANK', 'confidence' => 99, 'rank' => 'GENUS']));
        $this->assertNull(GbifNames::fromMatch(['matchType' => 'FUZZY', 'confidence' => 60, 'rank' => 'SPECIES', 'usageKey' => 5]));
        $this->assertSame(3150767, GbifNames::fromMatch(['matchType' => 'EXACT', 'confidence' => 98, 'rank' => 'SPECIES', 'status' => 'SYNONYM', 'usageKey' => 3091555, 'acceptedUsageKey' => 3150767])['gbif_key']);
        $this->assertSame('Lavandula', GbifNames::genusOf("Lavandula angustifolia 'Hidcote'"));
    }

    public function testTheStarterListIsSound(): void
    {
        $names = array_column(PlantLibrarySeed::PLANTS, 0);
        $this->assertSame(count($names), count(array_unique($names)), 'no plant listed twice');
        foreach (PlantLibrarySeed::PLANTS as [$n, $c, $g, $l]) {
            $this->assertArrayHasKey($g, PlantCareService::GROUPS, $n);
            $this->assertContains($l, ['species', 'genus'], $n);
            if ($l === 'genus') $this->assertDoesNotMatchRegularExpression('/\s/', $n, "$n is a genus");
        }
        $this->assertGreaterThan(140, count($names));
    }
}
