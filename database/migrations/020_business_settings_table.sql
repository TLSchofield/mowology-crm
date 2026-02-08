-- Business Settings Table
-- Stores company branding, legal info, and messaging templates
-- Used for invoices, email templates, and public-facing documents

CREATE TABLE IF NOT EXISTS business_settings (
  id INT PRIMARY KEY DEFAULT 1,

  -- Company Information
  company_name VARCHAR(255) NOT NULL DEFAULT 'Mowology',
  company_phone VARCHAR(20),
  company_email VARCHAR(255),
  company_website VARCHAR(255),
  company_address TEXT,

  -- Legal Information
  gst_registration VARCHAR(50),
  pst_registration VARCHAR(50),
  business_license VARCHAR(100),

  -- Branding
  logo_path VARCHAR(255),
  logo_alt_text VARCHAR(255),
  brand_color_primary VARCHAR(7),
  brand_color_secondary VARCHAR(7),

  -- Invoice Settings
  invoice_footer_text TEXT,
  invoice_terms_text TEXT,
  invoice_payment_instructions TEXT,

  -- Email Settings
  email_signature_text TEXT,
  email_footer_html TEXT,

  -- Client Messages
  quote_message_header TEXT COMMENT 'Message shown at top of quote',
  quote_message_footer TEXT COMMENT 'Message shown at bottom of quote',
  invoice_message_header TEXT COMMENT 'Message shown at top of invoice',
  invoice_message_footer TEXT COMMENT 'Message shown at bottom of invoice',
  receipt_message_header TEXT,
  receipt_message_footer TEXT,

  -- Metadata
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT COMMENT 'User ID who last updated settings',

  CONSTRAINT fk_business_settings_user
    FOREIGN KEY (updated_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings if table is new
INSERT INTO business_settings (id, company_name)
VALUES (1, 'Mowology Landscaping')
ON DUPLICATE KEY UPDATE id=id;

-- Create index for faster lookups
CREATE INDEX idx_business_settings_created ON business_settings(created_at);
