<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mia's media library: context tags (never an address), the vision privacy gate, Tim's tags
 * survive re-tagging, the picker's ranking and exclusions, crop boxes, learned combo scores,
 * and Otto's "completed with no photos" item.
 */
final class MediaTagsTest extends TestCase
{
    public function testContextTagsAreNamespacedCoarseAndNeverTheAddress(): void
    {
        $t = MediaTagRules::contextTags([
            'service_types' => ['Hedge Trimming', 'hedge_trimming', 'Fall Cleanup'], 'date' => '2026-10-02 10:00:00',
            'photo_type' => 'before', 'visit_id' => 55, 'has_pair' => true, 'postal_code' => 'V6K 2B7',
            'city' => 'Vancouver', 'property_type' => 'strata', 'optout' => false, 'favorite' => true,
            'address' => '1234 West 4th Avenue',
        ]);
        $this->assertSame(['service/hedge-trimming', 'service/fall-cleanup', 'season/fall', 'stage/before', 'pair/55',
                           'area/kitsilano', 'type/strata', 'consent/ok', 'use/hero'], $t);
        foreach ($t as $tag) {
            $this->assertStringNotContainsString('1234', $tag);
            $this->assertMatchesRegularExpression('#^[a-z]+/[a-z0-9-]+$#', $tag);
        }
    }

    public function testSeasonsAreasAndTypes(): void
    {
        $this->assertSame('winter', MediaTagRules::season('2026-01-15'));
        $this->assertSame('spring', MediaTagRules::season('2026-04-15'));
        $this->assertSame('summer', MediaTagRules::season('2026-08-31'));
        $this->assertSame('fall', MediaTagRules::season('2026-11-30'));
        $this->assertNull(MediaTagRules::season(null));
        $this->assertSame('burnaby', MediaTagRules::area('V5G 1A1'));
        $this->assertSame('richmond', MediaTagRules::area(null, 49.17, -123.13));
        $this->assertSame('north-vancouver', MediaTagRules::area('V7L 1A1', null, null, 'North Vancouver'));
        $this->assertSame('home', MediaTagRules::propertyType('single_family'));
        $this->assertSame('commercial', MediaTagRules::propertyType('commercial'));
        $this->assertSame('strata', MediaTagRules::propertyType('townhouse'));
        $this->assertContains('consent/no', MediaTagRules::contextTags(['optout' => true]));
        $this->assertNotContains('pair/9', MediaTagRules::contextTags(['photo_type' => 'after', 'visit_id' => 9, 'has_pair' => false]));
    }

    public function testVisionFlagsMakeAPhotoBlurNeeded(): void
    {
        $ok = MediaTagRules::visionTags(['subjects' => ['hedge', 'lawn', 'spaceship'], 'quality' => 5, 'faces' => false, 'children' => false,
                                         'house_numbers' => false, 'licence_plates' => false, 'identifiable_street_front' => false]);
        $this->assertSame(['subject/hedge', 'subject/lawn', 'quality/5', 'privacy/ok'], $ok);
        foreach (['faces', 'children', 'house_numbers', 'licence_plates', 'identifiable_street_front'] as $flag) {
            $this->assertContains('privacy/blur-needed', MediaTagRules::visionTags(['quality' => 4, $flag => true]), $flag);
        }
    }

    public function testMergeKeepsTimsTagsAndUsageHistory(): void
    {
        $old = ['season/spring', 'used/2026-05-gbp', 'portfolio-pick', 'subject/lawn'];
        $new = MediaTagRules::merge($old, ['season/fall', 'consent/ok'], MediaTagRules::CONTEXT_NS);
        $this->assertSame(['consent/ok', 'portfolio-pick', 'season/fall', 'subject/lawn', 'used/2026-05-gbp'], $new);
    }

    public function testPublicUseNeedsPrivacyOkAndConsent(): void
    {
        $this->assertTrue(MediaTagRules::publicOk(['privacy/ok', 'consent/ok']));
        $this->assertFalse(MediaTagRules::publicOk(['privacy/blur-needed', 'consent/ok']));
        $this->assertFalse(MediaTagRules::publicOk(['privacy/ok', 'consent/no']));
        $this->assertFalse(MediaTagRules::publicOk(['consent/ok']), 'not checked yet = not public');
        $this->assertTrue(MediaTagRules::visitPublicOk([['stage/before', 'privacy/ok'], ['stage/after', 'privacy/ok']]));
        $this->assertFalse(MediaTagRules::visitPublicOk([['stage/before', 'privacy/ok'], ['stage/after', 'privacy/ok'], ['stage/during', 'privacy/blur-needed']]));
        $this->assertFalse(MediaTagRules::visitPublicOk([['stage/after', 'privacy/ok']]), 'needs a before too');
    }

    private static function item(int $id, array $tags): array
    {
        return ['id' => $id, 'file_path' => '/x/' . $id . '.jpg', 'tags' => $tags];
    }

    public function testPickerRanksPairsFirstAndExcludesUnsafeLowAndRecent(): void
    {
        $safe = ['privacy/ok', 'consent/ok', 'quality/4', 'season/fall', 'service/fall-cleanup'];
        $items = [
            self::item(1, array_merge($safe, ['stage/before', 'pair/10'])),
            self::item(2, array_merge($safe, ['stage/after', 'pair/10'])),
            self::item(3, array_merge($safe, ['use/hero', 'quality/5'])),                            // hero single
            self::item(4, ['privacy/blur-needed', 'consent/ok', 'quality/5', 'service/fall-cleanup']), // blur
            self::item(5, ['privacy/ok', 'consent/no', 'quality/5', 'service/fall-cleanup']),          // opted out
            self::item(6, ['privacy/ok', 'consent/ok', 'quality/3', 'service/fall-cleanup']),          // low quality
            self::item(7, $safe),                                                                      // used recently
            self::item(8, ['privacy/ok', 'consent/ok', 'quality/5', 'service/hedge-trimming']),        // other service
        ];
        $picks = MediaPickerService::rank($items, 'fall-cleanup', 'fall', [], [7 => '2026-09-01'], 5);
        $this->assertSame(['pair', 'single'], array_column($picks, 'kind'));
        $this->assertSame(2, $picks[0]['media_id'], 'the after leads a pair');
        $this->assertSame(1, $picks[0]['before_item']['id']);
        $this->assertSame(3, $picks[1]['media_id']);

        // No service match at all → fall back to any safe photo.
        $any = MediaPickerService::rank($items, 'snow-removal', null, [], [], 10);
        $this->assertContains(8, array_column($any, 'media_id'));
        $this->assertNotContains(4, array_column($any, 'media_id'));
    }

    public function testLearnedComboScoreReordersSingles(): void
    {
        $a = self::item(1, ['privacy/ok', 'quality/4', 'service/hedge-trimming', 'subject/hedge', 'stage/after']);
        $b = self::item(2, ['privacy/ok', 'quality/4', 'service/hedge-trimming', 'subject/lawn', 'stage/after']);
        $plain = MediaPickerService::rank([$a, $b], 'hedge', null, [], [], 2);
        $this->assertSame(2, $plain[0]['media_id'], 'tie → newest id');
        $learned = MediaPickerService::rank([$a, $b], 'hedge', null, ['hedge-trimming|hedge|after' => 1.0], [], 2);
        $this->assertSame(1, $learned[0]['media_id']);

        $scores = MediaTagService::comboScores([
            ['combo' => 'a', 'uses' => 2, 'engagement' => 40, 'clicks' => 0],
            ['combo' => 'b', 'uses' => 2, 'engagement' => 10, 'clicks' => 0],
            ['combo' => 'c', 'uses' => 1, 'engagement' => 500, 'clicks' => 0],
        ]);
        $this->assertSame(['a' => 0.6, 'b' => -0.6], $scores, 'one use is not evidence');
    }

    public function testCropBoxes(): void
    {
        $this->assertSame([420, 0, 3024, 3024], MediaPickerService::cropBox(3864, 3024, 1.0));
        $this->assertSame([0, 0, 4000, 3000], MediaPickerService::cropBox(4000, 3000, 4 / 3));
        $this->assertSame([0, 500, 3000, 2250], MediaPickerService::cropBox(3000, 3250, 4 / 3));
        $this->assertSame([0, 0, 800, 600], MediaPickerService::cropBox(800, 600, null));
        $this->assertSame([1080, 1080], MediaPickerService::SIZES['instagram']);
        $this->assertSame([1200, 900], MediaPickerService::SIZES['gbp']);
    }

    public function testVisionRequestShape(): void
    {
        $req = MediaTagService::buildVisionRequest(['media_type' => 'image/jpeg', 'data' => 'AAAA']);
        $this->assertSame('claude-sonnet-5-5', $req['model']);
        $this->assertSame('json_schema', $req['output_config']['format']['type']);
        $this->assertSame('image', $req['messages'][0]['content'][0]['type']);
        $this->assertContains('identifiable_street_front', MediaTagService::visionSchema()['required']);
    }

    public function testOttoGetsCompletedVisitsWithNoPhotos(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, visit_number TEXT, status TEXT, completed_at TEXT)");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, service_type TEXT)");
        $db->exec("CREATE TABLE visit_photos (id INTEGER PRIMARY KEY, visit_id INT, deleted_at TEXT)");
        $db->exec("INSERT INTO job_plans VALUES (1, 'Lawn Care')");
        $db->exec("INSERT INTO job_visits VALUES (1, 1, 'JOB-1', 'completed', '2026-10-04 15:00:00'), (2, 1, 'JOB-2', 'completed', '2026-10-05 15:00:00'),
                   (3, 1, 'JOB-3', 'completed', '2026-09-01 15:00:00'), (4, 1, 'JOB-4', 'scheduled', NULL)");
        $db->exec("INSERT INTO visit_photos VALUES (1, 2, NULL)");
        $items = MediaTagService::missingPhotoItems($db, '2026-10-06');
        $this->assertSame(['otto:no-photos:1'], array_column($items, 'key'));
        $this->assertSame(3, $items[0]['priority']);
    }
}
