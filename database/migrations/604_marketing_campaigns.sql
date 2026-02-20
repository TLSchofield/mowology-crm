-- Migration 604: Marketing Campaigns & Email Templates
-- Enables targeted email campaigns: seasonal upsells, post-job follow-ups,
-- review requests, and custom campaigns.
-- Depends on: contacts, products, field_observations

-- ── Email Templates ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS email_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(50) NOT NULL DEFAULT 'custom'
    COMMENT 'field_recommendation, seasonal_upsell, post_job, review_request, quote_followup, newsletter, custom',
  subject VARCHAR(255) NOT NULL,
  body_html TEXT NOT NULL,
  body_text TEXT NULL,
  merge_fields TEXT NULL COMMENT 'Comma-separated available merge fields',
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_category (category),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Marketing Campaigns ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS marketing_campaigns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  template_id INT NULL COMMENT 'FK to email_templates',
  product_id INT NULL COMMENT 'Target product for upsell campaigns',
  segment_type VARCHAR(50) NOT NULL DEFAULT 'all_marketing'
    COMMENT 'all_marketing, never_purchased_product, inactive_3mo, inactive_6mo, service_type, custom_list',
  segment_rules TEXT NULL COMMENT 'JSON filter criteria',
  trigger_type VARCHAR(30) DEFAULT 'manual'
    COMMENT 'manual, seasonal, field_observation',
  auto_send TINYINT(1) DEFAULT 0 COMMENT '0=queue for review, 1=auto-send',
  schedule_date DATETIME NULL,
  subject_override VARCHAR(255) NULL COMMENT 'Override template subject if set',
  body_override TEXT NULL COMMENT 'Override template body if set',
  status VARCHAR(20) DEFAULT 'draft'
    COMMENT 'draft, scheduled, queued, sending, completed, paused, cancelled',
  recipient_count INT DEFAULT 0,
  sent_count INT DEFAULT 0,
  open_count INT DEFAULT 0,
  click_count INT DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status (status),
  KEY idx_schedule (schedule_date),
  KEY idx_template (template_id),
  KEY idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Campaign Sends (individual email records) ────────────────────────────
CREATE TABLE IF NOT EXISTS campaign_sends (
  id INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT NOT NULL,
  contact_id INT NOT NULL,
  email VARCHAR(255) NOT NULL,
  status VARCHAR(20) DEFAULT 'pending'
    COMMENT 'pending, sent, failed, bounced, unsubscribed',
  sent_at DATETIME NULL,
  opened_at DATETIME NULL,
  clicked_at DATETIME NULL,
  error_message VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_campaign (campaign_id),
  KEY idx_contact (contact_id),
  KEY idx_status (status),
  KEY idx_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Marketing Unsubscribes ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS marketing_unsubscribes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contact_id INT NULL,
  email VARCHAR(255) NOT NULL,
  reason VARCHAR(255) NULL,
  unsubscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email (email),
  KEY idx_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Seed starter email templates ─────────────────────────────────────────
INSERT INTO email_templates (name, category, subject, body_html, merge_fields, is_active) VALUES
(
  'Seasonal Product Upsell',
  'seasonal_upsell',
  'Time for {{product_name}} — Your Lawn Will Thank You',
  '<p>Hi {{first_name}},</p>\n<p>Spring is just around the corner, and it''s the perfect time to consider <strong>{{product_name}}</strong> for your property at {{property_address}}.</p>\n<p>{{product_description}}</p>\n<p>Many of our clients in your area are already booking this service. We''d love to help keep your property looking its best.</p>\n<p><a href=\"{{cta_url}}\" style=\"display:inline-block;padding:12px 24px;background:#2D8659;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;\">Get a Free Quote</a></p>\n<p>Thanks,<br>The Mowology Team</p>',
  'first_name,last_name,email,property_address,product_name,product_price,product_description,cta_url,unsubscribe_url,company_name,company_phone',
  1
),
(
  'Post-Job Thank You',
  'post_job',
  'Thank You for Choosing Mowology!',
  '<p>Hi {{first_name}},</p>\n<p>Thank you for choosing Mowology for your recent service at {{property_address}}. We hope everything looks great!</p>\n<p>If you have any questions or concerns about the work, don''t hesitate to reach out. We''re always happy to help.</p>\n<p>We''d also love to hear your feedback:</p>\n<p><a href=\"{{review_url}}\" style=\"display:inline-block;padding:12px 24px;background:#2D8659;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;\">Leave Us a Review</a></p>\n<p>Thanks again,<br>The Mowology Team</p>',
  'first_name,last_name,email,property_address,review_url,unsubscribe_url,company_name,company_phone',
  1
),
(
  'Review Request',
  'review_request',
  '{{first_name}}, How Did We Do?',
  '<p>Hi {{first_name}},</p>\n<p>We recently completed work at {{property_address}} and would love to know how we did.</p>\n<p>Your feedback helps us improve and helps other homeowners find quality landscaping services.</p>\n<p><a href=\"{{review_url}}\" style=\"display:inline-block;padding:12px 24px;background:#2D8659;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;\">Leave a Quick Review</a></p>\n<p>Thank you for your time,<br>The Mowology Team</p>',
  'first_name,last_name,email,property_address,review_url,unsubscribe_url,company_name,company_phone',
  1
),
(
  'Inactive Client Re-engagement',
  'custom',
  'We Miss You, {{first_name}}!',
  '<p>Hi {{first_name}},</p>\n<p>It''s been a while since we''ve seen you! We noticed your property at {{property_address}} hasn''t had a service visit recently.</p>\n<p>Whether your lawn needs a refresh, your hedges need trimming, or you''re looking for something new — we''re here to help.</p>\n<p><a href=\"{{cta_url}}\" style=\"display:inline-block;padding:12px 24px;background:#2D8659;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;\">Book a Service</a></p>\n<p>Looking forward to hearing from you,<br>The Mowology Team</p>',
  'first_name,last_name,email,property_address,cta_url,unsubscribe_url,company_name,company_phone',
  1
);
