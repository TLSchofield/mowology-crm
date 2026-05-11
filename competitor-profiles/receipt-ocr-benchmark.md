# Receipt OCR — Competitor Benchmark

**Generated:** 2026-05-11
**Scope:** Receipt capture, OCR pipeline, tax extraction, and mobile review UX
**Context:** Canadian field service (BC landscaping); benchmark against Mowology receipt system post-AAA audit
**Depth:** Quick scan — feature comparison focus

---

## At a Glance

| App | OCR Method | Offline OCR | Canadian GST+PST | Per-field confidence | GPS job match | Duplicate method |
|-----|-----------|-------------|-----------------|----------------------|---------------|-----------------|
| **Expensify** | Cloud SmartScan (human fallback) | No | Manual config only | No — whole-receipt flags | No | Semantic (amount/date/currency) |
| **Dext** | Cloud ML + human fallback | No | Yes — automatic split | No — aggregate claim | No | Semantic + AI forgery detection |
| **Hubdoc** | Cloud ML | No | Partial (setup required) | No | No | Semantic (date/supplier/amount) |
| **Zoho Expense** | Cloud Autoscan | No | Config required | No | No | Semantic (date/amount/currency) |
| **Jobber** | None — photo attach only | N/A | N/A | N/A | No (manual attach) | N/A |
| **Mowology** | Apple Vision on-device + GCV server | **Yes — 200ms on Neural Engine** | **Yes — automatic BC GST+PST, TVQ-aware** | **Yes — green/yellow/red per field** | **Yes — GPS schedule match** | **SHA-256 image hash** |

---

## Feature Deep-Dive

### OCR Speed & First-Data UX

| App | Time to first visible field |
|-----|-----------------------------|
| Expensify | 5–30 seconds (SmartScan); can take minutes/hours at peak |
| Dext | Seconds to 20 minutes (enters human review queue) |
| Hubdoc | Seconds to 24 hours |
| Zoho Expense | Seconds (accuracy issues require frequent corrections) |
| Mowology | **~200ms (Apple Vision, fully on-device, no network required)** |

Mowology's two-phase UX — instant pre-fill from Vision → server enrichment arriving later — is architecturally different from every competitor. All others make the user wait before showing anything. For a field worker photographing 8 receipts between jobs, the difference between 200ms and 30 seconds is the difference between completing the task and abandoning it.

### Canadian Tax Extraction

| App | GST | PST (BC 7%) | TVQ (QC 9.975%) | HST (blended ON/NS/NB) | Math validation |
|-----|-----|-------------|-----------------|------------------------|-----------------|
| Expensify | Manual config | Manual only | Manual only | Supported | No |
| Dext | Yes — automatic | Yes — automatic | Yes — automatic | Yes | No |
| Hubdoc | Yes | Yes (province setup) | Yes | Yes | No |
| Zoho Expense | Config required | Config required | Config required | Config required | No |
| Mowology | **Yes — auto** | **Yes — auto (BC 7%)** | Noted; TVQ roadmap | Not yet (BC only) | **Yes — flags mismatches** |

**Verdict:** Dext is the only competitor matching Mowology's automatic Canadian tax split. Mowology adds the math validation that nobody else has.

**Gap:** HST handling for Ontario/Nova Scotia/New Brunswick clients — when expanding outside BC, the current two-field model (GST + PST) doesn't map to a blended HST receipt.

### Offline Capability

| App | Offline capture | Offline OCR | Queue / drain |
|-----|----------------|-------------|---------------|
| Expensify | Yes | No | Uploads on reconnect |
| Dext | Yes | No | Uploads on reconnect |
| Hubdoc | Not confirmed | No | — |
| Zoho Expense | No | No | — |
| Mowology | **Yes** | **Yes — Vision runs on-device** | **Application Support queue, auto-drains via NWPathMonitor** |

Every cloud competitor captures the photo offline but delivers zero OCR data until connected. For a landscaping crew in areas with spotty LTE, Mowology is the only option that populates the form immediately regardless of signal quality.

### Vendor / Supplier Intelligence

| App | Vendor matching | Pattern learning | Field service job linkage |
|-----|----------------|-----------------|--------------------------|
| Expensify | Yes (Concierge AI, learned since 2025) | Category/rules | No |
| Dext | Yes (Supplier Rules + AI Assist since Mar 2026) | Full rule engine for accountants | No |
| Hubdoc | No | No | No |
| Zoho Expense | Limited | Limited | No |
| Mowology | Yes (fuzzy match + GPS proximity) | `expense_learned_patterns` per vendor | **Yes — GPS schedule → job suggestion** |

**GPS job suggestion is a feature no competitor offers.** Expensify and Dext can link expenses to projects manually, but neither suggests the job automatically based on where the crew member was standing.

### Duplicate Detection

| App | Method | Catches same-receipt-different-date? |
|-----|--------|--------------------------------------|
| Expensify | Semantic (amount + date + currency) | No — resubmit with typo in date slips through |
| Dext | Semantic + AI manipulation detection | Catches AI-altered receipts; not raw resubmit |
| Hubdoc | Semantic (date + supplier + amount) | No |
| Zoho Expense | Semantic (date + amount + currency) | No |
| Mowology | **SHA-256 image hash** | **Yes — same bytes = same receipt, regardless of metadata** |

SHA-256 on raw image bytes is a meaningfully different approach. A worker who photographs the same receipt on Thursday and Friday will be caught by Mowology even if they entered different dates. Competitors would miss this unless the amount and date both match exactly.

### Per-field Confidence

**No competitor exposes field-level confidence indicators to the end user.** Expensify flags whole-receipt violations (e.g., "amount doesn't match SmartScan"). Dext publishes an aggregate 99.9% claim. Neither tells the worker "this vendor name is guessed, but this total is certain."

Mowology's green/yellow/red confidence dots per field (from server `field_confidences{}`) are a genuine differentiator. Workers know exactly where to check their work.

---

## Gaps vs. Best-in-Class

### Must-Close Before SaaS Expansion

| Gap | Who has it | Effort | CRA/business impact |
|-----|-----------|--------|---------------------|
| Accounting export (QBO/Xero) | Dext, Hubdoc | High | **Critical** — accountants won't adopt without it |
| HST blended province handling | Dext, Hubdoc | Medium | Required for ON/NS/NB clients |
| Card transaction reconciliation | Expensify, Dext | High | Major time-saver for business card users |

### Nice-to-Have

| Gap | Who has it | Effort |
|-----|-----------|--------|
| Quebec TVQ automatic extraction | Dext, Hubdoc | Low — same pattern as PST, add TVQ (9.975%) rate |
| AI manipulation / forgery detection | Dext | Medium |
| CRA-defensible 6-year retention log | Dext (explicitly positioned) | Medium |
| Accountant workspace (multi-client view) | Dext, Hubdoc | High |
| Multilingual OCR (beyond en-CA / fr-CA) | Zoho | Low — Vision and GCV both handle it |

---

## Verdict

### For a single-operator or small-team BC field service business:
**Mowology's receipt system is ahead of every competitor on the field worker UX that matters** — instant offline OCR, per-field confidence, GPS job matching, SHA-256 duplicate detection, and BC tax auto-split. Jobber (the specific replacement target) has no receipt OCR at all.

### For the SaaS platform ambition (multiple tenants, each with their own bookkeeper):
**The accounting integration gap is the critical blocker.** Dext's position in the Canadian accounting ecosystem is strong because accountants use it directly. Until Mowology exports in QBO/Xero-compatible format with supplier rules that an external bookkeeper can configure, it cannot replace Dext in a multi-tenant context where the client's accountant is the key user.

### The honest ranking:
1. **Capture UX / field worker experience:** Mowology > Expensify > Dext > Zoho > Hubdoc > Jobber (0)
2. **Accountant integration / audit trail:** Dext > Hubdoc > Expensify > Zoho > Mowology (none) > Jobber (none)
3. **Canadian tax accuracy:** Dext = Mowology > Hubdoc > Expensify > Zoho > Jobber
4. **Offline resilience:** Mowology > Expensify ≈ Dext > Zoho (none) > Jobber (none)

---

## Raw Data Sources

- Research date: 2026-05-11
- Expensify: expensify.com/receipt-scanning-app, help.expensify.com
- Dext: dext.com, help.dext.com (Canadian secondary tax extraction docs)
- Hubdoc: central.xero.com (Hubdoc data extraction docs)
- Zoho Expense: zoho.com/us/expense/receipt-scanner-app
- Jobber: getjobber.com/features/expense-tracking, help.getjobber.com
- OCR accuracy benchmarks: zerentry.com/blog/ocr-accuracy-comparison-2026
- Raw scrape data: `raw/receipt-ocr-benchmark/2026-05-11/`
