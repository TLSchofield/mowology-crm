# Phase 1 Runbook — Client/Account Model

Companion to [client-account-model.md](client-account-model.md). Everything you
need to **review, run, verify, and roll back** Phase 1.

## ⚠️ How migrations actually run on this project

There are **two** migration paths, and they've diverged — the 10xx line (incl.
this one) does **not** use the file-based Migrations Manager:

- **Migrations Manager** (Settings → Database → Migrations) reads
  `public_html/database/migrations`. That dir tops out before the 10xx series,
  so it will **not** list 1046–1049. Ignore it for this phase.
- **URL runner scripts** — `public/crm/api/run-migration-XXXX.php` — the proven
  path for every recent 10xx migration. Each is admin-only, self-contained, and
  idempotent. **This is how you run Phase 1.**

The canonical `.sql` files live in repo-root `database/migrations/` (not
deployed). The runner scripts in `public/crm/api/` carry the same SQL inline and
ARE deployed (cPanel ships `public/`). Both must stay on `main` to be reachable.

Run **in this exact order** by visiting each URL as a logged-in admin:

| # | Runner URL | `.sql` mirror | Effect | Reversible |
|---|-----------|---------------|--------|-----------|
| 1 | `/crm/api/run-migration-1046.php` | `1046_sites_table.sql` | `sites` stub + seed Mowology | drop table |
| 2 | `/crm/api/run-migration-1047.php` | `1047_clients_tables.sql` | `clients` + `client_contacts` | drop tables |
| 3 | `/crm/api/run-migration-1048.php` | `1048_entities_client_id.sql` | nullable `client_id` on 4 tables | drop columns |
| 4 | `/crm/api/run-migration-1049.php` | `1049_backfill_*.sql` | populate + stamp client_id (idempotent) | data-only; re-runnable |

**Dry-run the backfill first:** `/crm/api/run-migration-1049.php?dry=1` runs every
INSERT/UPDATE inside a transaction and **rolls back**, returning the row counts
it *would* write — preview without committing. Drop `?dry=1` to apply for real.

Each runner returns JSON: `{ok, results:[{step,status,rows...}]}`. A `status` of
`already_exists` (1046–1048) means that step was applied on a prior run — fine.

All four are **additive and non-breaking** — no existing column is changed or
dropped, so the app keeps running on the old columns throughout Phase 1.

---

## 0. Pre-flight — verify live columns BEFORE running 1049

Production schema differs from the schema file. Run these read-only checks first
(Database dashboard SQL, or the schema snapshot). 1049 assumes each is present:

```sql
-- expect a row for each
SELECT 'invoices.contact_id'     AS col, COUNT(*) FROM information_schema.columns
  WHERE table_name='invoices'   AND column_name='contact_id'
UNION ALL SELECT 'properties.company_id', COUNT(*) FROM information_schema.columns
  WHERE table_name='properties' AND column_name='company_id'
UNION ALL SELECT 'companies.invoice_routing_method', COUNT(*) FROM information_schema.columns
  WHERE table_name='companies'  AND column_name='invoice_routing_method'
UNION ALL SELECT 'companies.primary_contact_id', COUNT(*) FROM information_schema.columns
  WHERE table_name='companies'  AND column_name='primary_contact_id'
UNION ALL SELECT 'companies.billing_contact_id', COUNT(*) FROM information_schema.columns
  WHERE table_name='companies'  AND column_name='billing_contact_id';
```

If any returns 0, fix that line in 1049 before running it.

**Optional — prefer `properties.billing_company_id` over `company_id`:** if the
live `properties` table has `billing_company_id` (it's used by the invoice page
but exists in no migration), and you want it to win, change the properties join
in Step E of 1049 to:
`LEFT JOIN clients oc ON oc.legacy_company_id = COALESCE(p.billing_company_id, p.company_id)`.

---

## 1. Verification — run AFTER each step

After 1046–1048 (structure):
```sql
SELECT COUNT(*) FROM sites;                 -- ≥ 1 (Mowology)
SHOW COLUMNS FROM clients;                   -- table exists
SHOW COLUMNS FROM properties LIKE 'client_id';   -- 1 row
SHOW COLUMNS FROM invoices  LIKE 'client_id';    -- 1 row
```

After 1049 (data) — these are the numbers that prove the backfill:
```sql
-- one individual client per named contact, one org client per company
SELECT
  (SELECT COUNT(*) FROM contacts
     WHERE TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) <> '') AS named_contacts,
  (SELECT COUNT(*) FROM clients WHERE legacy_contact_id IS NOT NULL)               AS individual_clients,
  (SELECT COUNT(*) FROM companies)                                                 AS companies,
  (SELECT COUNT(*) FROM clients WHERE legacy_company_id IS NOT NULL)               AS org_clients;
-- expect: named_contacts == individual_clients,  companies == org_clients

-- every individual client has its owner link
SELECT COUNT(*) AS individual_clients_missing_owner
FROM clients cl
LEFT JOIN client_contacts cc
       ON cc.client_id = cl.id AND cc.role='owner' AND cc.is_primary=1
WHERE cl.legacy_contact_id IS NOT NULL AND cc.id IS NULL;   -- expect 0

-- coverage: how many billable rows still lack a client_id (should be only rows
-- that genuinely have no company AND no contact/site-contact)
SELECT 'invoices'  AS entity, COUNT(*) AS total, SUM(client_id IS NULL) AS unmapped FROM invoices
UNION ALL SELECT 'quotes',     COUNT(*), SUM(client_id IS NULL) FROM quotes
UNION ALL SELECT 'job_plans',  COUNT(*), SUM(client_id IS NULL) FROM job_plans
UNION ALL SELECT 'properties', COUNT(*), SUM(client_id IS NULL) FROM properties;

-- spot-check: invoices whose resolved client name differs from the legacy
-- bill_to_name (investigate any surprises before trusting Phase 2 reads)
SELECT i.id, i.invoice_number, i.bill_to_name, cl.display_name, cl.client_type
FROM invoices i JOIN clients cl ON cl.id = i.client_id
ORDER BY i.id DESC LIMIT 25;
```

Any `unmapped` count should correspond to rows with no resolvable customer at
all (orphans) — list them and decide case by case; do **not** force a client_id.

---

## 2. Rollback (manual — paste into Database SQL if needed)

Safe because Phase 1 is additive. Run bottom-up. Drop FKs before columns.

```sql
-- 1048 — entity columns
ALTER TABLE invoices   DROP FOREIGN KEY fk_invoices_client;   ALTER TABLE invoices   DROP COLUMN client_id;
ALTER TABLE quotes     DROP FOREIGN KEY fk_quotes_client;     ALTER TABLE quotes     DROP COLUMN client_id;
ALTER TABLE job_plans  DROP FOREIGN KEY fk_job_plans_client;  ALTER TABLE job_plans  DROP COLUMN client_id;
ALTER TABLE properties DROP FOREIGN KEY fk_properties_client; ALTER TABLE properties DROP COLUMN client_id;

-- 1047 — junction then accounts
DROP TABLE IF EXISTS client_contacts;
DROP TABLE IF EXISTS clients;

-- 1046 — tenant stub
DROP TABLE IF EXISTS sites;
```

The URL runners do **not** write to `migrations_log`, so there's nothing to
clear — just re-visit the runner URLs to re-apply. To re-run ONLY the backfill
after fixing data, you don't need rollback at all: 1049 is idempotent, so visit
`/crm/api/run-migration-1049.php` again.

---

## 3. After Phase 1 — what unblocks

- **Phase 2** points `BillToResolver` and the invoice "Customer" picker at
  `client_id` (one unified account search — kills the strata-not-findable bug).
- `ContactService` starts auto-creating an individual client on new-contact
  insert (keeps the D1 invariant true for new data).
