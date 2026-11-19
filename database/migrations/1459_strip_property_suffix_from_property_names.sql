-- Auto-created properties were named "<Client name> Property". Drop the suffix.
-- Safe to run twice: only touches names that still end in " Property" and have a name before it.
UPDATE properties
SET property_name = TRIM(SUBSTRING(property_name, 1, CHAR_LENGTH(property_name) - 9))
WHERE property_name LIKE '% Property'
  AND CHAR_LENGTH(TRIM(SUBSTRING(property_name, 1, CHAR_LENGTH(property_name) - 9))) > 0;
