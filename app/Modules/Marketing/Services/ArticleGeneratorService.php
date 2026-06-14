<?php
/**
 * ArticleGeneratorService — AI-powered SEO article drafting
 *
 * Generates locally-specific, E-E-A-T-compliant SEO articles using Claude.
 * Every article requires: service + city, a case study section, FAQ with
 * JSON-LD schema, internal link suggestions, and photo shoot prompts.
 *
 * SETUP: Define ANTHROPIC_API_KEY in /app_config/secrets.php
 *   define('ANTHROPIC_API_KEY', 'sk-ant-...');
 *
 * @package Mowology\Modules\Marketing\Services
 */

declare(strict_types=1);

class ArticleGeneratorService
{
    private const API_URL    = 'https://api.anthropic.com/v1/messages';
    private const MODEL      = 'claude-sonnet-4-6';
    private const MAX_TOKENS = 4500;

    // Phrases that make AI content sound robotic — forbidden in generated output
    private const FORBIDDEN_PHRASES = [
        'in today\'s digital landscape', 'it\'s important to note', 'at the end of the day',
        'dive into', 'delve into', 'in conclusion', 'in summary', 'to summarize',
        'it is worth noting', 'needless to say', 'without further ado',
        'as we navigate', 'in the realm of', 'game-changer', 'leverage',
        'utilize', 'paradigm', 'holistic approach', 'synergy',
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Generate a full SEO article draft.
     *
     * @param array $params {
     *   keyword:         string  Target search query (required)
     *   city:            string  City/area (required)
     *   service_type:    string  e.g. "lawn aeration", "hedge trimming" (required)
     *   season:          string  e.g. "spring", "fall" (optional)
     *   project_details: string  Real project notes for case study (optional)
     *   word_count:      int     Target word count — default 1800 (optional)
     * }
     * @return array{
     *   success: bool,
     *   title?: string,
     *   meta_description?: string,
     *   body_html?: string,
     *   faq_items?: array,
     *   schema_json?: string,
     *   suggested_links?: array,
     *   photo_prompts?: array,
     *   word_count?: int,
     *   error?: string
     * }
     */
    public function generate(array $params): array
    {
        $keyword     = trim($params['keyword'] ?? '');
        $city        = trim($params['city'] ?? '');
        $serviceType = trim($params['service_type'] ?? '');

        if (!$keyword || !$city || !$serviceType) {
            return ['success' => false, 'error' => 'keyword, city, and service_type are required'];
        }

        $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
        if (!$apiKey) {
            return ['success' => false, 'error' => 'ANTHROPIC_API_KEY not defined in secrets.php'];
        }

        $season         = trim($params['season'] ?? '');
        $projectDetails = trim($params['project_details'] ?? '');
        $targetWords    = max(1000, min(3000, (int)($params['word_count'] ?? 1800)));

        // Fetch CMS pages for internal link suggestions
        $internalLinks = $this->fetchInternalLinkCandidates($serviceType, $city);

        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt   = $this->buildUserPrompt(
            $keyword, $city, $serviceType, $season, $projectDetails, $targetWords, $internalLinks
        );

        $response = $this->callClaude($apiKey, $systemPrompt, $userPrompt);
        if (!$response['success']) {
            return $response;
        }

        return $this->parseResponse($response['content']);
    }

    private function buildSystemPrompt(): string
    {
        $forbidden = implode('", "', self::FORBIDDEN_PHRASES);

        return <<<PROMPT
You are an expert SEO content writer for a Canadian landscaping company. You write authority articles that rank on Google AND read as genuinely human-written.

RULES — NEVER BREAK THESE:
1. Every article must be specific to the city and service type provided. Generic landscaping advice is worthless.
2. Use a conversational, confident voice. Write as though the company owner is speaking from 10+ years of hands-on experience.
3. Forbidden phrases — these signal AI content and WILL be rejected. Never use: "{$forbidden}".
4. No hedge language ("may", "could", "some experts say") — state things directly.
5. Include a case study section with specific project details (client type, challenge, outcome, measurements). If no real project details are provided, create a realistic, specific example.
6. Every FAQ must answer a real question people search for — not generic questions.
7. Internal links must use realistic relative URLs for a landscaping company website.
8. Photo prompts must describe specific real shots that prove the company does this work.

OUTPUT FORMAT — Return ONLY valid JSON matching this exact structure:
{
  "title": "H1 title — keyword-inclusive, benefit-focused, under 65 chars",
  "meta_description": "155-char meta description with keyword",
  "intro": "Opening paragraph, 80-100 words. Hook + qualification + what they'll learn.",
  "sections": [
    {"heading": "H2 heading", "body": "300-400 word section in HTML paragraphs"}
  ],
  "case_study": {"heading": "H2 heading", "body": "HTML — specific project example with measurements/outcomes"},
  "faq": [
    {"question": "Full question?", "answer": "Concise 2-3 sentence answer."}
  ],
  "cta": "Closing paragraph linking to the company's service — natural, not pushy",
  "suggested_links": [
    {"anchor_text": "lawn aeration services", "url": "/services/lawn-aeration", "context": "Link from the second section near X"}
  ],
  "photo_prompts": [
    "Before/after of [specific scenario]",
    "Close-up of [specific detail] showing [what]",
    "Team member doing [specific task] on [surface type]"
  ],
  "schema_type": "Article"
}
PROMPT;
    }

    private function buildUserPrompt(
        string $keyword,
        string $city,
        string $serviceType,
        string $season,
        string $projectDetails,
        int    $targetWords,
        array  $internalLinks
    ): string {
        $seasonLine   = $season ? "Season/timing: {$season}" : '';
        $projectLine  = $projectDetails
            ? "Real project to use as case study: {$projectDetails}"
            : "No real project provided — invent a realistic, specific example for {$city}.";
        $linksSection = !empty($internalLinks)
            ? "Existing pages to link to internally:\n" . implode("\n", array_map(
                fn($l) => "  - {$l['title']} → {$l['slug']}",
                $internalLinks
            ))
            : "No CMS pages found — suggest realistic URLs for a landscaping website.";

        return <<<PROMPT
Write a {$targetWords}-word SEO article with this brief:

TARGET KEYWORD: {$keyword}
SERVICE TYPE: {$serviceType}
CITY/AREA: {$city}
{$seasonLine}
{$projectLine}

{$linksSection}

Requirements:
- Sections must cover: what it is, why timing matters in {$city}'s climate, what to expect (process), cost factors, DIY vs professional
- FAQ: minimum 6 questions people actually search for about {$serviceType} in {$city}
- 3 specific photo prompts that show real {$serviceType} work
- Suggest 3-4 internal links placed in natural context

Return ONLY the JSON object. No preamble, no explanation.
PROMPT;
    }

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
            CURLOPT_TIMEOUT        => 120,
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

        $content = $data['content'][0]['text'] ?? '';
        if (!$content) {
            return ['success' => false, 'error' => 'Empty response from Claude'];
        }

        return ['success' => true, 'content' => $content];
    }

    private function parseResponse(string $content): array
    {
        // Strip any markdown code fences Claude may have added
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $clean = preg_replace('/\s*```$/m', '', $clean);
        $clean = trim($clean);

        $data = json_decode($clean, true);
        if (!$data || !isset($data['title'])) {
            return ['success' => false, 'error' => 'Failed to parse Claude response as JSON', 'raw' => $content];
        }

        // Assemble body_html from sections
        $bodyParts = [];
        $bodyParts[] = '<p>' . htmlspecialchars($data['intro'] ?? '', ENT_QUOTES, 'UTF-8') . '</p>';

        foreach ($data['sections'] ?? [] as $section) {
            $bodyParts[] = '<h2>' . htmlspecialchars($section['heading'] ?? '', ENT_QUOTES, 'UTF-8') . '</h2>';
            $bodyParts[] = $section['body'] ?? '';
        }

        if (!empty($data['case_study'])) {
            $bodyParts[] = '<h2>' . htmlspecialchars($data['case_study']['heading'] ?? '', ENT_QUOTES, 'UTF-8') . '</h2>';
            $bodyParts[] = $data['case_study']['body'] ?? '';
        }

        // FAQ HTML
        if (!empty($data['faq'])) {
            $bodyParts[] = '<h2>Frequently Asked Questions</h2>';
            $bodyParts[] = '<div class="faq-list">';
            foreach ($data['faq'] as $item) {
                $q = htmlspecialchars($item['question'] ?? '', ENT_QUOTES, 'UTF-8');
                $a = htmlspecialchars($item['answer'] ?? '', ENT_QUOTES, 'UTF-8');
                $bodyParts[] = "<div class=\"faq-item\"><h3 class=\"faq-question\">{$q}</h3><p class=\"faq-answer\">{$a}</p></div>";
            }
            $bodyParts[] = '</div>';
        }

        if (!empty($data['cta'])) {
            $bodyParts[] = '<p class="article-cta">' . htmlspecialchars($data['cta'], ENT_QUOTES, 'UTF-8') . '</p>';
        }

        // Build FAQ JSON-LD schema
        $schemaJson = $this->buildFaqSchema($data['faq'] ?? [], $data['title'] ?? '');

        // Estimate word count
        $plainText = strip_tags(implode(' ', $bodyParts));
        $wordCount = str_word_count($plainText);

        return [
            'success'         => true,
            'title'           => $data['title'] ?? '',
            'meta_description'=> $data['meta_description'] ?? '',
            'body_html'       => implode("\n", $bodyParts),
            'faq_items'       => $data['faq'] ?? [],
            'schema_json'     => $schemaJson,
            'suggested_links' => $data['suggested_links'] ?? [],
            'photo_prompts'   => $data['photo_prompts'] ?? [],
            'word_count'      => $wordCount,
        ];
    }

    private function buildFaqSchema(array $faqItems, string $articleTitle): string
    {
        if (empty($faqItems)) {
            return '';
        }

        $mainEntity = array_map(fn($item) => [
            '@type'          => 'Question',
            'name'           => $item['question'] ?? '',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => $item['answer'] ?? '',
            ],
        ], $faqItems);

        $schema = [
            '@context'   => 'https://schema.org',
            '@graph'     => [
                [
                    '@type'      => 'FAQPage',
                    'mainEntity' => $mainEntity,
                ],
                [
                    '@type'     => 'Article',
                    'headline'  => $articleTitle,
                    'author'    => [
                        '@type' => 'Organization',
                        'name'  => 'Mowology Landscaping',
                    ],
                    'publisher' => [
                        '@type' => 'Organization',
                        'name'  => 'Mowology Landscaping',
                        'url'   => 'https://mowology.ca',
                    ],
                ],
            ],
        ];

        return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function fetchInternalLinkCandidates(string $serviceType, string $city): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT title, slug
                FROM cms_pages
                WHERE status = 'published'
                  AND (
                    title LIKE ? OR title LIKE ? OR slug LIKE ?
                  )
                ORDER BY updated_at DESC
                LIMIT 10
            ");
            $svcPct  = '%' . $serviceType . '%';
            $cityPct = '%' . $city . '%';
            $stmt->execute([$svcPct, $cityPct, $svcPct]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}
