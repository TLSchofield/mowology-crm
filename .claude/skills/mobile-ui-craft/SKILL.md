---
name: mobile-ui-craft
description: Use BEFORE building or changing any phone-facing screen or any motion in the Mowology CRM / Ittai — customer pages (quote, invoice, portal, gallery, proof), public forms (quote wizard, contact, password, unsubscribe), the CRM on a phone (schedule cards, quote builder, time clock), and the native iPhone and Android apps. Holds the mobile rules (touch targets, 16px inputs, safe areas, tables to cards, hover, contrast, focus, forms) and the motion rules (timings, easing, loading, optimistic updates, reduced motion), each with its number and source, the one-place table, the audit script, and the traps already hit.
covers:
  - public/crm/css/mobile-cards.css
  - public/crm/css/mowology-brand.css
  - public/customer/portal.css
  - public/crm/includes/blocks/quote_wizard.php
  - scripts/ui-audit.py
metadata:
  version: 1.0.0
---

# Mobile UI craft — rules, numbers, one place per job

Load this before touching a phone-facing screen or adding motion. Run `python3 scripts/ui-audit.py` before and after
(it checks the mechanical rules below and lists what fails). Numbers are from the sources in `references/sources.md`;
anything marked **(unfetched)** is standard knowledge not re-read this session.

## 1. Mobile rules (web first; native notes in the last column)

| # | Rule | Number | Web | iPhone / Android |
|---|------|--------|-----|------------------|
| M1 | Inputs, selects and textareas must not zoom the page on focus | font-size **≥ 16px** on every phone width | the cause of iPhone Safari zoom; fix with one rule under `@media (max-width: 767px)` | not applicable (native) |
| M2 | Never lock pinch-zoom | no `user-scalable=no`, no `maximum-scale=1` | viewport meta | n/a |
| M3 | Touch targets | **44 × 44 pt/px** comfortable (Apple, WCAG 2.5.5 AAA); **48dp** Material (unfetched); **24 × 24 CSS px** is only the WCAG 2.5.8 AA floor | pad the hit area, keep the icon near 20px | `minimumInteractiveComponentSize` in Compose; 44pt frames in SwiftUI |
| M4 | Nothing under the home bar | `viewport-fit=cover` in the meta, then `padding-bottom: calc(Npx + env(safe-area-inset-bottom))` on every fixed bottom element | every `position: fixed; bottom: 0` rule | SwiftUI safe area; Compose `WindowInsets` / `navigationBarsPadding()` |
| M5 | Tables become cards on a phone | below 640px a row = one card (title, status, amount, action); numbers right-aligned with tabular figures | `mobile-cards.css` is the CRM pattern; customer pages use `.portal-table` — check each reflows | lists, not tables |
| M6 | Multi-column grids need a phone layout | any `grid-template-columns` with ≥ 4 columns needs a mobile override | quote builder `.mw-line-item` (7 columns) has none | n/a |
| M7 | Hover is never the only way to an action | gate hover styles with `@media (hover: hover)`; use `@media (pointer: coarse)` for larger controls | primary actions visible, or in the card / swipe / bottom sheet | n/a |
| M8 | Contrast | text **4.5:1**; large text (≥ 24px, or ≥ 18.5px bold) **3:1**; UI components and focus rings **3:1** | check every muted gray; do not round (4.499 fails) | same numbers |
| M9 | Colour is never the only signal | status = colour **and** icon or label | pills carry the word | same |
| M10 | Focus is visible | `:focus-visible` ring 2px, 2px offset, 3:1; never `outline: none` without a replacement; modals trap focus and Esc returns it | | TalkBack / VoiceOver order matches visual order |
| M11 | Forms validate on blur, then live | not on every keystroke, not only at submit; clear the error as soon as it is fixed; green check on a valid field | | same |
| M12 | Primary actions in thumb reach | bottom sheet or bottom bar for the main action; the top is a dead zone on tall phones | | same |

## 2. Motion rules

| # | Rule | Number |
|---|------|--------|
| A1 | Response to a tap | visible feedback **< 100 ms**; a result **< 400 ms** feels instant (Doherty); past 1 s the flow of thought breaks; past 10 s show percent-done (NN/g) |
| A2 | Entrances | **200–300 ms**, ease-out; never linear (linear only for spinners and continuous motion) |
| A3 | Exits | about **40 % faster** than the entrance (e.g. 250 in, ~150 out) |
| A4 | Press / confirm / things that move to a place | **Springs, not timers** on native: a short overshoot (about **1.5 %**, per a Motion UI Interface reel, not a primary source) that settles, because real objects move that way. iPhone: SwiftUI `.spring(...)` / `.smooth` / `.snappy`, never a fixed-duration `easeInOut` for movement. Android: Compose `spring()` with low-bounce damping. Web has no spring: use `cubic-bezier` ease-out with a slight overshoot, or CSS `linear()` easing. No wobble; interruptible (a new tap retargets from the current position, it does not restart). |
| A5 | Lists | stagger items about **50 ms** apart; reuse a small set of curves app-wide |
| A6 | Loading | nothing under ~300 ms; skeleton when the shape is known (mirror the final layout, animate the shimmer); spinner for short unknown waits (< ~3 s); progress bar with time left beyond that; never a full-page endless spinner |
| A7 | Optimistic updates | update the UI at once, sync in the background, roll back cleanly on failure. **Only for reversible, low-stakes actions** (toggles, reorders). **Never for money, sending, deleting, or anything that queues offline without a visible "waiting to send" state.** |
| A8 | Reduced motion | every animation sits behind `@media (prefers-reduced-motion: no-preference)` or is switched off under `reduce`; native: honour the system setting |
| A9 | Offline | motion must not hide state: a queued action shows "saved here, sending when online", not a success tick |

## 3. Where each job lives (one place)

- Phone-card CSS: `public/crm/css/mobile-cards.css`; CRM brand overrides: `public/crm/css/mowology-brand.css` (never inline `<style>` in AppStack pages; use `--mw-*` tokens).
- Customer pages: `public/customer/portal.css`. Public site: `public/assets/css/` (tokens in `base/variables.css`).
- Ittai v1.2 tokens: `docs/design/ittai-v1.2/tokens.css` — follow them; the symbol is frozen.
- Mechanical checks: `scripts/ui-audit.py` (add a check there, not in prose).

## 4. Parity

A phone rule applies on the web page, the iPhone app and the Android app in the same job (memory: design decisions reach Android too). When a rule is web-only (M1, M2, M4 CSS), say so in the change.

## 5. Traps already hit (dated)

- **2026-11-25 audit:** ~33 CRM input rules sit at 13–15px with no 16px override; the public quote wizard (14.7px), contact form (15px), password reset (15px), unsubscribe (14px) and the portal copy-link box (12.5px) all zoom on iPhone. `.mw-line-item` has no phone layout. Pinch-zoom is locked on `client/proof.php`, `customer/gallery.php` (customers need to zoom photos) and several CRM pages.
- **2026-11-25:** a fixed-duration timer for movement reads as a dead stop; springs are the iPhone default, so Android must match (A4), or the apps feel different even when the screens match.
- A skeleton that does not match the final layout is worse than a spinner (layout jump).
- Optimistic UI on a queued offline write shows success the server never saw — see A7/A9.

## Before and after every change

Run `python3 scripts/ui-audit.py`; fix what the change touched; add a dated trap here when a mistake is found.
