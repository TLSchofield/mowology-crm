-- Migration 1201: Listings — where Mowology is listed and whether each listing matches (NAP).
-- Date: 2026-10-06
-- Purpose: /crm/marketing/listings.php tracks each directory by hand (most have no API):
--   status (unknown | not_claimed | claimed | verified), profile URL, review count and rating,
--   last checked, and what the listing shows (name, address, phone, website), compared with
--   the master record read from business_settings. Mia's brief reminds Tim monthly to check
--   Houzz and Yelp.
-- Seeds: the directories Tim asked for. Profile URLs already known from the site's schema.org
--   sameAs list (public/includes/bootstrap.php); review counts/ratings from
--   .agents/product-marketing-context.md as checked 2026-09-28. Everything else starts
--   'unknown' (not checked) — no guesses.
-- MySQL 5.7 compatible. INSERT IGNORE on the unique site_key, so re-running is harmless.

CREATE TABLE IF NOT EXISTS marketing_listings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_key VARCHAR(40) NOT NULL,
  site_name VARCHAR(100) NOT NULL,
  claim_url VARCHAR(500) NULL            COMMENT 'Where to claim / manage the listing',
  status VARCHAR(12) NOT NULL DEFAULT 'unknown' COMMENT 'unknown | not_claimed | claimed | verified',
  profile_url VARCHAR(500) NULL,
  review_count INT NULL,
  rating DECIMAL(2,1) NULL,
  listed_name VARCHAR(200) NULL          COMMENT 'What the listing shows (entered by hand)',
  listed_address VARCHAR(300) NULL,
  listed_phone VARCHAR(40) NULL,
  listed_website VARCHAR(300) NULL,
  nap_status VARCHAR(10) NOT NULL DEFAULT 'unchecked' COMMENT 'unchecked | match | mismatch',
  nap_issues VARCHAR(255) NULL,
  notes TEXT NULL,
  is_optional TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 100,
  last_checked DATE NULL,
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_listing_site (site_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: business listings and NAP consistency';

INSERT IGNORE INTO marketing_listings (site_key, site_name, claim_url, status, profile_url, review_count, rating, is_optional, sort_order, last_checked) VALUES
  ('gbp',        'Google Business Profile',      'https://business.google.com/',                     'verified', 'https://www.google.com/maps?cid=15220268177293590228', 56, 4.5, 0, 10, '2026-09-28'),
  ('apple',      'Apple Business Connect',       'https://businessconnect.apple.com/',               'unknown',  NULL, NULL, NULL, 0, 20, NULL),
  ('bing',       'Bing Places',                  'https://www.bingplaces.com/',                      'unknown',  NULL, NULL, NULL, 0, 30, NULL),
  ('facebook',   'Facebook',                     'https://www.facebook.com/pages/',                  'claimed',  'https://www.facebook.com/mowology', NULL, NULL, 0, 40, NULL),
  ('instagram',  'Instagram',                    'https://www.instagram.com/',                       'claimed',  'https://www.instagram.com/mowology', NULL, NULL, 0, 50, NULL),
  ('nextdoor',   'Nextdoor',                     'https://business.nextdoor.com/',                   'unknown',  NULL, NULL, NULL, 0, 60, NULL),
  ('houzz',      'Houzz',                        'https://www.houzz.com/for-pros',                   'unknown',  NULL, NULL, NULL, 0, 70, NULL),
  ('yelp',       'Yelp',                         'https://biz.yelp.ca/',                             'unknown',  'https://www.yelp.ca/biz/mowology-vancouver-2', 10, 4.2, 0, 80, '2026-09-28'),
  ('linkedin',   'LinkedIn company page',        'https://www.linkedin.com/company/setup/new/',      'unknown',  NULL, NULL, NULL, 0, 90, NULL),
  ('choa',       'CHOA business directory',      'https://www.choa.bc.ca/',                          'unknown',  NULL, NULL, NULL, 0, 100, NULL),
  ('bclna',      'BCLNA member directory',       'https://bclna.com/',                               'unknown',  NULL, NULL, NULL, 0, 110, NULL),
  ('yp',         'YP.ca (Yellow Pages)',         'https://www.yellowpages.ca/',                      'unknown',  'https://www.yellowpages.ca/bus/British-Columbia/Vancouver/Mowology-Lawns-Landscapes-Ltd/8128394.html', NULL, NULL, 0, 120, NULL),
  ('canada411',  'Canada411',                    'https://www.canada411.ca/',                        'unknown',  NULL, NULL, NULL, 0, 130, NULL),
  ('homestars',  'HomeStars',                    'https://homestars.com/pros',                       'unknown',  'https://homestars.com/companies/2804132-mowology-lawns-landscapes', 17, 4.6, 1, 140, '2026-09-28'),
  ('bbb',        'Better Business Bureau',       'https://www.bbb.org/',                             'unknown',  'https://www.bbb.org/ca/bc/vancouver/profile/lawn-care/mowology-lawn-and-landscapes-ltd-0037-1368602', NULL, NULL, 1, 150, NULL);

INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('listings_categories', 'Landscaper; Lawn care service; Snow removal service', 'Listings: business categories for the master NAP record (edit on the Listings page)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
