-- ============================================================================
-- Migration 617: Strata / Commercial Landscaping Templates
-- ============================================================================
-- Aerial shot of strata garden maintenance — curved beds, precision-trimmed
-- shrubs, crew on-site. Different audience from residential: strata councils
-- and property managers. B2B tone while staying social-media native.
-- service_type = 'garden_maintenance'. Evergreen — strata contracts are
-- year-round. platform_targets covers both channels.
-- ============================================================================

INSERT INTO social_templates
    (name, category, service_type, caption_template, hashtag_preset,
     cta_preset, cta_url_preset, platform_targets, is_active, active_months,
     usage_count, created_at, updated_at)
VALUES

-- ── Facebook: direct B2B address ──────────────────────────────────────────────
(
    'Strata — Facebook B2B (council address)',
    'proof_of_work',
    'garden_maintenance',
    'Strata council looking for reliable grounds maintenance?

This is what consistent, professional landscaping looks like at a commercial scale — immaculate gardens, crew on-site, no chasing required.

We take on strata and commercial contracts across Vancouver, Burnaby, and Richmond. Recurring service, competitive rates, and a crew that shows up.

📍 Vancouver, Burnaby, Richmond + surrounding areas
💬 Commercial inquiries → mowology.ca
📞 (778) 846-9273',
    '#StrataLandscaping #CommercialLandscaping #Vancouver #Burnaby #Richmond #Mowology #VancouverLandscaping #LandscapingVancouver #StrataManagement #PropertyManagement #CommercialGrounds #GardenMaintenance #BCLiving #ProfessionalLandscaping #StrataMaintenance',
    'BOOK',
    'https://mowology.ca',
    'facebook',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: precision from above ──────────────────────────────────────────
(
    'Strata — Instagram Main (different scale same standard)',
    'proof_of_work',
    'garden_maintenance',
    'Strata maintenance. A different scale, the same standard. 🌿

Commercial & strata landscaping across Vancouver.

Commercial inquiries → mowology.ca | Link in bio',
    '#StrataLandscaping #CommercialLandscaping #Vancouver #Mowology #VancouverLandscaping #StrataManagement #PropertyManagement #GardenMaintenance #BCLiving #ProfessionalLandscaping #StrataMaintenance #LandscapingVancouver',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: aerial angle ───────────────────────────────────────────────────
(
    'Strata — Instagram Alt A (bird''s eye view)',
    'proof_of_work',
    'garden_maintenance',
    'A bird''s eye view of what we do. 🌿

Every shrub. Every edge. Every visit.

Commercial & strata inquiries → mowology.ca | Link in bio',
    '#StrataLandscaping #CommercialLandscaping #Vancouver #Mowology #VancouverLandscaping #StrataManagement #PropertyManagement #GardenMaintenance #BCLiving #ProfessionalLandscaping #BehindTheScenes #LandscapingVancouver',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: direct strata council address ──────────────────────────────────
(
    'Strata — Instagram Alt B (strata boards this one''s for you)',
    'proof_of_work',
    'garden_maintenance',
    'Strata boards, this one''s for you. 🌿

Reliable grounds maintenance. No chasing. No no-shows.

Commercial inquiries → mowology.ca | Link in bio',
    '#StrataLandscaping #CommercialLandscaping #Vancouver #Burnaby #Richmond #Mowology #VancouverLandscaping #StrataManagement #PropertyManagement #GardenMaintenance #BCLiving #ProfessionalLandscaping #StrataMaintenance',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
),

-- ── Facebook + Instagram: crew pride / proof of work ─────────────────────────
(
    'Strata — Crew on Site (proof of work)',
    'proof_of_work',
    'garden_maintenance',
    'Crew on-site. Work getting done. 🌿

Strata and commercial grounds maintenance across Vancouver, Burnaby, and Richmond.

Consistent service. Competitive rates. A crew that shows up.

Commercial inquiries → mowology.ca',
    '#StrataLandscaping #CommercialLandscaping #Vancouver #Burnaby #Richmond #Mowology #VancouverLandscaping #StrataManagement #GardenMaintenance #ProfessionalLandscaping #StrataMaintenance #LandscapingVancouver #CrewLife #BCLiving',
    'BOOK',
    'https://mowology.ca',
    'facebook,instagram',
    1,
    NULL,
    0,
    NOW(),
    NOW()
);

