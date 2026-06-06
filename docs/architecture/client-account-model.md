# Client / Account Model — Architecture & Migration Plan

**Status:** Approved (2026-06-06) — Phase 0 in progress
**Owner:** Tim Schofield
**Context:** Mowology CRM, en route to a Jobber-replacement SaaS (Feb 2027)

---

## 1. Problem

The CRM has a **split-brain billing model**. Two separate top-level entities —
`contacts` (people) and `companies` (organizations) — both try to answer
"who do I bill," and the answer is actually scattered across *both* plus
`properties`. The symptom is code like this in `public/crm/invoices/create.php`:

```sql
COALESCE(jp.company_id, cb.id, cc.id)                       AS company_id,
COALESCE(c.company_name, cb.company_name, cc.company_name)  AS company_name,
```

That COALESCE ladder exists because the bill-to identity lives in three places
at once. Consequences:

- `companies` is effectively empty — every strata you create is friction with
  no payoff, and the invoice "Customer" picker (contacts **or** companies)
  can't reliably surface them.
- A real-world person who plays two roles (e.g. a strata council member who is
  *also* a personal landscaping customer) has nowhere clean to live.
- ~20 tables and ~141 PHP files reference `contact_id` / `company_id` directly,
  so the inconsistency is spreading.

### The scenario that proves the model is wrong

A strata council president (Jane) wants **her own unit's** lawn done *and* signs
off on the **strata common grounds** contract.

- Jane = one person.
- Her unit → billed to **Jane personally**.
- The common grounds → billed to **"Strata Plan VR15/40"**.

If the strata identity is baked onto Jane's contact record, this is impossible.
**Bill-to is a property/job concern, not a person concern.** That realization
drives the whole model.

---

## 2. Target model — unified `clients` (account) spine

One bill-to entity. People and addresses hang off it. This is the Jobber model,
made multi-tenant-ready.

```
clients                         ← the bill-to / account (person OR organization)
  id
  client_type   ENUM(individual, business, strata, property_manager)
  display_name  "Jane Doe" | "Strata Plan VR15/40" | "FirstService Residential"
  billing_address / city / province / postal / email / phone
  payment_terms / payment_method / invoice_routing_method
  managed_by_client_id  NULL → FK clients.id   ← a strata managed BY a PM firm
  lifecycle_stage / status
  -- (multi-tenant) site_id / tenant_id added when SaaS tenancy lands

contacts                        ← people (stays a GLOBAL registry of humans)
  id, first_name, last_name, email, phone, mobile…   (unchanged)

client_contacts                 ← junction: which people belong to which account
  client_id, contact_id
  role  ENUM(owner, council_member, property_manager, billing, site)
  is_primary, receives_invoices, receives_notifications

properties                      ← service addresses
  client_id        → FK clients.id   (who pays for work here)
  site_contact_id  → FK contacts.id  (the human on site)

jobs / quotes / invoices
  client_id        → FK clients.id   ← the bill-to spine, one source of truth
```

### Why a `client_contacts` junction (not contacts-belong-to-one-client)

It is the direct answer to the Jane scenario. Jane is **one** `contacts` row,
linked to **two** clients — `owner` on her personal account, `council_member`
on the strata account. A property manager running 30 buildings is one contact
linked to 30 clients. No duplication; no role baked onto the person.

### Why `managed_by_client_id`

A strata is its own client (the legal payer), but the PM firm managing it is
*also* a client. Invoice routing for the strata can resolve "send to the
managing firm's billing contact" without duplicating anything. PM firms are the
**only** organization that genuinely earns a reusable record — they're shared
across many buildings.

### Scenario mapping

| Scenario | clients | contacts / links | properties |
|---|---|---|---|
| Residential (Jane) | `Jane Doe` (individual) | Jane → owner | her unit → client=Jane |
| Self-managed strata | `Strata VR15/40` (strata) | council member → council_member | grounds → client=strata |
| PM-managed strata | `Strata VR15/40`, managed_by → `FirstService` | PM person → property_manager (on FirstService) | grounds → client=strata |
| Jane *also* on council | — | same Jane row, 2nd link to strata | — |

---

## 3. Rollout — expand, migrate, contract (no big bang)

Each phase is independently shippable and reversible. Production invoicing keeps
working throughout. The `BillToResolver` (Phase 0) is the single seam that every
later phase changes behind.

### Phase 0 — Abstraction layer (no schema change, non-breaking) ← current

Introduce `app/Modules/Clients/Services/BillToResolver.php`: given a
property / visit / plan, it returns one normalized **bill-to** object
(name, account type, billing address, primary contact, recipients). This
collapses the COALESCE-ladder logic into one place. New code calls the resolver;
old code is untouched. Recipient routing delegates to the existing
`determineInvoiceRecipients()` in `Invoices/Services/InvoiceRouting.php`.

### Phase 1 — Expand

Create `clients` + `client_contacts`. Backfill: every contact → a
client(individual); every company → a client(organization); link them via the
junction. Add **nullable** `client_id` to properties / jobs / quotes / invoices
*alongside* the existing columns. Dual-write. Old code still works.

### Phase 2 — Migrate reads

Point `BillToResolver` and the invoice "Customer" picker at `client_id` (one
unified account search — this is where the picker bug finally dies). Fall back
to legacy columns for un-backfilled rows.

### Phase 3 — Migrate writes

New jobs / quotes / invoices treat `client_id` as source of truth; backfill
history.

### Phase 4 — Contract

Once everything reads/writes `client_id`, retire `company_properties`, the
COALESCE joins, and the contacts-vs-companies split in the UI.

---

## 4. Invariants (hold across all phases)

- **Bill-to lives at the property/job level**, never on a contact.
- A `contacts` row is always a human and may belong to many clients.
- A `clients` row is the only thing an invoice's `client_id` points at.
- Migrations follow the project's MySQL rules: no `IF NOT EXISTS` on
  `ALTER TABLE`, no DDL inside a transaction, `company_id`/`client_id` FKs are
  nullable (`companies` may be empty), `ON DELETE SET NULL`.
- Nothing is dropped until a later phase proves the replacement reads/writes
  correctly in production.

---

## 5. Open questions (revisit before Phase 1)

- Do individuals get an auto-created `client` of `client_type=individual`, or is
  the contact promoted lazily on first invoice? (Lean: auto-create on backfill.)
- Invoice routing roles vs. the existing `invoice_routing_method` enum on
  `companies` — reconcile into `client_contacts.receives_invoices`.
- Multi-tenant `site_id` placement — add to `clients` now (nullable) or in the
  tenancy phase.
