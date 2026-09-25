-- ============================================================================
-- Migration 611: Seed Headline A/B Test Templates (Instagram / lawn_care)
-- ============================================================================
-- Three headline variants with consistent body copy.
-- The pipeline selects by usage_count ASC so each gets equal exposure —
-- built-in A/B rotation without any extra infrastructure.
-- ============================================================================

INSERT INTO social_templates
    (name, category, service_type, caption_template, hashtag_preset,
     cta_preset, cta_url_preset, platform_targets, is_active, usage_count,
     created_at, updated_at)
VALUES

-- ── Variant A: Confident assertion ───────────────────────────────────────────
(
    'Headline A — Your lawn should be this good (IG)',
    'proof_of_work',
    'lawn_care',
    'Your lawn should be this good. 🌿

Not complicated. Not expensive.

Just consistent, professional mowing on a schedule that works for you.

Free quote → mowology.ca',
    '#LawnCare #LawnCareVancouver #Vancouver #Burnaby #Richmond #Landscaping #Mowology #LawnGoals #GreenGrass #SummerLawn #BCLiving #CurbAppeal #WeeklyMowing #YardWork #OutdoorLiving',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    0,
    NOW(),
    NOW()
),

-- ── Variant B: Question hook ──────────────────────────────────────────────────
(
    'Headline B — When''s the last time (IG)',
    'proof_of_work',
    'lawn_care',
    'When''s the last time your lawn looked this good? 🌱

Probably longer than you''d like to admit.

We''ll make sure it never gets to that point again.

Free quote → mowology.ca',
    '#LawnCare #LawnCareVancouver #Vancouver #Burnaby #Richmond #Landscaping #Mowology #LawnGoals #GreenGrass #SummerLawn #BCLiving #CurbAppeal #WeeklyMowing #YardWork #OutdoorLiving',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    0,
    NOW(),
    NOW()
),

-- ── Variant C: Aspirational / social proof ────────────────────────────────────
(
    'Headline C — Best lawn on the block (IG)',
    'proof_of_work',
    'lawn_care',
    'The best lawn on the block starts here. 🏆

Your neighbours will notice.

We handle the mowing, edging, and consistency — you handle the compliments.

Free quote → mowology.ca',
    '#LawnCare #LawnCareVancouver #Vancouver #Burnaby #Richmond #Landscaping #Mowology #LawnGoals #GreenGrass #SummerLawn #BCLiving #CurbAppeal #WeeklyMowing #YardWork #OutdoorLiving',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    0,
    NOW(),
    NOW()
);

