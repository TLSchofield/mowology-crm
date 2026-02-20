# jobFlow Automation

## Phase 3 — Lead Enrichment & Automation

### Objective

Reduce manual admin time by automatically enriching each lead with structured metadata at the moment of submission. This enables:
- Priority routing (high-value leads flagged for immediate follow-up)
- Lead tagging for CRM filtering
- Frequency suggestion for plan creation
- Upsell interest tracking
- Full UTM attribution chain

---

## Lead Classification (`helpers/classification.php`)

### `classifyJobType(array $data): string`

Rule-based classification of the primary job type:

| Condition | Returns |
|-----------|---------|
| 2+ services selected | `'multi-service'` |
| lawn_care or maintenance only | `'lawn'` |
| hedge_trimming only | `'hedge'` |
| cleanup only | `'cleanup'` |
| snow_removal only | `'snow'` |
| No services | `'unknown'` |

**AI extension point:** Replace the body of this function with an API call to Claude Haiku using `$data['description']` as the prompt context. The return type (string) stays the same. Example:

```php
// Future AI integration:
$response = $anthropicClient->messages->create([
    'model' => 'claude-haiku-4-5-20251001',
    'max_tokens' => 10,
    'messages' => [['role' => 'user', 'content' =>
        'Classify this landscaping request as one word: lawn, hedge, cleanup, snow, or multi-service. ' .
        'Request: ' . $data['description']
    ]]
]);
return trim($response->content[0]->text);
```

### `classifyValueTier(array $data): string`

Scoring system returns `'high'` / `'medium'` / `'low'`:

| Signal | Points |
|--------|--------|
| Commercial property | +3 |
| Strata property | +2 |
| Residential | +1 |
| Large lawn | +3 |
| Medium lawn | +2 |
| Small lawn | +1 |
| Has irrigation | +2 |
| Per service selected (max 3) | +1 each |
| Urgency = asap | +2 |
| Per upsell selected (max 2) | +1 each |

Thresholds: score ≥ 9 → high, score ≥ 5 → medium, < 5 → low.

### `isHighPriorityLead(array $data): bool`

Returns true if ANY of:
- Value tier = 'high'
- Urgency = 'asap'
- Property has irrigation
- Commercial or strata property

Priority leads get `[PRIORITY]` appended to the activity_log entry and are flagged in the internal notification email.

### `suggestFrequency(array $data): string`

Returns: `'weekly'` | `'biweekly'` | `'one-time'` | `'seasonal'`

Logic:
- Non-lawn services → 'one-time' (or 'seasonal' for snow removal)
- Irrigated lawn → 'weekly' (faster growth)
- Large lawn → 'weekly'
- Otherwise → 'biweekly'

### `classifyLead(array $data): array`

Composite function returning all classification data:

```php
[
    'job_type'            => 'lawn',
    'value_tier'          => 'high',
    'is_priority'         => true,
    'suggested_frequency' => 'weekly',
    'tags'                => ['lawn', 'high-value', 'priority', 'irrigated'],
]
```

---

## CRM Record Creation

Every confirmed submission creates (or updates) records atomically in a single database transaction:

1. **Contact** — find by phone OR email, create if new
2. **Property** — find by address, create if new; update geocodes if already exists
3. **Quote Request** — always new; includes service CSV, urgency, classification notes in `project_description`
4. **Consent Log** — 3 rows: quote_followup, marketing_email, sms
5. **Contact consent columns** — updated to reflect latest consent state
6. **Activity Log** — includes value tier and priority flag

The `project_description` field carries classification metadata as a structured suffix:
```
[Classification: job_type:lawn tier:high freq:weekly upsells:fertilizer lawn_size:large irrigated:1 priority:1]
```

This allows the CRM team to see classification data immediately without a separate table, while keeping the schema unchanged.

---

## UTM Attribution

Captured on first GET to getQuote.php, stored in `$_SESSION['jf_track']`:

| Parameter | Source |
|-----------|--------|
| `utm_source` | GET param |
| `utm_medium` | GET param |
| `utm_campaign` | GET param |
| `utm_content` | GET param |
| `utm_term` | GET param |
| `src` | GET param (internal source e.g. 'strata-landing') |
| `promo` | GET param |
| `referrer` | `HTTP_REFERER` (first visit only) |

All sanitised: alphanumeric + hyphens + underscores, max 100 chars each.

Passed through session to confirm.php and used for:
- `logLeadEvent()` UTM parameters → ROI tracking tables
- `quoteSource` string → stored in `quote_requests.source`

---

## Internal Notification Enhancement

The notification email sent to the owner (`sendQuoteRequestNotifications()`) now receives additional fields from the automation layer:

```php
[
    'value_tier'  => 'high',        // 'high' | 'medium' | 'low'
    'is_priority' => true,          // bool
    'upsells'     => 'Fertilizer Program, Hedge Trimming Add-on',
]
```

This allows the owner to instantly see which leads need same-day follow-up without opening the CRM.

---

## Future Extension Points

### AI Classification

- `classifyJobType()` is the AI hook — replace body with LLM call
- All callers receive a string return value; no interface changes needed
- Suggested model: `claude-haiku-4-5-20251001` (fast, low-cost, sufficient for simple classification)

### Automated Follow-up Sequences

The `suggested_frequency` and `job_type` from `classifyLead()` can drive:
- Automatic email template selection (weekly plan vs one-time)
- CRM task creation with follow-up date
- Plan proposal pre-population

### Lead Scoring in CRM

The `value_tier` and `is_priority` flags are currently stored only in `project_description` as text. When ready:
1. Add `value_tier VARCHAR(10)` and `is_priority TINYINT(1)` columns to `quote_requests`
2. Update the INSERT in confirm.php to use these columns
3. CRM views can then filter/sort by priority without text parsing

### Webhook / Zapier Integration

Add after the DB commit in confirm.php (non-blocking try/catch):

```php
// Future: POST to webhook for Zapier/Make automation
$webhookPayload = json_encode([
    'contact' => ['name' => $data['name'], 'phone' => $data['phone']],
    'classification' => $classification,
    'source' => $quoteSource,
]);
// fire-and-forget cURL POST to webhook URL
```
