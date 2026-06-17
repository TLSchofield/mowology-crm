<?php
/**
 * ContentCascadeService — "Write once, amplify everywhere"
 *
 * Takes a published article and fans it out to all active channels:
 *   • Social posts (Facebook, Instagram, Google Business Profile)
 *   • Email campaign (draft, user approves before send)
 *   • PDF promo footer (invoice + quote)
 *   • Email signature promo line
 *
 * The ContentCascadeService never auto-publishes. Every output is a draft
 * that the user reviews and approves.
 *
 * @package Mowology\Modules\Marketing\Services
 */

declare(strict_types=1);

class ContentCascadeService
{
    private const API_URL    = 'https://api.anthropic.com/v1/messages';
    private const MODEL      = 'claude-sonnet-4-6';
    private const MAX_TOKENS = 2000;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Run the full cascade for a published article.
     *
     * @param array $article {
     *   id:          int     CMS page/article ID
     *   title:       string  Article title
     *   body_html:   string  Full article HTML
     *   url:         string  Full public URL of the article (e.g. https://mowology.ca/blog/lawn-aeration-surrey)
     *   intro:       string  First paragraph (used for email preview)
     *   meta_description: string
     * }
     * @param array $channels Which channels to run, e.g. ['social', 'email', 'pdf_footer', 'email_signature']
     * @param int   $userId   ID of the user triggering the cascade
     * @return array Summary of all drafts created
     */
    public function cascade(array $article, array $channels, int $userId): array
    {
        $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
        if (!$apiKey) {
            return ['success' => false, 'error' => 'ANTHROPIC_API_KEY not defined in secrets.php'];
        }

        $results = [
            'success'         => true,
            'social_posts'    => [],
            'email_campaign'  => null,
            'pdf_promo'       => null,
            'email_signature' => null,
            'errors'          => [],
        ];

        // Generate all social + email content in one Claude call
        if (in_array('social', $channels) || in_array('email', $channels)) {
            $generated = $this->generateCascadeContent($apiKey, $article);
            if (!$generated['success']) {
                $results['errors'][] = 'Content generation failed: ' . $generated['error'];
            } else {
                $content = $generated['content'];

                if (in_array('social', $channels)) {
                    $results['social_posts'] = $this->createSocialDrafts($content, $article, $userId);
                }

                if (in_array('email', $channels)) {
                    $results['email_campaign'] = $this->createEmailDraft($content, $article, $userId);
                }
            }
        }

        // Record active article + campaign ID for attribution reporting
        if ($results['email_campaign']) {
            $this->recordActiveArticle($article, (int)$results['email_campaign']['campaign_id']);
        }

        // PDF promo footer — simple text, no Claude needed
        if (in_array('pdf_footer', $channels)) {
            $results['pdf_promo'] = $this->updatePdfPromo($article);
        }

        // Email signature promo line — simple text, no Claude needed
        if (in_array('email_signature', $channels)) {
            $results['email_signature'] = $this->updateEmailSignaturePromo($article);
        }

        return $results;
    }

    // ── Content Generation ───────────────────────────────────────────────

    private function generateCascadeContent(string $apiKey, array $article): array
    {
        $title       = $article['title'] ?? '';
        $url         = $article['url'] ?? '';
        $intro       = strip_tags($article['intro'] ?? '');
        $metaDesc    = $article['meta_description'] ?? $intro;

        // Truncate intro to first 500 chars for the prompt
        $introSnippet = mb_substr(strip_tags($article['body_html'] ?? $intro), 0, 600);

        $systemPrompt = <<<PROMPT
You write social media posts and email content for a Canadian landscaping company called Mowology.
Voice: confident, friendly, local. Not corporate or salesy.
Each platform has specific requirements — follow them exactly.
Return ONLY valid JSON. No preamble.
PROMPT;

        $userPrompt = <<<PROMPT
Create cascade content for this article:

TITLE: {$title}
URL: {$url}
META DESCRIPTION: {$metaDesc}
ARTICLE OPENING: {$introSnippet}

Return this exact JSON structure:
{
  "facebook": {
    "caption": "120-150 word post. Conversational. End with a question or soft CTA. Include the URL naturally in the text.",
    "hashtags": ["#Mowology", "#Landscaping", "2-3 more relevant hashtags"]
  },
  "instagram": {
    "caption": "First line: a hook under 125 chars that makes people tap 'more'. Then 3-5 short punchy lines. End with CTA: 'Link in bio →'",
    "hashtags": ["#Mowology", "#LawnCare", "8-10 relevant hashtags for reach"]
  },
  "gbp": {
    "caption": "80-100 word Google Business Profile post. Local focus. Include the URL. Professional but warm."
  },
  "email": {
    "subject_lines": [
      "Subject option 1 — curiosity-based, under 50 chars",
      "Subject option 2 — benefit-based, under 50 chars",
      "Subject option 3 — question-based, under 50 chars"
    ],
    "preview_text": "45-char preview text that complements the subject",
    "email_body": "HTML email body: greeting, 2-3 sentences summarizing what the article covers, a clear CTA button placeholder [READ_ARTICLE_BUTTON], sign-off. Use inline styles for a clean, readable email."
  }
}
PROMPT;

        $response = $this->callClaude($apiKey, $systemPrompt, $userPrompt);
        if (!$response['success']) {
            return $response;
        }

        // Parse JSON response
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $response['content']);
        $clean = preg_replace('/\s*```$/m', '', $clean);
        $data  = json_decode(trim($clean), true);

        if (!$data) {
            return ['success' => false, 'error' => 'Failed to parse cascade content JSON'];
        }

        return ['success' => true, 'content' => $data];
    }

    // ── Social Posts ─────────────────────────────────────────────────────

    private function createSocialDrafts(array $content, array $article, int $userId): array
    {
        $created = [];
        $platforms = [
            'facebook'  => $content['facebook'] ?? null,
            'instagram' => $content['instagram'] ?? null,
            'gbp'       => $content['gbp'] ?? null,
        ];

        // Pre-load active accounts per platform so we can satisfy the
        // social_post_platforms.account_id NOT NULL constraint.
        $accountStmt = $this->db->prepare(
            "SELECT id FROM social_accounts WHERE platform = ? AND is_active = 1 LIMIT 1"
        );

        foreach ($platforms as $platform => $data) {
            if (!$data) continue;

            // Skip platforms with no connected account — can't publish without one.
            $accountStmt->execute([$platform]);
            $accountId = $accountStmt->fetchColumn();
            if (!$accountId) continue;

            $caption = trim($data['caption'] ?? '');
            if (!empty($data['hashtags'])) {
                $caption .= "\n\n" . implode(' ', $data['hashtags']);
            }

            try {
                // Insert social post
                $stmt = $this->db->prepare("
                    INSERT INTO social_posts
                        (caption, status, created_by, created_at, updated_at)
                    VALUES (?, 'draft', ?, NOW(), NOW())
                ");
                $stmt->execute([trim($caption), $userId]);
                $postId = (int)$this->db->lastInsertId();

                // Insert platform target row with the resolved account_id.
                $this->db->prepare("
                    INSERT INTO social_post_platforms
                        (post_id, account_id, platform, status, created_at)
                    VALUES (?, ?, ?, 'pending', NOW())
                ")->execute([$postId, (int)$accountId, $platform]);

                $created[] = [
                    'platform'   => $platform,
                    'post_id'    => $postId,
                    'account_id' => (int)$accountId,
                    'caption'    => mb_substr($caption, 0, 100) . (mb_strlen($caption) > 100 ? '…' : ''),
                ];
            } catch (Throwable $e) {
                error_log("ContentCascadeService social draft error ({$platform}): " . $e->getMessage());
            }
        }

        return $created;
    }

    // ── Email Campaign ───────────────────────────────────────────────────

    private function createEmailDraft(array $content, array $article, int $userId): ?array
    {
        $email = $content['email'] ?? null;
        if (!$email) return null;

        $subjectLines = $email['subject_lines'] ?? [];
        $subject      = $subjectLines[0] ?? $article['title']; // default to first variant
        $previewText  = $email['preview_text'] ?? '';
        $url          = $article['url'] ?? '';

        // Replace button placeholder with real link
        $buttonHtml = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '" style="display:inline-block;padding:12px 24px;background:#2D8659;color:#fff;'
            . 'text-decoration:none;border-radius:6px;font-weight:600;">Read the Full Article →</a>';

        $bodyHtml = str_replace('[READ_ARTICLE_BUTTON]', $buttonHtml, $email['email_body'] ?? '');

        try {
            $stmt = $this->db->prepare("
                INSERT INTO marketing_campaigns
                    (name, segment_type, trigger_type, status, subject_override,
                     body_override, created_by, created_at, updated_at)
                VALUES (?, 'all_marketing', 'manual', 'draft', ?, ?, ?, NOW(), NOW())
            ");
            $campaignName = 'Article: ' . mb_substr($article['title'] ?? 'Untitled', 0, 80);
            $stmt->execute([$campaignName, $subject, $bodyHtml, $userId]);
            $campaignId = (int)$this->db->lastInsertId();

            return [
                'campaign_id'    => $campaignId,
                'subject'        => $subject,
                'subject_alts'   => array_slice($subjectLines, 1),
                'preview_text'   => $previewText,
            ];
        } catch (Throwable $e) {
            error_log('ContentCascadeService email draft error: ' . $e->getMessage());
            return null;
        }
    }

    // ── PDF Promo Footer ─────────────────────────────────────────────────

    private function updatePdfPromo(array $article): array
    {
        $title = htmlspecialchars($article['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $url   = htmlspecialchars($article['url'] ?? '', ENT_QUOTES, 'UTF-8');
        $html  = "From the blog: <a href=\"{$url}\">{$title}</a>";

        try {
            // Upsert into ops_settings
            $this->db->prepare("
                INSERT INTO ops_settings (setting_key, setting_value, description, updated_at)
                VALUES ('pdf_promo_html', ?, 'Latest article promo — shown in invoice + quote PDF footers', NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ")->execute([$html]);

            return ['updated' => true, 'html' => $html];
        } catch (Throwable $e) {
            error_log('ContentCascadeService pdf promo error: ' . $e->getMessage());
            return ['updated' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Email Signature Promo ────────────────────────────────────────────

    private function updateEmailSignaturePromo(array $article): array
    {
        $title = htmlspecialchars($article['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $url   = htmlspecialchars($article['url'] ?? '', ENT_QUOTES, 'UTF-8');
        $line  = "Latest from the blog: <a href=\"{$url}\" style=\"color:#2D8659;\">{$title}</a>";

        try {
            $this->db->prepare("
                UPDATE business_settings
                SET email_signature_text = ?, updated_at = NOW()
                WHERE id = 1
            ")->execute([$line]);

            return ['updated' => true, 'html' => $line];
        } catch (Throwable $e) {
            error_log('ContentCascadeService email sig error: ' . $e->getMessage());
            return ['updated' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Attribution ────────────────────────────────────────────────��────

    private function recordActiveArticle(array $article, int $campaignId): void
    {
        // Stores the current active content-engine article so the analytics
        // dashboard can correlate email campaign performance back to the source article.
        $value = json_encode([
            'article_id'  => $article['id'],
            'title'       => $article['title'],
            'url'         => $article['url'],
            'campaign_id' => $campaignId,
            'cascaded_at' => date('Y-m-d H:i:s'),
        ]);
        try {
            $this->db->prepare("
                INSERT INTO ops_settings (setting_key, setting_value, description, updated_at)
                VALUES ('content_engine_active_article', ?, 'Latest Content Engine article + campaign ID for attribution', NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ")->execute([$value]);
        } catch (Throwable $e) {
            error_log('ContentCascadeService recordActiveArticle: ' . $e->getMessage());
        }
    }

    // ── Claude HTTP call ─────────────────────────────────────────────────

    private function callClaude(string $apiKey, string $systemPrompt, string $userPrompt): array
    {
        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => [
                [
                    'type'          => 'text',
                    'text'          => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
                'anthropic-beta: prompt-caching-2024-07-31',
            ],
        ]);

        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => 'curl error: ' . $err];
        }

        $data = json_decode($raw, true);

        if ($code !== 200) {
            $msg = $data['error']['message'] ?? "HTTP {$code}";
            return ['success' => false, 'error' => 'Claude API error: ' . $msg];
        }

        $text = $data['content'][0]['text'] ?? '';
        if (!$text) {
            return ['success' => false, 'error' => 'Empty response from Claude'];
        }

        return ['success' => true, 'content' => $text];
    }
}
