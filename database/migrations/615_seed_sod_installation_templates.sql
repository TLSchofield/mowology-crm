-- ============================================================================
-- Migration 615: Sod Installation Templates
-- ============================================================================
-- Fresh turf delivery photo — behind-the-scenes scale shot with sun flare.
-- Best-performing content type: process/BTS posts build credibility and get
-- saved by homeowners planning landscaping projects.
-- service_type = 'landscaping'. Evergreen (active_months NULL) — sod
-- installs year-round in Vancouver's mild climate.
-- ============================================================================

INSERT INTO social_templates
    (name, category, service_type, caption_template, hashtag_preset,
     cta_preset, cta_url_preset, platform_targets, is_active, active_months,
     usage_count, created_at, updated_at)
VALUES

-- ── Facebook: educational + behind the scenes ─────────────────────────────────
(
    'Sod Install — Facebook BTS',
    'proof_of_work',
    'landscaping',
    'Sod delivery day. 🌿

This is the part most people don''t see — the prep, the delivery, the early morning hustle before the transformation happens.

Sod installation is the fastest way to go from bare dirt to a lawn you can actually use. No waiting months for seed to fill in. No patchy results. Just fresh, established turf laid flat and ready to root.

We handle the whole thing — prep, installation, and aftercare advice to make sure it takes.

📍 Vancouver, Burnaby, Richmond + surrounding areas
💬 Free quote → mowology.ca
📞 (778) 846-9273',
    '#SodInstallation #NewLawn #Landscaping #LawnInstallation #Vancouver #Burnaby #Richmond #Mowology #VancouverLandscaping #LandscapingVancouver #FreshTurf #LawnCare #BCLiving #CurbAppeal #NewConstruction',
    'BOOK',
    'https://mowology.ca',
    'facebook',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: anticipation hook ──────────────────────────────────────────────
(
    'Sod Install — Instagram Main (someone''s yard about to change)',
    'proof_of_work',
    'landscaping',
    'Fresh turf delivery. Someone''s yard is about to change. 🌿

Sod installation that''s ready to walk on in days, not weeks.

Free quote → mowology.ca | Link in bio',
    '#SodInstallation #NewLawn #Landscaping #LawnInstallation #Vancouver #Mowology #VancouverLandscaping #FreshTurf #LawnCare #BCLiving #CurbAppeal #LandscapingVancouver #YardTransformation',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: minimal / poetic ───────────────────────────────────────────────
(
    'Sod Install — Instagram Alt A (before the green)',
    'proof_of_work',
    'landscaping',
    'Before the green. 🌿

New sod installation — same-week availability.

Free quote → mowology.ca | Link in bio',
    '#SodInstallation #NewLawn #Landscaping #Vancouver #Mowology #VancouverLandscaping #FreshTurf #LawnCare #BCLiving #CurbAppeal #LandscapingVancouver #YardTransformation',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: process / BTS ──────────────────────────────────────────────────
(
    'Sod Install — Instagram Alt B (before it''s a lawn)',
    'proof_of_work',
    'landscaping',
    'This is what a new lawn looks like before it''s a lawn. 🌱

Delivery in the morning. Green by the afternoon.

Free quote → mowology.ca | Link in bio',
    '#SodInstallation #NewLawn #Landscaping #Vancouver #Mowology #VancouverLandscaping #FreshTurf #LawnCare #BCLiving #BehindTheScenes #LandscapingVancouver #YardTransformation',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: scale + flex ───────────────────────────────────────────────────
(
    'Sod Install — Instagram Alt C (very happy client incoming)',
    'proof_of_work',
    'landscaping',
    'A lot of sod. One very happy client incoming. 🌿

Full lawn installations across Vancouver, Burnaby, and Richmond.

Free quote → mowology.ca | Link in bio',
    '#SodInstallation #NewLawn #Landscaping #Vancouver #Burnaby #Richmond #Mowology #VancouverLandscaping #FreshTurf #LawnCare #BCLiving #CurbAppeal #LandscapingVancouver',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
);

