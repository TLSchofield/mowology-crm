-- Migration 605: Seed observation_product_rules with starter rules
-- Maps crew field observation types to default product recommendations.
-- Uses name-based subqueries so product IDs are resolved dynamically.
-- Depends on: migration 602 (field_observations + observation_product_rules tables)

-- Clean up any partial data from a previous failed run
DELETE FROM observation_product_rules WHERE observation_type IN ('ph_test','soil_condition','weed_growth','lawn_disease','hedge_overgrowth','drainage_issue');

-- pH Test → Dolopril Lime (low pH / acidic soil)
INSERT INTO observation_product_rules
    (observation_type, condition_match, recommended_product_id, email_subject, email_body_template, auto_send, is_active, priority)
SELECT
    'ph_test',
    'ph < 6.5',
    id,
    'Your Lawn Could Use Some Lime, {{first_name}}',
    '<p>Hi {{first_name}},</p>\n<p>During our recent visit to your property at <strong>{{property_address}}</strong>, our crew tested the soil pH and found it to be on the acidic side.</p>\n<p>Acidic soil makes it harder for your lawn to absorb nutrients, leading to thin, patchy grass. A <strong>Dolopril Lime</strong> application raises the pH back to the ideal range (6.5–7.0), helping your lawn green up and grow thicker.</p>\n{{#observation_notes}}<p><em>Crew notes: {{observation_notes}}</em></p>{{/observation_notes}}\n{{#photo_url}}<p><img src=\"{{photo_url}}\" alt=\"Soil observation\" style=\"max-width:100%;border-radius:8px;\"></p>{{/photo_url}}\n<p>We can schedule a lime application at your convenience — it''s a quick treatment with lasting results.</p>\n<p><a href=\"{{cta_url}}\" style=\"display:inline-block;background:#2D8659;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;\">Get a Quote</a></p>\n<p>Thanks,<br>The Mowology Team</p>',
    0, 1, 10
FROM products WHERE name = 'DOLOPRIL LIME' AND is_archived = 0 LIMIT 1;

-- Soil Condition → Spring Fertilizer
INSERT INTO observation_product_rules
    (observation_type, condition_match, recommended_product_id, email_subject, email_body_template, auto_send, is_active, priority)
SELECT
    'soil_condition',
    NULL,
    id,
    'Your Soil Needs a Boost, {{first_name}}',
    '<p>Hi {{first_name}},</p>\n<p>Our crew noticed some soil quality concerns at <strong>{{property_address}}</strong> during a recent visit.</p>\n<p>Nutrient-depleted soil is one of the most common reasons lawns struggle. A professional fertilizer application replenishes the essential nutrients your grass needs to grow thick and green.</p>\n{{#observation_notes}}<p><em>Crew notes: {{observation_notes}}</em></p>{{/observation_notes}}\n{{#photo_url}}<p><img src=\"{{photo_url}}\" alt=\"Soil observation\" style=\"max-width:100%;border-radius:8px;\"></p>{{/photo_url}}\n<p>We''d love to help get your lawn back on track with a targeted fertilizer treatment.</p>\n<p><a href=\"{{cta_url}}\" style=\"display:inline-block;background:#2D8659;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;\">Get a Quote</a></p>\n<p>Thanks,<br>The Mowology Team</p>',
    0, 1, 5
FROM products WHERE name = 'SPRING FERTILIZER' AND is_archived = 0 LIMIT 1;

-- Weed Growth → Moss Controller
INSERT INTO observation_product_rules
    (observation_type, condition_match, recommended_product_id, email_subject, email_body_template, auto_send, is_active, priority)
SELECT
    'weed_growth',
    NULL,
    id,
    'We Spotted Some Weed & Moss Growth, {{first_name}}',
    '<p>Hi {{first_name}},</p>\n<p>During our recent service at <strong>{{property_address}}</strong>, our crew noticed some weed and moss growth that could use attention.</p>\n<p>Left unchecked, weeds and moss compete with your grass for water and nutrients, eventually taking over healthy turf. A targeted treatment now prevents bigger problems later.</p>\n{{#observation_notes}}<p><em>Crew notes: {{observation_notes}}</em></p>{{/observation_notes}}\n{{#photo_url}}<p><img src=\"{{photo_url}}\" alt=\"Weed observation\" style=\"max-width:100%;border-radius:8px;\"></p>{{/photo_url}}\n<p>We can take care of this quickly — just let us know when works best for you.</p>\n<p><a href=\"{{cta_url}}\" style=\"display:inline-block;background:#2D8659;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;\">Get a Quote</a></p>\n<p>Thanks,<br>The Mowology Team</p>',
    0, 1, 5
FROM products WHERE name = 'MOSS CONTROLLER' AND is_archived = 0 LIMIT 1;

-- Lawn Disease → Spring Fertilizer (closest match for lawn health)
INSERT INTO observation_product_rules
    (observation_type, condition_match, recommended_product_id, email_subject, email_body_template, auto_send, is_active, priority)
SELECT
    'lawn_disease',
    NULL,
    id,
    'We Noticed a Lawn Health Issue, {{first_name}}',
    '<p>Hi {{first_name}},</p>\n<p>Our crew identified signs of potential lawn disease at <strong>{{property_address}}</strong> during a recent visit.</p>\n<p>Lawn disease can spread quickly if left untreated, turning small patches into large bare areas. Early intervention with the right treatment makes a big difference.</p>\n{{#observation_notes}}<p><em>Crew notes: {{observation_notes}}</em></p>{{/observation_notes}}\n{{#photo_url}}<p><img src=\"{{photo_url}}\" alt=\"Lawn disease observation\" style=\"max-width:100%;border-radius:8px;\"></p>{{/photo_url}}\n<p>We recommend a targeted treatment plan to stop the spread and help your lawn recover. Let us put together a quote for you.</p>\n<p><a href=\"{{cta_url}}\" style=\"display:inline-block;background:#2D8659;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;\">Get a Quote</a></p>\n<p>Thanks,<br>The Mowology Team</p>',
    0, 1, 5
FROM products WHERE name = 'SPRING FERTILIZER' AND is_archived = 0 LIMIT 1;

-- Hedge Overgrowth → One Off Lawn Cut (closest general service)
-- Note: If you create a dedicated "Hedge Trimming" product later, update this rule
INSERT INTO observation_product_rules
    (observation_type, condition_match, recommended_product_id, email_subject, email_body_template, auto_send, is_active, priority)
SELECT
    'hedge_overgrowth',
    NULL,
    id,
    'Your Hedges Are Due for a Trim, {{first_name}}',
    '<p>Hi {{first_name}},</p>\n<p>Our crew noticed the hedges at <strong>{{property_address}}</strong> are getting a bit overgrown.</p>\n<p>Regular hedge trimming keeps your property looking sharp and prevents overgrowth from blocking light to your lawn and gardens. It also promotes healthier, denser hedge growth.</p>\n{{#observation_notes}}<p><em>Crew notes: {{observation_notes}}</em></p>{{/observation_notes}}\n{{#photo_url}}<p><img src=\"{{photo_url}}\" alt=\"Hedge observation\" style=\"max-width:100%;border-radius:8px;\"></p>{{/photo_url}}\n<p>We''d be happy to add hedge trimming to your next service, or schedule a standalone visit.</p>\n<p><a href=\"{{cta_url}}\" style=\"display:inline-block;background:#2D8659;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;\">Get a Quote</a></p>\n<p>Thanks,<br>The Mowology Team</p>',
    0, 1, 5
FROM products WHERE name = 'One Off Lawn Cut' AND is_archived = 0 LIMIT 1;

-- Drainage Issue → Premium Topsoil (grading/drainage often needs topsoil)
INSERT INTO observation_product_rules
    (observation_type, condition_match, recommended_product_id, email_subject, email_body_template, auto_send, is_active, priority)
SELECT
    'drainage_issue',
    NULL,
    id,
    'We Noticed a Drainage Concern, {{first_name}}',
    '<p>Hi {{first_name}},</p>\n<p>During our recent visit to <strong>{{property_address}}</strong>, our crew observed some drainage issues on your property.</p>\n<p>Poor drainage can lead to standing water, soggy patches, and long-term damage to your lawn and landscaping. Regrading with quality topsoil is often the most effective solution.</p>\n{{#observation_notes}}<p><em>Crew notes: {{observation_notes}}</em></p>{{/observation_notes}}\n{{#photo_url}}<p><img src=\"{{photo_url}}\" alt=\"Drainage observation\" style=\"max-width:100%;border-radius:8px;\"></p>{{/photo_url}}\n<p>We can assess the area and recommend the best approach to fix the drainage. Let''s set up a time to discuss.</p>\n<p><a href=\"{{cta_url}}\" style=\"display:inline-block;background:#2D8659;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;\">Get a Quote</a></p>\n<p>Thanks,<br>The Mowology Team</p>',
    0, 1, 5
FROM products WHERE name = 'Premium Topsoil' AND is_archived = 0 LIMIT 1;

-- Aeration opportunity → Aeration service
INSERT INTO observation_product_rules
    (observation_type, condition_match, recommended_product_id, email_subject, email_body_template, auto_send, is_active, priority)
SELECT
    'soil_condition',
    'compacted',
    id,
    'Your Lawn Could Benefit from Aeration, {{first_name}}',
    '<p>Hi {{first_name}},</p>\n<p>Our crew noticed signs of compacted soil at <strong>{{property_address}}</strong>.</p>\n<p>Compacted soil prevents water, air, and nutrients from reaching grass roots — leading to thin, stressed turf. Core aeration relieves compaction by pulling small plugs of soil, letting your lawn breathe and grow stronger.</p>\n{{#observation_notes}}<p><em>Crew notes: {{observation_notes}}</em></p>{{/observation_notes}}\n{{#photo_url}}<p><img src=\"{{photo_url}}\" alt=\"Soil compaction\" style=\"max-width:100%;border-radius:8px;\"></p>{{/photo_url}}\n<p>Spring and fall are the best times to aerate. We''d love to get this on the schedule for you.</p>\n<p><a href=\"{{cta_url}}\" style=\"display:inline-block;background:#2D8659;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;\">Get a Quote</a></p>\n<p>Thanks,<br>The Mowology Team</p>',
    0, 1, 8
FROM products WHERE name = 'AERATION' AND is_archived = 0 LIMIT 1;

-- Power Rake suggestion → Power Rake service
INSERT INTO observation_product_rules
    (observation_type, condition_match, recommended_product_id, email_subject, email_body_template, auto_send, is_active, priority)
SELECT
    'soil_condition',
    'thatch',
    id,
    'Thatch Buildup? A Power Rake Will Fix That, {{first_name}}',
    '<p>Hi {{first_name}},</p>\n<p>During our visit to <strong>{{property_address}}</strong>, we noticed a buildup of thatch — that layer of dead grass and debris sitting on top of the soil.</p>\n<p>A little thatch is normal, but too much blocks water, air, and nutrients from reaching the roots. A power rake removes the excess thatch, giving your lawn room to breathe and grow back thicker.</p>\n{{#observation_notes}}<p><em>Crew notes: {{observation_notes}}</em></p>{{/observation_notes}}\n{{#photo_url}}<p><img src=\"{{photo_url}}\" alt=\"Thatch buildup\" style=\"max-width:100%;border-radius:8px;\"></p>{{/photo_url}}\n<p>Power raking is best done in spring or early fall. Let us know if you''d like to schedule it.</p>\n<p><a href=\"{{cta_url}}\" style=\"display:inline-block;background:#2D8659;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;\">Get a Quote</a></p>\n<p>Thanks,<br>The Mowology Team</p>',
    0, 1, 8
FROM products WHERE name = 'POWER RAKE' AND is_archived = 0 LIMIT 1;
