-- Mia page generation: a draft can now hold a whole page. `body` is the page text in Mia's small markup (headings, paragraphs, bullet lists).
-- Existing rows are untouched (NULL). Comments contain no semicolons. MySQL 5.7 compatible.

ALTER TABLE mia_content_drafts ADD COLUMN body MEDIUMTEXT NULL COMMENT 'page text in Mia markup, only for kind page';
