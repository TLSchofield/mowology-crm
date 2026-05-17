-- ============================================================================
-- Migration 610: Seed Instagram Caption Templates
-- ============================================================================
-- Adds brand-voice Instagram templates to social_templates.
-- These rotate through the auto-generation pipeline when service_type matches.
-- Hashtags are stored separately so the publisher can drop them in the
-- caption or in a first comment (current behaviour: caption body).
-- ============================================================================

INSERT INTO social_templates
    (name, category, service_type, caption_template, hashtag_preset,
     cta_preset, cta_url_preset, platform_targets, is_active, usage_count,
     created_at, updated_at)
VALUES

-- ── Lawn Goals (short & punchy) ───────────────────────────────────────────
(
    'Lawn Goals — Short & Punchy (IG)',
    'proof_of_work',
    'lawn_care',
    'Lawn goals. 🌿

Your yard should be so good, someone wants to roll in it.

We''ll handle the mowing. You handle the memories.

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

-- ── Result-focused (neighbourhood personalised) ───────────────────────────
(
    'Neighbourhood Result — Before/After (IG)',
    'proof_of_work',
    'lawn_care',
    'Another {neighborhood} lawn, sorted. ✅

Before → After. That''s the Mowology difference.

Consistent cuts. No chasing. No no-shows.

Get yours → mowology.ca',
    '#LawnCare #LawnCareVancouver #BeforeAndAfter #Vancouver #Mowology #LawnGoals #GreenGrass #BCLiving #CurbAppeal #WeeklyMowing #LandscapingVancouver #YardWork #OutdoorLiving',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    0,
    NOW(),
    NOW()
),

-- ── Crew pride / social proof ─────────────────────────────────────────────
(
    'Crew Pride — Social Proof (IG)',
    'proof_of_work',
    NULL,
    '{crew_name} put in the work on this {service_label} in {neighborhood} today. 💪

Clean edges. Happy client. Another one done.

Book your spot → mowology.ca',
    '#LawnCare #Vancouver #Mowology #LandscapingVancouver #BCLiving #CurbAppeal #GreenGrass #YardWork #ProfessionalLandscaping #OutdoorLiving',
    'BOOK',
    'https://mowology.ca',
    'instagram,facebook',
    1,
    0,
    NOW(),
    NOW()
),

-- ── Seasonal hook (Spring) ────────────────────────────────────────────────
(
    'Spring Refresh Hook (IG)',
    'seasonal',
    NULL,
    '{season} cleanup, {neighborhood} style. 🌱

The grass is back. The weeds aren''t welcome.

We handle the {service_label} so you can actually enjoy your yard.

Free quote → mowology.ca',
    '#SpringLawn #LawnCare #Vancouver #Mowology #SpringCleaning #BCLiving #GreenGrass #CurbAppeal #LawnGoals #OutdoorLiving #WeeklyMowing #LandscapingVancouver',
    'BOOK',
    'https://mowology.ca',
    'instagram,facebook',
    1,
    0,
    NOW(),
    NOW()
);

-- ── Track migration ───────────────────────────────────────────────────────
INSERT INTO migrations_log (migration_filename, executed_at, status)
VALUES ('610_seed_instagram_templates.sql', NOW(), 'success')
ON DUPLICATE KEY UPDATE executed_at = NOW(), status = 'success';
