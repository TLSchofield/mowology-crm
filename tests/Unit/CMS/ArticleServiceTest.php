<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ArticleServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE cms_pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER, slug TEXT, title TEXT, meta_title TEXT,
            meta_description TEXT, canonical_url TEXT, og_image_path TEXT, page_type TEXT, layout_template TEXT,
            status TEXT, noindex INTEGER, publish_at TEXT, unpublish_at TEXT, published_at TEXT, published_by INTEGER,
            created_by INTEGER, created_at TEXT, updated_by INTEGER, updated_at TEXT,
            is_template_generated INTEGER, template_source_key TEXT, generated_variables TEXT)");
        $db->exec("CREATE TABLE cms_blocks (
            id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, block_type TEXT, position INTEGER,
            config_json TEXT, content_json TEXT, visibility_json TEXT, created_at TEXT, updated_at TEXT)");
        return $db;
    }

    public function testSlugifyIsLowercaseAsciiHyphenatedAndBounded(): void
    {
        $this->assertSame('strata-landscaping-cost-in-vancouver-2026', ArticleService::slugify('Strata Landscaping Cost in Vancouver (2026)'));
        $this->assertSame('laurel-and-cedar-hedges', ArticleService::slugify('Laurel & Cedar   Hedges!'));
        $this->assertSame('', ArticleService::slugify('   '));
        $long = str_repeat('word ', 40);
        $this->assertLessThanOrEqual(80, strlen(ArticleService::slugify($long)));
        $this->assertStringEndsNotWith('-', ArticleService::slugify($long));
    }

    public function testExcerptCutsAtAWordBoundaryAndStripsTags(): void
    {
        $html = '<p>Strata councils in <strong>Vancouver</strong> usually tender landscaping every three years. Here is what to ask.</p>';
        $this->assertSame('Strata councils in Vancouver usually tender landscaping every three years. Here is what to ask.', ArticleService::excerpt($html));
        $short = ArticleService::excerpt($html, 40);
        $this->assertStringEndsWith('…', $short);
        $this->assertLessThanOrEqual(41, mb_strlen($short));
        $this->assertStringNotContainsString('Vancouv…', $short);
    }

    public function testReadingTimeNeverDropsBelowOneMinute(): void
    {
        $this->assertSame(1, ArticleService::readingMinutes('<p>short</p>'));
        $this->assertSame(5, ArticleService::readingMinutes('<p>' . str_repeat('lawn ', 1000) . '</p>'));
    }

    public function testBlogPostingSchemaCarriesAuthorDatesPublisherAndLocation(): void
    {
        $page = [
            'slug' => 'blog/strata-landscaping-cost', 'title' => 'Strata Landscaping Cost',
            'meta_description' => 'What strata landscaping costs in Vancouver.',
            'published_at' => '2026-09-23 10:00:00', 'updated_at' => '2026-09-24 09:00:00',
            'og_image_path' => '/assets/img/blog/cost.jpg',
        ];
        $meta = ['author' => 'Tim Schofield', 'keyword' => 'strata landscaping cost', 'city' => 'Vancouver', 'word_count' => 1800];
        $site = ['name' => 'Mowology', 'url' => 'https://mowology.ca/', 'logo' => 'https://mowology.ca/logo.png'];

        $s = ArticleService::blogPostingSchema($page, $meta, $site);

        $this->assertSame('BlogPosting', $s['@type']);
        $this->assertSame('https://mowology.ca/blog/strata-landscaping-cost', $s['url']);
        $this->assertSame('Tim Schofield', $s['author']['name']);
        $this->assertSame('https://mowology.ca/#business', $s['publisher']['@id']);
        $this->assertStringStartsWith('2026-09-23T10:00:00', $s['datePublished']);
        $this->assertStringStartsWith('2026-09-24T09:00:00', $s['dateModified']);
        $this->assertSame('https://mowology.ca/assets/img/blog/cost.jpg', $s['image']);
        $this->assertSame('Vancouver', $s['contentLocation']['name']);
        $this->assertSame(1800, $s['wordCount']);

        $org = ArticleService::blogPostingSchema($page, [], $site);
        $this->assertSame('Organization', $org['author']['@type']);
    }

    public function testFaqSchemaSkipsEmptyPairsAndReturnsNullWhenNothingUsable(): void
    {
        $this->assertNull(ArticleService::faqSchema([]));
        $this->assertNull(ArticleService::faqSchema([['question' => 'Q?', 'answer' => '']]));
        $s = ArticleService::faqSchema([['question' => 'How often?', 'answer' => 'Weekly.'], ['q' => 'Cost?', 'a' => 'Depends.']]);
        $this->assertSame('FAQPage', $s['@type']);
        $this->assertCount(2, $s['mainEntity']);
        $this->assertSame('Depends.', $s['mainEntity'][1]['acceptedAnswer']['text']);
    }

    public function testSaveCreatesAPageAndBodyBlockUnderBlogWithUniqueSlugs(): void
    {
        $svc = new ArticleService($this->db(), 1);
        $r1 = $svc->save([
            'title' => 'How Often Should Strata Landscaping Happen?',
            'body_html' => '<p>' . str_repeat('Weekly in season. ', 60) . '</p>',
            'author' => 'Tim', 'city' => 'Burnaby', 'keyword' => 'strata landscaping frequency',
            'faq_items' => [['question' => 'Winter?', 'answer' => 'Bi-weekly.'], ['question' => '', 'answer' => 'x']],
            'status' => 'published',
        ], 7);

        $this->assertSame('blog/how-often-should-strata-landscaping-happen', $r1['slug']);
        $this->assertSame('published', $r1['status']);
        $this->assertStringEndsWith('/blog/how-often-should-strata-landscaping-happen', $r1['url']);

        $page = $svc->getById($r1['page_id']);
        $this->assertSame('article', $page['layout_template']);
        $this->assertSame('custom', $page['page_type']);
        $this->assertSame(7, (int)$page['published_by']);
        $this->assertNotEmpty($page['published_at']);
        $this->assertSame('content_engine', $page['template_source_key']);
        $meta = ArticleService::meta($page);
        $this->assertSame('Tim', $meta['author']);
        $this->assertCount(1, $meta['faq_items']);
        $this->assertSame(180, $meta['word_count']);
        $this->assertStringContainsString('Weekly in season.', $svc->body($r1['page_id']));
        $this->assertNotSame('', $page['meta_description'], 'meta description is derived from the body when absent');

        // Same title again → -2 suffix, and drafts are not listed as published.
        $r2 = $svc->save(['title' => 'How Often Should Strata Landscaping Happen?', 'body_html' => '<p>Draft body text here.</p>'], 7);
        $this->assertSame('blog/how-often-should-strata-landscaping-happen-2', $r2['slug']);
        $this->assertSame('draft', $r2['status']);
        $this->assertNull($svc->getById($r2['page_id'])['published_at']);

        $list = $svc->listPublished();
        $this->assertCount(1, $list);
        $this->assertSame($r1['page_id'], (int)$list[0]['id']);
        $this->assertSame(1, $svc->countPublished());
        $this->assertSame([], $svc->related($r1['page_id']));
    }

    public function testSaveWithPageIdUpdatesInPlaceKeepsSlugAndFirstPublishDate(): void
    {
        $svc = new ArticleService($this->db(), 1);
        $r = $svc->save(['title' => 'Fall Cleanup Checklist', 'body_html' => '<p>Rake, prune, mulch.</p>', 'status' => 'published'], 3);
        $firstPublished = $svc->getById($r['page_id'])['published_at'];

        $u = $svc->save(['page_id' => $r['page_id'], 'title' => 'Fall Cleanup Checklist for Vancouver', 'body_html' => '<p>Rake, prune, mulch, drain.</p>', 'status' => 'published'], 4);
        $this->assertSame($r['slug'], $u['slug'], 'slug is stable across edits');
        $page = $svc->getById($r['page_id']);
        $this->assertSame('Fall Cleanup Checklist for Vancouver', $page['title']);
        $this->assertSame($firstPublished, $page['published_at']);
        $this->assertSame(4, (int)$page['updated_by']);
        $this->assertStringContainsString('drain', $svc->body($r['page_id']));
        $this->assertSame(1, $svc->countPublished(), 'update did not create a second block or page');
    }

    public function testSaveRejectsEmptyTitleOrBody(): void
    {
        $svc = new ArticleService($this->db(), 1);
        $this->expectException(InvalidArgumentException::class);
        $svc->save(['title' => 'No body', 'body_html' => '<p>  </p>'], 1);
    }
}
