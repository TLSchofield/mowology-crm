<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The free label reader: Tim's real seed-bag photo (2026-10-07) and a machine nameplate.
 * Care advice comes only from the label's own words — a bag that prints none gets none.
 */
class LabelReaderServiceTest extends TestCase
{
    public const SEED = "SUN & SHADE\nLAWN SEED\n50% Turf Type Perennial Ryegrass\n30% Creeping Red Fescue\n15% Chewing's Fescue\n5% Kentucky Bluegrass\n"
                      . "Wt: 5 KG\nCANADA NO.1 LAWN MIXTURE\nItem# TL02100350\nB# 260618-CATL-027235\nRichardson Seed\nTerraLink Horticulture Inc.\n464 Riverside Road, Abbotsford BC";
    public const EGO = "EGO POWER+\n56V LITHIUM-ION\nLAWN MOWER\nModel LM2135SP\nSerial No. NLM2135SP2104000123\nRated Voltage 56V DC\nChervon (HK) Ltd.\nMade in China";

    public function test_seed_bag_reads_name_sku_size_mix_batch_and_maker(): void
    {
        $r = LabelReaderService::read(self::SEED);
        $this->assertSame('product', $r['kind']);
        $this->assertSame('Richardson Sun & Shade Lawn Seed 5 kg', $r['display_name']);
        $this->assertSame('Sun & Shade Lawn Seed', $r['name']);
        $this->assertSame('Richardson Seed', $r['brand']);
        $this->assertSame('TerraLink Horticulture Inc.', $r['maker']);
        $this->assertSame('TL02100350', $r['sku']);
        $this->assertSame('5kg', $r['size']);
        $this->assertSame('260618-CATL-027235', $r['batch']);
        $this->assertSame('464 Riverside Road, Abbotsford BC', $r['address']);
        $this->assertSame('CANADA NO.1 LAWN MIXTURE', $r['grade']);
        $this->assertCount(4, $r['composition']);
        $this->assertSame(['pct' => 50.0, 'what' => 'Turf Type Perennial Ryegrass'], $r['composition'][0]);
        $this->assertSame(5.0, $r['composition'][3]['pct']);
        $this->assertNull($r['serial']);
    }

    public function test_no_care_advice_is_invented_when_the_label_prints_none(): void
    {
        $r = LabelReaderService::read(self::SEED);
        $this->assertSame([], $r['care']);
        $this->assertSame([], $r['safety']);
        $this->assertSame('', LabelReaderService::careText($r));
    }

    public function test_storage_and_caution_lines_are_taken_word_for_word(): void
    {
        $r = LabelReaderService::read(self::SEED . "\nSTORE IN A COOL DRY PLACE\nCaution: treated seed, do not use for food or feed");
        $this->assertSame(['Store in a cool dry place.'], $r['care']);
        $this->assertSame(['Caution: treated seed, do not use for food or feed.'], $r['safety']);
        $this->assertSame('Store in a cool dry place. Caution: treated seed, do not use for food or feed.', LabelReaderService::careText($r));
    }

    public function test_machine_nameplate_reads_make_model_serial_volts_and_class(): void
    {
        $r = LabelReaderService::read(self::EGO);
        $this->assertSame('machine', $r['kind']);
        $this->assertSame('EGO', $r['brand']);
        $this->assertSame('LM2135SP', $r['model']);
        $this->assertSame('NLM2135SP2104000123', $r['serial']);
        $this->assertSame('56V', $r['voltage']);
        $this->assertSame('mower', $r['equipment_class']);
        $this->assertSame('battery', $r['power_source']);
        $this->assertSame('EGO LM2135SP lawn mower', $r['display_name']);
    }

    public function test_gas_trimmer_is_gas_and_a_trimmer(): void
    {
        $r = LabelReaderService::read("STIHL\nFS 91 R\nString Trimmer\nModel FS91R\nS/N 523456789\n28.4 cc 2-stroke engine");
        $this->assertSame('machine', $r['kind']);
        $this->assertSame('Stihl', $r['brand']);
        $this->assertSame('trimmer', $r['equipment_class']);
        $this->assertSame('gas', $r['power_source']);
        $this->assertSame('523456789', $r['serial']);
    }

    public function test_sizes_and_names_normalise_alike(): void
    {
        $this->assertSame('5kg', LabelReaderService::sizeOf('2 B. 5K Seed'));
        $this->assertSame('5kg', LabelReaderService::sizeOf('Lawn Seed 5 kg'));
        $this->assertSame('10l', LabelReaderService::sizeOf('Bar oil 10 L'));
        $this->assertSame('1yd', LabelReaderService::sizeOf('CBM 1 yard'));
        $this->assertNull(LabelReaderService::sizeOf('Black Composted Bark Mulch'));
        $this->assertSame(['seed'], LabelReaderService::words('2 B. 5K Seed'));
        $this->assertSame(LabelReaderService::normName('Black Mulch Bag 2'), LabelReaderService::normName('BLACK MULCH'));
    }
}
