<?php
declare(strict_types=1);

/**
 * Synthetic supplier lists for the price-list tests — FAKE plants and prices only (real trade
 * lists are private and never go into the repo). Builds a real .xlsx with ZipArchive, using shared
 * strings or inline strings, and an SQLite copy of migration 1331's tables.
 */
final class PriceListFixtures
{
    /** @param array<int, array<string, mixed>> $rows [rowNumber => [col => value]] */
    public static function xlsx(array $rows, bool $inlineStrings = false): string
    {
        $shared = [];
        $sheetRows = '';
        ksort($rows);
        foreach ($rows as $r => $cols) {
            $cells = '';
            ksort($cols);
            foreach ($cols as $col => $v) {
                $ref = $col . $r;
                if (is_int($v) || is_float($v)) {
                    $cells .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                } elseif ($inlineStrings) {
                    $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$v, ENT_XML1) . '</t></is></c>';
                } else {
                    if (!isset($shared[$v])) $shared[$v] = count($shared);
                    $cells .= '<c r="' . $ref . '" t="s"><v>' . $shared[$v] . '</v></c>';
                }
            }
            $sheetRows .= '<row r="' . $r . '">' . $cells . '</row>';
        }
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $sst = '<?xml version="1.0" encoding="UTF-8"?><sst ' . $ns . '>';
        $i = 0;
        foreach (array_keys($shared) as $s) {
            // Every other string as rich-text runs, to prove both shapes read.
            $sst .= ($i++ % 2) ? '<si><r><t>' . htmlspecialchars(mb_substr((string)$s, 0, 3), ENT_XML1) . '</t></r><r><t xml:space="preserve">' . htmlspecialchars(mb_substr((string)$s, 3), ENT_XML1) . '</t></r></si>'
                               : '<si><t>' . htmlspecialchars((string)$s, ENT_XML1) . '</t></si>';
        }
        $sst .= '</sst>';
        $tmp = tempnam(sys_get_temp_dir(), 'fx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook ' . $ns . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Avail" sheetId="1" r:id="rId7"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/list.xml"/></Relationships>');
        if (!$inlineStrings) $zip->addFromString('xl/sharedStrings.xml', $sst);
        $zip->addFromString('xl/worksheets/list.xml', '<?xml version="1.0"?><worksheet ' . $ns . '><sheetData>' . $sheetRows . '</sheetData></worksheet>');
        $zip->close();
        $bytes = (string)file_get_contents($tmp);
        unlink($tmp);
        return $bytes;
    }

    /** The Inline format row exactly as migration 1331 seeds it (proves the seed itself parses). */
    public static function inlineFormat(): array
    {
        $sql = (string)file_get_contents(__DIR__ . '/../../../database/migrations/1331_supplier_price_lists_catalog.sql');
        if (!preg_match("/'(\\{\"header_anchor\".*?\\})'\\);/s", $sql, $m)) throw new RuntimeException('Inline spec not found in migration 1331');
        return ['id' => 1, 'code' => 'inline-nurseries', 'family' => 'plants', 'sender_domains' => 'inlinenurseries.com',
                'link_hosts' => 'mcusercontent.com', 'arrives' => 'link', 'spec_json' => $m[1], 'is_active' => 1];
    }

    /** A fake availability list in Inline's layout. $prices: [code => price]. */
    public static function availabilityRows(array $prices, int $serial = 46307): array
    {
        $rows = [
            1 => ['B' => 'Test Nursery'], 2 => ['B' => 'Wholesale Availability'], 3 => ['B' => $serial],
            7 => ['B' => 'Company:', 'G' => 'Order Minimum $500.00'], 8 => ['G' => '11cm Round - 10/tray'],
            14 => ['A' => 'ProductID', 'B' => 'Item', 'C' => 'Size', 'D' => 'Zone', 'E' => 'Category', 'F' => 'Price', 'G' => 'Available', 'H' => 'Order', 'I' => 'Total'],
        ];
        $plants = [
            1001 => ["Fakeia testii 'Blue Moon'", '11cm Round', 4, 'Perennials'],
            1002 => ["Fakeia testii 'Blue Moon'", 'No.1 Pot', 4, 'Perennials'],
            1003 => ["Mockus Starry® 'Vivid Dawn' (PW)", 'No.2 Pot', 5, 'Perennials'],
            1004 => ['Samplea x vulgaris (Sample Cherry)', '5-6ft No.10 Pot', 6, 'Deciduous Trees & STDs'],
            1005 => ["Fakeia testii 'Red Sun'", 'Quart Pot', 4, 'Groundcovers'],
        ];
        $r = 15;
        foreach ($prices as $code => $price) {
            [$name, $size, $zone, $cat] = $plants[$code];
            $rows[$r++] = ['A' => $code, 'B' => $name, 'C' => $size, 'D' => $zone, 'E' => $cat, 'F' => $price, 'G' => 25, 'I' => 0];
        }
        return $rows;
    }

    public static function sqlite(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE supplier_accounts (id INTEGER PRIMARY KEY, name TEXT, domain TEXT)");
        $db->exec("CREATE TABLE supplier_list_formats (id INTEGER PRIMARY KEY, code TEXT, name TEXT, family TEXT, sender_domains TEXT, link_hosts TEXT, arrives TEXT, spec_json TEXT, is_active INT DEFAULT 1)");
        $db->exec("CREATE TABLE catalog_families (id INTEGER PRIMARY KEY, slug TEXT UNIQUE, name TEXT, matcher TEXT, default_use_kind TEXT, sort_order INT DEFAULT 0)");
        $db->exec("CREATE TABLE catalog_categories (id INTEGER PRIMARY KEY, parent_id INT, family TEXT, name TEXT, slug TEXT, UNIQUE(family, slug))");
        $db->exec("CREATE TABLE catalog_category_map (id INTEGER PRIMARY KEY, family TEXT, text_key TEXT, category_id INT, how TEXT, UNIQUE(family, text_key))");
        $db->exec("CREATE TABLE catalog_products (id INTEGER PRIMARY KEY, family TEXT, category_id INT, canonical_name TEXT, brand TEXT, mfr_part_no TEXT, upc TEXT,
                   match_key TEXT, alt_key TEXT, group_key TEXT, common_names TEXT, UNIQUE(family, match_key))");
        $db->exec("CREATE TABLE catalog_attribute_values (product_id INT, attr_key TEXT, value TEXT, source TEXT, PRIMARY KEY (product_id, attr_key))");
        $db->exec("CREATE TABLE catalog_size_equivalents (id INTEGER PRIMARY KEY, family TEXT, raw_key TEXT, canonical TEXT, note TEXT, sort_order INT DEFAULT 0, UNIQUE(family, raw_key))");
        $db->exec("CREATE TABLE catalog_aliases (id INTEGER PRIMARY KEY, family TEXT, alias_key TEXT, product_id INT, how TEXT, times_confirmed INT DEFAULT 1, UNIQUE(family, alias_key))");
        $db->exec("CREATE TABLE tenant_catalog_families (site_id INT, family TEXT, PRIMARY KEY (site_id, family))");
        $db->exec("CREATE TABLE supplier_price_lists (id INTEGER PRIMARY KEY, site_id INT DEFAULT 1, supplier_account_id INT, format_id INT, kind TEXT, list_date TEXT, valid_until TEXT,
                   title TEXT, file_name TEXT, source_url TEXT, source_message_key TEXT, rules_json TEXT, row_count INT, imported_at TEXT, UNIQUE(site_id, supplier_account_id, kind, list_date))");
        $db->exec("CREATE TABLE supplier_price_items (id INTEGER PRIMARY KEY, site_id INT DEFAULT 1, list_id INT, product_code TEXT, name TEXT, size TEXT, zone TEXT, category TEXT,
                   brand TEXT, mfr_part_no TEXT, upc TEXT, price REAL, list_price REAL, special_price REAL, available INT, comment TEXT, use_kind TEXT, prev_price REAL,
                   price_change TEXT, catalog_product_id INT, catalog_category_id INT, size_code TEXT, height TEXT, name_key TEXT, match_confidence REAL, match_how TEXT)");
        $db->exec("CREATE TABLE catalog_match_queue (id INTEGER PRIMARY KEY, site_id INT DEFAULT 1, family TEXT, name_key TEXT, raw_name TEXT, suggested_product_id INT, score REAL,
                   status TEXT DEFAULT 'open', decided_by INT, decided_at TEXT, UNIQUE(site_id, family, name_key))");
        $db->exec("INSERT INTO catalog_families (slug, name, matcher, default_use_kind, sort_order) VALUES
                   ('plants','Plants','botanical',NULL,1), ('plumbing','Plumbing','part_number','consumable',4), ('retail_goods','Retail goods','part_number','resale',10)");
        $db->exec("INSERT INTO catalog_categories (parent_id, family, name, slug) VALUES (NULL,'plants','Plants','plants'), (NULL,'plumbing','Plumbing','plumbing'), (1,'plants','Perennials','perennials')");
        $db->exec("INSERT INTO catalog_category_map (family, text_key, category_id, how) VALUES ('plants','perennials',3,'seed')");
        $db->exec("INSERT INTO catalog_size_equivalents (family, raw_key, canonical, note) VALUES ('plants','no1','no1','No.1 pot, about 1 US gal'), ('plants','1 qt','qt',NULL)");
        $db->exec("INSERT INTO tenant_catalog_families (site_id, family) VALUES (1,'plants')");
        $db->exec("INSERT INTO supplier_accounts (id, name, domain) VALUES (8,'Test Nursery','testnursery.example'), (9,'Other Growers','othergrowers.example')");
        return $db;
    }
}
