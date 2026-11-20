-- Migration 1226: "Tidy iCloud" — a filing assistant for Tim's mailbox (mowology@icloud.com)
-- Date: 2026-10-07
-- Purpose: MailTidyService proposes a folder plan, previews where every message in INBOX /
--   Archive would go, checks Junk for real work mail, and — only on Tim's click — MOVES mail
--   (never deletes) through ImapWriter, logging every move so a batch can be undone.
--     1. mail_tidy_rules   — the folder plan, the sender / domain / subject rules and the
--                            settings, as plain rows (no JSON). rule_uid keeps seeding idempotent.
--     2. mail_tidy_previews / mail_tidy_cursors / mail_tidy_items — a resumable preview: per
--                            folder scan position, and one row per message that would move
--                            (or be rescued from Junk). Subjects are kept only for a few
--                            samples per folder pair and for the Junk rescue list.
--     3. mail_tidy_batches / mail_tidy_moves — every Apply click is a batch; every move is
--                            logged with Message-ID, UID, UIDVALIDITY, from and to folder, so
--                            "Undo batch" can move each message back.
-- MySQL 5.7 compatible (no JSON, no generated columns, no window functions); safe to re-run.

CREATE TABLE IF NOT EXISTS mail_tidy_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rule_uid VARCHAR(191) NOT NULL          COMMENT 'type:key — e.g. folder:clients, domain:vancity.com, setting:keep_tidy',
  rule_type VARCHAR(20) NOT NULL          COMMENT 'folder | address | domain | subject | setting',
  folder_key VARCHAR(40) NOT NULL         COMMENT 'folder rows: the plan key; rules: the target plan key; settings: the setting name',
  match_value VARCHAR(255) NOT NULL       COMMENT 'folder: IMAP name (modified UTF-7, & = &-); rule: address / domain / phrase; setting: value',
  label VARCHAR(120) NULL                 COMMENT 'folder: readable name',
  note VARCHAR(255) NULL                  COMMENT 'what goes there / what the setting does',
  is_existing TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'folder already in iCloud on 2026-10-07 (kept, never renamed)',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mail_tidy_rule_uid (rule_uid),
  KEY idx_mail_tidy_rule_type (rule_type, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Tidy iCloud: folder plan, filing rules and settings';

CREATE TABLE IF NOT EXISTS mail_tidy_previews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  status VARCHAR(20) NOT NULL DEFAULT 'scanning' COMMENT 'scanning | ready | stale',
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  scanned INT NOT NULL DEFAULT 0,
  to_move INT NOT NULL DEFAULT 0,
  rescue INT NOT NULL DEFAULT 0,
  stayed INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  KEY idx_mail_tidy_previews_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Tidy iCloud: one preview (nothing moved by a preview)';

CREATE TABLE IF NOT EXISTS mail_tidy_cursors (
  preview_id INT NOT NULL                 COMMENT '0 = the Keep-it-tidy cron position',
  folder VARCHAR(120) NOT NULL,
  role VARCHAR(10) NOT NULL DEFAULT 'tidy' COMMENT 'tidy | junk | keep',
  uid_validity BIGINT NULL,
  uid_next BIGINT NULL,
  last_uid BIGINT NOT NULL DEFAULT 0,
  scanned INT NOT NULL DEFAULT 0,
  stayed INT NOT NULL DEFAULT 0,
  recent INT NOT NULL DEFAULT 0           COMMENT 'kept in INBOX because newer than inbox_keep_days',
  done TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (preview_id, folder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Tidy iCloud: where each preview scan got to';

CREATE TABLE IF NOT EXISTS mail_tidy_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  preview_id INT NOT NULL,
  kind VARCHAR(10) NOT NULL               COMMENT 'move | rescue (Junk → INBOX)',
  folder VARCHAR(120) NOT NULL            COMMENT 'where it is now',
  uid BIGINT NOT NULL,
  uid_validity BIGINT NOT NULL,
  message_id VARCHAR(191) NOT NULL,
  from_addr VARCHAR(255) NULL,
  subject VARCHAR(255) NULL               COMMENT 'samples and the rescue list only',
  msg_date DATETIME NULL,
  target_key VARCHAR(40) NULL,
  target_folder VARCHAR(120) NOT NULL,
  confidence TINYINT NOT NULL DEFAULT 0,
  reason VARCHAR(160) NULL,
  selected TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending | moved | skipped | failed',
  batch_id INT NULL,
  UNIQUE KEY uq_mail_tidy_item (preview_id, folder, uid),
  KEY idx_mail_tidy_items_status (preview_id, kind, status),
  KEY idx_mail_tidy_items_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Tidy iCloud: messages a preview would move';

CREATE TABLE IF NOT EXISTS mail_tidy_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  preview_id INT NULL,
  kind VARCHAR(12) NOT NULL               COMMENT 'apply | rescue | undo | keep_tidy',
  undo_of INT NULL                        COMMENT 'undo batches: the batch being reversed',
  status VARCHAR(12) NOT NULL DEFAULT 'running' COMMENT 'running | done | stopped',
  started_by INT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  target INT NOT NULL DEFAULT 0           COMMENT 'messages this batch set out to move',
  moved INT NOT NULL DEFAULT 0,
  failed INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  KEY idx_mail_tidy_batches_kind (kind, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Tidy iCloud: one row per Apply / Undo click (and Keep-it-tidy run)';

CREATE TABLE IF NOT EXISTS mail_tidy_moves (
  id INT AUTO_INCREMENT PRIMARY KEY,
  batch_id INT NOT NULL,
  message_id VARCHAR(191) NOT NULL,
  uid BIGINT NOT NULL                     COMMENT 'UID in from_folder before the move',
  uid_validity BIGINT NULL,
  from_folder VARCHAR(120) NOT NULL,
  to_folder VARCHAR(120) NOT NULL,
  moved_at DATETIME NOT NULL,
  undone_at DATETIME NULL,
  undo_batch_id INT NULL,
  KEY idx_mail_tidy_moves_batch (batch_id),
  KEY idx_mail_tidy_moves_mid (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Tidy iCloud: undo log — every message moved, and whether it was moved back';

-- The proposed plan, rules and settings (Keep it tidy OFF). INSERT IGNORE: re-running never
-- overwrites a rule Tim has changed.
INSERT IGNORE INTO mail_tidy_rules (rule_uid, rule_type, folder_key, match_value, label, note, is_existing, sort_order) VALUES
('folder:clients', 'folder', 'clients', 'clients', 'Clients', 'Customers, strata and property managers — anyone in the CRM', 1, 10),
('folder:enquiries', 'folder', 'enquiries', 'Enquiries', 'Enquiries', 'New work requests from people not yet in the CRM', 0, 20),
('folder:suppliers', 'folder', 'suppliers', 'Suppliers', 'Suppliers', 'Lawnboy, Home Depot, nurseries, parts dealers — orders and deliveries', 0, 30),
('folder:receipts', 'folder', 'receipts', 'RECEIPTS', 'Receipts & Invoices', 'E-receipts, order confirmations and bills', 1, 40),
('folder:payments', 'folder', 'payments', 'Money', 'Money', 'Payments in and out: Interac e-Transfers, Stripe, Yardi EFT, Square / PayPal notices', 1, 50),
('folder:banking', 'folder', 'banking', 'Banking &- Tax', 'Banking & Tax', 'Vancity, TD, CRA, GST / PST, the accountant', 0, 60),
('folder:insurance', 'folder', 'insurance', 'Insurance &- Vehicles', 'Insurance & Vehicles', 'Insurance, ICBC, the RAM / TD auto loan, Trackimo', 0, 70),
('folder:team', 'folder', 'team', 'Team &- Payroll', 'Team & Payroll', 'Wave payroll, WorkSafeBC, hiring', 0, 80),
('folder:marketing', 'folder', 'marketing', 'Marketing &- Web', 'Marketing & Web', 'Google Business / Search Console, Facebook, Instagram, website, domain, SEO', 0, 90),
('folder:software', 'folder', 'software', 'Software &- Accounts', 'Software & Accounts', 'Apps, subscriptions, security codes (Apple and Arlo keep their own folders)', 0, 100),
('folder:apple', 'folder', 'apple', 'Apple &- Tech', 'Apple & Tech', 'Apple ID, App Store, iCloud, Apple receipts', 1, 110),
('folder:arlo', 'folder', 'arlo', 'Arlo', 'Arlo', 'Arlo camera alerts and account mail', 1, 120),
('folder:newsletters', 'folder', 'newsletters', 'Newsletters &- Promos', 'Newsletters & Promos', 'Anything sent to a mailing list (it has an unsubscribe link), and sales pitches', 1, 130),
('folder:crm', 'folder', 'crm', 'Mowology CRM', 'Mowology CRM', 'The CRM''s own system mail (cron reports, alerts, no-reply notices)', 1, 140),
('folder:jobber', 'folder', 'jobber', 'Jobber', 'Legacy / Jobber', 'Jobber mail from before the 2026-02-25 switch to the CRM', 1, 150),
('folder:london_drugs', 'folder', 'london_drugs', 'London Drugs', 'London Drugs', 'London Drugs orders and photo mail', 1, 160),
('folder:wing_chun', 'folder', 'wing_chun', 'Wing Chun', 'Wing Chun', 'Wing Chun / Wing Tsun — personal', 1, 170),
('folder:personal', 'folder', 'personal', 'Personal', 'Personal', 'Clearly personal mail on an explicit rule — when unsure it stays in INBOX', 1, 180),
('domain:payments.interac.ca', 'domain', 'payments', 'payments.interac.ca', NULL, NULL, 0, 1001),
('domain:interac.ca', 'domain', 'payments', 'interac.ca', NULL, NULL, 0, 1002),
('domain:stripe.com', 'domain', 'payments', 'stripe.com', NULL, NULL, 0, 1003),
('domain:paypal.com', 'domain', 'payments', 'paypal.com', NULL, NULL, 0, 1004),
('domain:paypal.ca', 'domain', 'payments', 'paypal.ca', NULL, NULL, 0, 1005),
('domain:squareup.com', 'domain', 'payments', 'squareup.com', NULL, NULL, 0, 1006),
('domain:square.com', 'domain', 'payments', 'square.com', NULL, NULL, 0, 1007),
('domain:yardi.com', 'domain', 'payments', 'yardi.com', NULL, NULL, 0, 1008),
('domain:vancity.com', 'domain', 'banking', 'vancity.com', NULL, NULL, 0, 1009),
('domain:td.com', 'domain', 'banking', 'td.com', NULL, NULL, 0, 1010),
('domain:tdcanadatrust.com', 'domain', 'banking', 'tdcanadatrust.com', NULL, NULL, 0, 1011),
('domain:cra-arc.gc.ca', 'domain', 'banking', 'cra-arc.gc.ca', NULL, NULL, 0, 1012),
('domain:canada.ca', 'domain', 'banking', 'canada.ca', NULL, NULL, 0, 1013),
('domain:gov.bc.ca', 'domain', 'banking', 'gov.bc.ca', NULL, NULL, 0, 1014),
('domain:intuit.com', 'domain', 'banking', 'intuit.com', NULL, NULL, 0, 1015),
('domain:icbc.com', 'domain', 'insurance', 'icbc.com', NULL, NULL, 0, 1016),
('domain:ramtrucks.com', 'domain', 'insurance', 'ramtrucks.com', NULL, NULL, 0, 1017),
('domain:mopar.com', 'domain', 'insurance', 'mopar.com', NULL, NULL, 0, 1018),
('domain:tdautofinance.ca', 'domain', 'insurance', 'tdautofinance.ca', NULL, NULL, 0, 1019),
('domain:trackimo.com', 'domain', 'insurance', 'trackimo.com', NULL, NULL, 0, 1020),
('domain:intact.ca', 'domain', 'insurance', 'intact.ca', NULL, NULL, 0, 1021),
('domain:squareone.ca', 'domain', 'insurance', 'squareone.ca', NULL, NULL, 0, 1022),
('domain:aviva.ca', 'domain', 'insurance', 'aviva.ca', NULL, NULL, 0, 1023),
('domain:cooperators.ca', 'domain', 'insurance', 'cooperators.ca', NULL, NULL, 0, 1024),
('domain:bcaa.com', 'domain', 'insurance', 'bcaa.com', NULL, NULL, 0, 1025),
('domain:waveapps.com', 'domain', 'team', 'waveapps.com', NULL, NULL, 0, 1026),
('domain:worksafebc.com', 'domain', 'team', 'worksafebc.com', NULL, NULL, 0, 1027),
('domain:indeed.com', 'domain', 'team', 'indeed.com', NULL, NULL, 0, 1028),
('domain:facebookmail.com', 'domain', 'marketing', 'facebookmail.com', NULL, NULL, 0, 1029),
('domain:instagram.com', 'domain', 'marketing', 'instagram.com', NULL, NULL, 0, 1030),
('domain:business.google.com', 'domain', 'marketing', 'business.google.com', NULL, NULL, 0, 1031),
('domain:godaddy.com', 'domain', 'marketing', 'godaddy.com', NULL, NULL, 0, 1032),
('domain:canadianwebhosting.com', 'domain', 'marketing', 'canadianwebhosting.com', NULL, NULL, 0, 1033),
('domain:namecheap.com', 'domain', 'marketing', 'namecheap.com', NULL, NULL, 0, 1034),
('domain:semrush.com', 'domain', 'marketing', 'semrush.com', NULL, NULL, 0, 1035),
('domain:mailchimp.com', 'domain', 'marketing', 'mailchimp.com', NULL, NULL, 0, 1036),
('domain:yelp.com', 'domain', 'marketing', 'yelp.com', NULL, NULL, 0, 1037),
('domain:yelp.ca', 'domain', 'marketing', 'yelp.ca', NULL, NULL, 0, 1038),
('domain:homestars.com', 'domain', 'marketing', 'homestars.com', NULL, NULL, 0, 1039),
('domain:nextdoor.com', 'domain', 'marketing', 'nextdoor.com', NULL, NULL, 0, 1040),
('domain:dropbox.com', 'domain', 'software', 'dropbox.com', NULL, NULL, 0, 1041),
('domain:microsoft.com', 'domain', 'software', 'microsoft.com', NULL, NULL, 0, 1042),
('domain:adobe.com', 'domain', 'software', 'adobe.com', NULL, NULL, 0, 1043),
('domain:zoom.us', 'domain', 'software', 'zoom.us', NULL, NULL, 0, 1044),
('domain:github.com', 'domain', 'software', 'github.com', NULL, NULL, 0, 1045),
('domain:anthropic.com', 'domain', 'software', 'anthropic.com', NULL, NULL, 0, 1046),
('domain:openai.com', 'domain', 'software', 'openai.com', NULL, NULL, 0, 1047),
('domain:accounts.google.com', 'domain', 'software', 'accounts.google.com', NULL, NULL, 0, 1048),
('domain:apple.com', 'domain', 'apple', 'apple.com', NULL, NULL, 0, 1049),
('domain:arlo.com', 'domain', 'arlo', 'arlo.com', NULL, NULL, 0, 1050),
('domain:homedepot.ca', 'domain', 'suppliers', 'homedepot.ca', NULL, NULL, 0, 1051),
('domain:homedepot.com', 'domain', 'suppliers', 'homedepot.com', NULL, NULL, 0, 1052),
('domain:rona.ca', 'domain', 'suppliers', 'rona.ca', NULL, NULL, 0, 1053),
('domain:leevalley.com', 'domain', 'suppliers', 'leevalley.com', NULL, NULL, 0, 1054),
('domain:stihl.ca', 'domain', 'suppliers', 'stihl.ca', NULL, NULL, 0, 1055),
('domain:husqvarna.com', 'domain', 'suppliers', 'husqvarna.com', NULL, NULL, 0, 1056),
('domain:princessauto.com', 'domain', 'suppliers', 'princessauto.com', NULL, NULL, 0, 1057),
('domain:canadiantire.ca', 'domain', 'suppliers', 'canadiantire.ca', NULL, NULL, 0, 1058),
('domain:kubota.ca', 'domain', 'suppliers', 'kubota.ca', NULL, NULL, 0, 1059),
('domain:toro.com', 'domain', 'suppliers', 'toro.com', NULL, NULL, 0, 1060),
('domain:getjobber.com', 'domain', 'jobber', 'getjobber.com', NULL, NULL, 0, 1061),
('domain:jobber.com', 'domain', 'jobber', 'jobber.com', NULL, NULL, 0, 1062),
('domain:londondrugs.com', 'domain', 'london_drugs', 'londondrugs.com', NULL, NULL, 0, 1063),
('address:sc-noreply@google.com', 'address', 'marketing', 'sc-noreply@google.com', NULL, NULL, 0, 1064),
('address:businessprofile-noreply@google.com', 'address', 'marketing', 'businessprofile-noreply@google.com', NULL, NULL, 0, 1065),
('address:ads-noreply@google.com', 'address', 'marketing', 'ads-noreply@google.com', NULL, NULL, 0, 1066),
('address:analytics-noreply@google.com', 'address', 'marketing', 'analytics-noreply@google.com', NULL, NULL, 0, 1067),
('address:no-reply@accounts.google.com', 'address', 'software', 'no-reply@accounts.google.com', NULL, NULL, 0, 1068),
('subject:wing chun', 'subject', 'wing_chun', 'wing chun', NULL, NULL, 0, 1069),
('subject:wing tsun', 'subject', 'wing_chun', 'wing tsun', NULL, NULL, 0, 1070),
('subject:wingtsun', 'subject', 'wing_chun', 'wingtsun', NULL, NULL, 0, 1071),
('subject:sifu', 'subject', 'wing_chun', 'sifu', NULL, NULL, 0, 1072),
('subject:search console', 'subject', 'marketing', 'search console', NULL, NULL, 0, 1073),
('subject:google business profile', 'subject', 'marketing', 'google business profile', NULL, NULL, 0, 1074),
('subject:gst/hst', 'subject', 'banking', 'gst/hst', NULL, NULL, 0, 1075),
('subject:gst return', 'subject', 'banking', 'gst return', NULL, NULL, 0, 1076),
('subject:notice of assessment', 'subject', 'banking', 'notice of assessment', NULL, NULL, 0, 1077),
('subject:you sent a payment', 'subject', 'payments', 'you sent a payment', NULL, NULL, 0, 1078),
('subject:you received a payment', 'subject', 'payments', 'you received a payment', NULL, NULL, 0, 1079),
('subject:you''ve got money', 'subject', 'payments', 'you''ve got money', NULL, NULL, 0, 1080),
('subject:payment received', 'subject', 'payments', 'payment received', NULL, NULL, 0, 1081),
('setting:min_confidence', 'setting', 'min_confidence', '70', NULL, 'Below this confidence, mail stays put', 0, 2001),
('setting:inbox_keep_days', 'setting', 'inbox_keep_days', '30', NULL, 'INBOX keeps the last N days whatever they are', 0, 2002),
('setting:sources', 'setting', 'sources', 'INBOX,Archive', NULL, 'Folders that get filed (comma list)', 0, 2003),
('setting:junk_folders', 'setting', 'junk_folders', 'Junk,Junk E-mailings oh', NULL, 'Folders checked for false positives', 0, 2004),
('setting:chunk_size', 'setting', 'chunk_size', '200', NULL, 'Messages per MOVE', 0, 2005),
('setting:pause_ms', 'setting', 'pause_ms', '1500', NULL, 'Pause between chunks (gentle with Apple)', 0, 2006),
('setting:batch_max', 'setting', 'batch_max', '2000', NULL, 'Messages per Apply click', 0, 2007),
('setting:keep_tidy', 'setting', 'keep_tidy', '0', NULL, 'Keep it tidy: 1 = the cron files new mail older than keep_tidy_after_days', 0, 2008),
('setting:keep_tidy_after_days', 'setting', 'keep_tidy_after_days', '7', NULL, 'Keep it tidy files mail older than this', 0, 2009);
