<?php
declare(strict_types=1);
/**
 * HydrangeaMasterclassSeeder
 *
 * Loads the Hydrangea Masterclass content (app/Modules/Quiz/Data/hydrangea_masterclass.php)
 * into the existing quiz + certification tables. Idempotent: running it again adds
 * only what is missing and never changes or deletes existing rows.
 *
 * Uses the same columns the admin "save_question" action writes (quiz.php), not the
 * older pesticide seed's column names.
 */
class HydrangeaMasterclassSeeder
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Check the content array for mistakes before anything touches the database.
     * Returns a list of problems; empty means the content is sound.
     *
     * @return string[]
     */
    public static function validate(array $content): array
    {
        $problems = [];
        $seen = [];

        if (empty($content['course']['slug']) || empty($content['course']['tier_slug'])) {
            $problems[] = 'Course needs a slug and tier_slug.';
        }
        if (empty($content['modules']) || !is_array($content['modules'])) {
            $problems[] = 'No modules.';
            return $problems;
        }

        foreach ($content['modules'] as $mi => $module) {
            $label = 'Module ' . ($mi + 1);
            if (trim((string)($module['name'] ?? '')) === '') {
                $problems[] = "$label has no name.";
            }
            foreach (($module['questions'] ?? []) as $qi => $q) {
                $ql = "$label Q" . ($qi + 1);
                if (count($q) !== 6) {
                    $problems[] = "$ql must have 6 fields.";
                    continue;
                }
                [$text, $difficulty, $level, $notes, $correct, $wrongs] = $q;
                if (trim((string)$text) === '') {
                    $problems[] = "$ql has empty question text.";
                }
                if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
                    $problems[] = "$ql has invalid difficulty.";
                }
                if (!is_int($level) || $level < 1 || $level > 6) {
                    $problems[] = "$ql has invalid learning level.";
                }
                if (trim((string)$notes) === '') {
                    $problems[] = "$ql has no learn notes.";
                }
                if (trim((string)$correct) === '') {
                    $problems[] = "$ql has empty correct answer.";
                }
                if (!is_array($wrongs) || count($wrongs) !== 3) {
                    $problems[] = "$ql must have exactly 3 wrong answers.";
                } else {
                    $all = array_merge([$correct], $wrongs);
                    foreach ($all as $a) {
                        if (trim((string)$a) === '' || mb_strlen((string)$a) > 255) {
                            $problems[] = "$ql has an empty or too-long answer.";
                            break;
                        }
                    }
                    if (count(array_unique(array_map('mb_strtolower', array_map('strval', $all)))) !== 4) {
                        $problems[] = "$ql has duplicate answers.";
                    }
                }
                $key = mb_strtolower(trim((string)$text));
                if (isset($seen[$key])) {
                    $problems[] = "$ql repeats an earlier question.";
                }
                $seen[$key] = true;
            }
        }
        return $problems;
    }

    /**
     * @return array{categories_added:int, questions_added:int, questions_skipped:int, mapped:int, course_id:int, log:string[]}
     * @throws \RuntimeException when content is invalid or the tier is missing
     */
    public function seed(array $content, int $userId): array
    {
        $problems = self::validate($content);
        if ($problems) {
            throw new \RuntimeException('Content invalid: ' . implode(' ', $problems));
        }

        $log = [];
        $catsAdded = $qAdded = $qSkipped = $mapped = 0;

        $this->db->beginTransaction();
        try {
            $courseId = $this->ensureCourse($content['course'], $log);

            $catFind = $this->db->prepare("SELECT id FROM quiz_categories WHERE name = ? LIMIT 1");
            $catIns  = $this->db->prepare(
                "INSERT INTO quiz_categories (name, description, icon, colour, sort_order)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $qFind = $this->db->prepare(
                "SELECT id FROM quiz_questions WHERE category_id = ? AND question_text = ? LIMIT 1"
            );
            $qIns = $this->db->prepare(
                "INSERT INTO quiz_questions
                 (category_id, question_text, learn_notes, difficulty, question_type, learning_level,
                  is_active, relevant_months, seasonal_tags, seasonal_priority, created_by)
                 VALUES (?, ?, ?, ?, 'multiple_choice', ?, 1, ?, ?, 5, ?)"
            );
            $oIns = $this->db->prepare(
                "INSERT INTO quiz_options (question_id, option_text, is_correct, sort_order)
                 VALUES (?, ?, ?, ?)"
            );
            $mapIns = $this->db->prepare(
                "INSERT IGNORE INTO cert_course_questions (course_id, question_id, is_required, weight, added_by)
                 VALUES (?, ?, 0, 1, ?)"
            );

            foreach ($content['modules'] as $module) {
                if (empty($module['questions'])) {
                    continue; // module not written yet
                }
                $catFind->execute([$module['name']]);
                $catId = (int)$catFind->fetchColumn();
                if ($catId === 0) {
                    $catIns->execute([
                        $module['name'], $module['description'], $module['icon'],
                        $module['colour'], (int)$module['sort_order'],
                    ]);
                    $catId = (int)$this->db->lastInsertId();
                    $catsAdded++;
                    $log[] = '✓ Created category: ' . $module['name'];
                }

                foreach ($module['questions'] as $q) {
                    [$text, $difficulty, $level, $notes, $correct, $wrongs] = $q;

                    $qFind->execute([$catId, $text]);
                    $qId = (int)$qFind->fetchColumn();
                    if ($qId > 0) {
                        $qSkipped++;
                    } else {
                        $qIns->execute([
                            $catId, $text, $notes, $difficulty, $level,
                            $module['months'], $module['tags'], $userId,
                        ]);
                        $qId = (int)$this->db->lastInsertId();
                        $oIns->execute([$qId, $correct, 1, 0]);
                        foreach ($wrongs as $i => $wrong) {
                            $oIns->execute([$qId, $wrong, 0, $i + 1]);
                        }
                        $qAdded++;
                    }

                    $mapIns->execute([$courseId, $qId, $userId]);
                    $mapped += $mapIns->rowCount();
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'categories_added'  => $catsAdded,
            'questions_added'   => $qAdded,
            'questions_skipped' => $qSkipped,
            'mapped'            => $mapped,
            'course_id'         => $courseId,
            'log'               => $log,
        ];
    }

    private function ensureCourse(array $c, array &$log): int
    {
        $find = $this->db->prepare("SELECT id FROM cert_courses WHERE slug = ? LIMIT 1");
        $find->execute([$c['slug']]);
        $id = (int)$find->fetchColumn();
        if ($id > 0) {
            $log[] = '— Course already exists (id=' . $id . ')';
            return $id;
        }

        $tier = $this->db->prepare("SELECT id FROM cert_tiers WHERE slug = ? LIMIT 1");
        $tier->execute([$c['tier_slug']]);
        $tierId = (int)$tier->fetchColumn();
        if ($tierId === 0) {
            throw new \RuntimeException('Tier "' . $c['tier_slug'] . '" not found — run migration 990 first.');
        }

        $this->db->prepare(
            "INSERT INTO cert_courses
             (tier_id, name, slug, description, icon, colour, pass_score_pct, min_questions, max_questions,
              attempts_allowed, cooldown_hours, recert_months, is_required, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $tierId, $c['name'], $c['slug'], $c['description'], $c['icon'], $c['colour'],
            $c['pass_score_pct'], $c['min_questions'], $c['max_questions'],
            $c['attempts_allowed'], $c['cooldown_hours'], $c['recert_months'],
            $c['is_required'], $c['sort_order'],
        ]);
        $id = (int)$this->db->lastInsertId();
        $log[] = '✓ Created course: ' . $c['name'];
        return $id;
    }
}
