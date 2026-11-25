-- Migration 1321: is a supplier's point exact (a building) or street-level? (owner, 2026-10-10)
-- Only exact points become places on Otto's map (ops_places match a stop within 150 m);
-- street-level points still serve "nearest supplier". SupplierDirectoryService::placeAll. MySQL 5.7.
ALTER TABLE supplier_accounts
  ADD COLUMN geo_exact TINYINT(1) NULL COMMENT '1 = building-level point, 0 = street/area only, NULL = not looked up';
