# MIA-UX

Basis: Ittai Design System v1.2 (`docs/design/ittai-v1.2/`, frozen 2026-10-17; the repo never uses the word "Kanso", so this doc says "Ittai v1.2 / Kanso principles"). Principles from the brief: quiet hierarchy, hairline structure, **rows before cards**, one primary action per region, status as icon + text, tokens only, Inter for UI.

## 0. Where it renders — a decision for the owner
- **Web CRM today = AppStack Bootstrap 4 with `--mw-*` tokens.** There is **no Ittai web CSS**; the Ittai system exists only as native iOS SwiftUI (`ItTokens.swift`, `ItComponents.swift`), and the README's `tools/build-tokens.mjs` generator is missing.
- Recommendation: build Mia's web workspace now in the existing AppStack shell using `--mw-*` tokens and new `.mw-mia-*` classes in `mowology-brand.css` (project rules: no inline styles, no hex values, no new CSS files without import-chain entry), laid out to Ittai principles (rows, hairlines, one primary action). Add the iOS surface via the existing head-card path (`TeamCardService`/`HeadCardView`). A real Ittai web theme layer is a separate project; do not block Mia on it.

## 1. Workspace: Presence · Growth · Results
One page `public/crm/marketing/mia_appstack.php` (`$activePage = 'marketing'` – verify the key exists; the documented list lacks it, so confirm in `appstack_sidebar.php`), three tabs, default **Growth** when anything awaits a decision, otherwise Presence.

**Top strip (every tab):** one line – "Mia · 3 waiting for you" + autonomy level badge ("Recommend only"). Nothing else.

**Presence** – "Are we findable and trusted?"
- Rows: Website health (one status row per area: Indexing, Titles & descriptions, Mobile speed, Broken links, Structured data) each `icon + text + count`, click → findings list.
- Google presence: Search Console connected? Business Profile connected? (state + next step; "Needs your Google approval" when an API is not granted – never a fake green).
- Reputation: rating trend, reviews waiting for reply (links to existing review-reply drafts).

**Growth** – "What should we do next?"
- **Action queue** (primary): rows sorted by score. Each row: title in plain English, one-line why, "Estimate" benefit, risk icon, [Review]. Primary button only on the open item.
- Goals: current goal(s) with baseline → now, review date.
- Content: drafts, project stories ready, scheduled.

**Results** – "Is it paying?"
- Funnel as five numbers in one row (Inquiries → Qualified → Estimates → Accepted → Paid) with source split; no chart unless it answers a decision.
- Per-service: revenue, margin, leads (so promotions favour profit, not traffic). Uncertainty line: "Based on 14 leads – too few to be sure."
- Past actions: verdict `improved / flat / worse / too early` with the measured numbers.

Not shown: vanity charts, keyword rank lists, SEO jargon.

## 2. Action preview (every external change)
Single detail view, same layout for all action types:
1. **What Mia wants to change** (plain sentence).
2. **Before / After** (rendered preview or diff of title/description/text; signed noindex preview URL for pages).
3. **Why** (2–3 bullets, each linked to evidence: "Page shown 1,240 times, clicked 11 times last 28 days").
4. **Expected benefit** – labelled *Estimate*, with range and confidence word (Low/Med/High) – never "will rank".
5. **Risk / uncertainty** – e.g. "Changes the page title Google currently shows."
6. **Controls:** [Approve] primary · [Edit] · [Not now] · [Reject… ▾ reason: not our service / not our voice / bad timing / other]. Approve on external publish uses the existing two-tap pattern.
7. Footer: "Can be undone: Yes (previous version kept)" or "Cannot be undone".

Owner should never need to know what a canonical tag is; the technical name is in a collapsed "Details".

## 3. Morning Briefing (Charlie)
Mia already supplies `brief()` items via `MiaDeskService`. Add at most **2 items per day**, only when:
- an action is waiting that scores above threshold, or
- something broke (estimate form failing → also raises an Ada item), or
- a project story/seasonal item is ready.

Examples (copy supplied from queue rows, not free-written):
- `Mia — 2 ready` "Three finished jobs would make good project pages. I prepared the best one."
- `Mia — Website` "Your estimate form returned errors on 4 test submits. Ada has been told."
No item when nothing is meaningful. Push uses the existing `PushHeads` label "Mia [Marketing]"; quiet-hours logic does not exist today – add before any push is sent from Mia's new events.

## 4. Website connection flow (3 steps, phone-friendly)
1. **Address** – enter site URL. Mia checks it loads (SSRF-safe) and which platform it looks like (informational).
2. **Prove it's yours** – choose: add a tag to the home page / upload a small file / add a DNS TXT. Show the exact snippet with a copy button.
3. **What may Mia do?** – radio: *Just look and report* (default) · *Also prepare drafts*. Publishing is not offered here; it is enabled per connector later with its own explanation.
Then: first audit starts; result lands in the queue within minutes. If the site blocks crawlers, say so plainly.

## 5. Mobile (phone-only owner is a standing product rule)
- Every flow completes on the phone: the queue is a vertical list of rows, preview is a full-screen sheet with sticky [Approve] bar; no tables, no horizontal scroll.
- Targets ≥44 px; status never colour-only.
- iOS: card on the head dashboard (existing `TeamCardService`) deep-linking to the web action page until a native screen exists. Field mode (outdoor) applies to iOS only; web uses AppStack light/dark.

## 6. Design-system compliance checklist
- [ ] Only tokens (`--mw-*`); no hex; classes `.mw-mia-*` in `mowology-brand.css`; no `<style>` in PHP.
- [ ] Rows, hairline dividers; cards only for the action preview.
- [ ] One primary action per region; status = icon + text.
- [ ] Inter (already in AppStack head); Zen Kaku Gothic New not used on workspace.
- [ ] Contrast checked in light and dark.
- [ ] No notification unless there is something to decide.
- [ ] Feather icon names for sidebar/nav.
