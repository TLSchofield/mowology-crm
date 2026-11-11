# Extracting a Brand Voice from Existing Writing

A repeatable method for deriving a brand's actual voice from samples of their own writing, instead of guessing at "professional but friendly" from a one-line brief. SKILL.md's "Voice and Tone" section asks for a formality level and a personality — this is *how* to answer that from evidence when the client has existing copy, rather than from vibes.

Use this before writing for an established brand with a website, past emails, social posts, or founder writing already in the world. Skip it for a brand new venture with nothing written yet — there, the "Voice and Tone" prompts in SKILL.md are the right starting point instead.

---

## Contents

1. Gathering the sample set
2. The seven-axis analysis
3. Building the voice card
4. Testing it
5. When the samples disagree with each other
6. Worked example

---

## 1. Gathering the Sample Set

Pull 5–10 pieces of the client's own writing, prioritizing in this order:

1. **Founder-written material** — a personal email, a Slack announcement, an About page draft written before an agency touched it. This is the least filtered, truest signal.
2. **Their best-performing existing copy** — whatever they say converts or gets shared, if they know.
3. **Support/customer-facing writing** — how a support rep or founder actually answers a real customer question. Often more honest than the marketing site, because nobody's polishing it for a pitch.
4. **The current website/app copy**, even if the client says they don't like it — it's still evidence of default habits, useful as a baseline to compare against.
5. **Reviews they've written of *other* things** (a G2 review left for a tool they use, a LinkedIn comment) — reveals natural sentence rhythm outside a marketing context, which is often the most reliable single signal for how someone actually talks.

Avoid using copy an agency or a previous freelance writer wrote *for* them as the primary sample — that's someone else's voice being tested, not theirs, unless the client has explicitly adopted and defended that voice as their own going forward.

**Apply:** if fewer than three samples exist, say so explicitly and treat this as a green-field voice decision (SKILL.md's Voice and Tone section) rather than forcing an extraction from too little evidence.

---

## 2. The Seven-Axis Analysis

Read the samples and score each axis, citing the specific line that justifies the score — don't just intuit a label.

1. **Sentence length and rhythm.** Count roughly: are sentences consistently short (under 12 words), long and flowing, or a deliberate mix? Note any signature move — a one-word sentence for punch, a habit of starting with "And" or "But."
2. **Formality register.** Contractions or not? First names or titles? Would this read naturally at a dinner table, in a boardroom, or somewhere between? Cite the actual word choice that reveals it (do they write "we're" or "we are"; "folks" or "clients"; "gonna" or never).
3. **Self-reference.** "We" (corporate plural), "I" (personal, usually founder-voice), or does the brand mostly avoid referring to itself at all and stay focused on the reader ("you")? This single axis often does more to define a voice than any other.
4. **Humor and warmth.** Deadpan, playful, warm-but-serious, or entirely absent? Look for one real joke or aside in the samples — its *type* (self-deprecating, wry, wholesome, sarcastic) matters more than whether jokes appear often.
5. **Directness vs. hedging.** Does the writing state things flatly ("This doesn't work for teams over 50 people") or soften them ("This might not be the best fit for larger teams")? Count hedge words (maybe, sort of, we think, might) as a real signal, not noise.
6. **Vocabulary register.** Plain everyday words, or does the brand reach for a specific vocabulary (technical jargon used confidently, industry slang, a particular metaphor family — sports, cooking, nature)? Note any word the brand uses unusually often; a repeated distinctive word is often more identity-defining than a whole paragraph of generic description.
7. **What it never does.** Sometimes the clearest signal is an absence — never uses exclamation points, never says "innovative" or "solution," never opens with a question, never uses corporate "leverage/utilize/synergy" vocabulary. An explicit "never" list is often more useful to a future writer than a list of positive traits, because it's a harder constraint to accidentally violate.

**Apply:** score all seven from direct textual evidence before writing a single word of new copy. If a sample set is too thin to score an axis confidently, mark it "insufficient evidence" rather than guessing.

---

## 3. Building the Voice Card

Compress the seven-axis analysis into a short reference — three to six lines, quotable, checkable against any draft:

```
BRAND VOICE CARD — [Client Name]

Sentence rhythm: short, declarative; rarely more than one comma per sentence
Formality: casual-professional — contractions always, first names, no jargon
Self-reference: "we," never "I" — no single named voice in the copy
Humor: dry, understated, never at the customer's expense
Directness: flat statements, no hedging ("this isn't for you if..." not "this might not work for...")
Vocabulary: plain words; one recurring metaphor family (building/construction)
Never: exclamation points, "solution," "leverage," a question as an opener
```

Store this in `.agents/product-marketing-context.md` (the `product-marketing-context` skill's file) alongside positioning, so it persists across copy tasks instead of being re-derived each time.

---

## 4. Testing It

Before trusting a voice card, run one check: take a single sentence from the original samples, and write a new sentence on an unrelated topic using the same card. Read both aloud back to back. If the new sentence doesn't sound like the same person could have written it, the card is wrong somewhere — usually the self-reference or directness axis, which are the two most commonly mis-scored.

A second, sharper test: write one sentence that deliberately *violates* the "never" list, and one that follows it, on the same claim. If a client (or a careful reader) can't immediately tell which one is "on brand," the never-list isn't specific enough yet.

---

## 5. When the Samples Disagree with Each Other

Real brands are rarely perfectly consistent — the founder's Twitter voice and the company's help docs often don't match. When samples conflict:

- **Weight founder-written, unedited material highest** for anything meant to feel personal (About page, launch announcements, founder-signed emails).
- **Weight the highest-performing existing copy highest** for anything meant to convert (landing pages, ads) — if the client can point to what's worked, that's stronger evidence than what merely exists.
- **Flag the conflict explicitly to the client** rather than silently picking one — "your website copy is formal but your emails are casual; which one is the brand's real voice going forward?" is a five-second question that saves a full rewrite later.

---

## 6. Worked Example

**Samples provided:** three past email newsletters, the current homepage, and one Slack message the founder pasted in as "how I'd actually explain this to a friend."

**Analysis:**
- Sentence rhythm: homepage uses long compound sentences; the Slack message and emails use short, choppy ones. → **Conflict.** Founder's real voice (Slack, emails) is short and choppy; homepage was likely written or heavily edited by someone else.
- Self-reference: emails consistently use "I," signed by the founder's first name. Homepage uses corporate "we." → **Conflict**, same root cause.
- Humor: the Slack message includes one dry, self-deprecating aside about the product's early bugs. Homepage has none.
- Directness: emails state limitations flatly ("this won't help if you're already using X"). Homepage hedges ("may not be suitable for all use cases").

**Resolution:** flag to the client that the homepage doesn't match how the founder actually writes; recommend the voice card follow the founder's emails and Slack message (first-person "I," short sentences, dry humor, flat statements of limitation) for new copy, and suggest the homepage itself may be a candidate for a voice-alignment pass later — but don't silently rewrite existing pages without saying why.
