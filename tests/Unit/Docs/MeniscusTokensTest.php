<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The meniscus tab bar (SPEC section 14) is drawn from the numbers in tokens.json → meniscus. This fails when a
 * platform's own file drifts from them. iPhone: its geometry file must READ the generated tokens (no literal copies).
 * Android lives in another repository: checked when its checkout is present (MOWOLOGY_ANDROID_DIR or the default
 * worktree), skipped otherwise. Its ItMeniscusToken.kt is generated from the same tokens.json by the Android repo's script.
 */
class MeniscusTokensTest extends TestCase
{
    private function tokens(): array
    {
        $json = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/docs/design/ittai-v1.2/tokens.json'), true);
        $out = [];
        foreach ($json['meniscus']['tokens'] as $t) $out[$t['name']] = (float)$t['value'];
        return $out;
    }

    public function testTheSectionHasEveryNumberTheSpecNames(): void
    {
        $names = array_keys($this->tokens());
        foreach (['bar-height', 'bead', 'gap', 'rise', 'shoulder', 'join-angle', 'icon-top', 'icon', 'bead-glyph', 'label-size',
                  'spring-response', 'spring-damping', 'spring-rest', 'spring-cap', 'sample-rate', 'action-morph', 'roll-fade',
                  'still-epsilon', 'landing', 'icon-fade', 'long-press', 'pressed-opacity'] as $n) {
            $this->assertContains('meniscus-' . $n, $names, $n);
        }
    }

    public function testTheIphoneReadsTheTokensInsteadOfCopyingThem(): void
    {
        $dir = dirname(__DIR__, 3) . '/ios/MowologyCRM/MowologyCRM/Core/Design/Ittai/';
        $geo = (string)file_get_contents($dir . 'ItMeniscusGeometry.swift');
        foreach (['bead', 'gap', 'rise', 'shoulder', 'joinAngle', 'springResponse', 'springDamping', 'springRest', 'springCap', 'sampleRate', 'actionMorph', 'rollFade', 'stillEpsilon'] as $n) {
            $this->assertStringContainsString('ItMeniscusToken.' . $n, $geo, $n);
        }
        $this->assertDoesNotMatchRegularExpression('/static let (bead|gap|rise|shoulder)\b[^=]*=\s*\d/', $geo, 'a literal copy of a token');
        $bar = (string)file_get_contents($dir . 'ItMeniscusBar.swift');
        foreach (['barHeight', 'iconTop', 'icon', 'iconFade', 'labelSize', 'longPress', 'pressedOpacity'] as $n) {
            $this->assertStringContainsString('ItMeniscusToken.' . $n, $bar, $n);
        }
    }

    public function testAndroidReadsTheGeneratedTokens(): void
    {
        $base = getenv('MOWOLOGY_ANDROID_DIR') ?: (getenv('HOME') . '/Projects/mowology-android-codex');
        $dir = $base . '/android/app/src/main/java/ca/mowology/crew/ui/ittai/';
        if (!is_file($dir . 'MeniscusGeometry.kt') || !is_file($dir . 'ItMeniscusBar.kt')) $this->markTestSkipped('the Android checkout is not here');
        $this->assertFileExists($dir . 'ItMeniscusToken.kt', 'run node scripts/ittai-kotlin-tokens.mjs in the Android repo');
        // 1. the generated Kotlin file carries exactly tokens.json's numbers
        $gen = (string)file_get_contents($dir . 'ItMeniscusToken.kt');
        foreach ($this->tokens() as $name => $value) {
            $const = strtoupper(str_replace('-', '_', preg_replace('/^meniscus-/', '', $name)));
            $this->assertEquals(1, preg_match('/const val ' . $const . ' = ([0-9.]+)f?\b/', $gen, $m), $const);
            $this->assertEqualsWithDelta($value, (float)$m[1], 0.00001, "ItMeniscusToken.$const vs $name");
        }
        // 2. the bar's own files read them instead of re-typing them
        $geo = (string)file_get_contents($dir . 'MeniscusGeometry.kt');
        $bar = (string)file_get_contents($dir . 'ItMeniscusBar.kt');
        foreach (['BEAD', 'GAP', 'RISE', 'SHOULDER', 'BAR_HEIGHT', 'ICON_TOP', 'ICON', 'BEAD_GLYPH', 'LABEL_SIZE', 'JOIN_ANGLE', 'LANDING', 'SAMPLE_RATE',
                  'SPRING_RESPONSE', 'SPRING_DAMPING', 'SPRING_REST', 'SPRING_CAP', 'ACTION_MORPH', 'ROLL_FADE', 'STILL_EPSILON', 'ICON_LABEL_GAP'] as $c) {
            $this->assertStringContainsString('ItMeniscusToken.' . $c, $geo, $c);
        }
        foreach (['LONG_PRESS', 'ICON_FADE', 'PRESSED_OPACITY'] as $c) $this->assertStringContainsString('ItMeniscusToken.' . $c, $bar, $c);
        $this->assertDoesNotMatchRegularExpression('/const val (BEAD|GAP|RISE|SHOULDER|BAR_HEIGHT|ICON_TOP|ICON|BEAD_GLYPH|LABEL_SP|RESPONSE|DAMPING|REST|CAP) = [0-9]/', $geo, 'a literal copy of a token');
    }
}
