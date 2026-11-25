-- 1374: two more supplier list formats, both ATTACHED to the email (owner, 2026-10-19: "More availability lists came in").
-- Specimen Trees (Pitt Meadows) mails a monthly availability .xls (old Excel; app/Services/Spreadsheet/XlsReader.php):
--   one row per plant + size, header row "strCategory | memDescription | strProductID | …", date in I1.
-- SW Garden Supply mails its contractor bulk-materials price list as a PDF (PdfTableReader): one row per product ending
--   in contractor/yd, retail/yd, half-yard retail, tonnes/yd. Prices are per cubic yard; half-yard and weight go to the comment.
-- Data only (no DDL). Safe to re-run (INSERT IGNORE on the unique code).
INSERT IGNORE INTO supplier_list_formats (code, name, family, sender_domains, link_hosts, arrives, spec_json) VALUES
  ('specimen-trees', 'Specimen Trees monthly availability (.xls)', 'plants', 'specimentrees.com', NULL, 'attachment',
   '{"header_anchor": "strProductID", "columns": {"product_code": ["strProductID"], "name": ["memDescription"], "size": ["strUnitMeasure"], "comment": ["strAdditionalInfo"], "zone": ["Zone"], "category": ["strCategory"], "available": ["dblUnitsInStock"], "price": ["curSalesPrice"]}, "title_cell": "E1", "date_cell": "I1", "attachment_types": ["xls", "xlsx"]}'),
  ('sw-garden-contractor', 'SW Garden Supply contractor bulk material prices (PDF)', 'hardscape', 'swgarden.ca', NULL, 'attachment',
   '{"header_anchor": "Product", "columns": {"name": ["Product"], "comment": ["Notes"], "price": ["Contractor per yard"], "list_price": ["Retail per yard"]}, "title_cell": "A1", "attachment_types": ["pdf"], "pdf": {"numbers": 4, "columns": ["Contractor per yard", "Retail per yard", "Half yard retail", "t per yard"], "start": "Per Yard", "stop": "^\\\\*\\\\*\\\\*", "extra": ["Half yard retail", "t per yard"]}}');
