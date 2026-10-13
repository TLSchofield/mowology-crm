<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Reading a machine's model/serial plate (EquipmentPlateParser, Otto's Add equipment, 2026-10-13).
 * The Honda text is Tim's real mower plate as Vision returns it (label and value on separate lines);
 * the others are synthetic plates in the layouts dealers' machines use.
 */
final class EquipmentPlateParserTest extends TestCase
{
    public const HONDA = "HRC216 Hydrostatic Commercial\nMODEL\nHRC216K3HXCH\nSERIAL NO.\nMAKA-2058647\nAmerican Honda Motor Company, Inc.\nAssembled in USA";

    public function testTimsHondaPlate(): void
    {
        $p = EquipmentPlateParser::parse(self::HONDA);
        $this->assertSame(['Honda', 'HRC216K3HXCH', 'MAKA-2058647'], [$p['make'], $p['model'], $p['serial']]);
        $this->assertSame(['mower', 'gas'], [$p['equipment_class'], $p['power_source']], 'Honda HRC… is a gas mower');
        $this->assertSame('HRC216 Hydrostatic Commercial', $p['model_name']);
        $this->assertSame('Honda HRC216 mower', $p['display_name']);
        $this->assertSame('HRC216K3HXCH', $p['model_key']);
        $this->assertNull($p['engine_make']);
        $this->assertSame(1.0, $p['confidence']);
    }

    public function testSameLineLabelsAndOneLineOcr(): void
    {
        $p = EquipmentPlateParser::parse("MODEL HRC216K3HXCH SERIAL NO. MAKA-2058647\nAmerican Honda Motor Company, Inc. Assembled in USA");
        $this->assertSame(['Honda', 'HRC216K3HXCH', 'MAKA-2058647'], [$p['make'], $p['model'], $p['serial']]);
    }

    public function testStihlSlashLabelAndSpacedModel(): void
    {
        $p = EquipmentPlateParser::parse("STIHL\nFS 91 R\nMod./Type: FS 91 R\nS/N: 512345678\nAndreas Stihl AG & Co. KG");
        $this->assertSame(['Stihl', 'FS 91 R', 'FS91R', '512345678'], [$p['make'], $p['model'], $p['model_key'], $p['serial']]);
        $this->assertSame(['trimmer', 'gas'], [$p['equipment_class'], $p['power_source']]);
        $this->assertNull($p['model_name'], 'a company line is never the machine name');
    }

    public function testEgoBatteryMowerWithGroupedSerial(): void
    {
        $p = EquipmentPlateParser::parse("EGO POWER+\n56V\nM/N: LM2135SP\nS/N 2203 1234 5678\nChervon");
        $this->assertSame(['EGO', 'LM2135SP', '2203 1234 5678'], [$p['make'], $p['model'], $p['serial']]);
        $this->assertSame(['mower', 'battery'], [$p['equipment_class'], $p['power_source']]);
    }

    public function testEngineMakerBesideMachineMakerAndBilingualLabels(): void
    {
        $p = EquipmentPlateParser::parse("TORO\nMODEL / MODÈLE\n22299\nSERIAL NO. / NO DE SÉRIE\n321000123\nBriggs & Stratton engine");
        $this->assertSame(['Toro', 'Briggs & Stratton', '22299', '321000123'], [$p['make'], $p['engine_make'], $p['model'], $p['serial']]);
        $this->assertSame('gas', $p['power_source']);
        $x = EquipmentPlateParser::parse("EXMARK\nMODEL NO. LZX940EKC72400\nSERIAL NO. 412345678\nKawasaki FX1000V");
        $this->assertSame(['Exmark', 'Kawasaki', 'mower'], [$x['make'], $x['engine_make'], $x['equipment_class']]);
    }

    public function testNothingReadableAndWordsThatAreNotLabels(): void
    {
        $this->assertSame([null, null, null, 0.0], array_values(array_intersect_key(EquipmentPlateParser::parse(''), array_flip(['make', 'model', 'serial', 'confidence']))));
        $p = EquipmentPlateParser::parse("SNOW BLOWER\nSERVICE 25 HRS\nMODEL: 2450");
        $this->assertSame('2450', $p['model']);
        $this->assertNull($p['serial'], '"SNOW" and "SERVICE" are not serial labels');
        $this->assertSame('blower', $p['equipment_class']);
    }

    public function testLabelReaderUsesThePlateParserForSplitLines(): void
    {
        $r = LabelReaderService::read(self::HONDA);
        $this->assertSame(['machine', 'Honda', 'HRC216K3HXCH', 'MAKA-2058647', 'mower', 'gas'],
                          [$r['kind'], $r['brand'], $r['model'], $r['serial'], $r['equipment_class'], $r['power_source']]);
    }
}
