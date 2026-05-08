<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for VariantQuestionService
 */
class VariantQuestionServiceTest extends TestCase
{
    private function makeStmt(
        mixed $fetchReturn    = false,
        array $fetchAllReturn = [],
        mixed $fetchColReturn = 0
    ): PDOStatement {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($fetchReturn);
        $stmt->method('fetchAll')->willReturn($fetchAllReturn);
        $stmt->method('fetchColumn')->willReturn($fetchColReturn);
        return $stmt;
    }

    // ── buildDistractors tests ────────────────────────────────────────────────

    public function test_build_distractors_returns_empty_when_variant_not_found(): void
    {
        $db   = $this->createMock(PDO::class);
        $stmt = $this->makeStmt(false); // variant lookup returns nothing
        $db->method('prepare')->willReturn($stmt);

        $svc    = new VariantQuestionService($db);
        $result = $svc->buildDistractors(99, 1);

        $this->assertEmpty($result);
    }

    public function test_build_distractors_returns_four_options(): void
    {
        $db = $this->createMock(PDO::class);

        $variantRow = ['parent_id' => 5, 'option_id' => 10, 'option_text' => 'Chlorantraniliprole'];
        $distractors = [
            ['option_text' => 'Imidacloprid',  'times_selected' => 15, 'distractor_source_question_id' => 6],
            ['option_text' => 'Bifenthrin',    'times_selected' => 10, 'distractor_source_question_id' => 7],
            ['option_text' => 'Glyphosate',    'times_selected' => 8,  'distractor_source_question_id' => 8],
        ];

        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $this->makeStmt($variantRow),               // variant + correct option lookup
            $this->makeStmt(false, $distractors)        // sibling wrong options
        );

        $svc    = new VariantQuestionService($db);
        $result = $svc->buildDistractors(3, 2);

        $this->assertCount(4, $result);
        $correctOptions = array_filter($result, fn($o) => $o['is_correct']);
        $this->assertCount(1, $correctOptions);
        $wrongOptions = array_filter($result, fn($o) => !$o['is_correct']);
        $this->assertCount(3, $wrongOptions);
    }

    public function test_build_distractors_correct_option_text_matches_parent(): void
    {
        $db = $this->createMock(PDO::class);

        $variantRow  = ['parent_id' => 5, 'option_id' => 10, 'option_text' => 'Chlorantraniliprole'];
        $distractors = [
            ['option_text' => 'Imidacloprid', 'times_selected' => 5, 'distractor_source_question_id' => 6],
            ['option_text' => 'Bifenthrin',   'times_selected' => 3, 'distractor_source_question_id' => 7],
            ['option_text' => 'Deltamethrin', 'times_selected' => 2, 'distractor_source_question_id' => 8],
        ];

        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $this->makeStmt($variantRow),
            $this->makeStmt(false, $distractors)
        );

        $svc    = new VariantQuestionService($db);
        $result = $svc->buildDistractors(3, 2);

        $correctTexts = array_column(array_filter($result, fn($o) => $o['is_correct']), 'option_text');
        $this->assertContains('Chlorantraniliprole', $correctTexts);
    }

    public function test_build_distractors_pads_to_four_when_pool_is_thin(): void
    {
        $db = $this->createMock(PDO::class);

        $variantRow  = ['parent_id' => 5, 'option_id' => 10, 'option_text' => 'Chlorantraniliprole'];
        $distractors = [
            // Only one distractor available
            ['option_text' => 'Imidacloprid', 'times_selected' => 5, 'distractor_source_question_id' => 6],
        ];

        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $this->makeStmt($variantRow),
            $this->makeStmt(false, $distractors)
        );

        $svc    = new VariantQuestionService($db);
        $result = $svc->buildDistractors(3, 2);

        // Must still return 4 options (padded with placeholder)
        $this->assertCount(4, $result);
    }

    // ── generateReverseVariantDraft tests ────────────────────────────────────

    public function test_generate_reverse_draft_returns_empty_for_unknown_question(): void
    {
        $db   = $this->createMock(PDO::class);
        $stmt = $this->makeStmt(false); // parent question lookup returns nothing
        $db->method('prepare')->willReturn($stmt);

        $svc    = new VariantQuestionService($db);
        $result = $svc->generateReverseVariantDraft(999);

        $this->assertEmpty($result);
    }

    public function test_generate_reverse_draft_returns_expected_structure(): void
    {
        $db = $this->createMock(PDO::class);

        $parentRow = [
            'question_text'        => 'What is the active ingredient in Acelepryn?',
            'category_id'          => 3,
            'correct_option_id'    => 10,
            'correct_option_text'  => 'Chlorantraniliprole',
        ];
        $siblingCorrects = [
            ['option_text' => 'Merit 2F',  'question_id' => 6],
            ['option_text' => 'Talstar',   'question_id' => 7],
            ['option_text' => 'Banol',     'question_id' => 8],
        ];

        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $this->makeStmt($parentRow),
            $this->makeStmt(false, $siblingCorrects)
        );

        $svc    = new VariantQuestionService($db);
        $result = $svc->generateReverseVariantDraft(5);

        $this->assertArrayHasKey('variant_type', $result);
        $this->assertSame('reverse', $result['variant_type']);
        $this->assertArrayHasKey('question_text', $result);
        $this->assertStringContainsString('Chlorantraniliprole', $result['question_text']);
        $this->assertArrayHasKey('options', $result);
        $this->assertTrue($result['is_draft']);
    }

    // ── pickVariantOrParent tests ─────────────────────────────────────────────

    public function test_pick_variant_returns_parent_when_no_variants(): void
    {
        $db   = $this->createMock(PDO::class);
        $stmt = $this->makeStmt(false, []); // no variants found
        $db->method('prepare')->willReturn($stmt);

        $svc    = new VariantQuestionService($db);
        $result = $svc->pickVariantOrParent(5, 1);

        $this->assertSame('parent', $result['type']);
        $this->assertSame(5, $result['id']);
        $this->assertFalse($result['is_variant']);
        $this->assertNull($result['variant_type']);
    }

    public function test_pick_variant_returns_variant_when_available(): void
    {
        $db   = $this->createMock(PDO::class);
        $stmt = $this->makeStmt(['id' => 42, 'variant_type' => 'rephrase']); // variant found
        $db->method('prepare')->willReturn($stmt);

        $svc    = new VariantQuestionService($db);
        $result = $svc->pickVariantOrParent(5, 1);

        $this->assertSame('variant', $result['type']);
        $this->assertSame(42, $result['id']);
        $this->assertTrue($result['is_variant']);
        $this->assertSame('rephrase', $result['variant_type']);
    }

    // ── saveVariant tests ─────────────────────────────────────────────────────

    public function test_save_variant_inserts_new_and_returns_id(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('lastInsertId')->willReturn('77');

        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $this->makeStmt(), // INSERT variant
            $this->makeStmt(), // DELETE old options
            $this->makeStmt(), // INSERT option 1
            $this->makeStmt()  // INSERT option 2
        );

        $svc = new VariantQuestionService($db);
        $id  = $svc->saveVariant(
            5,
            'rephrase',
            'Acelepryn contains which active ingredient?',
            'Variant of the canonical Acelepryn question.',
            [
                ['option_text' => 'Chlorantraniliprole', 'is_correct' => true,  'distractor_source_question_id' => null],
                ['option_text' => 'Imidacloprid',        'is_correct' => false, 'distractor_source_question_id' => 6],
            ],
            1
        );

        $this->assertSame(77, $id);
    }
}
