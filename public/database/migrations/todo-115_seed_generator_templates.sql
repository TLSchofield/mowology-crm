-- ============================================================================
-- Phase 4: Seed Default Page Generator Templates
-- ============================================================================

-- Service Landing Page Template (Lawn Care example)
INSERT INTO cms_page_generator_config (
    config_key, config_label, config_data, enabled, created_at, updated_at
) VALUES (
    'service-landing-basic',
    'Service Landing Page',
    JSON_OBJECT(
        'description', 'Standard service landing page with hero, features, and CTA',
        'page_type', 'service_landing',
        'title_template', '{service} in {neighbourhood} | Mowology Landscaping',
        'slug_template', '{service}-{neighbourhood}',
        'meta_description_template', 'Professional {service} services in {neighbourhood}. Expert local landscapers serving Vancouver area.',
        'required_variables', JSON_ARRAY('service', 'neighbourhood'),
        'blocks', JSON_ARRAY(
            JSON_OBJECT(
                'block_type', 'hero',
                'content', '',
                'config', JSON_OBJECT(
                    'headline', '{service} in {neighbourhood}',
                    'subheadline', 'Expert local landscaping services you can trust',
                    'cta_text', 'Get Free Quote',
                    'cta_url', '/quote?service={service}'
                )
            ),
            JSON_OBJECT(
                'block_type', 'feature_grid',
                'content', '',
                'config', JSON_OBJECT(
                    'title', 'Why Choose Us',
                    'description', 'We bring professional {service} expertise to {neighbourhood}',
                    'features', JSON_ARRAY(
                        JSON_OBJECT('title', 'Local Expertise', 'description', 'Serving {neighbourhood} for over 10 years'),
                        JSON_OBJECT('title', 'Quality Work', 'description', 'Professional results on every project'),
                        JSON_OBJECT('title', 'Fast Response', 'description', 'Available when you need us')
                    )
                )
            ),
            JSON_OBJECT(
                'block_type', 'rich_text',
                'content', '<h2>Professional {service} Services</h2><p>Our team specializes in {service} throughout {neighbourhood}. We bring quality, reliability, and expertise to every project.</p>',
                'config', JSON_OBJECT()
            ),
            JSON_OBJECT(
                'block_type', 'cta',
                'content', '',
                'config', JSON_OBJECT(
                    'headline', 'Ready to Transform Your Property?',
                    'description', 'Get a free quote for {service} services in {neighbourhood}',
                    'primary_text', 'Request Free Quote',
                    'primary_url', '/quote?service={service}'
                )
            )
        )
    ),
    TRUE,
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE config_data = VALUES(config_data);

-- Before/After Gallery Template
INSERT INTO cms_page_generator_config (
    config_key, config_label, config_data, enabled, created_at, updated_at
) VALUES (
    'service-landing-portfolio',
    'Service Landing with Portfolio',
    JSON_OBJECT(
        'description', 'Service landing page featuring portfolio gallery',
        'page_type', 'service_landing',
        'title_template', '{service} Before & After in {neighbourhood} | Mowology',
        'slug_template', '{service}-portfolio-{neighbourhood}',
        'meta_description_template', 'See our {service} work in {neighbourhood} - before and after photos of completed projects',
        'required_variables', JSON_ARRAY('service', 'neighbourhood'),
        'blocks', JSON_ARRAY(
            JSON_OBJECT(
                'block_type', 'hero',
                'content', '',
                'config', JSON_OBJECT(
                    'headline', 'Professional {service} Results in {neighbourhood}',
                    'subheadline', 'View our completed projects and see what we can do for you',
                    'cta_text', 'Get Quote',
                    'cta_url', '/quote?service={service}'
                )
            ),
            JSON_OBJECT(
                'block_type', 'gallery',
                'content', '',
                'config', JSON_OBJECT(
                    'auto_populate_service', '{service}',
                    'auto_populate_neighbourhood', '{neighbourhood}',
                    'title', 'Our Recent Work',
                    'layout', 'grid'
                )
            ),
            JSON_OBJECT(
                'block_type', 'cta',
                'content', '',
                'config', JSON_OBJECT(
                    'headline', 'Ready for Results Like These?',
                    'description', 'Contact us today for a free consultation',
                    'primary_text', 'Start Your Project',
                    'primary_url', '/quote?service={service}&src={neighbourhood}-portfolio'
                )
            )
        )
    ),
    TRUE,
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE config_data = VALUES(config_data);

-- Neighbourhood Coverage Template
INSERT INTO cms_page_generator_config (
    config_key, config_label, config_data, enabled, created_at, updated_at
) VALUES (
    'neighbourhood-coverage',
    'Neighbourhood Coverage Page',
    JSON_OBJECT(
        'description', 'Generic neighbourhood coverage page for SEO distribution',
        'page_type', 'service_landing',
        'title_template', 'Landscaping Services in {neighbourhood} | Mowology',
        'slug_template', 'landscaping-{neighbourhood}',
        'meta_description_template', 'Professional landscaping services in {neighbourhood}. Serving Vancouver area with quality lawn care and design.',
        'required_variables', JSON_ARRAY('neighbourhood'),
        'blocks', JSON_ARRAY(
            JSON_OBJECT(
                'block_type', 'hero',
                'content', '',
                'config', JSON_OBJECT(
                    'headline', 'Landscaping Services in {neighbourhood}',
                    'subheadline', 'Expert local landscapers serving your neighbourhood',
                    'cta_text', 'Get Free Quote',
                    'cta_url', '/quote?src=neighbourhood-{neighbourhood}'
                )
            ),
            JSON_OBJECT(
                'block_type', 'rich_text',
                'content', '<h2>Local Landscaping Expertise</h2><p>We know {neighbourhood} and understand what works best for properties in your area. From lawn care to landscaping design, we deliver quality results.</p>',
                'config', JSON_OBJECT()
            ),
            JSON_OBJECT(
                'block_type', 'cta',
                'content', '',
                'config', JSON_OBJECT(
                    'headline', 'Trusted by {neighbourhood} Homeowners',
                    'description', 'Schedule your free landscaping assessment',
                    'primary_text', 'Request Consultation',
                    'primary_url', '/quote?src={neighbourhood}'
                )
            )
        )
    ),
    FALSE,
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE config_data = VALUES(config_data);

-- Track schema migration
INSERT INTO migrations_log (migration_file, executed_at)
VALUES ('115_seed_generator_templates.sql', NOW())
ON DUPLICATE KEY UPDATE executed_at = NOW();
