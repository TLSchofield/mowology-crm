-- ============================================================================
-- Migration 613: Lifestyle Templates + active_months Seasonal Filtering
-- ============================================================================
-- 1. Adds active_months VARCHAR to social_templates.
--    NULL  = evergreen, runs year-round.
--    '3,4,5' = Spring only. '6,7,8' = Summer. Etc.
--    Pipeline uses FIND_IN_SET(MONTH(NOW()), active_months) to filter.
--    Good posts cycle back naturally — usage_count rotates them equally,
--    and seasonal ones re-emerge each year when their months return.
--
-- 2. Sets Spring Refresh Hook (seeded in 610) to active in Mar–May only.
--
-- 3. Seeds 4 lifestyle / emotional templates (family on lawn photo).
--    Category: proof_of_work. These are evergreen (active_months NULL).
-- ============================================================================

-- ── Add active_months column ──────────────────────────────────────────────────
ALTER TABLE social_templates
    ADD COLUMN active_months VARCHAR(24) NULL DEFAULT NULL
        COMMENT 'Comma-separated month numbers (1–12) this template is active. NULL = all months (evergreen).'
        AFTER is_active;

-- ── Tag the Spring Refresh Hook as Spring-only ────────────────────────────────
UPDATE social_templates
SET active_months = '3,4,5'
WHERE name = 'Spring Refresh Hook (IG)'
  AND category = 'seasonal';

-- ── Lifestyle / emotional templates (family on lawn) ──────────────────────────
INSERT INTO social_templates
    (name, category, service_type, caption_template, hashtag_preset,
     cta_preset, cta_url_preset, platform_targets, is_active, active_months,
     usage_count, created_at, updated_at)
VALUES

-- Facebook: story format
(
    'Lifestyle — Family Moment Facebook',
    'proof_of_work',
    'lawn_care',
    'We talk a lot about lawns. Clean edges. Fresh cuts. Consistent service.

But this is really what it''s about.

Somewhere soft for the kids to land. A yard that actually gets used. Summer happening right outside your door.

We keep your lawn ready for moments like this.

📍 Vancouver, Burnaby, Richmond + surrounding areas
💬 Free quote → mowology.ca
📞 (778) 846-9273',
    '#LawnCare #LawnCareVancouver #Vancouver #Burnaby #Richmond #Mowology #BCLiving #FamilyTime #BackyardLife #SummerMemories #VancouverFamily #KidsOutdoors #OutdoorLiving #GreenGrass #LawnGoals',
    'BOOK',
    'https://mowology.ca',
    'facebook',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- Instagram: emotional lead
(
    'Lifestyle — This Is Why We Do It (IG)',
    'proof_of_work',
    'lawn_care',
    'This is why we do it. 🌿

Lawns aren''t just grass. They''re where summer happens.

Keep yours ready. | Link in bio',
    '#LawnCare #LawnCareVancouver #Vancouver #Mowology #BCLiving #FamilyTime #BackyardLife #SummerMemories #VancouverFamily #KidsOutdoors #OutdoorLiving #GreenGrass #LawnGoals #WeeklyMowing',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- Instagram: minimal / poetic
(
    'Lifestyle — Somewhere Soft to Land (IG)',
    'proof_of_work',
    'lawn_care',
    'Somewhere soft to land. 🌱

Free quote → mowology.ca | Link in bio',
    '#LawnCare #LawnCareVancouver #Vancouver #Mowology #BCLiving #FamilyTime #BackyardLife #SummerMemories #VancouverFamily #KidsOutdoors #OutdoorLiving #GreenGrass #LawnGoals',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- Instagram: nostalgic
(
    'Lifestyle — Best Summer Memories (IG)',
    'proof_of_work',
    'lawn_care',
    'The best summer memories happen on grass this green. 🌿

We keep yours ready all season long.

Free quote → mowology.ca | Link in bio',
    '#LawnCare #LawnCareVancouver #Vancouver #Mowology #BCLiving #FamilyTime #BackyardLife #SummerMemories #VancouverFamily #KidsOutdoors #OutdoorLiving #GreenGrass #LawnGoals #WeeklyMowing',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
);

