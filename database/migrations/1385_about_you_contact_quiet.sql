-- 1385 — Onboarding "About you" (owner, 2026-10-20): three things about the person — the name to use
-- (users.preferred_name, 1384), how they want to be contacted, and when not to bother them. Saved here;
-- the heads obeying them (channel + quiet hours for alerts and briefs) is the follow-up.
ALTER TABLE users ADD COLUMN contact_channel VARCHAR(10) NULL COMMENT 'text | email | app' AFTER preferred_name;
ALTER TABLE users ADD COLUMN quiet_start TIME NULL COMMENT 'nothing sent from this time' AFTER contact_channel;
ALTER TABLE users ADD COLUMN quiet_end TIME NULL COMMENT 'until this time (may be the next morning)' AFTER quiet_start;
ALTER TABLE users ADD COLUMN quiet_weekends TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = quiet all weekend too' AFTER quiet_end;
