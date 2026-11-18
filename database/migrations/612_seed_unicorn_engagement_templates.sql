-- ============================================================================
-- Migration 612: Seed Unicorn Engagement Templates
-- ============================================================================
-- Playful proof-of-work templates built around the inflatable unicorn photo.
-- High shareability — lighthearted content consistently outperforms straight
-- service posts on reach. Facebook version includes full contact block.
-- Instagram versions are scroll-stoppers with short punchy openers.
-- ============================================================================

INSERT INTO social_templates
    (name, category, service_type, caption_template, hashtag_preset,
     cta_preset, cta_url_preset, platform_targets, is_active, usage_count,
     created_at, updated_at)
VALUES

-- ── Facebook: long-form with contact block ────────────────────────────────────
(
    'Unicorn — Facebook Engagement',
    'proof_of_work',
    'lawn_care',
    'Even unicorns are picky about where they rest.

Can''t say we blame her. That lawn is immaculate.

If your backyard could use a little magic, we''ve got you. Mowology delivers the kind of cut that turns heads — and apparently, attracts mythical creatures.

📍 Serving Vancouver, Burnaby, Richmond + surrounding areas
💬 Free quote → mowology.ca
📞 (778) 846-9273',
    '#LawnCare #LawnCareVancouver #Vancouver #Burnaby #Richmond #Landscaping #Mowology #LawnGoals #GreenGrass #SummerLawn #BCLiving #CurbAppeal #WeeklyMowing #YardWork #OutdoorLiving #Unicorn',
    'BOOK',
    'https://mowology.ca',
    'facebook',
    1,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: main variant ───────────────────────────────────────────────────
(
    'Unicorn — Instagram Main (rare lawn)',
    'proof_of_work',
    'lawn_care',
    'Unicorns are rare. A lawn this good shouldn''t be. 🦄

We keep yards looking this good all season long.

Free quote → mowology.ca | Link in bio',
    '#LawnCare #LawnCareVancouver #Vancouver #Burnaby #Richmond #Landscaping #Mowology #LawnGoals #GreenGrass #SummerLawn #BCLiving #CurbAppeal #WeeklyMowing #YardWork #OutdoorLiving #Unicorn',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: Alt A — story-driven ──────────────────────────────────────────
(
    'Unicorn — Instagram Alt A (story-driven)',
    'proof_of_work',
    'lawn_care',
    'She traveled a long way to find a lawn worth resting on. 🦄

We understand.

Free quote → mowology.ca | Link in bio',
    '#LawnCare #LawnCareVancouver #Vancouver #Burnaby #Richmond #Landscaping #Mowology #LawnGoals #GreenGrass #SummerLawn #BCLiving #CurbAppeal #WeeklyMowing #YardWork #OutdoorLiving #Unicorn',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: Alt B — mythical angle ────────────────────────────────────────
(
    'Unicorn — Instagram Alt B (see it to believe it)',
    'proof_of_work',
    'lawn_care',
    'Your lawn should be so good, you have to see it to believe it. 🌿

We''ll make it happen.

Free quote → mowology.ca | Link in bio',
    '#LawnCare #LawnCareVancouver #Vancouver #Burnaby #Richmond #Landscaping #Mowology #LawnGoals #GreenGrass #SummerLawn #BCLiving #CurbAppeal #WeeklyMowing #YardWork #OutdoorLiving #Unicorn',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    0,
    NOW(),
    NOW()
),

-- ── Instagram: Alt C — short brand-building ───────────────────────────────────
(
    'Unicorn — Instagram Alt C (rare things)',
    'proof_of_work',
    'lawn_care',
    'Rare things deserve rare lawns. 🦄

Free quote → mowology.ca | Link in bio',
    '#LawnCare #LawnCareVancouver #Vancouver #Burnaby #Richmond #Landscaping #Mowology #LawnGoals #GreenGrass #SummerLawn #BCLiving #CurbAppeal #WeeklyMowing #YardWork #OutdoorLiving #Unicorn',
    'BOOK',
    'https://mowology.ca',
    'instagram',
    1,
    0,
    NOW(),
    NOW()
);

