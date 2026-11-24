-- Migration 1300: the e-Transfer address is info@mowology.ca (Auto-deposit on). pay@mowology.ca does not
-- exist (Tim, 2026-10-08) but was written into the overdue-reminder email template and automation rule.
UPDATE email_templates
   SET subject   = REPLACE(subject,   'pay@mowology.ca', 'info@mowology.ca'),
       body_html = REPLACE(body_html, 'pay@mowology.ca', 'info@mowology.ca'),
       body_text = REPLACE(body_text, 'pay@mowology.ca', 'info@mowology.ca')
 WHERE subject LIKE '%pay@mowology.ca%' OR body_html LIKE '%pay@mowology.ca%' OR body_text LIKE '%pay@mowology.ca%';
UPDATE automation_rules
   SET actions = REPLACE(actions, 'pay@mowology.ca', 'info@mowology.ca')
 WHERE actions LIKE '%pay@mowology.ca%';
