<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/MiaDemo.php';
require_once dirname(__DIR__, 2) . '/Support/MiaPageFixture.php';

/** Page generation: facts only, real projects as the case studies, an unpublished page, undo while untouched. Fake model, no network. */
final class MiaPageTest extends TestCase
{
    use MiaPageFixture;

    private function approveAndPage(): MiaPagePublisher
    {
        $this->written();
        $pub = new MiaPagePublisher($this->db, 1, $this->actions());
        $c = $pub->inspect($this->actionId);
        $this->assertTrue($c['ok'], json_encode($c));
        $d = $this->actions()->decide($this->actionId, 7, 'approve', null, null, $c['content'], $this->now);
        $this->assertTrue($d['ok'], json_encode($d));
        return $pub;
    }

    // ---- markup ----

    public function testMarkupBecomesEscapedHtmlAndAFaqAndCannotCarryTags(): void
    {
        $r = MiaPageMarkup::render("Intro paragraph.\n\n## Sections\n\n- one\n- two\n\n## Frequently asked questions\n\n### Why?\nBecause.", '<p>extra</p>');
        $this->assertStringContainsString('<h2>Sections</h2>', $r['html']);
        $this->assertStringContainsString("<li>one</li>", $r['html']);
        $this->assertStringContainsString('<p>extra</p>', $r['html']);
        $this->assertSame([['question' => 'Why?', 'answer' => 'Because.']], $r['faq']);
        $this->assertNotEmpty(MiaPageMarkup::problems('<script>alert(1)</script>'));
        $this->assertNotEmpty(MiaPageMarkup::problems('See https://evil.example now'));
        $this->assertNotEmpty(MiaPageMarkup::problems(''));
        $this->assertStringContainsString('&amp;', MiaPageMarkup::render('Fish & chips')['html']);
        $this->assertSame("a\n\nb", MiaPageMarkup::normalise("a  \r\n\r\n\r\n\r\nb  "));
    }

    // ---- facts and extras ----

    public function testOnlyServicesThatMatchTheSearchAreGivenAsFactsAndNothingElseIsInvented(): void
    {
        $f = $this->writer()->facts(self::QUERY);
        $this->assertSame(['Garden Maintenance'], array_column($f['matched'], 'name'));
        $this->assertSame('Mowology', $f['business'], 'the owner\'s short name');
        $this->assertContains('Lawn Mowing Standard', $f['others']);
        $this->assertEqualsCanonicalizing(['Point Grey', 'Kerrisdale'], $f['places'], 'published projects only');
    }

    public function testRealProjectsMatchBySearchWordsAndOnlyPublishedOnesAreUsed(): void
    {
        $p = MiaPageExtras::projects($this->db, self::QUERY);
        $this->assertSame(['Garden Care in Point Grey'], array_column($p, 'name'));
        $this->assertSame('/_media/variants/a.jpg', $p[0]['image']);
        $this->assertSame([], MiaPageExtras::projects($this->db, 'tree surgery'));
        $html = MiaPageExtras::html($p, 'https://mowology.ca', 'Mowology');
        $this->assertStringContainsString('<h2>Recent work</h2>', $html);
        $this->assertStringContainsString('https://mowology.ca/quote', $html);
        $this->assertStringNotContainsString('Not public yet', $html);
        $this->assertStringContainsString('&lt;', MiaPageExtras::html([['id' => 1, 'name' => '<b>x</b>', 'location' => '', 'description' => '', 'image' => null]], '', ''), 'names are escaped');
    }

    // ---- writer ----

    public function testAGoodPageIsSavedAsAPageDraftAndAttached(): void
    {
        $id = $this->written();
        $d = $this->db->query("SELECT * FROM mia_content_drafts WHERE id = $id")->fetch();
        $this->assertSame('page', $d['kind']);
        $this->assertStringContainsString('## Frequently asked questions', $d['body']);
        $a = $this->actions()->get($this->actionId);
        $this->assertSame($id, (int)$a['draft_ref_id']);
        $this->assertGreaterThanOrEqual(MiaPageWriter::MIN_WORDS, MiaPageMarkup::wordCount($d['body']));
    }

    public function testTheModelOnlySeesTheFactsAndIsToldNotToWriteACaseStudy(): void
    {
        $this->written();
        $text = json_encode($this->sent[0]['messages']) . json_encode($this->sent[0]['system']);
        $this->assertStringContainsString('Weekly garden weeding and mulch refresh', $text);
        $this->assertStringContainsString('Do NOT write a case study', $text);
        $this->assertStringNotContainsString('Not public yet', $text, 'unpublished projects are not even mentioned');
        $this->assertStringNotContainsString('1695', $text, 'search statistics are not page facts');
    }

    public function testNothingIsWrittenWhenNoServiceMatchesTheSearch(): void
    {
        $this->db->exec("UPDATE mia_actions SET evidence = '[{\"query\":\"tree surgery\",\"impressions\":900}]'");
        $r = $this->writer()->prepare($this->actionId, 1, $this->now);
        $this->assertSame('no_match', $r['code']);
        $this->assertCount(0, $this->sent, 'no model call when there is nothing true to say');
    }

    public function testInventedNumbersLinksNamesClaimsAndShortPagesAreRejected(): void
    {
        $f = $this->writer()->facts(self::QUERY);
        $ok = $this->good();
        $this->assertSame([], MiaPageWriter::lint($ok, $f, []));
        $cases = [
            'number' => $this->goodBody(['append' => "\n\nWe have served 500 clients over 12 years."]),
            'link' => $this->goodBody(['append' => "\n\nVisit www.example.com for more."]),
            'claim' => $this->goodBody(['append' => "\n\nWe are the best and most trusted crew, guaranteed."]),
            'name' => $this->goodBody(['append' => "\n\nOur crew leader Margaret handles every property."]),
            'filler' => $this->goodBody(['append' => "\n\nLet us delve into the details."]),
            'tag' => $this->goodBody(['append' => "\n\n<b>bold</b>"]),
            'phone' => $this->goodBody(['append' => "\n\nCall 604-555-0199 today."]),
            'short' => "Intro.\n\n## A\n\nText.\n\n## B\n\nText.\n\n## C\n\nText.\n\n## Frequently asked questions\n\n### One?\nA.\n\n### Two?\nB.\n\n### Three?\nC.",
        ];
        foreach ($cases as $k => $body) {
            $this->assertNotEmpty(MiaPageWriter::lint(array_merge($ok, ['body' => $body]), $f, []), $k);
        }
    }

    public function testARejectedFirstAttemptGetsOneRepairAndTwoFailuresAttachNothing(): void
    {
        $this->replies[] = $this->good(['body' => $this->goodBody(['append' => "\n\nWe have served 500 clients."])]);
        $this->replies[] = $this->good();
        $r = $this->writer()->prepare($this->actionId, 1, $this->now);
        $this->assertTrue($r['ok']); $this->assertSame(2, $r['attempts']);
        $this->assertStringContainsString('numbers that are not in the facts', json_encode($this->sent[1]['messages']));

        $this->setUp();
        $bad = $this->good(['body' => $this->goodBody(['append' => "\n\nWe are the best crew."])]);
        $this->replies[] = $bad; $this->replies[] = $bad;
        $r = $this->writer()->prepare($this->actionId, 1, $this->now);
        $this->assertSame('lint', $r['code']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM mia_content_drafts"));
    }

    public function testTheSameFactsAreNotPaidForTwiceAndAMissingColumnIsSaidPlainly(): void
    {
        $this->written();
        $r = $this->writer()->prepare($this->actionId, 1, $this->now);
        $this->assertTrue($r['reused']);
        $this->assertCount(1, $this->sent);
        $this->setUp();
        $this->db->exec("ALTER TABLE mia_content_drafts DROP COLUMN body");
        $this->assertSame('not_ready', $this->writer()->prepare($this->actionId, 1, $this->now)['code']);
    }

    public function testOnlyASearchWithNoPageGetsAWrittenPage(): void
    {
        $this->assertTrue(MiaPageWriter::wantsNewPage($this->actions()->get($this->actionId)));
        $this->assertFalse(MiaPageWriter::wantsNewPage(['type' => 'page_draft', 'evidence' => json_encode([['page' => 'https://mowology.ca/x', 'query' => 'q']])]));
        $this->assertFalse(MiaPageWriter::wantsNewPage(['type' => 'seo_meta_update', 'evidence' => json_encode([['query' => 'q']])]));
    }

    // ---- publisher ----

    public function testApprovedPageBecomesAnUnpublishedCmsPageWithRealProjectsAndAFaq(): void
    {
        $pub = $this->approveAndPage();
        $r = $pub->apply($this->actionId, 7, $this->now);
        $this->assertTrue($r['ok'], json_encode($r));
        $p = $this->db->query("SELECT * FROM cms_pages")->fetch();
        $this->assertSame('draft', $p['status'], 'never public');
        $this->assertStringStartsWith('blog/', $p['slug']);
        $body = (string)json_decode((string)$this->db->query("SELECT config_json FROM cms_blocks")->fetchColumn(), true)['content'];
        $this->assertStringContainsString('<h2>Recent work</h2>', $body);
        $this->assertStringContainsString('Garden Care in Point Grey', $body);
        $this->assertStringContainsString('https://mowology.ca/quote', $body);
        $this->assertStringNotContainsString('<script', $body);
        $faq = json_decode($p['generated_variables'], true)['faq_items'];
        $this->assertCount(4, $faq);
        $this->assertSame('done', $this->actions()->get($this->actionId)['status']);
        $this->assertSame('cms_article', $this->db->query("SELECT connector FROM mia_executions")->fetchColumn());
    }

    public function testNothingIsCreatedUnlessApprovedAndOtherWordsVoidTheApproval(): void
    {
        $this->written();
        $pub = new MiaPagePublisher($this->db, 1, $this->actions());
        $this->assertSame('not_approved', $pub->apply($this->actionId, 7, $this->now)['code']);
        $pub = $this->approveAfter();
        $this->db->exec("UPDATE mia_content_drafts SET body = body || ' An extra sentence added after approval.'");
        $this->assertSame('draft_changed', $pub->apply($this->actionId, 7, $this->now)['code']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM cms_pages"));
    }

    private function approveAfter(): MiaPagePublisher
    {
        $pub = new MiaPagePublisher($this->db, 1, $this->actions());
        $c = $pub->inspect($this->actionId);
        $this->assertTrue($this->actions()->decide($this->actionId, 7, 'approve', null, null, $c['content'], $this->now)['ok']);
        return $pub;
    }

    public function testAProjectPublishedAfterApprovalVoidsIt(): void
    {
        $pub = $this->approveAndPage();
        $this->db->exec("INSERT INTO portfolio_projects (project_name, description, location, status, categories, tags) VALUES ('Garden bed refresh', 'Fresh mulch.', 'Dunbar', 'published', '[]', '[]')");
        $this->assertSame('draft_changed', $pub->apply($this->actionId, 7, $this->now)['code'], 'the appended projects are part of what was approved');
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM cms_pages"));
    }

    public function testAFailureRecordingTheApplyRemovesThePageAgain(): void
    {
        $pub = $this->approveAndPage();
        $this->db->exec("DROP TABLE mia_executions");
        $r = $pub->apply($this->actionId, 7, $this->now);
        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM cms_pages"), 'no orphan page');
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM cms_blocks"));
    }

    public function testUndoRemovesAnUntouchedDraftPageButNotAnEditedOrPublishedOne(): void
    {
        $pub = $this->approveAndPage();
        $r = $pub->apply($this->actionId, 7, $this->now);
        $this->db->exec("UPDATE cms_pages SET title = 'Edited by the owner'");
        $this->assertSame('conflict', $pub->rollback($r['execution_id'], 7, $this->now)['code']);
        $this->db->exec("UPDATE cms_pages SET title = 'Garden maintenance in Vancouver', status = 'published'");
        $a = $pub->rollback($r['execution_id'], 7, $this->now);
        $this->assertSame('conflict', $a['code']); $this->assertStringContainsString('published', $a['error']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM cms_pages"));
        $this->db->exec("UPDATE cms_pages SET status = 'draft'");
        $u = $pub->rollback($r['execution_id'], 7, $this->now);
        $this->assertTrue($u['ok'], json_encode($u));
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM cms_pages") + $this->n("SELECT COUNT(*) FROM cms_blocks"));
        $this->assertSame('rolled_back', $this->actions()->get($this->actionId)['status']);
    }

    public function testTheOwnerGetsALinkToThePageInTheWebsiteEditorWhileItExists(): void
    {
        $pub = $this->approveAndPage();
        $pub->apply($this->actionId, 7, $this->now);
        $pid = (int)$this->db->query("SELECT id FROM cms_pages")->fetchColumn();
        $this->assertSame('/crm/cms/cms-page-edit.php?id=' . $pid, $pub->executionsFor($this->actionId)[0]['open_url']);
    }

    public function testAnApprovedIdeaCanBeReopenedButNotOnceItIsDone(): void
    {
        $c = $this->actions()->decide($this->actionId, 7, 'approve', null, null, "ACTION: Consider a page for the search \"garden maintenance\"\nWHY: People searched for it 1,695 times but no page ranks.\nBENEFIT: \nRISK: A thin page can hurt the site.", $this->now);
        $this->assertTrue($c['ok'], json_encode($c));
        $this->assertTrue($this->actions()->reopen($this->actionId, 7, 'to write the page', $this->now));
        $this->assertSame('proposed', $this->actions()->get($this->actionId)['status']);
        $this->assertFalse($this->actions()->reopen($this->actionId, 7, 'again', $this->now), 'only approved ones');
    }

    public function testPlacesAreGivenWithoutTheRepeatedCityAndProvince(): void
    {
        $this->assertSame('Point Grey', MiaPageWriter::cleanPlace('Point Grey Vancouver BC'));
        $this->assertSame('Mount Pleasant East', MiaPageWriter::cleanPlace('Mount Pleasant East Vancouver BC'));
        $this->assertSame('Kerrisdale', MiaPageWriter::cleanPlace('Kerrisdale, Vancouver, BC'));
        $this->assertSame('Vancouver', MiaPageWriter::cleanPlace('Vancouver'), 'never an empty place');
        $this->db->exec("UPDATE portfolio_projects SET location = 'Point Grey Vancouver BC' WHERE id = 1");
        $this->assertContains('Point Grey', $this->writer()->facts(self::QUERY)['places']);
    }

    public function testWordsInTheSearchDoNotBecomeFacts(): void
    {
        $f = $this->writer()->facts('24 hour insured hedge removal');
        $f['matched'] = [['name' => 'Hedge Trim Light', 'description' => 'Light hedge trimming (up to 1 hour)']];
        $f['query'] = '24 hour insured hedge removal';
        $this->assertNotEmpty(MiaPageWriter::lint($this->good(['body' => $this->goodBody(['append' => "\n\nWe offer insured 24 hour hedge removal."])]), $f, []));
        $this->assertStringNotContainsString('24 hour', MiaPageWriter::factsText($f));
    }

    public function testTheQuestionsMustBeInTheLastSectionAndReallyBeQuestions(): void
    {
        $f = $this->writer()->facts(self::QUERY);
        $notLast = str_replace('## Who this suits', "## Frequently asked questions\n\n### One?\nA.\n\n### Two?\nB.\n\n### Three?\nC.\n\n## Who this suits", $this->goodBody());
        $this->assertNotEmpty(MiaPageWriter::lint($this->good(['body' => $notLast]), $f, []));
        $b = $this->goodBody();
        $fake = substr($b, 0, strpos($b, '## Frequently asked questions')) . "## Frequently asked questions\n\nPlease call us with any question you have.";   // a heading with no questions
        $this->assertNotEmpty(MiaPageWriter::lint($this->good(['body' => $fake]), $f, []));
    }

    public function testACleanupAfterAFailedApplyNeverStripsAPageThatWasPublishedMeanwhile(): void
    {
        $pub = $this->approveAndPage();
        $this->db->exec("CREATE TRIGGER pub_after_save AFTER INSERT ON cms_blocks BEGIN UPDATE cms_pages SET status = 'published' WHERE id = NEW.page_id; END");
        $this->db->exec("DROP TABLE mia_executions");
        $r = $pub->apply($this->actionId, 7, $this->now);
        $this->assertFalse($r['ok']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM cms_pages WHERE status = 'published'"), 'the published page is left alone');
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM cms_blocks"), 'with its text');
    }
}
