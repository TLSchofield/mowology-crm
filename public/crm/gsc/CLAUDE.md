# CLAUDE.md — Google Search Console (GSC) Sync + Insights Module (DO NOT BREAK)

This folder powers the **Mowology CRM → Portfolio → GSC Insights** feature.
It is already working in production. Your job is to **extend safely**, not refactor.

---

## ✅ Current Working State (Baseline)

As of Feb 9, 2026:
- Manual sync works (button triggers sync)
- Cron sync works (server can run it)
- **Sync History table populates**
- Status transitions work (**Pending → Success/Partial/Failed**)
- Stored data drives the “Top Search Queries” + Insights UI

**DO NOT refactor working flows. Patch-only unless explicitly asked.**

---

## 🔒 Golden Rules (Do Not Break)

1) **Never change OAuth / token logic without explicit instruction**
   - Redirect URIs must match EXACTLY
   - Token refresh must remain stable
   - Do not rename env constants or config keys

2) **Do not change DB table names or columns**
   - We rely on schema stability across multiple pages
   - Add new tables if needed; do not rename existing ones

3) **Sync history must always finalize**
   - Never allow sync_history rows to remain stuck in `pending`
   - Any sync must update `completed_at` + `status` in a `finally` block

4) **No “big refactors”**
   - Avoid moving files, changing routes, or “cleaning architecture”
   - Prefer small, testable changes

---

## 🧠 Mental Model of the System

This module has 3 layers:

### 1) Connection Layer (OAuth)
- User connects Google account + GSC access granted
- Tokens stored encrypted in DB for each property
- Refresh token used for long-lived access

### 2) Sync Layer (Data Pull + Storage)
- `sync-cron.php` pulls GSC Search Analytics for each property
- Stores:
  - A snapshot in `gsc_snapshots`
  - Query/page rows in `gsc_query_page_stats`
- Writes one `gsc_sync_history` record per sync run:
  - status: pending → success/partial/failed
  - processed/inserted/updated counts
  - completed_at always set when done

### 3) Presentation Layer (Dashboard/Insights)
- Dashboard reads latest snapshot + aggregates
- Displays top queries and sync history
- Recommendations tab will interpret stored data into actions

---

## 📦 Database Objects (Do Not Rename)

Expected tables:
- `gsc_properties`
  - `id`, `site_url`
  - `access_token_encrypted`, `refresh_token_encrypted`
  - `expires_at`
- `gsc_snapshots`
  - `id`, `property_id`, `snapshot_date`, `data_json`, `pulled_at`
- `gsc_query_page_stats`
  - `snapshot_id`, `query`, `page`, `clicks`, `impressions`, `ctr`, `position`
- `gsc_sync_history`
  - `id`, `property_id`, `sync_type`
  - `status`, `started_at`, `completed_at`
  - `rows_processed`, `rows_inserted`, `rows_updated`
  - `duration_seconds`, `error_message`, `notes`, `initiated_by_user_id`

If a column is missing on a specific server, code should fail gracefully
(fallback updates are allowed).

---

## 🧾 File Responsibilities (Map)

### `sync-cron.php`
**Most important file.**
- Pulls data from GSC API
- Stores snapshots + rows
- Writes sync history record and finalizes it (never stuck pending)

### `sync-history.php`
- Reads history table
- Produces:
  - last 30 days history rows
  - summary counts (success/failed/partial)

### `recommendations-data.php` (planned/exists)
- SHOULD NOT fetch from GSC directly
- Must only interpret stored data into action items

### `index.php / view.php / create.php / edit.php`
- UI pages for the module
- Must not contain sync logic; sync stays in sync-cron.php

---

## ✅ Verified Working Constraints

### Search Analytics API Site URL Format
We use `sc-domain:example.com` format for API requests.
Do not switch to URL-prefix properties unless instructed.

### Date Window
GSC data has a lag. Use a safe window:
- start: -28 days
- end: -3 days (avoids empty results from lag)

---

## 🧪 Safe Testing Checklist (Before You Commit Changes)

After any change, you MUST verify:

1) Manual sync returns JSON `{ success: true }`
2) A new `gsc_sync_history` row is created
3) That row becomes `success` or `partial` (not stuck `pending`)
4) `completed_at` is set
5) `Top Search Queries` table still shows data

If any of these fail, revert.

---

## 🚀 How to Extend Without Breaking

### Add “Recommendations”
- Build a pure query layer:
  - read latest snapshot(s)
  - compute opportunities:
    - CTR improvement (high impressions, low CTR, pos 5–25)
    - Create new local landing pages (queries with city keywords)
    - Internal linking targets (pos 11–20)
- Output JSON used by UI cards
- Do not modify sync process

### Add ROI Scoring (safe)
- Use formula like:
  `score = impressions * (targetCTR - currentCTR)`
- Only compute from stored rows

---

## ❌ Do Not Do

- Do not regenerate OAuth credentials
- Do not change redirect URIs
- Do not rename folders or move files
- Do not “normalize schema”
- Do not turn working procedural files into a framework

---

## ✅ Expected Developer Behavior

When asked to implement something:
- Patch minimally
- Explain what you changed
- Provide the full updated file ONLY when requested
- Preserve existing behavior

If uncertain, STOP and ask what behavior must remain unchanged.

---
