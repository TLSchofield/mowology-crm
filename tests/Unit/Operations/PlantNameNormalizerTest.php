<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** One plant, many spellings: the botanical normaliser and the part-number matcher (migration 1331). */
final class PlantNameNormalizerTest extends TestCase
{
    public function testSplitsSeriesFromCultivar(): void
    {
        $p = PlantNameNormalizer::parse("Armeria Dreamaria® 'Vivid Dreams'");
        $this->assertSame(['armeria', '', 'vivid dreams', 'dreamaria'], [$p['genus'], $p['species'], $p['cultivar'], $p['series']]);
        $this->assertSame("Armeria Dreamaria 'Vivid Dreams'", $p['display']);
    }

    public function testQuotesMarksTagsAndCommonNames(): void
    {
        $a = PlantNameNormalizer::parse("Heuchera Primo® 'Pistachio Ambrosia' (PW)");
        $b = PlantNameNormalizer::parse('HEUCHERA  PRIMO™ “Pistachio Ambrosia”');
        $this->assertSame($a['key'], $b['key']);
        $this->assertSame(['PW'], $a['tags']);

        $c = PlantNameNormalizer::parse('Prunus x yedoensis (Flowering Cherry)');
        $this->assertTrue($c['hybrid']);
        $this->assertSame('yedoensis', $c['species']);
        $this->assertSame('', $c['cultivar']);
        $this->assertSame(['flowering cherry'], $c['aliases']);

        $d = PlantNameNormalizer::parse("Pieris japonica 'Scarlet O'Hara'");
        $e = PlantNameNormalizer::parse('Pieris japonica ‘Scarlet OHara’');
        $this->assertSame('scarlet ohara', $d['cultivar']);
        $this->assertSame($d['key'], $e['key']);

        $f = PlantNameNormalizer::parse("Sorbaria sorbifolia 'Matcha Ball®' (FE)");
        $this->assertSame('matcha ball', $f['cultivar']);
        $this->assertSame(['FE'], $f['tags']);
    }

    public function testHybridAndVariety(): void
    {
        $a = PlantNameNormalizer::parse("Anemone x 'Whirlwind'");
        $b = PlantNameNormalizer::parse("Anemone 'Whirlwind'");
        $this->assertSame($a['key'], $b['key'], 'the hybrid sign is not part of the key');
        $v = PlantNameNormalizer::parse("Hydrangea macrophylla var. normalis 'Blue Wave'");
        $this->assertSame('macrophylla var normalis', $v['species']);
        $this->assertSame('blue wave', $v['cultivar']);
        $g = PlantNameNormalizer::parse("Gentiana x Little Pinkie® 'Ashiro'");
        $this->assertSame(['little pinkie', 'ashiro'], [$g['series'], $g['cultivar']]);
    }

    public function testUnquotedCultivar(): void
    {
        $a = PlantNameNormalizer::parse('Lavandula angustifolia Hidcote');
        $b = PlantNameNormalizer::parse("Lavandula angustifolia 'Hidcote'");
        $this->assertSame($b['key'], $a['key']);
    }

    public function testCultivarKeyIgnoresSeries(): void
    {
        $a = PlantNameNormalizer::parse("Armeria Dreamaria® 'Vivid Dreams'");
        $b = PlantNameNormalizer::parse("Armeria 'Vivid Dreams'");
        $this->assertNotSame($a['key'], $b['key']);
        $this->assertSame($a['cultivar_key'], $b['cultivar_key']);
    }

    public function testSizes(): void
    {
        foreach (['No.1 Pot', '#1', '1 gal', '1G', '#1 pot', '1 Gallon'] as $s) $this->assertSame('no1', PlantNameNormalizer::size($s)['code'], $s);
        $this->assertSame('11cm', PlantNameNormalizer::size('11cm Round')['code']);
        $this->assertSame('qt', PlantNameNormalizer::size('Quart Pot')['code']);
        $this->assertSame('4in', PlantNameNormalizer::size('4inch Pot')['code']);
        $this->assertSame('no5-planter', PlantNameNormalizer::size('No.5 Planter')['code']);
        $this->assertSame(['code' => 'no5', 'height' => '5-6ft'], PlantNameNormalizer::size('5-6ft No.5 Pot'));
        $this->assertSame(['code' => 'no15', 'height' => '5-6ft'], PlantNameNormalizer::size('5-6ft No.15Pot'));
        $this->assertSame(['code' => 'no2', 'height' => '3ft+'], PlantNameNormalizer::size('3ft+ No.2 Pot'));
    }

    public function testSimilarityOnlySuggestsLikelySame(): void
    {
        $p = fn($s) => PlantNameNormalizer::parse($s);
        $this->assertSame(0.9, PlantNameNormalizer::similarity($p("Lavandula 'Hidcote'"), $p("Lavandula angustifolia 'Hidcote'")));
        $this->assertLessThan(CatalogService::SUGGEST_AT, PlantNameNormalizer::similarity($p("Lavandula angustifolia 'Hidcote'"), $p("Lavandula angustifolia 'Munstead'")));
        $this->assertGreaterThanOrEqual(CatalogService::SUGGEST_AT, PlantNameNormalizer::similarity($p("Hosta 'Frances Williams'"), $p("Hosta 'Francis Williams'")));
        $this->assertSame(0.0, PlantNameNormalizer::similarity($p('Prunus x yedoensis'), $p("Prunus x yedoensis 'Akebono'")));
        $this->assertSame(0.0, PlantNameNormalizer::similarity($p("Acer 'X'"), $p("Betula 'X'")));
        // Seen on a real list: these are different plants and must not even be suggested.
        $this->assertSame(0.0, PlantNameNormalizer::similarity($p("Echinacea purpurea 'Sunseekers Red'"), $p("Echinacea purpurea 'Sunseekers Orange'")));
        $this->assertSame(0.0, PlantNameNormalizer::similarity($p("Hydrangea paniculata 'Little Lime Punch'"), $p("Hydrangea paniculata 'Little Lime'")));
        $this->assertSame(0.0, PlantNameNormalizer::similarity($p("Achillea Milly Rock™ 'Rose'"), $p("Achillea millefolium New Vintage 'Rose'")));
        $this->assertSame(0.0, PlantNameNormalizer::similarity($p("Malus '4 Way Combo'"), $p("Malus '3 Way Combo'")));
        $this->assertSame(0.0, PlantNameNormalizer::similarity($p("Hydrangea paniculata STD 'Phantom'"), $p("Hydrangea paniculata 'Phantom'")));
    }

    public function testFormAndSeriesKeepPlantsApart(): void
    {
        $std = PlantNameNormalizer::parse("Hydrangea paniculata STD 'Phantom'");
        $shrub = PlantNameNormalizer::parse("Hydrangea paniculata 'Phantom'");
        $this->assertSame('std', $std['form']);
        $this->assertNotSame($std['cultivar_key'], $shrub['cultivar_key'], 'a tree-form standard is not the shrub');
        $this->assertSame($std['key'], PlantNameNormalizer::parse($std['display'])['key'], 'display re-parses to the same key');
        $m = new PlantMatcher();
        $a = $m->parse(['name' => "Phlox paniculata Ka-Pow 'Purple'"]);
        $this->assertTrue($m->accepts($a, $m->fromCatalog(['match_key' => 'phlox|paniculata|purple|kapow']), 'alt'), 'series written differently');
        $this->assertTrue($m->accepts($a, $m->fromCatalog(['match_key' => 'phlox|paniculata|purple|']), 'alt'), 'series left out');
        $this->assertFalse($m->accepts($a, $m->fromCatalog(['match_key' => 'phlox|paniculata|purple|flame']), 'alt'), 'another series');
    }

    public function testPartNumberMatcher(): void
    {
        $this->assertSame('036000291452', PartNumberMatcher::upc('0 36000 29145 2'));
        $this->assertNull(PartNumberMatcher::upc('036000291453'), 'bad check digit');
        $this->assertSame('SCH4012', PartNumberMatcher::mpn('sch-40 1/2'));

        $m = CatalogMatchers::for('part_number');
        $a = $m->parse(['name' => 'PVC Elbow 90 1/2in', 'brand' => 'Acme', 'mfr_part_no' => 'EL-90-050', 'upc' => '036000291452']);
        $this->assertSame('upc:036000291452', $a['key']);
        $this->assertSame(['upc', 'mpn', 'exact', 'alt'], array_column($a['lookups'], 'how'));
        $b = $m->parse(['name' => 'PVC elbow 90° ½"', 'brand' => 'ACME']);
        $this->assertSame('bn:acme|pvc elbow 90', substr($b['key'], 0, 20));
        $this->assertSame(0.0, $m->similarity($a, $m->parse(['name' => 'PVC Elbow 90 1/2in', 'brand' => 'Other'])));

        $this->assertSame('1/2in', $m->size('1/2"')['code']);
        $this->assertSame('1-1/2in', $m->size('1 1/2 inch')['code']);
        $this->assertSame('14awg', $m->size('14 AWG')['code']);
        $this->assertInstanceOf(PlantMatcher::class, CatalogMatchers::for('botanical'));
    }
}
