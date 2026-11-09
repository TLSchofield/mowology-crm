# Campaign Connector — Architecture Audit

**Date:** 2026-05-18  
**Author:** Architectural audit via Claude Code  
**Status:** Draft — drives Phase 1 build decisions

---

## 1. Executive Summary

### What exists

The Mowology CRM has a mature, multi-module backend that is **roughly 80% of the way** to the Campaign Connector vision. The following production systems are already in place:

- **Automation rules engine** — evaluates 12 trigger types, queues multi-channel actions asynchronously, runs every 5 minutes via cron
- **Campaign email sender** — sends queued campaigns in batches of 20/run, tracks opens/clicks, enforces CASL consent
- **Social publisher** — queues and publishes to Google Business Profile, Facebook, and Instagram with retry logic, engagement analytics, and UTM attribution
- **Review request service** — gated delivery (eligibility checks, 30-day cooldown, SMS consent) triggered at visit completion
- **Referral reward service** — awards free product trials on referred client's first visit
- **Revenue attribution** — `campaign_attribution` and `campaign_revenue_log` link sends → clicks → quotes → jobs → payments
- **Field observations pipeline** — crew logs on-site conditions → triggers product recommendation emails
- **Social draft pipeline** — auto-generates proof-of-work posts from flagged visits with before/after photos
- **Contact tagging** — arbitrary labels on contacts, used by automation rules for targeting
- **Consent management** — per-contact opt-in flags, unsubscribe table, re-consent cron

### What's missing (the 20%)

The gap is not in execution infrastructure — it's in **wiring and visibility**:

1. **No centralized event log.** Modules call services directly. There is no named-event table, so you cannot replay, audit, or extend what fires at what moment without modifying each module's internals.

2. **Four high-value events fire nothing in marketing:** invoice paid, quote declined, crew photo uploaded (non-flagged path), and new lead submitted via jobFlow.

3. **No "Opportunities Dashboard."** There is no CRM view that surfaces jobs completed with no follow-up, photos uploaded but not posted, invoices paid without a referral ask, or stale unconverted leads.

4. **No one-click approval UI.** Campaigns with `auto_send = 0` sit in a queue with no review interface — the admin cannot preview and fire them in a single click.

5. **No plug-in contract.** New modules have no defined interface to register with the campaign engine. Every integration requires knowing the internals of `automation_runner.php`.

### The opportunity

The infrastructure cost is already paid. The work remaining is:
- One new table (`campaign_trigger_log`) as a write-side event bus
- One new service class (`CampaignEventEmitter`) — a static one-liner
- Four 10-line wiring additions in existing files
- One new CRM page (the Opportunities Dashboard)
- Extending the automation runner to consume the event log

The result: any module can fire a named event with a payload. The automation engine listens. A single "this looks good, publish" click coordinates email + SMS + social + website.

---

## 2. Module Inventory

### 2.1 Core Operational Modules

| Module | Path | Tables | Events / Status Transitions | Campaign Data Available |
|--------|------|--------|----------------------------|------------------------|
| **Jobs** | `/public/crm/jobs/`, `/app/Modules/Jobs/` | `jobs`, `job_plans`, `job_visits`, `job_time_entries`, `visit_margin_snapshots`, `visit_photos`, `job_location_samples` | visit: `scheduled → in_progress → completed / skipped / weather / paused` | property address, city, service_type, completion_notes, actual_amount, before/after photos, crew name, drive time, labor cost, gross margin |
| **Quotes** | `/public/crm/quotes/`, `/app/Modules/Quotes/` | `quotes`, `quote_line_items` | `draft → sent → viewed → accepted / declined / expired` | quote_number, amount, valid_until, service_types, property_id, contact_id, signature, accepted_at, decline_reason |
| **Invoices** | `/public/crm/invoices/`, `/app/Modules/Invoices/` | `invoices`, `invoice_line_items`, `invoice_contacts` | `draft → sent → viewed → paid / partial / overdue` | invoice_number, total, balance_due, payment_method, paid_at, per-recipient tracking |
| **Clients** | `/public/crm/clients_appstack.php` | `contacts`, `companies`, `properties`, `company_properties` | lifecycle_stage transitions | first_name, last_name, email, phone, preferred_contact_method, receive_marketing, receive_sms, last_service_date, city |
| **JobFlow (leads)** | `/public/jobFlow/` | `quote_requests` | form submitted → created | name, email, phone, city, service_types[], urgency, lead_quality (hot/warm/cold), utm_source/medium/campaign, referral_code, consent flags |
| **Schedule** | `/public/crm/jobs/schedule.php`, `/app/Modules/Schedule/` | `job_visits` | visits assigned to crews | scheduled_date, crew assignment, property |
| **Products** | `/public/crm/products/`, `/app/Modules/Products/` | `products`, `product_categories`, `field_observations`, `observation_product_rules`, `contact_product_history` | observation logged → recommendation emailed | product_name, base_price, best_season, trigger_month, crew_talking_points, observation_type |
| **Customer Portal** | `/public/customer/` | reads `quotes`, `invoices` | quote viewed, quote accepted/declined, invoice viewed | quote_id, contact_id, view timestamps, IP address, digital signature |

### 2.2 Marketing & Campaign Modules

| Module | Path | Tables | Cron / Trigger | Campaign Data |
|--------|------|--------|----------------|---------------|
| **Marketing Engine** | `/app/Modules/Marketing/` | `marketing_campaigns`, `campaign_sends`, `email_templates`, `automation_rules`, `automation_logs`, `queued_actions`, `campaign_attribution`, `campaign_revenue_log`, `marketing_unsubscribes`, `marketing_optin_tokens` | `automation_runner.php` (5 min), `campaign_sender.php` (15 min), `seasonal_triggers.php` (daily), `invoice_overdue.php` | All contact/job/quote/invoice data via automation rule conditions |
| **Social Module** | `/app/Modules/Social/` | `social_accounts`, `social_posts`, `social_post_platforms`, `social_templates`, `social_post_media`, `social_queue`, `social_approvals`, `social_metrics_daily`, `social_audit_log`, `social_utm_links`, `social_schedules`, `social_time_slots` | `social_publisher.php` (5–30 min), `metrics_sync.php` | Visit photos, service_type, neighborhood, city, crew_name, UTM attribution, engagement metrics |
| **Reviews** | `/app/Modules/Reviews/` | (reads `contacts`, `job_visits`, `job_plans`) | Called from `pow-actions.php end_visit` | contact email/phone, Google review URL from `ops_settings` |
| **Referrals** | `/app/Modules/Referrals/` | `referral_links`, `referrals`, `referral_rewards` | Called from `pow-actions.php end_visit` | referral_code, referred_contact_id, reward_product_id |
| **Field Observations** | `/app/Modules/Products/` | `field_observations`, `observation_product_rules` | Rules evaluated by automation_runner | observation_type, recommended_product_id, auto_send flag |
| **Contracts** | `/app/Modules/Contracts/` | `contracts`, `job_plans` | `contract_renewal.php` (daily 1 AM) | renewal_date, billing_amount, renewal_increase_pct, contact_id |
| **Push Notifications** | `/app/Modules/` (inferred) | `device_tokens`, `push_queue` | `push-drain` cron | user_id, platform, notification title/body |

### 2.3 Automation Rule Trigger Types (currently supported)

```
quote_created         quote_viewed           quote_accepted
job_completed         invoice_overdue        client_inactive
territory             tagged                 season_change
weather_event         product_not_purchased  route_density_low
```

### 2.4 Automation Action Types (currently supported)

```
send_campaign    send_email    add_tag       remove_tag
create_task      add_nurture   notify_admin  schedule_sms
```

---

## 3. Source of Truth Map

### 3.1 Contact / Client Record

**Single source of truth:** `contacts` table

Key campaign fields:
- `email`, `phone`, `mobile` — delivery channels
- `receive_marketing` — master email consent (CASL)
- `receive_sms` — SMS consent gate (checked by `hasSmConsent()`)
- `consent_marketing_email`, `consent_sms`, `consent_quote_followup` — granular consent
- `consent_timestamp`, `consent_ip_address`, `consent_source` — CASL proof
- `last_service_date` — drives client_inactive trigger
- `review_request_sent_at`, `review_request_sent_count`, `review_request_opted_out` — review rate-limiting
- `lifecycle_stage` — funnel position

**Unsubscribe override:** `marketing_unsubscribes.email` (unique key) — checked by `campaign_sender.php` before every send. This is the hard opt-out that overrides all flags.

**Tags:** `contact_tags` (contact_id, tag) — many-to-many, any string value. Used by automation rules `tagged` trigger.

**Purchase history:** `contact_product_history` — materialized view refreshed by cron. Source of truth for `product_not_purchased` trigger.

### 3.2 Job Record & Lifecycle

```
quote_requests (inbound lead)
       ↓
  quotes (QUO-YYYY-NNNN)
       ↓ [accepted]
  job_plans (recurring plan definition)
       ↓
  job_visits (individual scheduled visit instances)
       ↓ [completed]
  visit_margin_snapshots (P&L snapshot at completion)
  visit_photos (before/after/during/crew)
       ↓
  invoices (INV-YYYY-NNNN)
       ↓ [paid]
  invoice_contacts (per-recipient tracking)
```

**Source of truth for job status:** `job_visits.status` — the visit is the atomic unit of field work.

**Completion hook point:** `pow-actions.php end_visit` — this is where all post-completion services fire. It is the current de-facto event hub.

### 3.3 Before / After Photos

**Source of truth:** `visit_photos` (photo_type: before|after|during|crew, linked to job_visits)

**Social pipeline connection:** `job_visits.social_draft_id` → `social_posts.id` — set when crew flags the visit. `media_derivatives.post_id` links the generated social card back to the post.

**Gap:** Photos uploaded without the flag (`upload_photo` action, non-flagged path) have no campaign connection at all.

### 3.4 Quote → Job → Invoice Chain

| Entity | Number Format | Key Status Field | Status Values |
|--------|--------------|-----------------|---------------|
| Quote Request | (none — internal) | `is_converted_to_quote` | 0 / 1 |
| Quote | `QUO-YYYY-NNNN` | `quotes.status` | draft, sent, accepted, rejected, expired |
| Job Plan | `JOB-YYYY-NNNN` | `jobs.status` | scheduled, in_progress, completed, cancelled, on_hold |
| Job Visit | (visit_number) | `job_visits.status` | scheduled, in_progress, completed, cancelled |
| Invoice | `INV-YYYY-NNNN` | `invoices.status` | draft, sent, paid, overdue, cancelled |

**Campaign attribution chain:** `campaign_sends.clicked_at` → `campaign_attribution` (quote_created) → `campaign_attribution` (job_booked) → `campaign_attribution` (invoice_paid) → `campaign_revenue_log`

### 3.5 Marketing Opt-In Status

**Source of truth — in order of precedence:**

1. `marketing_unsubscribes.email` — hard opt-out, always checked first
2. `contacts.receive_marketing` — master email consent flag
3. `contacts.consent_marketing_email` — CASL-specific email consent
4. `contacts.receive_sms` / `contacts.consent_sms` — SMS gate

**CASL proof fields:** `consent_timestamp`, `consent_ip_address`, `consent_source` — stored at time of consent collection (jobFlow form submission for new leads, explicit CRM action for existing contacts).

### 3.6 Geographic / Territory Data

**Source of truth:** `properties` table (address, city, province, lat, lng, site_contact_id)

Properties link to contacts via `site_contact_id`. Used by `territory` trigger type in automation rules for location-based campaigns (e.g., "we're coming to your neighborhood" posts).

---

## 4. Connection Gap Analysis

### 4.1 Events That Currently Fire Nothing in Marketing

| Event | Where It Happens | Current Behavior | Campaign Opportunity Lost |
|-------|-----------------|-----------------|--------------------------|
| **Invoice paid** | `record-payment.php` → sets `invoices.status = 'paid'` | DB update + activity_log entry only | "Thank you for your payment" email + referral request SMS |
| **Quote declined** | `customer/quote.php` decline branch | DB status change only | "We'd love your feedback" survey email |
| **Photo uploaded (non-flagged)** | `pow-actions.php upload_photo` | Photo saved to `visit_photos` only | "New before/after available — suggest post?" notification to admin |
| **New lead submitted** | `jobFlow-confirm.php` after DB insert | Quote request created, email to admin | Tag "hot_lead" / "warm_lead" / "cold_lead", trigger lead nurture automation |
| **Contract renewal** | `contract_renewal.php` daily cron | Renewal processed silently | "Your annual service renews in 30 days" client email |
| **Quote request unconverted (7+ days)** | (no cron checks this) | Nothing | "Still interested? Let's get you scheduled" follow-up |

### 4.2 Isolated Module Islands

| Module | Connected To | Not Connected To |
|--------|-------------|-----------------|
| **Contracts** | Job plans | Marketing engine (no campaign on renewal), no client notification |
| **Expenses** | Visit records (via visit_id) | Campaign data (material costs feed margin, but no campaign hooks) |
| **Field Observations** | `observation_product_rules` → email templates (via auto_send) | Social module (an observed issue could be a GBP post) |
| **Push Notifications** | Crew app, job reminders | Campaign engine (no push action type in automation_rules) |
| **Social Metrics** | `social_metrics_daily` | Marketing attribution (engagement data not linked to campaign_attribution) |
| **Quote Requests** | Admin notifications | Automation engine (lead_quality computed but no trigger fires) |

### 4.3 Duplicated / Inconsistent Data

| Issue | Detail |
|-------|--------|
| **Two SMS consent flags** | `receive_sms` and `consent_sms` both exist on `contacts`. The `hasSmConsent()` function should be the canonical check — verify which column it reads. |
| **Review request tracked in two places** | `contacts.review_request_sent_at` and `job_visits.review_request_sent_at` — the contact-level field gates the 30-day cooldown; the visit-level field tracks which visit triggered it. Both are needed but must be kept in sync. |
| **Social post source** | `social_posts.visit_id` links to the visit that generated the post. But `social_post_media.photo_type` is the photo classification. The before/after pipeline is split across three tables (visit_photos, social_post_media, social_posts) with no direct FK from visit_photos → social_posts. |
| **Campaign attribution gaps** | `campaign_sends` tracks email opens/clicks. `social_utm_links` tracks social click attribution. These are separate systems with no unified "contact saw X and then did Y" view. |

---

## 5. Proposed Connector Architecture

### 5.1 Design Principle

The existing automation engine is the right foundation. Do not replace it — extend it. The missing piece is a **write-side event log** that decouples operational modules from the marketing engine. Any module fires a named event; the runner reads it.

```
Operational Module
       │
       │  CampaignEventEmitter::fire(...)
       ▼
campaign_trigger_log   ←── single append-only table
       │
       │  automation_runner reads unprocessed rows
       ▼
automation_rules       ←── existing rule evaluation
       │
       ▼
queued_actions         ←── existing async queue
       │
       ▼
campaign_sender / social_publisher / sms gateway
```

### 5.2 New Table: `campaign_trigger_log`

Migration file: `database/migrations/1040_campaign_trigger_log.sql`

```sql
CREATE TABLE campaign_trigger_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    event_name    VARCHAR(100) NOT NULL
                  COMMENT 'invoice_paid, quote_declined, photos_uploaded, lead_submitted, contract_renewal, etc.',
    entity_type   VARCHAR(50)  NOT NULL
                  COMMENT 'invoice, quote, job_visit, lead, contract',
    entity_id     INT          NOT NULL,
    contact_id    INT          NULL,
    payload       TEXT         NOT NULL COMMENT 'JSON payload — full event context at fire time',
    source_module VARCHAR(100) NOT NULL
                  COMMENT 'invoices, quotes, jobflow, crew_app, contracts',
    fired_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at  DATETIME     NULL COMMENT 'NULL = pending processing by automation_runner',
    KEY idx_event        (event_name, fired_at),
    KEY idx_contact      (contact_id, fired_at),
    KEY idx_unprocessed  (processed_at, fired_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

**Design notes:**
- Append-only. Never delete rows (audit trail).
- `processed_at` is set by `automation_runner` after it evaluates rules for this event.
- `payload` is JSON-as-TEXT (MySQL 5.7 safe). Parse with `json_decode()` in PHP.
- Multiple rules can match one event — the runner marks `processed_at` after evaluating all matching rules, not per-rule.

### 5.3 New Service: `CampaignEventEmitter`

**File:** `/app/Modules/CampaignConnector/Services/CampaignEventEmitter.php`

```php
<?php
declare(strict_types=1);

class CampaignEventEmitter
{
    public static function fire(
        string $eventName,
        string $entityType,
        int    $entityId,
        ?int   $contactId,
        array  $payload,
        string $sourceModule
    ): void {
        try {
            $db = getDB();
            $db->prepare(
                'INSERT INTO campaign_trigger_log
                 (event_name, entity_type, entity_id, contact_id, payload, source_module, fired_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $eventName,
                $entityType,
                $entityId,
                $contactId,
                json_encode($payload),
                $sourceModule,
            ]);
        } catch (Throwable $e) {
            // Non-blocking — operational module must not fail if emitter fails
            error_log('[CampaignEventEmitter] ' . $e->getMessage());
        }
    }
}
```

**Key design rule:** The emitter wraps everything in a try/catch and swallows errors. An invoice payment cannot fail because the marketing table is down.

### 5.4 Four Missing Wirings

#### Wiring 1: Invoice Paid
**File:** `/public/crm/invoices/record-payment.php`  
**Where:** After the `status = 'paid'` UPDATE, before the redirect

```php
if ($newStatus === 'paid') {
    require_once APP_ROOT . '/Modules/CampaignConnector/Services/CampaignEventEmitter.php';
    CampaignEventEmitter::fire(
        'invoice_paid', 'invoice', $invoiceId, $contactId,
        [
            'invoice_number' => $invoice['invoice_number'],
            'amount'         => $invoice['total'],
            'payment_method' => $paymentMethod,
            'paid_at'        => date('Y-m-d H:i:s'),
            'balance_due'    => 0,
            'property_id'    => $invoice['property_id'] ?? null,
        ],
        'invoices'
    );
}
```

**Automation opportunity:** "Thank you for paying — here's your receipt + a referral link."

#### Wiring 2: Quote Declined
**File:** `/public/customer/quote.php`  
**Where:** The decline handling branch (after `status = 'declined'` UPDATE)

```php
require_once APP_ROOT . '/Modules/CampaignConnector/Services/CampaignEventEmitter.php';
CampaignEventEmitter::fire(
    'quote_declined', 'quote', $quoteId, $contactId,
    [
        'quote_number'  => $quote['quote_number'],
        'decline_reason'=> $reason ?? null,
        'declined_at'   => date('Y-m-d H:i:s'),
        'amount'        => $quote['total'],
        'service_type'  => $quote['service_type'],
    ],
    'quotes'
);
```

**Automation opportunity:** "We'd love to know why — quick feedback + a reduced-rate offer."

#### Wiring 3: Photos Uploaded
**File:** `/public/crm/api/pow-actions.php`  
**Where:** `upload_photo` action case, after successful DB insert

```php
require_once APP_ROOT . '/Modules/CampaignConnector/Services/CampaignEventEmitter.php';
CampaignEventEmitter::fire(
    'photos_uploaded', 'job_visit', $visitId, $visit['contact_id'] ?? null,
    [
        'photo_type'    => $photoType,  // before, after, during, crew
        'visit_id'      => $visitId,
        'property_id'   => $visit['property_id'] ?? null,
        'service_type'  => $visit['service_type'] ?? null,
        'photo_count'   => 1,
    ],
    'crew_app'
);
```

**Automation opportunity:** Admin notification — "New before/after photos available for [property] — approve for social post?"

#### Wiring 4: Lead Submitted
**File:** `/public/jobFlow/jobFlow-confirm.php`  
**Where:** After successful `quote_requests` INSERT

```php
require_once APP_ROOT . '/Modules/CampaignConnector/Services/CampaignEventEmitter.php';
CampaignEventEmitter::fire(
    'lead_submitted', 'lead', $quoteRequestId, $contactId,
    [
        'lead_quality'  => $leadQuality,  // hot, warm, cold
        'service_types' => $serviceTypes,
        'city'          => $city,
        'utm_source'    => $trackData['utm_source'] ?? null,
        'utm_campaign'  => $trackData['utm_campaign'] ?? null,
        'urgency'       => $urgency,
        'consent_sms'   => $consentSms,
        'consent_marketing' => $consentMarketing,
    ],
    'jobflow'
);
```

**Automation opportunity:** Tag by lead quality, trigger hot-lead urgent follow-up SMS, add cold lead to nurture sequence.

### 5.5 Automation Runner Extension

**File:** `/app/Modules/Marketing/Cron/automation_runner.php` (actual, not the shim)

Add a new processing step after existing rule evaluation:

```php
// NEW: Process campaign_trigger_log events
$unprocessed = $db->query(
    'SELECT * FROM campaign_trigger_log
     WHERE processed_at IS NULL
     ORDER BY fired_at ASC
     LIMIT 100'
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($unprocessed as $event) {
    $payload = json_decode($event['payload'], true);
    
    // Find automation rules matching this event_name
    $rules = $db->prepare(
        'SELECT * FROM automation_rules
         WHERE is_enabled = 1 AND trigger_type = ?'
    )->execute([$event['event_name']])->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rules as $rule) {
        processRule($rule, $event['contact_id'], $payload);
    }
    
    // Mark processed
    $db->prepare(
        'UPDATE campaign_trigger_log SET processed_at = NOW() WHERE id = ?'
    )->execute([$event['id']]);
}
```

### 5.6 New Trigger Types to Add to Automation Rules

Extend the `trigger_type` enum comment in `automation_rules` and add handling to the runner:

| New Trigger Type | Fires When | Key Payload Fields |
|-----------------|-----------|-------------------|
| `invoice_paid` | Invoice reaches `paid` status | amount, payment_method, paid_at |
| `quote_declined` | Quote declined via customer portal | decline_reason, service_type, amount |
| `photos_uploaded` | Crew uploads photo via PoW app | photo_type, visit_id, property_id |
| `lead_submitted` | JobFlow step 2 complete | lead_quality, service_types, utm_source |
| `contract_renewal` | Contract auto-renewed by cron | renewal_date, billing_amount, increase_pct |

**Migration:** `database/migrations/1041_extend_automation_trigger_types.sql` — ALTER TABLE comment only (no structural change needed; trigger_type is already VARCHAR(50)).

---

## 6. Opportunities Dashboard Specification

### 6.1 Purpose

A single CRM view (`/public/crm/campaign-opportunities_appstack.php`) that surfaces actionable campaign opportunities with a one-click "push to queue" button per item. This replaces the need for Tim to manually check multiple modules to find gaps.

### 6.2 Five Opportunity Panels

#### Panel 1: Uncampaigned Visit Completions

> Jobs completed in the last 14 days where no campaign action was queued after completion.

```sql
SELECT
    jv.id AS visit_id,
    jv.completed_at,
    jv.service_type,
    c.first_name, c.last_name,
    p.address, p.city,
    jv.actual_amount
FROM job_visits jv
JOIN job_plans  jp ON jp.id = jv.plan_id
JOIN contacts   c  ON c.id  = jp.contact_id
JOIN properties p  ON p.id  = jp.property_id
WHERE jv.status = 'completed'
  AND jv.completed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
  AND jp.contact_id NOT IN (
      SELECT contact_id FROM queued_actions
      WHERE created_at > jv.completed_at
        AND action_type IN ('send_campaign', 'send_email', 'schedule_sms')
  )
ORDER BY jv.completed_at DESC
LIMIT 20
```

**One-click action:** Queue the "Post-Job Thank You" email template for this contact.

#### Panel 2: Unposted Before/After Photos

> Visit photos uploaded in the last 7 days where no social post exists for that visit.

```sql
SELECT
    vp.id AS photo_id,
    vp.visit_id,
    vp.photo_type,
    vp.created_at AS uploaded_at,
    c.first_name, c.last_name,
    p.city,
    jp.service_type,
    vp.file_path
FROM visit_photos vp
JOIN job_visits jv ON jv.id = vp.visit_id
JOIN job_plans  jp ON jp.id = jv.plan_id
JOIN contacts   c  ON c.id  = jp.contact_id
JOIN properties p  ON p.id  = jp.property_id
WHERE vp.photo_type IN ('before', 'after')
  AND vp.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
  AND jv.id NOT IN (
      SELECT visit_id FROM social_posts
      WHERE visit_id IS NOT NULL
  )
ORDER BY vp.created_at DESC
LIMIT 20
```

**One-click action:** Open social post draft pre-filled with this visit's photos.

#### Panel 3: Paid Invoices Without Referral Ask

> Invoices paid in the last 30 days where no referral was sent to this contact.

```sql
SELECT
    i.id AS invoice_id,
    i.invoice_number,
    i.paid_at,
    i.total,
    c.id AS contact_id,
    c.first_name, c.last_name, c.email, c.receive_marketing
FROM invoices i
JOIN contacts c ON c.id = i.contact_id
WHERE i.status = 'paid'
  AND i.paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
  AND c.receive_marketing = 1
  AND c.id NOT IN (
      SELECT referrer_contact_id FROM referrals
      WHERE created_at > i.paid_at
  )
ORDER BY i.paid_at DESC
LIMIT 20
```

**One-click action:** Queue "Refer a Friend" email for this contact.

#### Panel 4: Inactive Marketable Contacts

> Contacts opted into marketing who haven't received any campaign email in 90+ days and haven't had service in 90+ days.

```sql
SELECT
    c.id AS contact_id,
    c.first_name, c.last_name, c.email,
    c.last_service_date,
    DATEDIFF(NOW(), c.last_service_date) AS days_inactive
FROM contacts c
WHERE c.receive_marketing = 1
  AND c.is_active = 1
  AND c.email IS NOT NULL
  AND c.email NOT IN (SELECT email FROM marketing_unsubscribes)
  AND (c.last_service_date IS NULL OR c.last_service_date < DATE_SUB(NOW(), INTERVAL 90 DAY))
  AND c.id NOT IN (
      SELECT contact_id FROM campaign_sends
      WHERE sent_at > DATE_SUB(NOW(), INTERVAL 90 DAY)
  )
ORDER BY c.last_service_date ASC
LIMIT 20
```

**One-click action:** Queue "We Miss You" re-engagement campaign.

#### Panel 5: Stale Unconverted Leads

> Quote requests submitted 7+ days ago that haven't been converted to a quote.

```sql
SELECT
    qr.id AS request_id,
    qr.created_at AS submitted_at,
    DATEDIFF(NOW(), qr.created_at) AS days_waiting,
    c.first_name, c.last_name, c.email,
    qr.service_types_requested,
    qr.urgency,
    qr.lead_quality
FROM quote_requests qr
JOIN contacts c ON c.id = qr.contact_id
WHERE qr.is_converted_to_quote = 0
  AND qr.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY qr.created_at ASC
LIMIT 20
```

**One-click action:** Send "Still interested?" follow-up email OR open a new quote for this request.

### 6.3 Dashboard UX Design

```
┌─────────────────────────────────────────────────────────────────┐
│  Campaign Opportunities            [Refresh]  [Send All Queued] │
├────────────────┬────────────────────────────────────────────────┤
│ ⬤ 3  Visits   │  Jobs completed — no follow-up sent            │
│    Uncampaigned│  ┌─────────────────────────────────────────┐   │
│                │  │ Smith, 123 Oak St · Lawn Mow · 3d ago   │   │
│ ⬤ 5  Photos   │  │ $145                    [Queue Email →]  │   │
│    Unposted    │  └─────────────────────────────────────────┘   │
│                │  ┌─────────────────────────────────────────┐   │
│ ⬤ 2  Invoices │  │ Chen, 456 Elm Ave · Hedge Trim · 6d ago  │   │
│    No Referral │  │ $280                    [Queue Email →]  │   │
│                │  └─────────────────────────────────────────┘   │
│ ⬤ 8  Inactive │                                                 │
│    Contacts    │                                                 │
│                │                                                 │
│ ⬤ 1  Stale    │                                                 │
│    Leads       │                                                 │
└────────────────┴────────────────────────────────────────────────┘
```

- Left column: panel selector with badge counts
- Right column: item cards with inline "Queue" action
- Each "Queue" button fires an AJAX call to `/crm/api/campaign-opportunities.php` that inserts a `queued_actions` row
- "Send All Queued" button triggers `automation_runner.php` via POST
- Badge counts refresh every 60 seconds via fetch

---

## 7. Plug-In Contract for Future Modules

### 7.1 The Problem

Currently, adding a new module to the campaign engine requires:
1. Editing `automation_runner.php` to add a new trigger type handler
2. Knowing the internal schema for `automation_rules` and `queued_actions`
3. No discovery mechanism — nothing tells you which modules are campaign-aware

### 7.2 PHP Interface

**File:** `/app/Modules/CampaignConnector/Contracts/CampaignAware.php`

```php
<?php
interface CampaignAware
{
    /**
     * Return the list of event names this module can emit.
     * These must match the event_name values passed to CampaignEventEmitter::fire().
     *
     * @return string[]
     */
    public static function registeredEvents(): array;

    /**
     * Return the payload schema for a given event name.
     * Keys are field names; values are type descriptions.
     * Used by the Opportunities Dashboard and rule editor for documentation.
     *
     * @param  string $eventName
     * @return array<string, string>  e.g. ['amount' => 'float', 'payment_method' => 'string']
     */
    public static function eventSchema(string $eventName): array;
}
```

### 7.3 Module Registration

**File:** `/app/Modules/CampaignConnector/campaign_modules.php`

A simple PHP array — no database table needed. The runner scans this file on startup.

```php
<?php
return [
    'invoices'  => \App\Modules\Invoices\InvoicesCampaignEmitter::class,
    'quotes'    => \App\Modules\Quotes\QuotesCampaignEmitter::class,
    'jobflow'   => \App\Modules\JobFlow\JobFlowCampaignEmitter::class,
    'crew_app'  => \App\Modules\Jobs\CrewAppCampaignEmitter::class,
    'contracts' => \App\Modules\Contracts\ContractsCampaignEmitter::class,
];
```

### 7.4 Example Implementation

**File:** `/app/Modules/Invoices/InvoicesCampaignEmitter.php`

```php
<?php
class InvoicesCampaignEmitter implements CampaignAware
{
    public static function registeredEvents(): array
    {
        return ['invoice_paid', 'invoice_overdue'];
    }

    public static function eventSchema(string $eventName): array
    {
        return match ($eventName) {
            'invoice_paid' => [
                'invoice_number' => 'string',
                'amount'         => 'float',
                'payment_method' => 'string (e_transfer|cash|cheque|credit_card|other)',
                'paid_at'        => 'datetime',
                'balance_due'    => 'float',
                'property_id'    => 'int|null',
            ],
            'invoice_overdue' => [
                'invoice_number' => 'string',
                'days_overdue'   => 'int',
                'amount_due'     => 'float',
            ],
            default => [],
        };
    }
}
```

### 7.5 What a New Module Must Do

To make a new module campaign-aware, a developer must:

1. Create an emitter class implementing `CampaignAware` in the module's directory
2. Register it in `campaign_modules.php`
3. Call `CampaignEventEmitter::fire()` at the appropriate event points in the module's PHP files
4. Add any new trigger types to the `automation_rules.trigger_type` comment and the runner's `$supportedTriggers` array

That's the entire contract. The runner, queue, sender, and attribution system handle everything else automatically.

---

## 8. Recommended Build Order

### Phase 1 — Event Bus Foundation (Est. 2–3 sessions)

**Goal:** Any module can fire a named event. Nothing breaks if it fails.

| Task | File(s) | Effort |
|------|---------|--------|
| Create `campaign_trigger_log` table | `database/migrations/1040_campaign_trigger_log.sql` | Small |
| Create `CampaignEventEmitter` service | `/app/Modules/CampaignConnector/Services/CampaignEventEmitter.php` | Small |
| Create `CampaignAware` interface | `/app/Modules/CampaignConnector/Contracts/CampaignAware.php` | Small |
| Wire `invoice_paid` event | `/public/crm/invoices/record-payment.php` | Small |
| Wire `quote_declined` event | `/public/customer/quote.php` | Small |
| Wire `photos_uploaded` event | `/public/crm/api/pow-actions.php` | Small |
| Wire `lead_submitted` event | `/public/jobFlow/jobFlow-confirm.php` | Small |
| Verify events appear in table | Manual test via CRM + DB check | Minimal |

**Deliverable:** Events accumulate in `campaign_trigger_log`. Automation runner not yet changed.

---

### Phase 2 — Engine Extension (Est. 2–3 sessions)

**Goal:** Automation rules can respond to the new event types.

| Task | File(s) | Effort |
|------|---------|--------|
| Add event log reader to automation_runner | `/app/Modules/Marketing/Cron/automation_runner.php` | Medium |
| Add new trigger types: `invoice_paid`, `quote_declined`, `photos_uploaded`, `lead_submitted` | automation_runner + migration comment | Small |
| Create starter automation rules in UI | CRM marketing page | Small |
| Test: invoice paid → "Thank you" email queued | End-to-end | Medium |
| Test: quote declined → "Feedback" email queued | End-to-end | Medium |
| Test: lead submitted hot → tagged "hot_lead" | End-to-end | Medium |

**Deliverable:** The existing automation engine now responds to 4 new events. No new UI needed yet.

---

### Phase 3 — Opportunities Dashboard (Est. 2–3 sessions)

**Goal:** Single view surfaces every campaign gap. One-click queues the action.

| Task | File(s) | Effort |
|------|---------|--------|
| Create opportunities API endpoint | `/public/crm/api/campaign-opportunities.php` | Medium |
| Build dashboard CRM page | `/public/crm/campaign-opportunities_appstack.php` | Medium |
| Add sidebar nav item | `/crm/includes/appstack_sidebar.php` | Small |
| Add badge counts to Dashboard widget | `/public/crm/dashboard_appstack.php` | Small |
| One-click "Queue Email" action | API endpoint + JS | Medium |
| One-click "Draft Social Post" action | API endpoint + JS | Medium |
| Auto-refresh badge counts | JS polling | Small |

**Deliverable:** Tim can open the Opportunities Dashboard and process every gap from one screen.

---

### Phase 4 — One-Click Approval UI (Est. 1–2 sessions)

**Goal:** Campaigns with `auto_send = 0` are reviewable and approvable in one click.

| Task | File(s) | Effort |
|------|---------|--------|
| Pending approvals panel (email preview) | `/public/crm/marketing/campaign-approvals_appstack.php` | Medium |
| Approve → trigger send immediately | API endpoint | Small |
| Reject → delete from queue with reason | API endpoint | Small |
| Social post approvals (already exists via `social_approvals` table) | Wire to same UI | Small |
| "Approve All" bulk action | JS + API | Small |

**Deliverable:** The "this looks good, publish" button. Every pending campaign — email, SMS, social — is reviewable and approvable from one screen.

---

### Phase 5 — Plug-In Contract & New Modules (Ongoing)

**Goal:** Any new module is campaign-aware from day one.

| Task | Effort |
|------|--------|
| Register module emitters in `campaign_modules.php` for all 4 wired modules | Small |
| Document the contract in this file (done) | Done |
| Wire `contract_renewal` event | Small |
| Wire `push_notification` action type into automation_rules | Medium |
| Social metrics → campaign attribution link | Medium |
| Unified "contact journey" view (all events for one contact) | Large |

---

## Appendix: Complete Table Reference for Campaign Connector

### Tables the Connector Reads

| Table | Key Columns for Campaigns |
|-------|--------------------------|
| `contacts` | receive_marketing, receive_sms, last_service_date, lifecycle_stage |
| `contact_tags` | tag (for `tagged` trigger) |
| `contact_product_history` | product_id, last_purchased (for `product_not_purchased` trigger) |
| `quotes` | status, sent_at, viewed_at, accepted_at, total |
| `quote_requests` | lead_quality, is_converted_to_quote, utm_source |
| `job_visits` | status, completed_at, service_type, visit_photos presence |
| `invoices` | status, paid_at, due_date, total |
| `products` | best_season, trigger_month, crew_talking_points |
| `field_observations` | observation_type, status, recommended_product_id |
| `social_posts` | visit_id (to detect unposted photos) |
| `referrals` | referrer_contact_id (to detect unprompted referrals) |

### Tables the Connector Writes

| Table | What Gets Written |
|-------|------------------|
| `campaign_trigger_log` | One row per operational event fired |
| `queued_actions` | One row per marketing action (email, SMS, tag) |
| `contact_tags` | When `add_tag` action executes |
| `automation_logs` | Rule evaluation results |
| `campaign_sends` | When `send_campaign` action executes |
| `activity_log` | When `notify_admin` action executes |

### New Tables Added by This Architecture

| Table | Migration | Purpose |
|-------|-----------|---------|
| `campaign_trigger_log` | `1040_campaign_trigger_log.sql` | Write-side event bus |

No other new tables required. All execution infrastructure (queued_actions, campaign_sends, automation_logs) already exists.
