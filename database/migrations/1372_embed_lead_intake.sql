-- 1372 — embeddable lead form for tenant websites (Ittai pilot: talkinghands.ca, 2026-10-18)
--
-- A tenant pastes one <script> tag on their own site; visitors' enquiries land here, scoped to the tenant.
-- Tenant = a cms_sites row (resolved by domain elsewhere in the CMS; talkinghands.ca is site 2 since runner 1001).
-- The older `sites` stub (migration 1046) is NOT used for this.
--
-- cms_sites.embed_key           public key in the snippet; identifies the tenant, is NOT a secret
-- cms_sites.embed_origins       comma list of exact origins allowed to post (https://example.com,https://www.example.com);
--                               the Origin check is what actually protects the key
-- cms_sites.lead_notify_email   where enquiry alerts go (falls back to cms_sites.email)
-- cms_sites.embed_enabled       master switch, off until a tenant is set up
-- cms_form_submissions.site_id  which tenant owns the enquiry; existing rows are Mowology (1)
--
-- MySQL 5.7 rules: no IF NOT EXISTS on ALTER. Safe to leave site 1 untouched (embed_enabled stays 0).

ALTER TABLE cms_sites
    ADD COLUMN embed_key VARCHAR(40) NULL DEFAULT NULL,
    ADD COLUMN embed_origins VARCHAR(1000) NULL DEFAULT NULL,
    ADD COLUMN lead_notify_email VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN embed_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD UNIQUE KEY uq_cms_sites_embed_key (embed_key);

ALTER TABLE cms_form_submissions
    ADD COLUMN site_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_cfs_site_created (site_id, created_at);

-- Pilot tenant: talkinghands (hobby school; alerts to the collective's inbox). Two sites: talkinghands.ca and
-- wingchunvancouver.ca (the main marketing site) — one tenant, two allowed origins.
UPDATE cms_sites
SET embed_key = SUBSTRING(MD5(CONCAT(RAND(), id, NOW())), 1, 24),
    embed_origins = 'https://talkinghands.ca,https://www.talkinghands.ca,https://wingchunvancouver.ca,https://www.wingchunvancouver.ca',
    lead_notify_email = 'talkinghandscollective@gmail.com',
    embed_enabled = 1
WHERE slug = 'talkinghands' AND embed_key IS NULL;
