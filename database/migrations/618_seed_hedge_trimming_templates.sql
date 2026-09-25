-- ============================================================================
-- Migration 618: Hedge Trimming Templates
-- ============================================================================
-- Crew in Mowology red shirt on ladder with EGO battery trimmer, large
-- residential hedge, blue sky. Strong branded proof-of-work.
-- Angles: crew action, humour/relatable, eco/battery, scale.
-- service_type = 'hedge_trimming'. Evergreen (active_months NULL).
-- ============================================================================

INSERT INTO social_templates
    (name, category, service_type, caption_template, hashtag_preset,
     cta_preset, cta_url_preset, platform_targets, is_active, active_months,
     usage_count, created_at, updated_at)
VALUES

-- ── Facebook: humour + practical ──────────────────────────────────────────────
(
    'Hedge — Facebook (outgrows you)',
    'proof_of_work',
    'hedge_trimming',
    'When your hedge outgrows you, that''s our cue. ✂️

Our crew handles hedge trimming of all sizes — residential, strata, commercial. Battery-powered equipment means no fumes in your garden, less noise in the neighbourhood, and a clean cut every time.

📍 Vancouver, Burnaby, Richmond + surrounding areas
💬 Free quote → mowology.ca
📞 (778) 846-9273',
    '#HedgeTrimming #HedgeTrim #Vancouver #Burnaby #Richmond #Mowology #VancouverLandscaping #LandscapingVancouver #GardenTrimming #ProfessionalLandscaping #BCLiving #CurbAppeal #HedgePruning #ShrubTrimming #GardenMaintenance',
    'BOOK',
    'https://mowology.ca',
    'facebook',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: punchy / crew pride ────────────────────────────────────────────
(
    'Hedge — Instagram Main (hedges don''t trim themselves)',
    'proof_of_work',
    'hedge_trimming',
    'Hedges don''t trim themselves. Good thing we do. ✂️

Professional hedge trimming across Vancouver, Burnaby, and Richmond.

Free quote → mowology.ca | Link in bio',
    '#HedgeTrimming #HedgeTrim #Vancouver #Burnaby #Richmond #Mowology #VancouverLandscaping #GardenTrimming #ProfessionalLandscaping #BCLiving #CurbAppeal #HedgePruning #ShrubTrimming #GardenMaintenance',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: branded / that's our crew ─────────────────────────────────────
(
    'Hedge — Instagram Alt A (that''s our crew)',
    'proof_of_work',
    'hedge_trimming',
    'That''s our crew. That''s your hedge. That''s the difference. 🌿

Free quote → mowology.ca | Link in bio',
    '#HedgeTrimming #HedgeTrim #Vancouver #Mowology #VancouverLandscaping #GardenTrimming #ProfessionalLandscaping #BCLiving #CurbAppeal #HedgePruning #CrewLife #GardenMaintenance',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: eco / battery angle ───────────────────────────────────────────
(
    'Hedge — Instagram Alt B (battery-powered)',
    'proof_of_work',
    'hedge_trimming',
    'Battery-powered. Zero emissions. Full results. ✂️

Professional hedge trimming that''s better for your neighbourhood.

Free quote → mowology.ca | Link in bio',
    '#HedgeTrimming #HedgeTrim #Vancouver #Mowology #VancouverLandscaping #GardenTrimming #EcoLandscaping #BatteryPowered #GreenLandscaping #BCLiving #CurbAppeal #HedgePruning #SustainableLandscaping',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: humour / relatable ─────────────────────────────────────────────
(
    'Hedge — Instagram Alt C (hedge called)',
    'proof_of_work',
    'hedge_trimming',
    'Your hedge called. It wants a haircut. ✂️

Free quote → mowology.ca | Link in bio',
    '#HedgeTrimming #HedgeTrim #Vancouver #Mowology #VancouverLandscaping #GardenTrimming #ProfessionalLandscaping #BCLiving #CurbAppeal #HedgePruning #ShrubTrimming #GardenHumour',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: crew action / ladder ───────────────────────────────────────────
(
    'Hedge — Instagram Alt D (up on the ladder)',
    'proof_of_work',
    'hedge_trimming',
    'Up on the ladder. Getting it done. ✂️

This is what professional hedge trimming looks like.

Free quote → mowology.ca | Link in bio',
    '#HedgeTrimming #HedgeTrim #Vancouver #Mowology #VancouverLandscaping #GardenTrimming #ProfessionalLandscaping #BCLiving #CurbAppeal #HedgePruning #CrewLife #BehindTheScenes',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
);
