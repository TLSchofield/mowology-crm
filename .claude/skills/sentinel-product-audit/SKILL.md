---
name: sentinel-product-audit
description: Use when running or reviewing a Sentinel product audit of Ittai (website, web app, iOS, Android) — the rubric (Kanso design, typography/spacing/hierarchy, UX, accessibility AAA, microinteractions, cross-platform parity, architecture and code quality, performance, commercial value, SEO and conversion), what evidence counts, how to score and write a recommendation, and the JSON output format. Also when Tim accepts, rejects or modifies a recommendation (scripts/sentinel/sentinel.py decide).
---

# Sentinel — how to audit Ittai

Sentinel proposes; Tim decides; the ledger remembers. State: `~/.mowology-sentinel/` (memory, ledger, backlog, reports).
Code: `scripts/sentinel/`. Design source: `docs/design/ittai-v1.2/` (tokens.json, SPEC-v1.2.md) — read the spec before judging design.

## What to look at (in this order of value)
1. **Does a customer finish the job?** Fewer steps, clear next action, no dead ends, errors that say what to do. Quote request, sign-up,
   first-run, the owner's daily screen. Count taps and fields; measure, don't opine.
2. **Money and trust:** conversion path on public pages (CTA visible above the fold at 375px, form friction, phone/SMS/e-mail options, proof,
   pricing clarity), page speed on mobile, anything that could lose a quote or a payment.
3. **Accessibility (the house rule is WCAG 2.2 AAA):** text contrast 7:1, targets 44pt (48 phones), focus visible, headings in order, alt text,
   form labels, no text under 12px, nothing floating over content, respects text-size and reduced-motion.
4. **Kanso (簡素) design:** only what is needed, generous quiet space, one primary action per view, restrained colour (tenant green/indigo
   from tokens, never ad-hoc hex), clear hierarchy by size and weight before colour, consistent radius/spacing from tokens.
   Typography: line length 45–75 characters, body ≥16px, scale from tokens, no more than two weights per view.
5. **Microinteractions and motion:** feedback within 100 ms of a tap, state changes that confirm (saved, sent), 150–300 ms ease-out, nothing
   that blocks the user, `prefers-reduced-motion` honoured. Reward completion; never decorate.
6. **Cross-platform consistency:** the same job should look and behave the same on web, iPhone and Android (names, order, empty states, errors).
7. **Architecture and code quality** (from the code snapshot only): duplicated logic across platforms, business logic in page files (house rule 10),
   inline `<style>`, hard-coded hex, untested services, N+1 queries visible in code, dead code, fragile coupling. Cite file:line.
8. **Performance:** LCP/CLS/INP from Lighthouse or Playwright evaluate (`performance.getEntriesByType`), image weight, render-blocking assets,
   requests per page, mobile first.
9. **SEO:** title/description/canonical/H1, structured data, internal links, indexability, local-business signals, image alt, speed.

## Guardian's measurements come first
If `guardian-latest.json` exists, read it before browsing: it holds measured page weight, LCP/CLS, axe accessibility results, tap-target and text-size counts, SEO basics and new rule violations. Do not re-report those findings as your own discoveries; build on them (why it matters, what the better design or flow is). Your value is judgement: information hierarchy, clarity of the next action, flow, copy, consistency, and what a customer feels. Never propose something Guardian already reports as an open finding unless you add a different, larger insight.

## Evidence rules
- A claim needs a measurement, a screenshot (`browser_take_screenshot` with filename `NAME-WIDTH.png`; cite just that filename) or file:line.
- Check at 375, 768 and 1440 px. Look at the real page, not memory of it.
- Never cite client names or personal data; there should be none on public pages — if you see any, report it as a finding without repeating it.
- Native apps: use screenshots found in `native/` of the run folder if present; otherwise write "not checked: no simulator/emulator running".

## Scoring (keeps cosmetic work low)
`priority = impact × confidence ÷ effort` with impact 1–5 (5 = measurably changes revenue, retention or task completion for many customers;
1 = cosmetic), confidence 1–5 (5 = measured), effort S=1 M=2 L=4 XL=8. A purely cosmetic change may score impact 1 only — and then you
should not propose it.

## Offline first (Tim, 2026-11-24)
Every recommendation that touches a crew or field feature must state, in its risk notes, what happens with no connection (works / queues / unavailable) and must not make an offline path worse. If you cannot tell from the code, say "offline: not verified".

## Learning from Tim
Your prompt includes **Rejected**, **Already known** and Tim's reasons. Never re-propose a rejected idea or a near-duplicate. If new
significant evidence exists (a metric moved, a regression, a new platform), put a 40+ character explanation in `new_evidence`. A
`modify` decision means Tim wanted a smaller or different version — build on his note, don't restart.

## Output — your whole final message is this JSON, nothing else
```json
{
  "summary": "two sentences: what you looked at and the headline",
  "checked": ["ittai.ca home @375/768/1440", "code: public/crm/css"],
  "not_checked": ["iOS: no simulator running"],
  "recommendations": [{
    "topic": "web.home.hero-contrast",           // stable dotted slug: surface.area.issue — the same issue always gets the same topic
    "title": "short, specific",
    "area": "web|webapp|ios|android|cross-platform|code|seo",
    "problem": "1-3 sentences",
    "evidence": [{"text": "what you measured/saw", "screenshot": "home-375.png"}],
    "proposal": "what to change, concretely (files/components if known)",
    "benefit": "customer or business benefit, in plain words",
    "effort": {"size": "S|M|L|XL", "estimate": "e.g. 2 hours"},
    "risk": {"level": "low|medium|high", "notes": "what could go wrong and how to contain it"},
    "acceptance": ["measurable criterion with a number, e.g. LCP <= 2.5 s on 4G mobile"],
    "impact": 1, "confidence": 1,
    "new_evidence": "only when re-raising something already decided"
  }]
}
```
(No comments in the real JSON.) Maximum three recommendations in a daily audit; a weekly audit may include up to ten, ranked, as a
roadmap, and should also review the ledger: which accepted items are still open, which rejected themes keep surfacing.

## Operating the system
- Run now: `scripts/sentinel/run-audit.sh daily|weekly` (needs the Claude CLI signed in once: `claude auth login`).
- Decide: `sentinel accept|reject|modify <id> "why"` (wrapper installed at `~/.mowology-sentinel/bin/sentinel`); a reason is mandatory.
- Reports: `~/.mowology-sentinel/reports/YYYY-MM-DD/daily.md`; backlog: `~/.mowology-sentinel/backlog.md`.
- Add a specialist: copy the `future_template` block in `scripts/sentinel/registry.json`; level 0 until Tim promotes it in writing.
