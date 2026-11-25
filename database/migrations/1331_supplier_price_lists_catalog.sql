-- Migration 1331: supplier price lists + the shared product catalogue (owner, 2026-10-12).
-- Services: SupplierListParser (format reader), CatalogService + CatalogMatchers (shared catalogue),
-- SupplierPriceListService (this company's lists). Page: /crm/suppliers/price-lists.php.
--
-- ITTAI SPLIT, marked in every table below:
--   SHARED (no site_id) = knowledge every tenant benefits from and that holds NO prices:
--     list formats, the category tree, catalogue products, attribute definitions and values,
--     size equivalences, confirmed name aliases, supplier-category mappings.
--   PRIVATE (site_id DEFAULT 1, like clients.site_id) = one company's own data:
--     which families it uses, its price lists and items (trade prices, specials, availability),
--     its review queue. Each tenant gets its own copy of a supplier's list from its own mailbox.
--     Lists are never shared between tenants. A list that teaches a new alias or format helps
--     everyone, but no price ever crosses over.
-- MySQL 5.7: plain DDL, JSON kept as TEXT and decoded in PHP (no JSON functions). Index prefixes keep
-- every key under 767 bytes (utf8mb4 on COMPACT rows).

-- SHARED: how a supplier's list reads (sender, where the file is, header anchor, column synonyms).
CREATE TABLE IF NOT EXISTS supplier_list_formats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(160) NOT NULL,
  family VARCHAR(30) NOT NULL DEFAULT 'other' COMMENT 'catalog_categories family the list sells',
  sender_domains VARCHAR(255) NULL COMMENT 'Mail from these domains uses this format, | separated',
  link_hosts VARCHAR(255) NULL COMMENT 'Hosts we may download the list from (https only), | separated',
  arrives VARCHAR(12) NOT NULL DEFAULT 'link' COMMENT 'link | attachment | both',
  spec_json TEXT NOT NULL COMMENT 'Header anchor, column synonyms, date/title/rules cells, kinds, valid-until patterns',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_list_format_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- SHARED: product families are data — a new trade adds rows, not code. matcher picks the family's
-- name matcher (CatalogMatchers): botanical | part_number | name.
CREATE TABLE IF NOT EXISTS catalog_families (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(30) NOT NULL,
  name VARCHAR(80) NOT NULL,
  matcher VARCHAR(16) NOT NULL DEFAULT 'part_number' COMMENT 'botanical | part_number | name',
  default_use_kind VARCHAR(12) NULL COMMENT 'consumable | resale | equipment — NULL = ask/unknown',
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_catalog_family (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- SHARED: the category tree. Top rows (parent_id NULL) are the families.
CREATE TABLE IF NOT EXISTS catalog_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_id INT NULL,
  family VARCHAR(30) NOT NULL,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_catalog_cat (family, slug),
  KEY idx_catalog_cat_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- SHARED: supplier category text → catalogue category, learned once for every tenant.
CREATE TABLE IF NOT EXISTS catalog_category_map (
  id INT AUTO_INCREMENT PRIMARY KEY,
  family VARCHAR(30) NOT NULL,
  text_key VARCHAR(160) NOT NULL COMMENT 'Folded supplier category text',
  category_id INT NOT NULL,
  how VARCHAR(12) NOT NULL DEFAULT 'seed' COMMENT 'seed | name | new | confirmed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_catalog_cat_map (family, text_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- SHARED: one row per real product, whoever sells it. No prices.
CREATE TABLE IF NOT EXISTS catalog_products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  family VARCHAR(30) NOT NULL,
  category_id INT NULL,
  canonical_name VARCHAR(255) NOT NULL,
  brand VARCHAR(120) NULL COMMENT 'Brand / manufacturer (trade goods)',
  mfr_part_no VARCHAR(80) NULL COMMENT 'Manufacturer part number, normalised (upper case, letters and digits)',
  upc VARCHAR(20) NULL,
  match_key VARCHAR(255) NOT NULL COMMENT 'The family matcher''s exact key',
  alt_key VARCHAR(255) NULL COMMENT 'Second-tier key (plants: genus|species|cultivar without series)',
  group_key VARCHAR(120) NULL COMMENT 'Where fuzzy suggestions look (plants: genus, goods: brand)',
  common_names VARCHAR(255) NULL COMMENT 'Common names seen in parentheses, | separated',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_catalog_product_key (family, match_key(160)),
  KEY idx_catalog_product_alt (family, alt_key(160)),
  KEY idx_catalog_product_group (family, group_key),
  KEY idx_catalog_product_mpn (mfr_part_no),
  KEY idx_catalog_product_upc (upc),
  KEY idx_catalog_product_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- SHARED: which attributes a family has (data, not columns).
CREATE TABLE IF NOT EXISTS catalog_attribute_defs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  family VARCHAR(30) NOT NULL,
  attr_key VARCHAR(40) NOT NULL,
  label VARCHAR(80) NOT NULL,
  type VARCHAR(12) NOT NULL DEFAULT 'text' COMMENT 'text | int | number | bool | enum | months',
  unit VARCHAR(16) NULL,
  options VARCHAR(255) NULL COMMENT 'enum choices, | separated',
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_catalog_attr_def (family, attr_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- SHARED: attribute values, key/value rows. Facts only (never prices), written by us or taken from
-- a supplier's own list or description — never copied from copyrighted plant databases.
CREATE TABLE IF NOT EXISTS catalog_attribute_values (
  product_id INT NOT NULL,
  attr_key VARCHAR(40) NOT NULL,
  value VARCHAR(255) NOT NULL,
  source VARCHAR(60) NULL COMMENT 'Where the fact came from (e.g. supplier list, our notes)',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (product_id, attr_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- SHARED: size / unit equivalences per family. raw_key = canonical gives the canonical size's note.
CREATE TABLE IF NOT EXISTS catalog_size_equivalents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  family VARCHAR(30) NOT NULL,
  raw_key VARCHAR(60) NOT NULL COMMENT 'Folded size as a supplier writes it',
  canonical VARCHAR(40) NOT NULL,
  note VARCHAR(120) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_catalog_size (family, raw_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- SHARED: confirmed spellings → product. Any tenant's confirmation matches the next list from any supplier.
CREATE TABLE IF NOT EXISTS catalog_aliases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  family VARCHAR(30) NOT NULL,
  alias_key VARCHAR(255) NOT NULL,
  product_id INT NOT NULL,
  how VARCHAR(12) NOT NULL DEFAULT 'confirmed' COMMENT 'confirmed | merged',
  times_confirmed INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_catalog_alias (family, alias_key(160)),
  KEY idx_catalog_alias_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- PRIVATE: which families this company works with (pages filter by it).
CREATE TABLE IF NOT EXISTS tenant_catalog_families (
  site_id INT NOT NULL DEFAULT 1,
  family VARCHAR(30) NOT NULL,
  PRIMARY KEY (site_id, family)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- PRIVATE: one list as this company received it.
CREATE TABLE IF NOT EXISTS supplier_price_lists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  supplier_account_id INT NOT NULL COMMENT 'supplier_accounts.id',
  format_id INT NULL COMMENT 'supplier_list_formats.id',
  kind VARCHAR(16) NOT NULL COMMENT 'availability | specials',
  list_date DATE NOT NULL,
  valid_until DATE NULL,
  title VARCHAR(160) NULL,
  file_name VARCHAR(255) NULL,
  source_url VARCHAR(500) NULL,
  source_message_key VARCHAR(191) NULL,
  rules_json TEXT NULL COMMENT 'Order rules as printed on the list (array of lines)',
  row_count INT NOT NULL DEFAULT 0,
  imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_price_list (site_id, supplier_account_id, kind, list_date),
  KEY idx_price_list_msg (source_message_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- PRIVATE: each line exactly as the supplier wrote it, plus the catalogue link and last list's price.
CREATE TABLE IF NOT EXISTS supplier_price_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  list_id INT NOT NULL,
  product_code VARCHAR(60) NULL COMMENT 'Supplier''s own code, as given',
  name VARCHAR(255) NOT NULL COMMENT 'Supplier''s own name, as given (never rewritten)',
  size VARCHAR(80) NULL COMMENT 'As given',
  zone VARCHAR(20) NULL,
  category VARCHAR(120) NULL COMMENT 'Supplier''s own category text, as given',
  brand VARCHAR(120) NULL,
  mfr_part_no VARCHAR(80) NULL,
  upc VARCHAR(20) NULL,
  price DECIMAL(10,2) NULL COMMENT 'This company''s trade price',
  list_price DECIMAL(10,2) NULL,
  special_price DECIMAL(10,2) NULL,
  available INT NULL,
  comment VARCHAR(255) NULL,
  use_kind VARCHAR(12) NULL COMMENT 'consumable | resale | equipment — bought to use, to resell, or as a tool',
  prev_price DECIMAL(10,2) NULL COMMENT 'Price on the previous list of the same kind',
  price_change VARCHAR(8) NULL COMMENT 'new | up | down | same',
  catalog_product_id INT NULL,
  catalog_category_id INT NULL,
  size_code VARCHAR(40) NULL,
  height VARCHAR(20) NULL,
  name_key VARCHAR(255) NULL COMMENT 'Matcher key of the name (re-links after a review decision)',
  match_confidence DECIMAL(4,3) NULL,
  match_how VARCHAR(12) NULL COMMENT 'exact | alt | alias | upc | mpn | new | confirmed | suggested',
  KEY idx_price_item_list (list_id),
  KEY idx_price_item_code (product_code),
  KEY idx_price_item_catalog (catalog_product_id, size_code),
  KEY idx_price_item_key (site_id, name_key(150))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- PRIVATE: fuzzy matches waiting for a person (confirm / separate / merge). Never auto-merged.
CREATE TABLE IF NOT EXISTS catalog_match_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  family VARCHAR(30) NOT NULL,
  name_key VARCHAR(255) NOT NULL,
  raw_name VARCHAR(255) NOT NULL,
  suggested_product_id INT NULL,
  score DECIMAL(4,3) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'open' COMMENT 'open | confirmed | separate | merged',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_catalog_queue (site_id, family, name_key(150)),
  KEY idx_catalog_queue_status (site_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seeds -----------------------------------------------------------------------------------------

INSERT IGNORE INTO catalog_families (slug, name, matcher, default_use_kind, sort_order) VALUES
  ('plants', 'Plants', 'botanical', NULL, 1),
  ('hardscape', 'Hardscape & aggregates', 'name', 'consumable', 2),
  ('irrigation', 'Irrigation', 'part_number', 'consumable', 3),
  ('plumbing', 'Plumbing', 'part_number', 'consumable', 4),
  ('electrical', 'Electrical', 'part_number', 'consumable', 5),
  ('tools', 'Tools & equipment', 'part_number', 'equipment', 6),
  ('chemicals', 'Chemicals & fertiliser', 'part_number', 'consumable', 7),
  ('fuel', 'Fuel', 'name', 'consumable', 8),
  ('salon_spa_supplies', 'Salon & spa supplies (professional use)', 'part_number', 'consumable', 9),
  ('retail_goods', 'Retail goods (resold to clients)', 'part_number', 'resale', 10),
  ('linens_consumables', 'Linens & consumables', 'part_number', 'consumable', 11),
  ('office', 'Office', 'part_number', 'consumable', 12),
  ('other', 'Other', 'name', NULL, 99);

-- One root category per family.
INSERT IGNORE INTO catalog_categories (parent_id, family, name, slug)
  SELECT NULL, f.slug, f.name, f.slug FROM catalog_families f;

INSERT IGNORE INTO catalog_categories (parent_id, family, name, slug)
  SELECT c.id, 'plants', x.name, x.slug FROM catalog_categories c
  JOIN (SELECT 'Perennials' AS name, 'perennials' AS slug UNION ALL SELECT 'Groundcovers', 'groundcovers'
        UNION ALL SELECT 'Grasses', 'grasses' UNION ALL SELECT 'Ferns', 'ferns'
        UNION ALL SELECT 'Fruit trees', 'fruit-trees' UNION ALL SELECT 'Deciduous trees & standards', 'deciduous-trees-standards'
        UNION ALL SELECT 'Deciduous & broadleaf shrubs', 'deciduous-broadleaf' UNION ALL SELECT 'Conifers', 'conifers'
        UNION ALL SELECT 'Bamboo', 'bamboo' UNION ALL SELECT 'Small fruit & vegetables', 'small-fruit-vegetables') x
  WHERE c.family = 'plants' AND c.parent_id IS NULL;

INSERT IGNORE INTO catalog_category_map (family, text_key, category_id, how)
  SELECT 'plants', x.text_key, c.id, 'seed' FROM catalog_categories c
  JOIN (SELECT 'perennials' AS text_key, 'perennials' AS slug UNION ALL SELECT 'groundcovers', 'groundcovers'
        UNION ALL SELECT 'grasses', 'grasses' UNION ALL SELECT 'ferns', 'ferns'
        UNION ALL SELECT 'fruit trees', 'fruit-trees' UNION ALL SELECT 'deciduous trees stds', 'deciduous-trees-standards'
        UNION ALL SELECT 'deciduous broadleaf', 'deciduous-broadleaf' UNION ALL SELECT 'conifers', 'conifers'
        UNION ALL SELECT 'bamboo', 'bamboo' UNION ALL SELECT 'small fruit vegetables', 'small-fruit-vegetables') x
    ON x.slug = c.slug
  WHERE c.family = 'plants';

INSERT IGNORE INTO catalog_attribute_defs (family, attr_key, label, type, unit, options, sort_order) VALUES
  ('plants', 'genus', 'Genus', 'text', NULL, NULL, 1),
  ('plants', 'species', 'Species', 'text', NULL, NULL, 2),
  ('plants', 'cultivar', 'Cultivar', 'text', NULL, NULL, 3),
  ('plants', 'series', 'Series / trade name', 'text', NULL, NULL, 4),
  ('plants', 'zone', 'Zone (as listed)', 'text', NULL, NULL, 5),
  ('plants', 'hardiness_zone_min', 'Hardiness zone (min)', 'int', NULL, NULL, 10),
  ('plants', 'hardiness_zone_max', 'Hardiness zone (max)', 'int', NULL, NULL, 11),
  ('plants', 'sun', 'Sun', 'enum', NULL, 'full|part|shade', 12),
  ('plants', 'water', 'Water', 'enum', NULL, 'low|med|high', 13),
  ('plants', 'mature_height_cm', 'Mature height', 'int', 'cm', NULL, 14),
  ('plants', 'mature_spread_cm', 'Mature spread', 'int', 'cm', NULL, 15),
  ('plants', 'bloom_months', 'Bloom months', 'months', NULL, NULL, 16),
  ('plants', 'deer_resistant', 'Deer resistant', 'bool', NULL, NULL, 17),
  ('plants', 'evergreen', 'Evergreen', 'bool', NULL, NULL, 18),
  ('plants', 'native_bc', 'Native to BC', 'bool', NULL, NULL, 19),
  ('plants', 'pruning_notes', 'Pruning notes', 'text', NULL, NULL, 20),
  ('plants', 'care_notes', 'Care notes', 'text', NULL, NULL, 21),
  ('plumbing', 'material', 'Material', 'text', NULL, NULL, 1),
  ('plumbing', 'nominal_size', 'Nominal size', 'text', NULL, NULL, 2),
  ('plumbing', 'pressure_rating', 'Pressure rating', 'number', 'psi', NULL, 3),
  ('plumbing', 'certification', 'Certification', 'text', NULL, NULL, 4),
  ('electrical', 'voltage', 'Voltage', 'number', 'V', NULL, 1),
  ('electrical', 'amperage', 'Amperage', 'number', 'A', NULL, 2),
  ('electrical', 'gauge', 'Wire gauge', 'text', 'AWG', NULL, 3),
  ('electrical', 'listing', 'CSA / UL listing', 'text', NULL, NULL, 4);

INSERT IGNORE INTO catalog_size_equivalents (family, raw_key, canonical, note, sort_order) VALUES
  ('plants', '9cm', '9cm', '9 cm pot', 10),
  ('plants', '10cm', '10cm', '10 cm pot', 11),
  ('plants', '11cm', '11cm', '11 cm round, about 4.5 in, about 1 qt', 12),
  ('plants', '11cm round', '11cm', NULL, 12),
  ('plants', '4in', '4in', '4 in pot', 13),
  ('plants', 'qt', 'qt', 'Quart pot, about 1 qt', 14),
  ('plants', '1 qt', 'qt', NULL, 14),
  ('plants', 'no1', 'no1', 'No.1 pot, about 1 US gal', 20),
  ('plants', 'no2', 'no2', 'No.2 pot, about 2 US gal', 21),
  ('plants', 'no3', 'no3', 'No.3 pot, about 3 US gal', 22),
  ('plants', 'no5', 'no5', 'No.5 pot, about 5 US gal', 23),
  ('plants', 'no7', 'no7', 'No.7 pot, about 7 US gal', 24),
  ('plants', 'no10', 'no10', 'No.10 pot, about 10 US gal', 25),
  ('plants', 'no15', 'no15', 'No.15 pot, about 15 US gal', 26),
  ('plants', 'no2-planter', 'no2-planter', 'No.2 planter', 30),
  ('plants', 'no5-planter', 'no5-planter', 'No.5 planter', 31),
  ('plants', 'plug', 'plug', 'Plug', 40),
  ('plants', 'liner', 'liner', 'Liner', 41),
  ('plants', 'bb', 'bb', 'Balled & burlapped', 42),
  ('plants', 'bareroot', 'bareroot', 'Bare root', 43),
  ('plumbing', '1 2', '1/2in', NULL, 10),
  ('plumbing', '1 2in', '1/2in', NULL, 10),
  ('plumbing', '1/2in', '1/2in', 'NPS 1/2, about DN15', 10),
  ('plumbing', '3 4', '3/4in', NULL, 11),
  ('plumbing', '3 4in', '3/4in', NULL, 11),
  ('plumbing', '3/4in', '3/4in', 'NPS 3/4, about DN20', 11),
  ('plumbing', '1in', '1in', 'NPS 1, about DN25', 12),
  ('electrical', '14awg', '14awg', '14 AWG', 10),
  ('electrical', '14 awg', '14awg', NULL, 10),
  ('electrical', '14', '14awg', NULL, 10),
  ('electrical', '12awg', '12awg', '12 AWG', 11),
  ('electrical', '12 awg', '12awg', NULL, 11),
  ('electrical', '12', '12awg', NULL, 11);

INSERT IGNORE INTO tenant_catalog_families (site_id, family) VALUES
  (1, 'plants'), (1, 'hardscape'), (1, 'irrigation'), (1, 'tools'), (1, 'chemicals'), (1, 'fuel');

-- The first list format: Inline Nurseries (weekly Mailchimp email, .xlsx links). Patterns use [0-9]
-- not backslash classes, so the SQL string needs no escaping.
INSERT IGNORE INTO supplier_list_formats (code, name, family, sender_domains, link_hosts, arrives, spec_json) VALUES
  ('inline-nurseries', 'Inline Nurseries wholesale availability and specials', 'plants', 'inlinenurseries.com', 'mcusercontent.com', 'link',
   '{"header_anchor":"ProductID","columns":{"product_code":["ProductID","Product ID","Item No","Item #","SKU","Code"],"name":["Item","Plant","Plant Name","Botanical Name","Description","Variety"],"size":["Size","Container","Pot Size"],"zone":["Zone","Hardiness Zone"],"category":["Category","Type","Group"],"price":["Price","Net Price","Your Price","Wholesale"],"list_price":["List Price","Regular Price"],"special_price":["@month","Special","Special Price","Sale Price"],"available":["Available","Avail","Availability","Qty","Quantity","On Hand"],"comment":["Comments","Comment","Notes","Note"]},"title_cell":"B3","date_cell":"B3","rules_cells":["G7:G12","B6"],"kinds":[{"kind":"specials","file":"special","title":"special"},{"kind":"availability","file":"availab"}],"skip_files":"booking","file_types":["xlsx","csv"],"valid_until":["shipping by ([a-z]+[.]? [0-9]{1,2})","shipping in ([a-z]+)"]}');
