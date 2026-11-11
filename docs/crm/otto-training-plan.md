# Otto — crew training: plan (awaiting Tim's approval)

Branch `feature/otto-training` (from `feature/otto-dispatcher-phase2`). Training belongs to Otto. A people/HR head comes later, only if hiring grows.
Same rules as before: Otto suggests ("Do it" / "Not now"); he never changes a schedule, assigns training to a crew member, or messages anyone on his own in v1.

## 1. What exists (checked in code)

**Two quiz systems, one certification system — nobody watches them.**

- **Daily Knowledge Quiz** (`/crm/quiz_appstack.php`, `app/Modules/Quiz/Api/{start,question,answer,finish,stats,categories,preshift}.php`, `QuizHelpers.php`):
  - `quiz_categories`, `quiz_questions` (`category_id`, `difficulty`), `quiz_options`;
  - `quiz_sessions` (user, category, `correct_count`, `month_year`) and `quiz_answers` (`question_id`, `is_correct`);
  - `quiz_user_mastery` (per user and question: `mastery_level`, `total_attempts`, `total_correct`, `next_review_at`);
  - `quiz_preshift_log` (the pre-shift quiz: `log_date`, `score_pct`);
  - plus badges, streaks and monthly prizes (`/crm/leaderboard_appstack.php`).
  - Tables come from the `public/crm/api/run-migration-quiz*.php` runners.
- **Certification** (`/crm/certification_appstack.php`, `app/Modules/Quiz/Api/certification.php`, `app/Modules/Quiz/Services/CertificationService.php`, migration 990):
  - `cert_tiers` (pass %, `recert_months`), `cert_courses`;
  - `cert_course_questions` (course ↔ quiz question);
  - `cert_exam_sessions` and `cert_exam_answers`;
  - `cert_records` (user × course: `status`, `best_score_pct`, `issued_at`, **`expires_at`**);
  - `cert_tier_achievements`.
  - Seeded courses: lawn-mowing, equipment-safety, whmis-ppe, basic-weed-pest, hedge-trimming, advanced-plant-id, client-comms, supervisor-field, plus an Acelepryn course.
- **The service ↔ certification mapping already exists:**
  - `cert_service_type_requirements` (`service_type_id` → `min_tier_level` + optional `course_id`). Seeded: tier 1 for lawn care, maintenance and cleanups; tier 2 + hedge-trimming course for hedges; tier 2 for garden; tier 3 + supervisor for landscaping.
  - `CertificationService::canWorkServiceType()` (`:308`) and `getAllowedServiceTypes()` (`:368`) answer "may X do Y" (advisory, `warning_only`).
  - **Nothing calls them.** The API mode `service_type_check` (`certification.php:135`) has no caller, so scheduling never checks.
  - There is no page to edit the mapping (migration seeds only).
- **Expiry is never enforced.** `findExpiringCerts()` (`:510`) and `expireOverdueCerts()` (`:541`) have no caller and no cron. Once a cert passes its `expires_at` it still reads `status = 'active'`. Otto must judge validity by `expires_at`, not `status`.
- **Quality signals that exist:**
  - `job_plans.photo_types_required` (e.g. ["before","after"]) with `photos_block_completion`. `VisitLifecycleService.php:655` blocks completion only when that flag is set. Proof photos come from `VisitPhotoService` (categories before / after / during / issue). A completed visit missing a required category means a skipped shot.
  - `visit_notes.note_type = 'issue'`, and photos of category `issue`.
  - Skipped visits (`job_visits.status = 'skipped'`).
- **Not usable as quality signals:**
  - `job_visits.is_flagged` / `visit_endorsements` are **positive** (crew "hearted" a visit).
  - `client_feedback` hangs off the dropped legacy `jobs` table, so it is dead.
  - There is no rework/re-visit marker.
- **Who did a visit:** `job_time_entries.user_id` (best evidence), `calendar_stops.crew_id` + `calendar_stop_crew`, `job_visits.assigned_crew_id`.
- **Equipment users** (from phase 2): `equipment.assigned_user_id`, and job-timer time on visits whose service uses a mower or blower (`service_equipment`).

## 2. Unknowns — read-only checks in Tim's Chrome

1. Usage per user over 90 days: `quiz_sessions`, `quiz_preshift_log`, `cert_exam_sessions`. Does the crew use these at all?
2. `cert_records` per user × course, with `expires_at`. How many have passed `expires_at` but still read active?
3. Active `cert_courses` with their question counts (`cert_course_questions`). Which modules are empty?
4. `cert_service_type_requirements` rows on production, and whether `job_plans.service_type` values equal `service_types.slug`. The phase-2 data suggests plans may hold labels; if so Otto matches slug or label.
5. How many plans set `photo_types_required` and `photos_block_completion`; how proof photos are categorised on production (`media_links.category`).
6. Count of `visit_notes` with `note_type = 'issue'` and of `issue` photos over 90 days. Is there enough signal?
7. Which users are crew (role) versus office, so office staff aren't flagged.
8. Does any service warrant a course that doesn't exist yet? Tim's list, e.g. aeration.

## 3. What Otto suggests

Each suggestion goes on his card and into his brief. Actions in v1 create a **task for Tim**, or are a recorded decision. Crew are never assigned training or told anything automatically.

1. **`training_gap`** — a crew member scheduled (next 7 days) on a service whose requirement they don't meet. Requirement = tier, plus a course whose `expires_at` is in the future.
   - Example: "Nigel is on 2 hedge trimming visits Thursday but hasn't passed Hedge Trimming."
   - Actions:
     - *Make me a task* (talk to Nigel / book the module);
     - *He's shadowing someone certified*. This becomes a lesson: don't flag him on stops shared with a certified person.
     - *Not now*.
   - Key `otto:training:<user>:<service>`. Priority 1 if the visit is today or tomorrow, else 2.
2. **`training_quality`** — the same person and service with ≥3 problem visits in 60 days. Problems are missing required photos, issue notes or issue photos, or skips. The suggestion points at the course mapped to that service.
   - Key `otto:training-quality:<user>:<service>`. Priority 3.
   - Words name the evidence, e.g. "3 hedge visits without an after photo since Sep 1".
3. **`training_topic`** — quiz questions most of the crew gets wrong.
   - Rule: over 30 days, ≥3 different people answered and ≥50% of them got it wrong (daily quiz + exam answers).
   - Grouped by category; the top 3 become "crew meeting topics". *Add to meeting* makes a task for Tim with the questions listed.
   - Key `otto:training-topic:<category>:<YYYY-MM>`. Priority 3.
4. **`safety_refresher`** — someone who uses mowers or blowers has an equipment-safety or WHMIS cert that has expired or expires within 30 days, or has none.
   - *Remind me* makes a task for Tim due on the expiry date.
   - Key `otto:safety:<user>:<course>`. `value` = the expiry date (Y-m-d), so Charlie's compliance calendar can place it.
   - Priority 1 if expired, else 2.

**Tim's ticks:** on the Bylaw rules page, a new "Training for this service" column per service type. It writes `cert_service_type_requirements` (course + min tier). This is the existing table; no new mapping is created.

**Learning:**
- shadowing pairs (`otto_lessons` scope `training`);
- which topics Tim took to a meeting;
- **certs earned after Otto flagged a gap** — the real learning signal, matched by a `cert_records.issued_at` after the decision.

**Badges:**
- *Coach*: 5 gaps closed by a cert earned after Otto's flag.
- *Safety first*: 10 refreshers booked before expiry.

**Brain units:** services mapped to training, shadowing pairs learned, topics covered, certs earned after a flag.

**Question:** "Nigel isn't certified for hedge trimming but always works with Sam, who is. Is that fine while he learns?" yes → shadowing lesson.

## 4. Build (after approval)

- `app/Modules/Operations/Services/TrainingRules.php` (pure: requirement met, problem counting, topic threshold, refresher window) and `TrainingService.php` (DB reads, task creation). New kinds added to `OpsDeskService` / `OttoActionService` / card JS.
- The mapping column on `/crm/ops/municipal-rules.php` via `/crm/api/dispatch.php` (`?mode=save_training_map`).
- **No new tables are needed.** It uses the cert/quiz tables, `otto_suggestions`, `otto_lessons` and `tasks`. Migration 1159 stays spare (only an index if production row counts call for one).
- Unit tests for every pure rule (expiry edges, the ≥3/60-day count, the ≥50%/≥3-people topic rule, the shadowing suppression); stub render and screenshot.

## 5. Decisions for Tim

1. Tasks for **you** (recommended), or assign the course to the crew member as a task? The second reaches the crew.
2. Thresholds: 3 problem visits in 60 days; topic at ≥50% wrong across ≥3 people; refreshers 30 days ahead.
3. Should a lapsed cert (past `expires_at`) count as missing? Recommended yes, which means equipment-safety lapses after 12 months.
4. Also schedule `expireOverdueCerts()` as a nightly cron so the Certification page shows the truth? This changes cert status data, so it is your call.
5. Which services need courses that don't exist yet (aeration?), and who writes them.

## Built (2026-10-06) — Tim's decisions applied

- Every "Do it" makes a **task for Tim** (assigned to whoever clicked); nothing reaches the crew. "Fine, they're shadowing" records a lesson instead.
- Nightly cron `expire_certs` (2:15 AM) runs `CertificationService::expireOverdueCerts()`; Otto also treats any cert past `expires_at` as missing.
- Training mapping: "Training each service needs" table on `/crm/ops/municipal-rules.php` writes `cert_service_type_requirements` (`?mode=save_training_map`).
- Office role `admin` is never flagged. Brain "services mapped" counts only mappings made from 2026-10-06 (not the migration-990 seeds).
- No migration needed (1159 still spare). Renders: `otto-training-card.png`, `otto-training-rules-page.png`.
