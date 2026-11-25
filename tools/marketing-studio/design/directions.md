# Marketing Studio — art direction

**Phase 0.** Single user (the owner), local tool, opened from the Desktop or inside the Claude desktop app's browser pane. The ONE action per screen: advance the current piece one step. Arrives knowing the business; needs to feel *guided and calm*, never like a form. Adjectives: workshop, deliberate, warm. Brand tokens exist (forest green, gold trust accent, orange CTA, Playfair Display + DM Sans) and are honoured. Category cliché for "marketing dashboards": white SaaS shell, blue accent, card grid, sidebar of icons. Declined.

**Design-log check.** Last three logged designs were all paper ground + serif display + mono apparatus + hairline rules, and all flat; Spectral, IBM Plex Mono, Fraunces and Karla are out. So: change the ground (dark), change the apparatus (numeric signage, no mono rail), choose a depth model with a light source.

## Direction A — The Workbench (chosen)
- Ground: dark, forest (brand `--bg-forest-deep` family), value-layered surfaces.
- Display class: serif (Playfair Display, brand) for step titles only; DM Sans (brand) for everything else. Kept because the serif/sans contrast is the brand's one differentiator.
- Apparatus: numeric signage. The current step's numeral is oversized (≈ 7× body) in gold.
- Section rhythm: one dominant + minor. A persistent process rail (minor) and one work canvas (dominant); the pipeline is a drawer, not a page.
- Technique poles: asymmetry over symmetry; economy over intricacy; sequentiality over randomness; contrast over harmony (one gold numeral against a dark field).
- Proportion: golden section 0.618 : 0.382 (rail : canvas = 0.382 : 0.618 of 1440 → 550/890; below 1024 the rail collapses to a top strip at 0.382 of the height budget).
- Grid + ONE violation: hierarchic two-track. Violation = **edge crossing**: the active step numeral straddles the rail/canvas boundary. Caused by content (the step you are on is the only thing that belongs to both the plan and the work); paid for by an otherwise strictly aligned canvas with uniform 8-step spacing and a single column of inputs.
- Type pairing voice: "a calm foreman reading the plan aloud". Playfair Display 600 (display, OFL) + DM Sans 400/500/600 (text, OFL). No mono.
- Palette (HSL): ground 152 40% 7%; surface-1 152 30% 11%; surface-2 152 26% 15%; line 152 20% 22%; ink 60 20% 94%; ink-muted 150 10% 70%; gold 43 52% 54% (accent; permitted: active numeral, progress line, focus ring; the focus ring counts as an accent use and is the only exception to "one accent use per viewport"); orange 22 96% 46% (CTA only, one button per screen); green 152 50% 35% for success states. Ink on orange: 60 20% 96% → 4.7:1.
- Depth: light source top-left; shadow ladder 2 tiers (`--shadow-1` cards, `--shadow-2` drawer and the primary button's green lift). Surfaces step by value as well.
- Motion: `cubic-bezier(.05,.65,.39,.95)`, 160ms micro / 280ms transitions; the ONE moment: advancing a step slides the rail's progress line and the big numeral counts up. `prefers-reduced-motion` disables the count and the slide.
- Memorable thing: the step you're on is a hand tall, gold, and leans into your work.

## Direction B — The Ledger (declined)
Paper ground, hairline table, every piece a row, steps as columns you tick across; display in a grotesque (Archivo Black); apparatus tabular; rhythm uniform bands; ratio 1:√2; violation: row breakout. Declined: it is the house style with the serif swapped, and it makes the process feel like admin rather than craft.

## Direction C — The Field Card (declined)
Image-led ground (crew photo, dark scrim), signage display (Bebas Neue), apparatus none, rhythm continuous scroll, ratio 3:4 portrait cards, violation: overlap of card over image edge. Declined: photos date fast, text on images needs constant contrast work, and the tool is used daily; it should be quiet.
