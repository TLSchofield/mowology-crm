-- ============================================================================
-- Migration 616: Lifestyle Templates — Joy / Freedom on the Lawn
-- ============================================================================
-- Girl spinning on lush green lawn, soft bokeh, dappled summer light.
-- Sister template set to migration 613 (boy on grass). Together these
-- form a "this is why we do it" lifestyle series — rotate naturally
-- with the rest of the lawn_care pool.
-- Evergreen (active_months NULL). service_type = 'lawn_care'.
-- ============================================================================

INSERT INTO social_templates
    (name, category, service_type, caption_template, hashtag_preset,
     cta_preset, cta_url_preset, platform_targets, is_active, active_months,
     usage_count, created_at, updated_at)
VALUES

-- ── Facebook: story format ────────────────────────────────────────────────────
(
    'Lifestyle — Spinning Facebook',
    'proof_of_work',
    'lawn_care',
    'A great lawn isn''t about the grass.

It''s about what happens on it.

Room to run, spin, fall down, and do it all again. That''s what we''re really maintaining out there — the space for summer to happen.

We keep your lawn ready for moments like this, all season long.

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

-- ── Instagram: minimal / perfect ─────────────────────────────────────────────
(
    'Lifestyle — Room to Run (IG)',
    'proof_of_work',
    'lawn_care',
    'Room to run. 🌿

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
),

-- ── Instagram: playful ────────────────────────────────────────────────────────
(
    'Lifestyle — Spin Around (IG)',
    'proof_of_work',
    'lawn_care',
    'Spin around. Fall down. Get back up. Repeat. 🌿

This is what a well-kept lawn is really for.

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

-- ── Instagram: space / freedom ────────────────────────────────────────────────
(
    'Lifestyle — Space for This (IG)',
    'proof_of_work',
    'lawn_care',
    'We maintain the lawn. You make the memories. 🌿

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

