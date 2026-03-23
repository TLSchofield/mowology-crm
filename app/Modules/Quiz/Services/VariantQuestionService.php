<?php
declare(strict_types=1);
/**
 * VariantQuestionService
 *
 * Manages variant questions — rephrased, reversed, application, and
 * customer-explanation forms of canonical quiz questions.
 *
 * The key innovation: wrong answers from SIBLING questions in the same
 * category become distractors in variant questions. This means students
 * see real concept names as wrong answers, forcing genuine knowledge
 * discrimination rather than picking the obviously absurd option.
 *
 * Example: "What is the active ingredient in Acelepryn?" has correct
 * answer "Chlorantraniliprole". A variant's distractors might be
 * "Imidacloprid" (from Merit 2F question) and "Bifenthrin" (from
 * Talstar question) — all real pest control chemicals, all plausible.
 */
class VariantQuestionService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Build distractor options for a variant question.
     * Pulls wrong options from sibling questions in the same category,
     * ranked by how often crew selected them (most-chosen = most plausible).
     * Returns 4 options: 1 correct + 3 distractors, shuffled.
     *
     * @return array [['option_text' => str, 'is_correct' => bool, 'distractor_source_question_id' => int|null]]
     */
    public function buildDistractors(int $variantId, int $categoryId): array
    {
        // Get the correct answer from the parent question
        $stmt = $this->db->prepare(
            "SELECT v.parent_id, qo.id AS option_id, qo.option_text
             FROM quiz_question_variants v
             JOIN quiz_options qo ON qo.question_id = v.parent_id AND qo.is_correct = 1
             WHERE v.id = ? LIMIT 1"
        );
        $stmt->execute([$variantId]);
        $correct = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$correct) return [];

        $parentId    = (int)$correct['parent_id'];
        $correctText = $correct['option_text'];

        // Pull best distractors from sibling wrong options
        // Rank by selection frequency (most-chosen wrong answers are most confusing)
        $stmt = $this->db->prepare(
            "SELECT qo.option_text,
                    COUNT(qa.id)   AS times_selected,
                    qo.question_id AS distractor_source_question_id
             FROM quiz_options qo
             LEFT JOIN quiz_answers qa ON qa.selected_option_id = qo.id
             JOIN quiz_questions qq   ON qq.id = qo.question_id
             WHERE qq.category_id  = ?
               AND qo.is_correct   = 0
               AND qo.question_id  != ?
               AND qq.is_active    = 1
               AND qo.option_text  != ?
             GROUP BY qo.id, qo.option_text, qo.question_id
             ORDER BY times_selected DESC, RAND()
             LIMIT 6"
        );
        $stmt->execute([$categoryId, $parentId, $correctText]);
        $pool = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Deduplicate by option_text (different questions may share phrasing)
        $seen       = [];
        $distractors = [];
        foreach ($pool as $d) {
            $key = strtolower(trim($d['option_text']));
            if (!isset($seen[$key])) {
                $seen[$key]   = true;
                $distractors[] = $d;
                if (count($distractors) >= 3) break;
            }
        }

        // Build final options array
        $options = [
            [
                'option_text'                    => $correctText,
                'is_correct'                     => true,
                'distractor_source_question_id'  => null,
            ],
        ];
        foreach ($distractors as $d) {
            $options[] = [
                'option_text'                   => $d['option_text'],
                'is_correct'                    => false,
                'distractor_source_question_id' => (int)$d['distractor_source_question_id'],
            ];
        }

        // Pad to 4 if pool was thin
        while (count($options) < 4) {
            $options[] = [
                'option_text'                   => '(Insufficient options — add more questions to this category)',
                'is_correct'                    => false,
                'distractor_source_question_id' => null,
            ];
        }

        shuffle($options);
        return $options;
    }

    /**
     * Generate a draft reverse-recall variant for a question.
     * Reverse: the correct answer becomes the question stem;
     * the question text becomes one of the wrong options;
     * other correct answers from the category become additional wrong options.
     *
     * Returns draft data (not persisted — admin reviews before activating).
     *
     * Example:
     *   Parent: "What is the active ingredient in Acelepryn?" → "Chlorantraniliprole"
     *   Reverse: "Chlorantraniliprole is found in which product?" → ["Acelepryn","Merit 2F","Talstar","Banol"]
     */
    public function generateReverseVariantDraft(int $parentQuestionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT qq.question_text, qq.category_id,
                    qo.id AS correct_option_id, qo.option_text AS correct_option_text
             FROM quiz_questions qq
             JOIN quiz_options qo ON qo.question_id = qq.id AND qo.is_correct = 1
             WHERE qq.id = ? LIMIT 1"
        );
        $stmt->execute([$parentQuestionId]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$parent) return [];

        $correctText  = $parent['correct_option_text'];
        $categoryId   = (int)$parent['category_id'];
        $questionText = $parent['question_text'];

        // Build reverse question stem
        $reverseStem = "\"" . rtrim($correctText, '.') . "\" is associated with which of the following?";

        // The original question text (trimmed) becomes one wrong option
        // Other correct answers from sibling questions become additional wrong options
        $stmt = $this->db->prepare(
            "SELECT qo.option_text, qo.question_id
             FROM quiz_options qo
             JOIN quiz_questions qq ON qq.id = qo.question_id
             WHERE qq.category_id = ? AND qo.is_correct = 1
               AND qo.question_id != ?
               AND qq.is_active = 1
             ORDER BY RAND()
             LIMIT 5"
        );
        $stmt->execute([$categoryId, $parentQuestionId]);
        $siblingCorrects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Correct option for the reverse: the original question text (what is being asked)
        // is what the student should map to the answer
        $options = [
            [
                'option_text'  => rtrim($questionText, '?') . '.',
                'is_correct'   => true,
                'is_distractor'=> false,
            ],
        ];

        // Distractors: other correct answers (wrong in this reversed context)
        $added = 0;
        foreach ($siblingCorrects as $sc) {
            if (strtolower(trim($sc['option_text'])) === strtolower(trim($questionText))) continue;
            $options[] = [
                'option_text'                   => $sc['option_text'],
                'is_correct'                    => false,
                'distractor_source_question_id' => (int)$sc['question_id'],
            ];
            $added++;
            if ($added >= 3) break;
        }

        shuffle($options);

        return [
            'parent_id'     => $parentQuestionId,
            'variant_type'  => 'reverse',
            'question_text' => $reverseStem,
            'learn_notes'   => 'Reverse recall: the answer to the original question becomes the question stem.',
            'options'       => $options,
            'is_draft'      => true,
        ];
    }

    /**
     * For a given parent question, select a variant to serve during a session.
     * Prefers variants the user has not seen in the last 7 days.
     * Falls back to the parent question if no active variants exist.
     *
     * Returns ['type' => 'parent'|'variant', 'id' => int, 'is_variant' => bool,
     *          'variant_type' => string|null]
     */
    public function pickVariantOrParent(int $parentQuestionId, int $userId): array
    {
        // Find active variants not recently seen by this user
        $stmt = $this->db->prepare(
            "SELECT v.id, v.variant_type FROM quiz_question_variants v
             WHERE v.parent_id = ? AND v.is_active = 1
               AND v.id NOT IN (
                   SELECT COALESCE(variant_id, 0)
                   FROM quiz_answers qa
                   JOIN quiz_sessions qs ON qs.id = qa.session_id
                   WHERE qs.user_id = ? AND qa.answered_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                     AND qa.variant_id IS NOT NULL
               )
             ORDER BY RAND()
             LIMIT 1"
        );
        $stmt->execute([$parentQuestionId, $userId]);
        $variant = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($variant) {
            return [
                'type'         => 'variant',
                'id'           => (int)$variant['id'],
                'is_variant'   => true,
                'variant_type' => $variant['variant_type'],
            ];
        }

        return [
            'type'         => 'parent',
            'id'           => $parentQuestionId,
            'is_variant'   => false,
            'variant_type' => null,
        ];
    }

    /**
     * Save a variant (new or update) including its options.
     * Returns the variant_id.
     */
    public function saveVariant(
        int $parentId,
        string $variantType,
        string $questionText,
        ?string $learnNotes,
        array $options,
        int $createdBy,
        ?int $variantId = null
    ): int {
        if ($variantId) {
            $stmt = $this->db->prepare(
                "UPDATE quiz_question_variants
                 SET variant_type = ?, question_text = ?, learn_notes = ?, is_active = 1
                 WHERE id = ?"
            );
            $stmt->execute([$variantType, $questionText, $learnNotes, $variantId]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO quiz_question_variants
                 (parent_id, variant_type, question_text, learn_notes, created_by)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$parentId, $variantType, $questionText, $learnNotes, $createdBy]);
            $variantId = (int)$this->db->lastInsertId();
        }

        // Replace options
        $stmt = $this->db->prepare("DELETE FROM quiz_variant_options WHERE variant_id = ?");
        $stmt->execute([$variantId]);

        foreach ($options as $i => $opt) {
            $stmt = $this->db->prepare(
                "INSERT INTO quiz_variant_options
                 (variant_id, option_text, is_correct, sort_order, distractor_source_question_id)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $variantId,
                $opt['option_text'],
                $opt['is_correct'] ? 1 : 0,
                $i,
                $opt['distractor_source_question_id'] ?? null,
            ]);
        }

        return $variantId;
    }

    /**
     * List all variants for a parent question with their options.
     */
    public function listVariants(int $parentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT v.*,
                    (SELECT COUNT(*) FROM quiz_variant_options qvo WHERE qvo.variant_id = v.id) AS option_count
             FROM quiz_question_variants v
             WHERE v.parent_id = ?
             ORDER BY v.variant_type ASC, v.created_at ASC"
        );
        $stmt->execute([$parentId]);
        $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($variants as &$v) {
            $stmt2 = $this->db->prepare(
                "SELECT * FROM quiz_variant_options WHERE variant_id = ? ORDER BY sort_order ASC"
            );
            $stmt2->execute([(int)$v['id']]);
            $v['options'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($v);
        return $variants;
    }

    /**
     * Get a single variant with its options for rendering in the quiz engine.
     */
    public function getVariantForPlay(int $variantId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT v.*, qq.category_id, qq.question_type, qq.learn_notes AS parent_learn_notes
             FROM quiz_question_variants v
             JOIN quiz_questions qq ON qq.id = v.parent_id
             WHERE v.id = ? AND v.is_active = 1 LIMIT 1"
        );
        $stmt->execute([$variantId]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$v) return null;

        $stmt = $this->db->prepare(
            "SELECT id, option_text, is_correct, sort_order
             FROM quiz_variant_options WHERE variant_id = ?
             ORDER BY sort_order ASC"
        );
        $stmt->execute([$variantId]);
        $v['options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $v;
    }

    /**
     * Delete a variant and its options.
     */
    public function deleteVariant(int $variantId): void
    {
        $stmt = $this->db->prepare("DELETE FROM quiz_variant_options WHERE variant_id = ?");
        $stmt->execute([$variantId]);
        $stmt = $this->db->prepare("DELETE FROM quiz_question_variants WHERE id = ?");
        $stmt->execute([$variantId]);
    }
}
