-- 1376 — which shareholder a Personal receipt is charged to (owner, 2026-10-19: "a button that says
-- personal that automatically posts the expense to shareholder"; Tim by default, Katrenna by choice).
-- NULL = the Personal category's own account (1300 Due from Shareholder, Tim). A code 1300–1309
-- (e.g. 1302 Katrenna's shareholder loan) posts the whole receipt there; no GST is claimed back.
ALTER TABLE expenses ADD COLUMN shareholder_account VARCHAR(10) NULL DEFAULT NULL AFTER accounting_category;
