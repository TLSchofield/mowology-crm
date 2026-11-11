<?php
declare(strict_types=1);

require_once __DIR__ . '/MiaDemo.php';

/** Shared fixture for the page-generation tests: services, projects, CMS tables, one page suggestion, a fake model. */
trait MiaPageFixture
{
    private PDO $db;
    private DateTimeImmutable $now;
    private array $replies = [];
    private array $sent = [];
    private int $actionId;
    private const QUERY = 'garden maintenance';

    /** About 430 words built only from the facts in setUp(). */
    private function goodBody(array $over = []): string
    {
        $intro = "Looking for garden maintenance in Vancouver? Mowology looks after gardens week by week, so beds stay tidy and plants get the attention they need. "
               . "This page explains what the service includes, how a visit works and what to ask before you book.";
        $sections = [
            'What garden maintenance includes' => [
                "Our Garden Maintenance service is weekly garden weeding and mulch refresh. The crew works through the beds, pulls the weeds by hand and spreads fresh mulch where the ground has gone bare. "
                . "Most clients ask for the same day each week so the garden is always looked after on a rhythm they can plan around.",
                "- Weeding of beds and borders\n- Mulch refresh where it is needed\n- A tidy finish before the crew leaves",
                "Mulch keeps moisture in the soil and holds weeds back. A refreshed bed also looks cared for, which matters just as much to a strata council as to a family on a quiet street."],
            'How a visit works' => [
                "A crew arrives at the agreed time, walks the property and starts with the beds that need the most work. Weeds go into the green waste, mulch goes down, and edges are cleaned up. "
                . "If the crew sees something that needs a different service, such as hedge trimming or a spring cleanup, they tell you so you can decide.",
                "The aim is simple. You should be able to walk out your front door after a visit and see a garden that looks better than it did that morning, with nothing left behind.",
                "We work in Point Grey and Kerrisdale as well as across the rest of Vancouver, and we keep each property on a regular route so visits stay predictable."],
            'Who this suits' => [
                "Garden maintenance suits homeowners who want a steady, looked-after garden without giving up their weekends. It also suits property managers who need beds kept tidy across many visits through the year.",
                "If you only need a one-time tidy, a seasonal cleanup may be the better fit. The crew can explain the difference when you ask for a quote, and you only pay for the service you choose.",
                "Tell us about the garden, the size of the beds and how often you would like a visit, and we will suggest a plan that fits."],
            'Frequently asked questions' => [
                "### What does garden maintenance cover?\nIt covers weekly weeding and a mulch refresh in your beds, with the crew tidying up before they leave.",
                "### Can I book the same day every week?\nYes. Most clients choose one day so the visit becomes part of the weekly routine, and we keep the property on a regular route.",
                "### Do you offer other services as well?\nYes. We also offer lawn mowing, hedge trimming, seasonal cleanups and snow removal, so one crew can look after the whole property.",
                "### How do I get a quote?\nCall us on (778) 846-9273 or use the quote form on the website, and tell us about the property.",
            ],
        ];
        $t = $intro . "\n\n";
        foreach ($sections as $h => $blocks) { $t .= "## $h\n\n" . implode("\n\n", $blocks) . "\n\n"; }
        return rtrim($t) . ($over['append'] ?? '');
    }

    private function good(array $over = []): array
    {
        return array_merge(['title' => 'Garden maintenance in Vancouver', 'meta_description' => 'Weekly garden weeding and mulch refresh for Vancouver homes and properties, looked after by one regular Mowology crew.',
                            'body' => $this->goodBody(), 'explanation' => 'This page explains the Garden Maintenance service in plain words and answers four common questions.'], $over);
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-26 16:00:00');
        $this->replies = []; $this->sent = [];
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        MiaDemo::schema($db);
        $db->exec("ALTER TABLE mia_content_drafts ADD COLUMN body TEXT");
        $db->exec("INSERT INTO users VALUES (7)");
        $db->exec("INSERT INTO business_settings VALUES (1, 'Mowology Landscaping', '(778) 846-9273', 'office@mowology.ca')");
        $db->exec("INSERT INTO mia_memory (site_id, kind, mem_key, mem_value, source) VALUES (1, 'brand', 'short_name', 'Mowology', 'owner')");
        $db->exec("INSERT INTO mia_websites (id, site_id, base_url, host, status) VALUES (1, 1, 'https://mowology.ca', 'mowology.ca', 'verified')");
        $db->exec("CREATE TABLE service_packages (id INTEGER PRIMARY KEY AUTOINCREMENT, service_type TEXT, package_name TEXT, slug TEXT, description TEXT, is_active INT DEFAULT 1)");
        $db->exec("INSERT INTO service_packages (service_type, package_name, slug, description) VALUES
            ('garden_maintenance', 'Garden Maintenance', 'garden-maintenance', 'Weekly garden weeding and mulch refresh'),
            ('lawn_care', 'Lawn Mowing Standard', 'lawn-mowing-standard', 'Standard residential lawn mowing (45 min)'),
            ('hedge_trimming', 'Hedge Trim Light', 'hedge-trim-light', 'Light hedge trimming (up to 1 hour)'),
            ('seasonal_cleanup', 'Spring Cleanup', 'spring-cleanup', 'Seasonal spring property cleanup (2 hours, 2 crew)'),
            ('snow_removal', 'Snow Removal Per Visit', 'snow-removal-per-visit', 'Single driveway snow removal')");
        $db->exec("CREATE TABLE portfolio_projects (id INTEGER PRIMARY KEY AUTOINCREMENT, project_number TEXT, project_name TEXT, description TEXT, location TEXT, status TEXT, featured INT DEFAULT 0, categories TEXT, tags TEXT,
            after_image_path TEXT, gallery_images TEXT)");
        $db->exec("INSERT INTO portfolio_projects (project_name, description, location, status, categories, tags, gallery_images) VALUES
            ('Garden Care in Point Grey', 'A tidy garden maintenance visit in Point Grey.', 'Point Grey', 'published', '[\"Garden\"]', '[]', '[\"/_media/variants/a.jpg\"]'),
            ('Hedge Trimming', 'Hedges trimmed and shaped.', 'Kerrisdale', 'published', '[\"Residential\"]', '[]', '[]'),
            ('Garden Maintenance draft only', 'Not public yet.', 'Dunbar', 'draft', '[]', '[]', '[]')");
        $db->exec("CREATE TABLE ba_pairs (id INTEGER PRIMARY KEY, area TEXT, status TEXT)");
        $db->exec("CREATE TABLE cms_pages (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, slug TEXT, title TEXT, meta_title TEXT, meta_description TEXT, canonical_url TEXT, og_image_path TEXT, page_type TEXT, layout_template TEXT,
            status TEXT, noindex INT, published_at TEXT, published_by INT, created_by INT, created_at TEXT, updated_by INT, updated_at TEXT, is_template_generated INT, template_source_key TEXT, generated_variables TEXT)");
        $db->exec("CREATE TABLE cms_blocks (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INT, block_type TEXT, position INT, config_json TEXT, content_json TEXT, visibility_json TEXT, created_at TEXT, updated_at TEXT)");
        $db->exec("CREATE TABLE mia_executions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, action_id INT NOT NULL, draft_id INT, website_id INT NOT NULL, connector TEXT, target_ref TEXT,
            page_url TEXT, before_json TEXT, after_json TEXT, status TEXT DEFAULT 'applied', verify_note TEXT, applied_by INT, applied_at TEXT, rolled_back_by INT, rolled_back_at TEXT, error TEXT)");
        $this->db = $db;
        $r = $this->actions()->propose(['type' => 'page_draft', 'side_effect' => 'none', 'opportunity_key' => 'query:1:abc', 'title' => 'Consider a page for the search "garden maintenance"',
            'why' => 'People searched for it 1,695 times but no page ranks.', 'risk' => 'A thin page can hurt the site.', 'evidence' => [['query' => self::QUERY, 'impressions' => 1695, 'clicks' => 0, 'best_position' => 20.5]],
            'evidence_basis' => ['q' => 1]], $this->now);
        $this->assertTrue($r['ok']);
        $this->actionId = (int)$r['id'];
    }

    private function actions(): MiaActionService { return new MiaActionService($this->db, 1, function (int $id) { return $id === 7; }); }
    private function n(string $sql): int { return (int)$this->db->query($sql)->fetchColumn(); }

    private function writer(): MiaPageWriter
    {
        $llm = new MiaLlmGateway($this->db, 1, function (array $body) {
            $this->sent[] = $body;
            $r = array_shift($this->replies) ?? ['__http' => 500];
            if (isset($r['__http'])) { return ['code' => $r['__http'], 'body' => '{}']; }
            return ['code' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => json_encode($r)]], 'usage' => ['input_tokens' => 900, 'output_tokens' => 1400]])];
        });
        return new MiaPageWriter($this->db, 1, $llm, $this->actions());
    }

    private function written(): int
    {
        $this->replies[] = $this->good();
        $r = $this->writer()->prepare($this->actionId, 1, $this->now);
        $this->assertTrue($r['ok'], json_encode($r));
        return (int)$r['draft_id'];
    }
}
