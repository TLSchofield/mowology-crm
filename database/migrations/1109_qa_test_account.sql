-- 1109_qa_test_account.sql
-- Dedicated low-privilege login for the automated CRM QA crawler
-- (tools/crm-crawl.php). role='user' (never 'admin'), plus a read-only
-- RBAC 'viewer' role grant so the crawler can reach nearly every page
-- without ever being able to edit, manage, or delete anything.
--
-- This file documents the intent. Because this repo's `users` table has
-- drifted from every static schema dump on file (columns like `username`
-- and `is_driver` exist in production but not in the committed schema
-- files — see reference_production_db_schema_diffs memory), the actual
-- executable form of this migration is the defensive, schema-introspecting
-- public/crm/run-migration-1109.php, which checks live columns before
-- inserting. Run that script (as an admin, once) rather than this file
-- directly against production.
--
-- Password hash below is bcrypt (password_hash($pw, PASSWORD_DEFAULT));
-- the plaintext is never committed — it lives only in the gitignored
-- public/app_config/qa-test-credentials.php.

INSERT INTO `users` (`email`, `username`, `password_hash`, `full_name`, `first_name`, `last_name`, `role`, `is_active`, `is_driver`)
VALUES ('qa.crawler@mowology.ca', 'qa_crawler', '$2y$12$sY4RNZNdEBcA9c/zFwdwR.5uh03yZkKlV62snOB1q5tDn8au4POVS', 'QA Crawler Bot', 'QA', 'Crawler', 'user', 1, 0);

-- Grant the read-only 'viewer' RBAC role (seeded by migration 400)
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`)
    SELECT u.id, r.id FROM users u CROSS JOIN roles r
    WHERE u.email = 'qa.crawler@mowology.ca' AND r.name = 'viewer';

-- Extend 'viewer' with view-only Growth/Settings visibility so the crawler
-- reaches marketing + settings pages too — never granting *.edit/*.manage.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT r.id, p.id FROM roles r JOIN permissions p
    ON p.`key` IN ('marketing.view', 'settings.view')
    WHERE r.name = 'viewer';
