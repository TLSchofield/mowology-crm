Pick up a Marketing Studio brief and turn it into a draft.

Brief to use: $ARGUMENTS (a slug). If empty, use the newest `*.md` file in `tools/marketing-studio/outbox/` that has no matching `*.draft.md`.

Steps:
1. Read the brief at `tools/marketing-studio/outbox/<slug>.md` and `.agents/product-marketing-context.md` (positioning, true proof points, customer language, the brand voice card and never-list).
2. Invoke the skill(s) the brief names (usually `copywriting`; also `email-sequence`, `social-content`, `ad-creative`, `page-cro`, `ai-seo` as listed). Choose the lead type from the awareness stage using `copywriting/references/masterson-forde-great-leads.md`, and answer the buyer's objections using `copywriting/references/objection-handling-service-buyers.md`.
3. Write the draft to `tools/marketing-studio/outbox/<slug>.draft.md` with exactly these sections: `# Draft` (the finished copy, ready to paste), `## Annotations` (lead type chosen and why; which objection is answered where; where each proof sits), `## Alternatives` (2–3 headline or CTA options with one-line rationale), `## Compliance flags` (every number, testimonial, guarantee or comparison that needs the owner's confirmation; say "none" if none).
4. If the brief has a `## Tactics` section, apply each card: the owner's "For this piece" answer wins over the standing answer, and the Annotations must say where each card shows up in the draft (e.g. which line is the Rolls Royce Moment, what was Left Out).
5. Hold the voice card: no exclamation marks, no "elevate / solution / exceptional", concrete words, one CTA. Use only proof points on the true-proof list. Never invent a number.
6. Update `tools/marketing-studio/state/pipeline.json`: find the piece whose `slug` matches, set `"status": "review"`, `"draftPath": "tools/marketing-studio/outbox/<slug>.draft.md"`, and `"draftAt": <unix time>`. Keep every other field intact.
7. Reply with: the lead type used, the three most important choices, and any compliance flags. Do not paste the whole draft into chat; the Studio shows it.

If the brief contains a `## Revision notes` section, treat it as a revision: keep what the notes don't mention, change what they do, and overwrite the draft file.
