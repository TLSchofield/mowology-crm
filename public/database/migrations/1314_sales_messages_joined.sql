-- Migration 1314: Sam joins an email to a quote's conversation by what it is about (owner, 2026-10-10).
-- Monica at Macdonald PM wrote "council approved" about QUO-2026-0073, but she wasn't a contact, so
-- the email was skipped and Sam's card still said Linda (the contact) was waiting on us.
-- Now a sender who isn't a contact is joined to a quote's contact when the email names the quote
-- number, starts with the quote's property address, or comes from the same company's domain.
-- Rules only, no AI. MySQL 5.7: plain ALTERs.
ALTER TABLE sales_messages
  ADD COLUMN from_name VARCHAR(120) NULL COMMENT 'Sender display name (Monica Nicule)' AFTER from_addr,
  ADD COLUMN quote_id INT NULL COMMENT 'The quote the email was joined to, when the sender is not the contact' AFTER contact_id,
  ADD COLUMN joined_by VARCHAR(20) NULL COMMENT 'quote_number | address | domain — why a non-contact email joined; NULL = the contact wrote it' AFTER quote_id;
