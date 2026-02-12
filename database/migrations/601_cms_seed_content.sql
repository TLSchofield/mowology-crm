-- Migration 601: CMS Seed Content — Pages, Blocks, Content Snippets
-- Created: 2026-02-12
-- Purpose: Populate initial CMS pages with conversion-focused copy and reusable snippets
-- Depends on: 500_cms_core.sql, 502_cms_template_library.sql, 600_cms_tokens_snippets.sql

-- ============================================================================
-- PAGE 1: HOMEPAGE
-- ============================================================================

INSERT INTO `cms_pages`
(`slug`, `title`, `meta_title`, `meta_description`, `page_type`, `layout_template`, `status`, `noindex`, `created_by`, `created_at`, `updated_at`)
VALUES
('home', 'Home', '{{BRAND}} — Professional {{SERVICE}} in {{AREA}}', 'Trusted {{SERVICE}} services in {{AREA}}. {{TRUST_STATEMENT}}. Call {{PHONE}} for your free estimate.', 'landing', 'homepage', 'published', 0, 1, NOW(), NOW());

SET @homepage_id = LAST_INSERT_ID();

-- Homepage blocks
INSERT INTO `cms_blocks` (`page_id`, `block_type`, `position`, `config_json`, `created_at`, `updated_at`)
VALUES
-- Hero
(@homepage_id, 'hero', 0, '{
  "headline": "{{AREA}}''s Trusted {{SERVICE}} Team",
  "subheadline": "{{TRUST_STATEMENT}}. Residential and strata properties across {{CITY}} and surrounding areas.",
  "cta_text": "{{CTA_TEXT}}",
  "cta_url": "{{CTA_URL}}",
  "media_id": null,
  "media_alt": "Professional landscaping in {{CITY}}"
}', NOW(), NOW()),

-- Service Cards
(@homepage_id, 'service_cards', 1, '{
  "title": "Our {{SERVICE}} Services",
  "description": "Comprehensive property care for homes and strata complexes in {{AREA}}.",
  "columns": 3,
  "services": [
    {
      "icon": "scissors",
      "title": "Residential Lawn Care",
      "description": "Weekly mowing, edging, and seasonal maintenance that keeps your property sharp year-round.",
      "url": "/services/residential-lawn-care-{{SERVICE_SLUG}}"
    },
    {
      "icon": "home",
      "title": "Strata Landscaping",
      "description": "Reliable grounds maintenance for multi-unit properties with accountable reporting.",
      "url": "/services/strata-landscaping-maintenance"
    },
    {
      "icon": "sun",
      "title": "Seasonal Cleanups",
      "description": "Spring preparation and fall cleanup to protect your landscape investment through every season.",
      "url": "/services/seasonal-cleanup"
    },
    {
      "icon": "droplet",
      "title": "Irrigation & Drainage",
      "description": "Efficient watering systems and drainage solutions designed for {{CITY}}''s coastal climate.",
      "url": "/services/irrigation"
    },
    {
      "icon": "grid",
      "title": "Hardscaping",
      "description": "Patios, walkways, and retaining walls built to last and designed to complement your landscape.",
      "url": "/services/hardscaping"
    },
    {
      "icon": "star",
      "title": "Garden Design",
      "description": "Custom planting plans using native and adaptive species suited to {{PROVINCE}}''s growing zones.",
      "url": "/services/garden-design"
    }
  ]
}', NOW(), NOW()),

-- Feature Grid — Why Choose Us
(@homepage_id, 'feature_grid', 2, '{
  "title": "Why {{CITY}} Properties Choose {{BRAND}}",
  "description": "",
  "layout": 3,
  "features": [
    {
      "icon": "camera",
      "title": "Photo-Verified Visits",
      "description": "Every service visit is documented with before-and-after photos so you always know exactly what was done."
    },
    {
      "icon": "calendar",
      "title": "Reliable Scheduling",
      "description": "Consistent weekly and bi-weekly service with advance scheduling. No guessing when we''ll arrive."
    },
    {
      "icon": "shield",
      "title": "Fully Insured",
      "description": "Licensed and insured for your peace of mind. WCB coverage on every crew member."
    },
    {
      "icon": "message-square",
      "title": "Clear Communication",
      "description": "Direct line to your account manager. We respond within hours, not days."
    },
    {
      "icon": "dollar-sign",
      "title": "Transparent Pricing",
      "description": "Detailed quotes with no hidden fees. You approve the scope before any work begins."
    },
    {
      "icon": "map-pin",
      "title": "Locally Owned",
      "description": "Based right here in {{CITY}}. We know the local growing conditions, soil types, and municipal regulations."
    }
  ]
}', NOW(), NOW()),

-- Testimonials
(@homepage_id, 'testimonials', 3, '{
  "title": "What {{CITY}} Homeowners Are Saying",
  "layout": "grid",
  "testimonials": [
    {
      "name": "Jennifer M.",
      "role": "Homeowner, Kitsilano",
      "content": "Finally found a landscaping company that actually shows up when they say they will. The photo updates after each visit give me total peace of mind.",
      "image_id": null
    },
    {
      "name": "David R.",
      "role": "Strata Council President, North Vancouver",
      "content": "We switched to {{BRAND}} last year and the difference is night and day. Professional, accountable, and our common areas have never looked better.",
      "image_id": null
    },
    {
      "name": "Karen T.",
      "role": "Homeowner, East Vancouver",
      "content": "Transparent pricing, great communication, and our lawn has never been healthier. Worth every penny.",
      "image_id": null
    }
  ]
}', NOW(), NOW()),

-- CTA
(@homepage_id, 'cta', 4, '{
  "headline": "Ready to Upgrade Your Property?",
  "subheadline": "Join hundreds of {{CITY}} property owners who trust {{BRAND}} for professional {{SERVICE}} services.",
  "primary_text": "{{CTA_TEXT}}",
  "primary_url": "{{CTA_URL}}",
  "secondary_text": "Call {{PHONE}}",
  "secondary_url": "tel:{{PHONE_TEL}}",
  "style": "gradient"
}', NOW(), NOW());


-- ============================================================================
-- PAGE 2: RESIDENTIAL LAWN CARE — KITSILANO
-- ============================================================================

INSERT INTO `cms_pages`
(`slug`, `title`, `meta_title`, `meta_description`, `page_type`, `layout_template`, `status`, `noindex`, `created_by`, `created_at`, `updated_at`)
VALUES
('services/residential-lawn-care-kitsilano', 'Residential Lawn Care Kitsilano', 'Residential Lawn Care {{NEIGHBOURHOOD}} | {{BRAND}}', 'Professional residential lawn care in {{NEIGHBOURHOOD}}, {{CITY}}. Weekly mowing, edging, seasonal maintenance. {{TRUST_STATEMENT}}. Call {{PHONE}}.', 'service', 'service_landing', 'published', 0, 1, NOW(), NOW());

SET @rlc_kits_id = LAST_INSERT_ID();

-- Page-level token overrides
INSERT INTO `page_tokens` (`page_id`, `token`, `value`, `created_at`, `updated_at`)
VALUES
(@rlc_kits_id, 'NEIGHBOURHOOD', 'Kitsilano', NOW(), NOW()),
(@rlc_kits_id, 'SERVICE', 'Lawn Care', NOW(), NOW()),
(@rlc_kits_id, 'SERVICE_SLUG', 'lawn-care', NOW(), NOW()),
(@rlc_kits_id, 'PRIMARY_KEYWORD', 'residential lawn care', NOW(), NOW()),
(@rlc_kits_id, 'SECONDARY_KEYWORD', 'lawn mowing service', NOW(), NOW()),
(@rlc_kits_id, 'CTA_URL', '/quote?service=lawn_care&area=kitsilano&src=rlc-kits-landing', NOW(), NOW());

-- Residential Lawn Care Kits blocks
INSERT INTO `cms_blocks` (`page_id`, `block_type`, `position`, `config_json`, `created_at`, `updated_at`)
VALUES
-- Hero
(@rlc_kits_id, 'hero', 0, '{
  "headline": "{{NEIGHBOURHOOD}}''s Most Reliable {{SERVICE}}",
  "subheadline": "Consistent, professional lawn maintenance for {{NEIGHBOURHOOD}} homes. {{TRUST_STATEMENT}}.",
  "cta_text": "Get Your Free Lawn Care Quote",
  "cta_url": "{{CTA_URL}}",
  "media_id": null,
  "media_alt": "Well-maintained residential lawn in {{NEIGHBOURHOOD}}, {{CITY}}"
}', NOW(), NOW()),

-- Feature Grid — What's Included
(@rlc_kits_id, 'feature_grid', 1, '{
  "title": "What''s Included in Every Visit",
  "description": "Our standard residential {{SERVICE}} package covers everything your lawn needs to stay healthy and sharp.",
  "layout": 3,
  "features": [
    {
      "icon": "scissors",
      "title": "Precision Mowing",
      "description": "Height-adjusted cuts matched to grass type and season. Never scalped, always clean lines."
    },
    {
      "icon": "maximize-2",
      "title": "Edging & Trimming",
      "description": "Crisp edges along walkways, driveways, and garden beds every single visit."
    },
    {
      "icon": "wind",
      "title": "Debris Cleanup",
      "description": "All clippings blown off hard surfaces. We leave your property cleaner than we found it."
    },
    {
      "icon": "camera",
      "title": "Photo Documentation",
      "description": "Before-and-after photos sent to your account after every service visit."
    },
    {
      "icon": "alert-circle",
      "title": "Issue Spotting",
      "description": "We flag early signs of pests, disease, or irrigation problems before they become expensive."
    },
    {
      "icon": "calendar",
      "title": "Flexible Scheduling",
      "description": "Weekly or bi-weekly plans. Pause anytime for holidays. No long-term contracts."
    }
  ]
}', NOW(), NOW()),

-- Testimonials
(@rlc_kits_id, 'testimonials', 2, '{
  "title": "What {{NEIGHBOURHOOD}} Homeowners Say",
  "layout": "grid",
  "testimonials": [
    {
      "name": "Sarah L.",
      "role": "Homeowner, {{NEIGHBOURHOOD}}",
      "content": "Switched from our old landscaper and the difference was immediate. Consistent schedule, great results, and the photo updates are a nice touch.",
      "image_id": null
    },
    {
      "name": "Andrew P.",
      "role": "Homeowner, {{NEIGHBOURHOOD}}",
      "content": "Our neighbours keep asking who does our lawn. Reliable, professional, and fair pricing. Exactly what we were looking for.",
      "image_id": null
    }
  ]
}', NOW(), NOW()),

-- FAQ
(@rlc_kits_id, 'faq', 3, '{
  "title": "Common Questions About {{SERVICE}} in {{NEIGHBOURHOOD}}",
  "description": "",
  "faqs": [
    {
      "question": "How often should my lawn be mowed in {{CITY}}?",
      "answer": "During the growing season (April through October), weekly mowing produces the best results. We offer bi-weekly plans for lower-maintenance properties. In winter months, we shift to monthly checks and seasonal cleanup."
    },
    {
      "question": "What does a typical lawn care visit include?",
      "answer": "Every visit includes mowing at the correct height for your grass type, string trimming around obstacles, edging along hard surfaces, debris blowdown, and before/after photo documentation."
    },
    {
      "question": "Do you use eco-friendly practices?",
      "answer": "Yes. We use battery-powered equipment where possible, practice grasscycling (returning clippings as natural fertilizer), and recommend organic treatment options. We follow {{CITY}}''s municipal pesticide regulations."
    },
    {
      "question": "Can I pause or cancel my service?",
      "answer": "Absolutely. We don''t require long-term contracts. Pause for vacation, skip a visit, or cancel anytime with a simple message to your account manager."
    },
    {
      "question": "What areas do you serve?",
      "answer": "We serve {{NEIGHBOURHOOD}} and the broader {{AREA}} region including Kitsilano, Point Grey, Dunbar, Kerrisdale, and surrounding {{CITY}} neighbourhoods."
    },
    {
      "question": "How do I get a quote?",
      "answer": "Request a free quote online or call us at {{PHONE}}. We''ll ask about your property size, current lawn condition, and service frequency preference. Most quotes are returned within 24 hours."
    }
  ]
}', NOW(), NOW()),

-- CTA
(@rlc_kits_id, 'cta', 4, '{
  "headline": "Get a Free {{SERVICE}} Quote for {{NEIGHBOURHOOD}}",
  "subheadline": "No contracts. No hidden fees. Just a well-maintained lawn, every visit.",
  "primary_text": "Request Your Free Quote",
  "primary_url": "{{CTA_URL}}",
  "secondary_text": "Call {{PHONE}}",
  "secondary_url": "tel:{{PHONE_TEL}}",
  "style": "gradient"
}', NOW(), NOW());


-- ============================================================================
-- PAGE 3: STRATA LANDSCAPING MAINTENANCE
-- ============================================================================

INSERT INTO `cms_pages`
(`slug`, `title`, `meta_title`, `meta_description`, `page_type`, `layout_template`, `status`, `noindex`, `created_by`, `created_at`, `updated_at`)
VALUES
('services/strata-landscaping-maintenance', 'Strata Landscaping Maintenance', 'Strata Landscaping Maintenance {{CITY}} | {{BRAND}}', 'Professional strata landscape maintenance in {{AREA}}. Accountable reporting, photo-verified visits, transparent pricing. Call {{PHONE}}.', 'service', 'service_landing', 'published', 0, 1, NOW(), NOW());

SET @strata_id = LAST_INSERT_ID();

-- Page-level token overrides
INSERT INTO `page_tokens` (`page_id`, `token`, `value`, `created_at`, `updated_at`)
VALUES
(@strata_id, 'SERVICE', 'Strata Landscaping', NOW(), NOW()),
(@strata_id, 'SERVICE_SLUG', 'strata-landscaping', NOW(), NOW()),
(@strata_id, 'PRIMARY_KEYWORD', 'strata landscaping maintenance', NOW(), NOW()),
(@strata_id, 'SECONDARY_KEYWORD', 'strata grounds maintenance', NOW(), NOW()),
(@strata_id, 'CTA_URL', '/quote?service=strata_landscaping&src=strata-landing', NOW(), NOW());

-- Strata Landscaping blocks
INSERT INTO `cms_blocks` (`page_id`, `block_type`, `position`, `config_json`, `created_at`, `updated_at`)
VALUES
-- Hero
(@strata_id, 'hero', 0, '{
  "headline": "Strata Grounds Maintenance {{CITY}} Councils Trust",
  "subheadline": "Accountable landscaping for multi-unit properties. {{TRUST_STATEMENT}}. Transparent reporting your council can rely on.",
  "cta_text": "Get Your Strata Quote",
  "cta_url": "{{CTA_URL}}",
  "media_id": null,
  "media_alt": "Well-maintained strata property grounds in {{CITY}}"
}', NOW(), NOW()),

-- Feature Grid — Strata-Specific Benefits
(@strata_id, 'feature_grid', 1, '{
  "title": "Built for Strata Accountability",
  "description": "We understand the reporting and reliability requirements that strata councils demand from their landscaping contractor.",
  "layout": 3,
  "features": [
    {
      "icon": "camera",
      "title": "Photo-Verified Visits",
      "description": "Timestamped before-and-after photos for every service visit. Proof you can share at council meetings."
    },
    {
      "icon": "file-text",
      "title": "Monthly Reporting",
      "description": "Detailed reports covering work completed, upcoming seasonal tasks, and property condition notes."
    },
    {
      "icon": "dollar-sign",
      "title": "Budget-Friendly Contracts",
      "description": "Annual contracts with fixed monthly pricing. No surprise invoices. Easy to budget in your strata plan."
    },
    {
      "icon": "shield",
      "title": "Fully Insured & WCB",
      "description": "Comprehensive commercial liability insurance and full WCB coverage on every crew member."
    },
    {
      "icon": "users",
      "title": "Dedicated Account Manager",
      "description": "One point of contact who knows your property. Direct communication with council or property management."
    },
    {
      "icon": "clock",
      "title": "Reliable & Consistent",
      "description": "Same crew, same schedule, same standard. We show up when we say we will, every time."
    }
  ]
}', NOW(), NOW()),

-- Testimonials
(@strata_id, 'testimonials', 2, '{
  "title": "Trusted by {{CITY}} Strata Councils",
  "layout": "grid",
  "testimonials": [
    {
      "name": "David R.",
      "role": "Strata Council President, North Vancouver",
      "content": "The monthly reporting alone made the switch worth it. Our previous contractor never provided documentation. {{BRAND}} sends photos and reports without us having to ask.",
      "image_id": null
    },
    {
      "name": "Priya S.",
      "role": "Property Manager, Burnaby",
      "content": "Managing multiple strata properties means I need contractors I can count on. {{BRAND}} is consistent, communicative, and their pricing has been exactly as quoted.",
      "image_id": null
    },
    {
      "name": "Michael W.",
      "role": "Strata Council Treasurer, {{CITY}}",
      "content": "Fixed monthly pricing makes budgeting straightforward. The quality has been excellent and owners have noticed the improvement in our common areas.",
      "image_id": null
    }
  ]
}', NOW(), NOW()),

-- FAQ
(@strata_id, 'faq', 3, '{
  "title": "Strata Landscaping Questions",
  "description": "",
  "faqs": [
    {
      "question": "What''s included in a strata landscaping contract?",
      "answer": "Our standard strata package covers lawn mowing, edging, hedge trimming, garden bed maintenance, leaf removal, seasonal cleanups, and irrigation system monitoring. We customize the scope to your property''s specific needs."
    },
    {
      "question": "How does your reporting work?",
      "answer": "After every visit, we upload timestamped photos to your account. Monthly, you receive a summary report covering all work completed, any issues flagged, and upcoming seasonal recommendations. Reports can be shared directly at council meetings."
    },
    {
      "question": "Can you work with our property management company?",
      "answer": "Absolutely. We work with several property management firms across {{AREA}}. We can report directly to your property manager, attend AGMs if needed, and coordinate with other contractors on your property."
    },
    {
      "question": "What''s the contract term?",
      "answer": "We offer 12-month contracts with fixed monthly pricing for budget predictability. Month-to-month arrangements are available at a slightly higher rate. We''re confident enough in our work that we don''t need to lock you in."
    },
    {
      "question": "How do you handle resident complaints or special requests?",
      "answer": "Your dedicated account manager handles all communication. Residents can be directed to contact us through your council or property manager. We respond to requests within 24 hours and document all additional work performed."
    },
    {
      "question": "Do you carry commercial insurance?",
      "answer": "Yes. We carry comprehensive commercial general liability insurance and full WorkSafeBC (WCB) coverage. We can provide certificates of insurance to your strata corporation or property management company on request."
    }
  ]
}', NOW(), NOW()),

-- CTA
(@strata_id, 'cta', 4, '{
  "headline": "Get a Custom Strata Landscaping Proposal",
  "subheadline": "We''ll walk your property, assess the scope, and deliver a detailed proposal with fixed monthly pricing.",
  "primary_text": "Request a Property Assessment",
  "primary_url": "{{CTA_URL}}",
  "secondary_text": "Call {{PHONE}}",
  "secondary_url": "tel:{{PHONE_TEL}}",
  "style": "dark"
}', NOW(), NOW());


-- ============================================================================
-- CONTENT SNIPPETS — Residential Lawn Care
-- ============================================================================

INSERT INTO `content_snippets`
(`snippet_key`, `variant`, `block_type`, `content_json`, `notes`, `is_active`, `created_at`, `updated_at`)
VALUES
-- Hero variants
('hero.residential_lawn_care.v1', 'residential', 'hero', '{
  "headline": "{{NEIGHBOURHOOD}}''s Most Reliable {{SERVICE}}",
  "subheadline": "Consistent, professional lawn maintenance for {{NEIGHBOURHOOD}} homes. {{TRUST_STATEMENT}}.",
  "cta_text": "Get Your Free Lawn Care Quote",
  "cta_url": "{{CTA_URL}}",
  "media_id": null,
  "media_alt": "Well-maintained residential lawn in {{NEIGHBOURHOOD}}, {{CITY}}"
}', 'Direct and confident. Leads with reliability as differentiator.', 1, NOW(), NOW()),

('hero.residential_lawn_care.v2', 'residential', 'hero', '{
  "headline": "Professional {{SERVICE}} Your {{NEIGHBOURHOOD}} Home Deserves",
  "subheadline": "Spend your weekends relaxing, not mowing. Trusted by homeowners across {{AREA}} for consistent, quality lawn care.",
  "cta_text": "See Our Lawn Care Plans",
  "cta_url": "{{CTA_URL}}",
  "media_id": null,
  "media_alt": "Beautiful green lawn at a {{NEIGHBOURHOOD}} home"
}', 'Benefit-led. Appeals to time savings and lifestyle.', 1, NOW(), NOW()),

-- Feature Grid variants
('feature_grid.residential_lawn_care.v1', 'residential', 'feature_grid', '{
  "title": "What''s Included in Every Visit",
  "description": "Our standard residential {{SERVICE}} package covers everything your lawn needs to stay healthy and sharp.",
  "layout": 3,
  "features": [
    {"icon": "scissors", "title": "Precision Mowing", "description": "Height-adjusted cuts matched to grass type and season. Never scalped, always clean lines."},
    {"icon": "maximize-2", "title": "Edging & Trimming", "description": "Crisp edges along walkways, driveways, and garden beds every single visit."},
    {"icon": "wind", "title": "Debris Cleanup", "description": "All clippings blown off hard surfaces. We leave your property cleaner than we found it."},
    {"icon": "camera", "title": "Photo Documentation", "description": "Before-and-after photos sent to your account after every service visit."},
    {"icon": "alert-circle", "title": "Issue Spotting", "description": "We flag early signs of pests, disease, or irrigation problems before they become expensive."},
    {"icon": "calendar", "title": "Flexible Scheduling", "description": "Weekly or bi-weekly plans. Pause anytime for holidays. No long-term contracts."}
  ]
}', 'Service-focused. Emphasizes what the customer gets.', 1, NOW(), NOW()),

('feature_grid.residential_lawn_care.v2', 'residential', 'feature_grid', '{
  "title": "Why {{NEIGHBOURHOOD}} Homeowners Choose {{BRAND}}",
  "description": "",
  "layout": 3,
  "features": [
    {"icon": "check-circle", "title": "No Contracts", "description": "Stay because you want to, not because you have to. Cancel or pause anytime."},
    {"icon": "camera", "title": "Photo Proof Every Visit", "description": "See exactly what was done. No more wondering if anyone actually showed up."},
    {"icon": "map-pin", "title": "Local {{CITY}} Team", "description": "We know your neighbourhood, your soil, and your growing conditions."},
    {"icon": "dollar-sign", "title": "Honest Pricing", "description": "Flat-rate plans with no hidden fees. The price we quote is the price you pay."},
    {"icon": "thumbs-up", "title": "Satisfaction Guarantee", "description": "Not happy with a visit? We''ll come back and make it right at no charge."},
    {"icon": "clock", "title": "Same Day, Every Week", "description": "Consistent scheduling so you always know when to expect us."}
  ]
}', 'Trust-led. Emphasizes low risk and reliability.', 1, NOW(), NOW()),

-- FAQ variants
('faq.residential_lawn_care.v1', 'residential', 'faq', '{
  "title": "Common Questions About {{SERVICE}} in {{NEIGHBOURHOOD}}",
  "description": "",
  "faqs": [
    {"question": "How often should my lawn be mowed in {{CITY}}?", "answer": "During the growing season (April through October), weekly mowing produces the best results. We offer bi-weekly plans for lower-maintenance properties. In winter months, we shift to monthly checks and seasonal cleanup."},
    {"question": "What does a typical lawn care visit include?", "answer": "Every visit includes mowing at the correct height for your grass type, string trimming around obstacles, edging along hard surfaces, debris blowdown, and before/after photo documentation."},
    {"question": "Do you use eco-friendly practices?", "answer": "Yes. We use battery-powered equipment where possible, practice grasscycling (returning clippings as natural fertilizer), and recommend organic treatment options. We follow {{CITY}}''s municipal pesticide regulations."},
    {"question": "Can I pause or cancel my service?", "answer": "Absolutely. We don''t require long-term contracts. Pause for vacation, skip a visit, or cancel anytime with a simple message to your account manager."},
    {"question": "What areas do you serve?", "answer": "We serve {{NEIGHBOURHOOD}} and the broader {{AREA}} region including Kitsilano, Point Grey, Dunbar, Kerrisdale, and surrounding {{CITY}} neighbourhoods."},
    {"question": "How do I get a quote?", "answer": "Request a free quote online or call us at {{PHONE}}. We''ll ask about your property size, current lawn condition, and service frequency preference. Most quotes are returned within 24 hours."}
  ]
}', 'Standard FAQ set covering scheduling, services, eco, and logistics.', 1, NOW(), NOW()),

('faq.residential_lawn_care.v2', 'residential', 'faq', '{
  "title": "Your {{SERVICE}} Questions, Answered",
  "description": "Here are the questions we hear most from {{NEIGHBOURHOOD}} homeowners.",
  "faqs": [
    {"question": "Is weekly mowing really necessary?", "answer": "In {{CITY}}''s climate, weekly mowing during the growing season keeps your lawn thick and healthy. Cutting more than one-third of the blade height stresses the grass and encourages weeds. Bi-weekly plans are available for lower-traffic lawns."},
    {"question": "What happens if it rains on my service day?", "answer": "Light rain doesn''t stop us — wet mowing can actually be less stressful on grass. For heavy rain or storms, we''ll reschedule to the next available day and notify you in advance."},
    {"question": "Do I need to be home during service?", "answer": "Not at all. Most of our clients aren''t home during visits. We just need gate access if your yard is fenced. You''ll receive photo confirmation when the visit is complete."},
    {"question": "How does your pricing work?", "answer": "We charge a flat rate per visit based on your property size and service frequency. No hourly billing, no surprise charges. You''ll receive a detailed quote before any work begins."},
    {"question": "What if I''m not satisfied with a visit?", "answer": "Let us know within 24 hours and we''ll return to address any concerns at no additional charge. Your satisfaction is how we earn your continued business."}
  ]
}', 'Objection-handling FAQ. Addresses common hesitations.', 1, NOW(), NOW()),

-- CTA variants
('cta.residential_lawn_care.v1', 'residential', 'cta', '{
  "headline": "Get a Free {{SERVICE}} Quote for {{NEIGHBOURHOOD}}",
  "subheadline": "No contracts. No hidden fees. Just a well-maintained lawn, every visit.",
  "primary_text": "Request Your Free Quote",
  "primary_url": "{{CTA_URL}}",
  "secondary_text": "Call {{PHONE}}",
  "secondary_url": "tel:{{PHONE_TEL}}",
  "style": "gradient"
}', 'Direct CTA. Emphasizes free + no commitment.', 1, NOW(), NOW()),

('cta.residential_lawn_care.v2', 'residential', 'cta', '{
  "headline": "Your Lawn, Our Responsibility",
  "subheadline": "Join your {{NEIGHBOURHOOD}} neighbours who already trust {{BRAND}} for reliable, professional lawn care.",
  "primary_text": "Start With a Free Quote",
  "primary_url": "{{CTA_URL}}",
  "secondary_text": "Questions? Call {{PHONE}}",
  "secondary_url": "tel:{{PHONE_TEL}}",
  "style": "gradient"
}', 'Social proof CTA. Leverages neighbour trust.', 1, NOW(), NOW()),

-- Testimonials
('testimonials.residential_lawn_care.v1', 'residential', 'testimonials', '{
  "title": "What {{NEIGHBOURHOOD}} Homeowners Say",
  "layout": "grid",
  "testimonials": [
    {"name": "Sarah L.", "role": "Homeowner, {{NEIGHBOURHOOD}}", "content": "Switched from our old landscaper and the difference was immediate. Consistent schedule, great results, and the photo updates are a nice touch.", "image_id": null},
    {"name": "Andrew P.", "role": "Homeowner, {{NEIGHBOURHOOD}}", "content": "Our neighbours keep asking who does our lawn. Reliable, professional, and fair pricing. Exactly what we were looking for.", "image_id": null}
  ]
}', 'Residential testimonials emphasizing reliability and results.', 1, NOW(), NOW()),


-- ============================================================================
-- CONTENT SNIPPETS — Strata Landscaping
-- ============================================================================

-- Hero variants
('hero.strata_landscaping.v1', 'strata', 'hero', '{
  "headline": "Strata Grounds Maintenance {{CITY}} Councils Trust",
  "subheadline": "Accountable landscaping for multi-unit properties. {{TRUST_STATEMENT}}. Transparent reporting your council can rely on.",
  "cta_text": "Get Your Strata Quote",
  "cta_url": "{{CTA_URL}}",
  "media_id": null,
  "media_alt": "Well-maintained strata property grounds in {{CITY}}"
}', 'Authority-led. Positions as trusted by councils.', 1, NOW(), NOW()),

('hero.strata_landscaping.v2', 'strata', 'hero', '{
  "headline": "Landscaping Your Strata Corporation Can Count On",
  "subheadline": "Consistent grounds maintenance with the reporting and accountability strata councils need. Serving {{AREA}} multi-unit properties.",
  "cta_text": "Request a Property Assessment",
  "cta_url": "{{CTA_URL}}",
  "media_id": null,
  "media_alt": "Professional strata landscape maintenance in {{AREA}}"
}', 'Reliability-led. Emphasizes consistency and reporting.', 1, NOW(), NOW()),

-- Feature Grid variants
('feature_grid.strata_landscaping.v1', 'strata', 'feature_grid', '{
  "title": "Built for Strata Accountability",
  "description": "We understand the reporting and reliability requirements that strata councils demand from their landscaping contractor.",
  "layout": 3,
  "features": [
    {"icon": "camera", "title": "Photo-Verified Visits", "description": "Timestamped before-and-after photos for every service visit. Proof you can share at council meetings."},
    {"icon": "file-text", "title": "Monthly Reporting", "description": "Detailed reports covering work completed, upcoming seasonal tasks, and property condition notes."},
    {"icon": "dollar-sign", "title": "Budget-Friendly Contracts", "description": "Annual contracts with fixed monthly pricing. No surprise invoices. Easy to budget in your strata plan."},
    {"icon": "shield", "title": "Fully Insured & WCB", "description": "Comprehensive commercial liability insurance and full WCB coverage on every crew member."},
    {"icon": "users", "title": "Dedicated Account Manager", "description": "One point of contact who knows your property. Direct communication with council or property management."},
    {"icon": "clock", "title": "Reliable & Consistent", "description": "Same crew, same schedule, same standard. We show up when we say we will, every time."}
  ]
}', 'Accountability-focused. Addresses strata council pain points.', 1, NOW(), NOW()),

('feature_grid.strata_landscaping.v2', 'strata', 'feature_grid', '{
  "title": "Why Strata Councils Switch to {{BRAND}}",
  "description": "",
  "layout": 3,
  "features": [
    {"icon": "trending-up", "title": "Property Value Protection", "description": "Well-maintained grounds directly impact unit values. We treat your common areas like our own property."},
    {"icon": "clipboard", "title": "AGM-Ready Documentation", "description": "Professional reports your council can present to owners. Photo evidence of every dollar spent."},
    {"icon": "refresh-cw", "title": "Seasonal Planning", "description": "Proactive seasonal schedules planned months ahead. No reactive scrambling when seasons change."},
    {"icon": "message-square", "title": "Responsive Communication", "description": "Your account manager responds within hours. We don''t disappear between service visits."},
    {"icon": "award", "title": "Commercial Experience", "description": "Experienced with multi-unit complexes, townhome developments, and mixed-use strata properties."},
    {"icon": "lock", "title": "Site Safety Compliance", "description": "All crews follow site safety protocols, carry ID, and respect resident privacy and quiet hours."}
  ]
}', 'Value-led. Emphasizes ROI and professional standards.', 1, NOW(), NOW()),

-- FAQ variants
('faq.strata_landscaping.v1', 'strata', 'faq', '{
  "title": "Strata Landscaping Questions",
  "description": "",
  "faqs": [
    {"question": "What''s included in a strata landscaping contract?", "answer": "Our standard strata package covers lawn mowing, edging, hedge trimming, garden bed maintenance, leaf removal, seasonal cleanups, and irrigation system monitoring. We customize the scope to your property''s specific needs."},
    {"question": "How does your reporting work?", "answer": "After every visit, we upload timestamped photos to your account. Monthly, you receive a summary report covering all work completed, any issues flagged, and upcoming seasonal recommendations. Reports can be shared directly at council meetings."},
    {"question": "Can you work with our property management company?", "answer": "Absolutely. We work with several property management firms across {{AREA}}. We can report directly to your property manager, attend AGMs if needed, and coordinate with other contractors on your property."},
    {"question": "What''s the contract term?", "answer": "We offer 12-month contracts with fixed monthly pricing for budget predictability. Month-to-month arrangements are available at a slightly higher rate. We''re confident enough in our work that we don''t need to lock you in."},
    {"question": "How do you handle resident complaints or special requests?", "answer": "Your dedicated account manager handles all communication. Residents can be directed to contact us through your council or property manager. We respond to requests within 24 hours and document all additional work performed."},
    {"question": "Do you carry commercial insurance?", "answer": "Yes. We carry comprehensive commercial general liability insurance and full WorkSafeBC (WCB) coverage. We can provide certificates of insurance to your strata corporation or property management company on request."}
  ]
}', 'Standard strata FAQ covering contracts, reporting, insurance.', 1, NOW(), NOW()),

('faq.strata_landscaping.v2', 'strata', 'faq', '{
  "title": "Questions From Strata Councils",
  "description": "Common questions we hear from strata council members and property managers.",
  "faqs": [
    {"question": "How do you handle scope changes mid-contract?", "answer": "We understand strata needs can change. Additional work is quoted separately and only proceeds with council approval. You''re never billed for work you didn''t authorize."},
    {"question": "What happens during adverse weather?", "answer": "We monitor weather forecasts and proactively adjust schedules. If a visit must be rescheduled, we notify your property manager in advance. Critical safety tasks like ice management are prioritized."},
    {"question": "Can you attend our strata AGM or council meeting?", "answer": "Yes. We''re happy to attend annual general meetings or council meetings to present our service report, answer owner questions, and discuss upcoming plans. We find this builds transparency and trust."},
    {"question": "How do you ensure crew quality and consistency?", "answer": "We assign the same crew to your property whenever possible. All team members are trained on your property''s specific requirements, safety protocols, and access procedures. You''ll recognize the faces that show up each week."},
    {"question": "What sets you apart from other strata landscaping companies?", "answer": "Photo-verified visits, dedicated account management, and reporting designed specifically for strata accountability. Most landscaping companies treat strata like a bigger residential job. We built our strata program around the accountability councils actually need."}
  ]
}', 'Deeper strata FAQ addressing governance and operational concerns.', 1, NOW(), NOW()),

-- CTA variants
('cta.strata_landscaping.v1', 'strata', 'cta', '{
  "headline": "Get a Custom Strata Landscaping Proposal",
  "subheadline": "We''ll walk your property, assess the scope, and deliver a detailed proposal with fixed monthly pricing.",
  "primary_text": "Request a Property Assessment",
  "primary_url": "{{CTA_URL}}",
  "secondary_text": "Call {{PHONE}}",
  "secondary_url": "tel:{{PHONE_TEL}}",
  "style": "dark"
}', 'Process-focused CTA. Emphasizes the assessment step.', 1, NOW(), NOW()),

('cta.strata_landscaping.v2', 'strata', 'cta', '{
  "headline": "Ready for Accountable Grounds Maintenance?",
  "subheadline": "Join the {{CITY}} strata councils that trust {{BRAND}} for reliable, documented landscaping services.",
  "primary_text": "Get Your Strata Quote",
  "primary_url": "{{CTA_URL}}",
  "secondary_text": "Call {{PHONE}}",
  "secondary_url": "tel:{{PHONE_TEL}}",
  "style": "gradient"
}', 'Social proof CTA. Leverages council trust.', 1, NOW(), NOW()),

-- Testimonials
('testimonials.strata_landscaping.v1', 'strata', 'testimonials', '{
  "title": "Trusted by {{CITY}} Strata Councils",
  "layout": "grid",
  "testimonials": [
    {"name": "David R.", "role": "Strata Council President, North Vancouver", "content": "The monthly reporting alone made the switch worth it. Our previous contractor never provided documentation. {{BRAND}} sends photos and reports without us having to ask.", "image_id": null},
    {"name": "Priya S.", "role": "Property Manager, Burnaby", "content": "Managing multiple strata properties means I need contractors I can count on. {{BRAND}} is consistent, communicative, and their pricing has been exactly as quoted.", "image_id": null},
    {"name": "Michael W.", "role": "Strata Council Treasurer, {{CITY}}", "content": "Fixed monthly pricing makes budgeting straightforward. The quality has been excellent and owners have noticed the improvement in our common areas.", "image_id": null}
  ]
}', 'Strata testimonials from council members and property managers.', 1, NOW(), NOW());
