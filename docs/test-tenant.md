# Test tenant — Phase 0, step 1: the outbound gate

A second copy of this app (its own subdomain, its own database) must be **impossible** to use to
message a real client, charge a real card, or leak into Mowology. Production behaviour is
byte-identical when the new constants are absent.

Two classes carry it, both in `app/Core/`, both with no side effects at load:

| Class | Job |
|---|---|
| `App\Core\OutboundGuard` | the one gate every email / SMS / push / cron / Stripe exit passes through |
| `App\Core\TenantIdentity` | the few identity values a tenant may change, with today's values as fallbacks |

## Constants (secrets.php of the test tenant)

Production's `secrets.php` defines **none** of these. Undefined = today's behaviour.

| Constant | Type | Meaning |
|---|---|---|
| `TENANT_MODE` | `'live'` \| `'test'` | Undefined or `'live'` = production, every guard method is a pass-through. **Anything else is treated as `'test'`** (a typo fails safe). |
| `SANDBOX_MAIL_SINK` | email | Where every test-mode email is redirected. No sink → emails are not sent at all, only logged. |
| `SANDBOX_ALLOW_RECIPIENTS` | string[] | Exact addresses allowed to receive **real** messages in test mode: emails (`owner@example.com`) and SMS gateway addresses (`6045550100@msg.telus.com` — the carrier matters, one entry per carrier you want). |
| `SANDBOX_ALLOW_CRONS` | string[] | Cron names allowed to run in test mode (names as passed to `cronGate`, listed below). |
| `SANDBOX_LOG_DIR` | path | Where the JSON-lines log goes. Default `app/Storage/sandbox/` (git-ignored). |
| `TENANT_MAIL_FROM` | email | default From address — fallback `no-reply@mowology.ca` |
| `TENANT_MAIL_FROM_NAME` | string | default From display name — fallback `Mowology` |
| `TENANT_OFFICE_EMAIL` | email | Reply-To / Return-Path — fallback `office@mowology.ca` |
| `TENANT_NOTIFY_EMAIL` | email | owner notification inbox (`NOTIFICATION_EMAIL`) — fallback `mowology@icloud.com` |
| `TENANT_NOTIFY_SMS` | gateway address | owner SMS gateway (`NOTIFICATION_SMS_GATEWAY`) — fallback `7788469273@msg.telus.com` |
| `STRIPE_WEBHOOK_SECRET_IS_TEST` | bool | `true` declares `STRIPE_WEBHOOK_SECRET` to be the Stripe **test-mode** signing secret. Without it the webhook endpoint processes **nothing** on a test tenant (every delivery is acknowledged with 200 and ignored). Never set it on production. |
| `TENANT_SESSION_SAVE_PATH` | path | PHP session folder, **test mode only**; used when it exists and is writable — otherwise `/home/mowology/tmp`. Live / undefined mode consults no constant and no environment variable. `app/Core/session_config.php` can run before secrets are loaded, so the tenant should **also** `SetEnv TENANT_MODE test` and `SetEnv TENANT_SESSION_SAVE_PATH …` in its `.htaccess` (both files read the env vars when the constants are missing; `OutboundGuard` reads the `TENANT_MODE` env var too, so the two can never disagree). |
| `CRON_LOCK_PREFIX` | `[a-z0-9_]` | cron lock-file prefix in `sys_get_temp_dir()` — fallback `mowology_cron_`. Two tenants on one host with the same prefix would block each other's crons. |

Example for a test tenant:

```php
define('TENANT_MODE', 'test');
define('SANDBOX_MAIL_SINK', 'tenant-test@example.com');
define('SANDBOX_ALLOW_RECIPIENTS', ['tim@example.com', '7788469273@msg.telus.com']);
define('SANDBOX_ALLOW_CRONS', []);               // nothing that sends or charges runs by itself
define('TENANT_MAIL_FROM', 'no-reply@test.example.com');
define('TENANT_MAIL_FROM_NAME', 'Ittai Test');
define('TENANT_OFFICE_EMAIL', 'office@test.example.com');
define('TENANT_NOTIFY_EMAIL', 'tenant-test@example.com');
define('TENANT_NOTIFY_SMS', '7788469273@msg.telus.com');
define('TENANT_SESSION_SAVE_PATH', '/home/<account>/tmp-test');
define('CRON_LOCK_PREFIX', 'tenanttest_cron_');
define('STRIPE_SECRET_KEY', 'sk_test_…');        // a live key is refused outright
define('STRIPE_WEBHOOK_SECRET', 'whsec_…');      // from the Stripe TEST dashboard …
define('STRIPE_WEBHOOK_SECRET_IS_TEST', true);   // … and say so, or the webhook ignores everything
```

`SANDBOX_ALLOW_RECIPIENTS` and `SANDBOX_ALLOW_CRONS` must be **arrays**. A string (or anything else) is a configuration error: it is logged once (`kind: config`) and treated as an empty list, so nothing is allowed.

## What happens in `TENANT_MODE = 'test'`

| Channel | Behaviour |
|---|---|
| **Email** | Redirected to `SANDBOX_MAIL_SINK`; the original To **and** Cc are dropped; subject becomes `[TEST -> original@recipient, cc@recipient] original subject`; a yellow banner naming the original recipients is prepended to the body; attachments are kept. If every recipient is on `SANDBOX_ALLOW_RECIPIENTS` the message goes out unchanged. No sink → nothing is sent, the caller still sees success, the log records it. A redirected message that falls back from PHPMailer to `mail()` meets the guard a second time; the sink counts as an allowed recipient, so it is not marked twice. |
| **SMS** (carrier email-to-SMS) | Never sent unless the exact gateway address is on `SANDBOX_ALLOW_RECIPIENTS`. Otherwise logged (`would have texted …`) and the carrier is reported as `Bell (sandbox)` etc. so callers see the usual success shape and flows continue. |
| **Push** (FCM / APNs) | Never sent; logged; reported to the queue as delivered so it does not retry five times. |
| **Crons** that send or charge | Refuse to run (`exit 0` with a log line, JSON when called over the web) unless named in `SANDBOX_ALLOW_CRONS`. Even an allowed cron's messages still pass the email/SMS/push gates above. |
| **Stripe** | `OutboundGuard::stripeKey()` throws `RuntimeException` before any client exists unless the key starts with `sk_test_` / `rk_test_`. |
| **Stripe webhook** (`public/api/stripe/webhook.php`) | Gated at the very top, before the SDK loads and before signature verification: unless `STRIPE_WEBHOOK_SECRET_IS_TEST === true` every delivery gets HTTP 200 and no work. After verification, an event with `livemode === true` (or no `livemode` field) gets HTTP 200 and no work — before routing, before any DB access. |
| **Fail closed** | **Everything** — allow-list, sink and test keys included — is blocked, with a `tripwire` log line and a loud `error_log`, when `TENANT_MODE` is not live and: `DB_NAME` (the constant `Database::connect()` builds its DSN from — there is no other source) is **undefined or empty**; or `DB_NAME` is `mowology_landscape_crm`; or the request host, normalised (lower-case, port and trailing dots removed, IPv6 brackets stripped), is `mowology.ca` / `www.mowology.ca`; or the sandbox log directory cannot be created / written (checked once per process with a real append before any decision — a decision that cannot be recorded is not taken). |

In `'live'` mode no method writes a file, logs, or alters an argument. `tests/Unit/Core/OutboundGuardTest.php` proves the same inputs come out identical.

## Every outbound exit and how it is guarded

| Exit | Guard |
|---|---|
| `app/Services/Messaging/MessagingService.php` — `_sendViaPhpMailer`, `_sendEmailMultiAttach`, `_sendSimpleHtmlEmail`, `_sendEmailWithAttachment`, `_sendEmailWithAttachments` | `OutboundGuard::email()` at the top of each (the actual `mail()` / `$mail->send()` sites — `sendEmail()` can only reach these) |
| same file — `sendSms()` (10 carrier gateways), `testSmsGateway()` | `OutboundGuard::sms()` per gateway address |
| `app/Services/Messaging/SmtpMailer.php` (legacy, loaded only by `public/crm/includes/smtp_mailer.php`) — `_sendViaPhpMailer`, `sendSimpleHtmlEmail`, `sendEmailWithAttachment` | `OutboundGuard::email()` |
| `app/Services/Messaging/EmailHelper.php` (legacy, loaded only by `public/crm/includes/email_helper.php`) — `sendCrmEmail()` | `OutboundGuard::email()` before both `mail()` calls |
| `app/Services/Notifications.php` and its live twin `public/includes/notifications.php` — `sendEmailNotification()`, `sendSMSNotification()` | `OutboundGuard::email()` / `::sms()`; constants from `TenantIdentity` |
| `app/Services/Push/FcmService.php::send()`, `app/Services/Push/ApnsService.php::send()` | `OutboundGuard::push()` right after `isConfigured()`; `PushDispatcher` and `QuoteViewNotifier` only reach these |
| Stripe — `InvoiceService`, `InvoiceRefundService`, `StripePayoutService`, `AutopayService`, `public/api/stripe/webhook.php` (×2), `public/crm/api/stripe/create-payment-intent.php` (×2), `public/crm/api/backfill-stripe-cards.php`, `public/crm/api/stripe-payout-probe.php`, `public/crm/companies/api-save-company-card.php`, `public/customer/api/{autopay-setup,confirm-saved-card,invoice-payment-intent}.php` | the key passes through `OutboundGuard::stripeKey()` at all 14 sites |
| Crons — **charge:** `autopay_charge`, `contract_billing`; **campaign:** `campaign_sender`, `automation_runner`, `invoice_reminders`, `invoice_overdue`, `optin_resend_sender`, `seasonal_triggers`, `mia_email_daily`, `mia_channels_weekly`; **sender:** `forgot_clockout_sms`, `auto_clockout`, `tracking_silent_alert`, `charlie_morning_brief`, `quote_request_reminder`, `push_drain` | `OutboundGuard::cronGate(name, kind)` immediately after `config.php` loads |

`tests/Unit/Core/OutboundExitsScanTest.php` scans **every** PHP file under `app/` and `public/` (the files above included). Each exit it finds — a direct `mail(`, `new PHPMailer` / `$mail->send()`, a curl / socket / HTTP read inside a function that targets a Twilio / Stripe / FCM / APNs host, a Stripe key set or client built, any `\Stripe\X::call()`, the webhook's `constructEvent` — must be preceded by an `OutboundGuard` call **inside the same function body** (brace-matched from the token stream: methods, closures, nested braces) or, for top-level script code, earlier at top level; a Stripe key must be wrapped on the very line it is used. It also insists the adapters above still contain exits (so a rewrite cannot hide one), that the webhook is gated before the SDK loads and again before routing, and that every listed cron keeps its top-level gate. Failures name `file:line`.

Everything else that sends (quotes, invoices, reminders, Sam/Yui/Mia/Otto/Charlie, special requests, consent, reviews, portal) calls `sendEmail()` / `sendSms()` / `PushDispatcher`, so it is covered by the rows above.

## Reading the sandbox log

One JSON object per line, one file per day: `app/Storage/sandbox/outbound-YYYY-MM-DD.jsonl` (or `SANDBOX_LOG_DIR`).

```json
{"time":"2026-10-21T14:02:11-07:00","kind":"email","target":"client@example.com, pm@example.com","action":"redirected","preview":"Your invoice INV-2026-0042","sink":"tenant-test@example.com"}
{"time":"2026-10-21T14:02:12-07:00","kind":"sms","target":"6045550199@txt.bellmobility.ca","action":"blocked","preview":"Your crew is on the way","reason":"would have texted 6045550199@txt.bellmobility.ca"}
{"time":"2026-10-21T14:05:00-07:00","kind":"cron","target":"autopay_charge","action":"refused","preview":"","cron_kind":"charge","reason":"not in SANDBOX_ALLOW_CRONS"}
```

`kind` ∈ `email | sms | push | cron | stripe | webhook | config | tripwire`; `action` ∈ `redirected | blocked | refused | ignored | error | blocked-all`. Every field is control-character-stripped (a newline in a recipient cannot forge a second record) and capped — preview 200 characters with HTML stripped, any other string 300, labels 40; bodies and device tokens are never written. Every line also goes to `error_log` as `OutboundGuard[kind] action: target (reason)`, cleaned the same way.

`OutboundGuard::summary(int $limit = 50)` returns `mode`, `tripped`, `log_dir`, `counts` (per `kind:action`) and the newest entries, read-only, for the admin page that comes later. Quick look from a shell: `tail -n 50 app/Storage/sandbox/outbound-$(date +%F).jsonl | jq .`

## What is NOT covered (and why it is still safe today)

These outbound HTTP calls are **not** gated by `OutboundGuard`. They are inert on a test tenant only because the tenant's `secrets.php` leaves their keys blank / undefined — keep it that way until they get their own gate:

- Anthropic (`ANTHROPIC_API_KEY`) — Penny / Sam / Yui / Mia / Otto / Charlie prompts, receipt line-item Claude tier
- Meta Graph (Facebook / Instagram publishing), Google Business Profile, Google Search Console, Google OAuth
- Google Maps / geocoding and route calls
- Trackimo GPS, weather (NOAA / ECCC — read-only, harmless), QuickBooks (`QBO_*`)
- OCR (Vision / Tesseract), plant ID
- IMAP mailbox polling (read-only, but points at the owner's real iCloud mailbox — the tenant must get its own `MailboxConfig`)
- the iMessage text bridge (runs on the iMac, not on the server)

Also **not** done in this step, deliberately — the wider "assumes Mowology" cleanup. What was seen while wiring:

- `MessagingService.php` still has `office@mowology.ca` / `https://mowology.ca` as **template content** (`company_email`, `company_website`, `SITE_URL` fallbacks at ~649/748/871/888), the `X-Mailer: Mowology CRM` header, `'Mowology'` as the default `$fromName` / `$senderName` parameter value, and `(778) 846-9273` inside SMS templates (`SmsTemplates.php`)
- `SmtpMailer.php` / `EmailHelper.php` / both `notifications.php` keep their literal `From:` defaults (legacy, parameter defaults — guarded, not re-branded)
- `AutopayService` sets `setAppInfo('Mowology CRM', …, 'https://mowology.ca')`
- `ApnsService` default topic `ca.mowology.crm-field`; FCM project from `FCM_PROJECT_ID`
- `CronLock` and `PushDispatcher` MySQL `GET_LOCK('mowology_push_drain')` — a DB-level lock, per database, so already tenant-safe
- AI prompts, email/PDF templates, the public site copy, `SITE_*` constants and the `cms_sites` resolver (~135 files mention Mowology by name)

## Recipe stub — creating a tenant (to be completed by the lead)

1. cPanel: subdomain `test.<domain>` → its own document root; its own MySQL database + data-only user (and a DDL user for the migration runners); its own `tmp-test/` session folder.
2. Deploy the same tree. `secrets.php` for the tenant = production's keys **removed** (Anthropic, Meta, Google, Trackimo, QBO, IMAP), `STRIPE_SECRET_KEY = sk_test_…`, the webhook signing secret from the Stripe **test** dashboard, and the constants in the table above with `TENANT_MODE = 'test'`.
3. `.htaccess`: `SetEnv TENANT_MODE test` and `SetEnv TENANT_SESSION_SAVE_PATH /home/<account>/tmp-test`.
4. Load the schema (`database/COMPLETE_DATABASE_SCHEMA_CLEAN.sql` + migrations), then seed test clients with **fake** emails / phones only.
5. Open any page: confirm `OutboundGuard::summary()['tripped']` is `null` and `mode` is `test`; send one test quote and confirm it lands in the sink with the `[TEST -> …]` subject and the banner.
6. Cron lines for the tenant: none of the gated crons until each is deliberately allow-listed.
7. Later phases: admin page over `summary()`, per-tenant `MailboxConfig`, the identity cleanup above.
