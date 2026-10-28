<?php
declare(strict_types=1);

require_once __DIR__ . '/AgentRegistry.php';

/**
 * SkillRegistry — what each head can actually do, as data (Ittai audit 2026-10-27, stage 5).
 *
 * Replaces "everything the head knows is in one prompt" with a list of named, versioned skills, each saying: which
 * head owns it, what it is for, which service runs it, whether a language model is involved (and under which
 * llm_calls `skill` label, so cost lines up), which policy action gates it, and which eval covers it. Only things
 * that exist in the code today are listed (AgentRegistryTest checks the llm labels and services against the source).
 *
 * `deterministic` = no model call decides anything. `action` = the AgentPolicy key for the step that changes the
 * world (null for a read-only or draft-only skill). `eval` = a case name prefix in AgentEvals, or null if not yet covered.
 *
 * Pure. Global namespace; require_once it.
 */
final class SkillRegistry
{
    /** @return array<int, array{head:string, name:string, purpose:string, service:string, llm:?string, deterministic:bool, action:?string, eval:?string, version:int}> */
    public static function all(): array
    {
        $s = static fn(string $head, string $name, string $purpose, string $service, ?string $llm, ?string $action = null, ?string $eval = null): array => [
            'head' => $head, 'name' => $name, 'purpose' => $purpose, 'service' => $service, 'llm' => $llm,
            'deterministic' => $llm === null, 'action' => $action, 'eval' => $eval, 'version' => 1,
        ];
        return [
            $s('charlie', 'ask', 'Answer an owner question from CRM records', 'CharlieAskService', 'ask'),
            $s('charlie', 'brief', 'Collect, rank and email each head\'s items', 'CharlieBriefService', null),
            $s('yui', 'reply_draft', 'Draft a reply to a client email', 'YuiDraftService', 'reply_draft'),
            $s('yui', 'send_reply', 'Send a reply the owner approved', 'YuiDraftService', null, 'yui.send_reply', 'draft_safety'),
            $s('yui', 'classify_reply', 'Tell payment claims, thanks and questions apart', 'UnclaimedReplyService', null, null, 'yui.classify'),
            $s('penny', 'receipt_bookkeeper', 'Read a receipt and propose category, tag and job', 'ReceiptBookkeeperService', 'receipt_bookkeeper'),
            $s('penny', 'receipt_lines', 'Extract line items from receipt text', 'ReceiptLlmExtractor', 'receipt_lines'),
            $s('penny', 'bank_guidance', 'Explain a bank line and suggest an account', 'BankGuidanceService', 'bank_guidance'),
            $s('penny', 'approve_receipt', 'Apply an approved receipt suggestion', 'BookkeeperDeskService', null, 'penny.approve_receipt', 'penny.lessons'),
            $s('penny', 'bank_rule_apply', 'Apply a learned bank rule (two owner confirmations)', 'BankRuleLearning', null, 'penny.bank_rule_apply'),
            $s('otto', 'manual_read', 'Read an equipment manual for service intervals', 'OttoManualService', 'manual_read'),
            $s('otto', 'apply_suggestion', 'Apply an approved schedule suggestion', 'OttoActionService', null, 'otto.apply_suggestion'),
            $s('sam', 'reply_draft', 'Draft a quote follow-up', 'SamFollowupService', 'reply_draft'),
            $s('sam', 'send_followup', 'Send a follow-up the owner approved', 'SamFollowupService', null, 'sam.send_followup', 'draft_safety'),
            $s('sam', 'accept_quote', 'Accept a quote the customer signed by email', 'SamAcceptanceService', null, 'sam.accept_quote'),
            $s('sam', 'stale_quotes', 'List quotes that need a follow-up', 'SalesDeskService', null),
            $s('mia', 'media_tag', 'Tag a photo by looking at it', 'MediaTagService', 'media_tag'),
            $s('mia', 'article', 'Draft an SEO article', 'ArticleGeneratorService', 'article', null, 'article.honesty'),
            $s('mia', 'cascade', 'Draft social and email posts from an article', 'ContentCascadeService', 'cascade'),
            $s('mia', 'approve_campaign', 'Approve a proposed campaign', 'MiaCampaignService', null, 'mia.approve_campaign'),
            $s('ada', 'cron_checks', 'Flag crons that are missing, failing or slow', 'AdaDeskService', null),
        ];
    }

    /** @return array[] one head's skills */
    public static function forHead(string $head): array
    {
        return array_values(array_filter(self::all(), static fn($k) => $k['head'] === strtolower($head)));
    }
}
