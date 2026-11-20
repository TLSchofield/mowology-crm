<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/MiaDemo.php';
require_once dirname(__DIR__, 2) . '/Support/MiaPageFixture.php';

/** The owner's path for a suggested page, through the API: write it, read it, change it, approve it, save it unpublished, undo. */
final class MiaPageFlowTest extends TestCase
{
    use MiaPageFixture;

    private function h(): MiaApiHandler
    {
        $h = new MiaApiHandler($this->db, 7, null, null, 1);
        $h->setPageWriterFactory(function (PDO $db, int $site, MiaActionService $actions) { return $this->writer(); });
        return $h;
    }
    private function call(string $mode, string $method, array $q = [], array $in = []): array { return $this->h()->handle($mode, $method, $q, $in, $this->now); }

    public function testASuggestionWithNoPageOffersToWriteOneAndShowsNothingElseYet(): void
    {
        [$c, $d] = $this->call('action_detail', 'GET', ['id' => $this->actionId]);
        $this->assertTrue($d['item']['can_write_page']);
        $this->assertNull($d['item']['draft']);
    }

    public function testWritingTheThePageGivesTheOwnerTheTextTheRealProjectsAndAnEditForm(): void
    {
        $this->replies[] = $this->good();
        [$c, $w] = $this->call('page_write', 'POST', [], ['id' => $this->actionId]);
        $this->assertSame(200, $c, json_encode($w));
        $draft = $w['item']['draft'];
        $this->assertStringContainsString('## Frequently asked questions', $draft['body']);
        $this->assertSame(['Garden Care in Point Grey'], array_column($draft['appended'], 'name'), 'only real, published, matching projects');
        $this->assertGreaterThan(350, $draft['words']);
        $this->assertArrayNotHasKey('can_write_page', $w['item']);
        [$c, $again] = $this->call('page_write', 'POST', [], ['id' => $this->actionId]);
        $this->assertSame(409, $c); $this->assertSame('has_draft', $again['code']);
    }

    public function testAnIdeaApprovedEarlierIsReopenedToWriteTheTextAndMustBeApprovedAgain(): void
    {
        [$c, $d] = $this->call('action_detail', 'GET', ['id' => $this->actionId]);
        $this->assertSame(200, $this->call('action_decide', 'POST', [], ['id' => $this->actionId, 'decision' => 'approve', 'seen_hash' => $d['item']['seen_hash']])[0]);
        $this->assertSame('approved', $this->actions()->get($this->actionId)['status']);
        $this->replies[] = $this->good();
        [$c, $w] = $this->call('page_write', 'POST', [], ['id' => $this->actionId]);
        $this->assertSame(200, $c, json_encode($w));
        $this->assertSame('proposed', $this->actions()->get($this->actionId)['status'], 'the approval was of the idea: the text needs its own');
    }

    public function testNoServiceMatchAndMissingMigrationAreSaidPlainly(): void
    {
        $this->db->exec("UPDATE mia_actions SET evidence = '[{\"query\":\"tree surgery\",\"impressions\":900}]'");
        [$c, $r] = $this->call('page_write', 'POST', [], ['id' => $this->actionId]);
        $this->assertSame(422, $c); $this->assertSame('no_match', $r['code']);
        $this->assertStringContainsString('nothing true', $r['error']);
        $this->setUp();
        $this->db->exec("ALTER TABLE mia_content_drafts DROP COLUMN body");
        [$c, $r] = $this->call('page_write', 'POST', [], ['id' => $this->actionId]);
        $this->assertSame(503, $c); $this->assertStringContainsString('1405', $r['error']);
    }

    public function testEditApproveSaveUnpublishedAndUndo(): void
    {
        $this->replies[] = $this->good();
        [$c, $w] = $this->call('page_write', 'POST', [], ['id' => $this->actionId]);
        $d = $w['item']['draft'];
        $edited = str_replace('Looking for garden maintenance in Vancouver?', 'Want your garden looked after every week?', $d['body']);
        [$c, $e] = $this->call('draft_edit', 'POST', [], ['id' => $this->actionId, 'title' => 'Garden maintenance in Vancouver', 'meta_description' => $d['meta_description'], 'body' => $edited]);
        $this->assertSame(200, $c, json_encode($e));
        $this->assertStringContainsString('every week?', $e['item']['draft']['body']);
        [$c, $bad] = $this->call('draft_edit', 'POST', [], ['id' => $this->actionId, 'title' => 'Garden maintenance in Vancouver', 'meta_description' => $d['meta_description'], 'body' => $edited . "\n\n<script>x</script>"]);
        $this->assertSame(422, $c);

        [$c, $det] = $this->call('action_detail', 'GET', ['id' => $this->actionId]);
        [$c, $ap] = $this->call('action_decide', 'POST', [], ['id' => $this->actionId, 'decision' => 'approve', 'seen_hash' => $det['item']['seen_hash']]);
        $this->assertSame(200, $c, json_encode($ap));
        [$c, $det2] = $this->call('action_detail', 'GET', ['id' => $this->actionId]);
        $this->assertTrue($det2['item']['apply']['can_apply'], json_encode($det2['item']['apply']));
        [$c, $app] = $this->call('action_apply', 'POST', [], ['id' => $this->actionId]);
        $this->assertSame(200, $c, json_encode($app));
        $this->assertSame('draft', $this->db->query("SELECT status FROM cms_pages")->fetchColumn());
        $this->assertStringContainsString('every week?', (string)$this->db->query("SELECT config_json FROM cms_blocks")->fetchColumn());

        $exec = (int)$this->db->query("SELECT id FROM mia_executions")->fetchColumn();
        [$c, $u] = $this->call('action_rollback', 'POST', [], ['execution_id' => $exec]);
        $this->assertSame(200, $c, json_encode($u));
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM cms_pages"));
    }

    public function testAStaleScreenCannotApproveAPageThatChanged(): void
    {
        $this->replies[] = $this->good();
        $this->call('page_write', 'POST', [], ['id' => $this->actionId]);
        [$c, $det] = $this->call('action_detail', 'GET', ['id' => $this->actionId]);
        $this->db->exec("UPDATE mia_content_drafts SET body = body || ' Added in another tab.'");
        [$c, $r] = $this->call('action_decide', 'POST', [], ['id' => $this->actionId, 'decision' => 'approve', 'seen_hash' => $det['item']['seen_hash']]);
        $this->assertSame(409, $c); $this->assertSame('changed', $r['code']);
    }

    public function testAnEditedPageShowsMiasChecksButIsNotBlocked(): void
    {
        $this->replies[] = $this->good();
        $this->call('page_write', 'POST', [], ['id' => $this->actionId]);
        [$c, $w] = $this->call('action_detail', 'GET', ['id' => $this->actionId]);
        $this->assertSame([], $w['item']['draft']['checks']);
        $body = $w['item']['draft']['body'] . "\n\nWe are fully insured and licensed.";
        [$c, $e] = $this->call('draft_edit', 'POST', [], ['id' => $this->actionId, 'title' => 'Garden maintenance in Vancouver', 'meta_description' => $w['item']['draft']['meta_description'], 'body' => $body]);
        $this->assertSame(200, $c, 'the owner may say what is true of their business');
        $this->assertNotEmpty($e['item']['draft']['checks']);
        $this->assertStringContainsString('insured', implode(' ', $e['item']['draft']['checks']));
    }
}
