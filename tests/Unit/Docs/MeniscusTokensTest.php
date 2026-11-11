<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The meniscus tab bar (SPEC section 14) is drawn from the numbers in tokens.json → meniscus. This fails when a
 * platform's own file drifts from them. iPhone: its geometry file must READ the generated tokens (no literal copies).
 * Android lives in another repository: compared when its checkout is present (MOWOLOGY_ANDROID_DIR or the default
 * worktree), skipped otherwise.
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

    public function testAndroidHoldsTheSameNumbers(): void
    {
        $base = getenv('MOWOLOGY_ANDROID_DIR') ?: (getenv('HOME') . '/Projects/mowology-android-codex');
        $dir = $base . '/android/app/src/main/java/ca/mowology/crew/ui/ittai/';
        if (!is_file($dir . 'MeniscusGeometry.kt') || !is_file($dir . 'ItMeniscusBar.kt')) $this->markTestSkipped('the Android checkout is not here');
        $geo = (string)file_get_contents($dir . 'MeniscusGeometry.kt');
        $bar = (string)file_get_contents($dir . 'ItMeniscusBar.kt');
        $t = $this->tokens();
        $const = static function (string $src, string $name): ?float {
            return preg_match('/const val ' . $name . '\s*=\s*([0-9.]+)/', $src, $m) ? (float)$m[1] : null;
        };
        $map = ['bar-height' => 'BAR_HEIGHT', 'bead' => 'BEAD', 'gap' => 'GAP', 'rise' => 'RISE', 'shoulder' => 'SHOULDER', 'join-angle' => 'JOIN_DEG',
                'icon-top' => 'ICON_TOP', 'icon' => 'ICON', 'bead-glyph' => 'BEAD_GLYPH', 'label-size' => 'LABEL_SP',
                'spring-response' => 'RESPONSE', 'spring-damping' => 'DAMPING', 'spring-rest' => 'REST', 'spring-cap' => 'CAP', 'landing' => 'LANDING'];
        foreach ($map as $token => $kt) {
            $this->assertEqualsWithDelta($t['meniscus-' . $token], $const($geo, $kt), 0.00001, "Android $kt vs meniscus-$token");
        }
        $this->assertStringContainsString('1.0 / ' . (int)$t['meniscus-sample-rate'], $geo, 'sample rate');
        // thresholds that Android keeps as literals
        foreach (['meniscus-action-morph' => '0.33f', 'meniscus-roll-fade' => '0.25f', 'meniscus-still-epsilon' => '0.01f'] as $token => $lit) {
            $this->assertEqualsWithDelta($t[$token], (float)$lit, 0.00001);
            $this->assertStringContainsString($lit, $geo, $token);
        }
        $this->assertStringContainsString((string)(int)($t['meniscus-long-press'] * 1000) . 'L', $bar, 'long press');
        $this->assertStringContainsString('tween(' . (int)($t['meniscus-icon-fade'] * 1000), $bar, 'icon fade');
        $this->assertStringContainsString((string)$t['meniscus-pressed-opacity'] . 'f', $bar, 'pressed opacity');
    }
}
