-- ============================================================================
-- Migration 614: Fall Seasonal Templates (active Sept–Nov)
-- ============================================================================
-- Macro autumn leaf photo — artistic scroll-stopper. Pivot from the visual
-- beauty of fall to the reality of fall cleanup for Vancouver lawns.
-- active_months = '9,10,11' — fires automatically each fall, hibernates
-- December through August.
-- ============================================================================

INSERT INTO social_templates
    (name, category, service_type, caption_template, hashtag_preset,
     cta_preset, cta_url_preset, platform_targets, is_active, active_months,
     usage_count, created_at, updated_at)
VALUES

-- ── Facebook: educational + urgency ──────────────────────────────────────────
(
    'Fall — Facebook Cleanup Urgency',
    'seasonal',
    'seasonal_cleanup',
    'Fall is officially here in Vancouver, and it''s putting on a show. 🍂

But while the colours are stunning, those leaves are piling up fast — and leaving them on your lawn through winter can smother grass, trap moisture, and invite the kind of damage that shows up in spring.

Our fall cleanup service clears the debris, cuts the grass to the right height for dormancy, and gets your yard set up to bounce back strong next year.

Book before the rush — fall slots fill up quickly.

📍 Vancouver, Burnaby, Richmond + surrounding areas
💬 Free quote → mowology.ca
📞 (778) 846-9273',
    '#FallCleanup #LawnCare #Vancouver #Burnaby #Richmond #Mowology #SeasonalCleanup #FallLawn #BCLiving #VancouverLandscaping #LandscapingVancouver #YardWork #FallLeaves #LawnCareVancouver #OutdoorLiving',
    'BOOK',
    'https://mowology.ca',
    'facebook',
    1,
    '9,10,11',
    0,
    NOW(),
    NOW()
),

-- ── Instagram: main — doing its thing ────────────────────────────────────────
(
    'Fall — Instagram Main (fall is doing its thing)',
    'seasonal',
    'seasonal_cleanup',
    'Fall is doing its thing. 🍂

That means leaves in the gutters, debris on the lawn, and your yard working overtime.

We handle the cleanup so you don''t have to.

Free quote → mowology.ca | Link in bio',
    '#FallCleanup #LawnCare #Vancouver #Burnaby #Richmond #Mowology #SeasonalCleanup #FallLawn #BCLiving #VancouverLandscaping #YardWork #FallLeaves #LawnCareVancouver #OutdoorLiving #Autumn',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    '9,10,11',
    0,
    NOW(),
    NOW()
),

-- ── Instagram: contrast hook ──────────────────────────────────────────────────
(
    'Fall — Instagram Alt A (beautiful but brutal)',
    'seasonal',
    'seasonal_cleanup',
    'Beautiful to look at. Brutal on your lawn. 🍂

Fall cleanup done right keeps your yard healthy through winter and ready to go in spring.

Free quote → mowology.ca | Link in bio',
    '#FallCleanup #LawnCare #Vancouver #Mowology #SeasonalCleanup #FallLawn #BCLiving #VancouverLandscaping #YardWork #FallLeaves #LawnCareVancouver #OutdoorLiving #Autumn',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    '9,10,11',
    0,
    NOW(),
    NOW()
),

-- ── Instagram: short + dry humour ────────────────────────────────────────────
(
    'Fall — Instagram Alt B (or just call us)',
    'seasonal',
    'seasonal_cleanup',
    '🍂 Fall''s here. Your lawn needs attention.

(Or just call us.)

Free quote → mowology.ca | Link in bio',
    '#FallCleanup #LawnCare #Vancouver #Mowology #SeasonalCleanup #FallLawn #BCLiving #VancouverLandscaping #YardWork #FallLeaves #LawnCareVancouver #OutdoorLiving #Autumn',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    '9,10,11',
    0,
    NOW(),
    NOW()
),

-- ── Instagram: wordplay ───────────────────────────────────────────────────────
(
    'Fall — Instagram Alt C (leaves are falling)',
    'seasonal',
    'seasonal_cleanup',
    'The leaves are falling. Your lawn doesn''t have to. 🍂

We keep yards healthy through every season.

Free quote → mowology.ca | Link in bio',
    '#FallCleanup #LawnCare #Vancouver #Mowology #SeasonalCleanup #FallLawn #BCLiving #VancouverLandscaping #YardWork #FallLeaves #LawnCareVancouver #OutdoorLiving #Autumn',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    '9,10,11',
    0,
    NOW(),
    NOW()
),

-- ── Instagram: urgency hook ───────────────────────────────────────────────────
(
    'Fall — Instagram Alt D (hardest season coming)',
    'seasonal',
    'seasonal_cleanup',
    'Your lawn''s hardest season is here. 🍂

Don''t let it show in spring.

Fall cleanup → free quote at mowology.ca | Link in bio',
    '#FallCleanup #LawnCare #Vancouver #Mowology #SeasonalCleanup #FallLawn #BCLiving #VancouverLandscaping #YardWork #FallLeaves #LawnCareVancouver #OutdoorLiving #Autumn',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    '9,10,11',
    0,
    NOW(),
    NOW()
);

