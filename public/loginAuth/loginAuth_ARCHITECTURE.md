# loginAuth — Architecture (Mowology Web App)

This document defines the authentication, session, and authorization architecture
for the Mowology web application.

It is the single source of truth for how login, sessions, roles, and access
control must be implemented and used across the system.

---

## Purpose

- Provide a single, secure authentication system
- Support multiple user roles:
  - staff
  - admin
- Centralize session handling and authorization logic
- Expose stable guard/helper functions for the rest of the app
- Ensure paths and includes are deterministic and environment-safe

---

## Folder Layout (AUTHORITATIVE)

```
/app/Core/Auth/
  auth.php              ← Canonical auth logic (all helpers, rate limiting, snake_case wrappers)
  authz.php             ← RBAC permission system

/public/loginAuth/
  ARCHITECTURE.md       ← This file
  PROMPT.MD             ← AI editing rules for auth scope
  auth.php              ← Compatibility shim → forwards to /app/Core/Auth/auth.php
  login.php             ← Login page (inline POST handling + UI)
  logout.php            ← Logout handler
  forgot_password.php   ← Forgot password (inline POST, sends reset email)
  reset_password.php    ← Token-based password reset (GET validates token, POST updates password)

/app_config/
  config.php
  session_config.php
  secrets.php
```

### File Roles

| File | Role |
|------|------|
| `/app/Core/Auth/auth.php` | **Canonical** — all auth functions, rate limiting, CSRF, activity log, snake_case wrappers |
| `/public/loginAuth/auth.php` | **Compatibility shim** — searches upward for `/app/Core/paths.php`, then `require_once` the canonical auth. DO NOT add logic here. |
| `/public/loginAuth/login.php` | Login form page. Handles POST inline (validates CSRF, checks rate limit, calls `loginUser()`). Self-contained CSS. |
| `/public/loginAuth/forgot_password.php` | Forgot password form. Handles POST inline. Generates token, sends email via native `mail()`. |
| `/public/loginAuth/reset_password.php` | Token landing page. GET validates token, POST updates password. |
| `/public/loginAuth/logout.php` | Calls `logoutUser()` and redirects. |

---

## Responsibility Boundaries

### /loginAuth/ OWNS
- Login and logout flows
- Session creation, rotation, and destruction
- Password verification and reset workflows
- Role and status authorization
- Guard/helper functions used by other folders
- Login rate limiting (brute-force protection)

### /loginAuth/ MUST NOT OWN
- Business logic (jobs, quotes, invoices, CRM actions)
- Profile editing or domain-specific data changes
- Database migrations or schema ownership
- CMS logic or UI theming
- Environment configuration or secrets

---

## Configuration Location (NON-NEGOTIABLE)

All runtime configuration lives in:

```
/app_config/
  config.php
  session_config.php
  secrets.php
```

Rules:
- Auth files must never redefine config or secrets
- Secrets must never be echoed, logged, or exposed
- /app_config should not be web-accessible
- All paths must be absolute and anchored

---

## Include Contract

The compatibility shim at `/public/loginAuth/auth.php` forwards to the canonical
`/app/Core/Auth/auth.php`. The canonical file loads session and config from
`/app/Core/` (which are themselves shims or canonical copies).

Any protected page elsewhere in the app MUST include:

```php
require_once __DIR__ . '/../loginAuth/auth.php';
```

This single include provides: session bootstrap, database access (`getDB()`),
all auth helpers, CSRF functions, rate limiting, and RBAC permissions.

Rules:
- Always anchor paths using `__DIR__` and `dirname(__DIR__)`
- Never rely on the working directory
- Never use fragile relative paths without anchoring

---

## Session Model

Session variables are stored as flat keys in `$_SESSION`:

| Key | Type | Set By |
|-----|------|--------|
| `user_id` | int | `loginUser()` |
| `user_email` | string | `loginUser()` |
| `user_name` | string | `loginUser()` |
| `user_role` | string | `loginUser()` — values: `admin`, `staff` |
| `login_time` | int | `loginUser()` — unix timestamp |
| `last_activity` | int | `checkSessionTimeout()` — updated each request |
| `csrf_token` | string | `generateCSRFToken()` — 64 hex chars |

Fail-closed rule:
If the session is missing, malformed, or invalid, the user is unauthorized.

---

## Authorization Model

Canonical helpers (defined in `/app/Core/Auth/auth.php`, camelCase):

| camelCase (canonical) | snake_case (wrapper) | Purpose |
|----------------------|---------------------|---------|
| `isLoggedIn()` | `is_logged_in()` | Check session |
| `requireLogin()` | `require_login()` | Guard — redirect if not authenticated |
| `getCurrentUser()` | `current_user()` | Returns `['id','email','name','role']` or `null` |
| `isAdmin()` | `is_admin()` | Check admin role |
| `loginUser($email, $pw)` | `login_user($email, $pw)` | Authenticate + create session |
| `logoutUser()` | `logout_user()` | Destroy session |
| `hashPassword($pw)` | `hash_password($pw)` | Bcrypt hash |
| `generateCSRFToken()` | `generate_csrf_token()` | Create/get CSRF token |
| `verifyCSRFToken($t)` | `verify_csrf_token($t)` | Validate CSRF token |
| `checkSessionTimeout($s)` | `check_session_timeout($s)` | Enforce idle timeout |
| `logActivity(...)` | `log_activity(...)` | Write to activity_log |

Both naming conventions are supported. Canonical code uses camelCase.
Snake_case wrappers exist for compatibility and are guarded by `function_exists()`.

Authorization must be enforced server-side.
Redirects alone are never sufficient security.

---

## Rate Limiting

Added Feb 2026 to protect against brute-force login attacks.

### How It Works

| Setting | Value |
|---------|-------|
| Max attempts | 5 (constant `LOGIN_MAX_ATTEMPTS`) |
| Time window | 10 minutes (constant `LOGIN_WINDOW_SECONDS`) |
| Tracked by | email + IP address |
| Storage | `login_attempts` table (migration 401) |

### Flow

1. User submits login form
2. `login.php` calls `isLoginRateLimited($email)` before `loginUser()`
3. If rate-limited: shows "Too many login attempts" error, does not attempt auth
4. If not rate-limited: proceeds to `loginUser()`
5. On failed login: `loginUser()` calls `recordFailedLogin($email)`
6. On successful login: `loginUser()` calls `clearLoginAttempts($email)`

### Rate Limiting Functions

| Function | Purpose |
|----------|---------|
| `isLoginRateLimited($email, $ip)` | Check if blocked (>= 5 attempts in 10 min) |
| `recordFailedLogin($email, $ip)` | Insert row into `login_attempts` |
| `clearLoginAttempts($email, $ip)` | Delete rows for email+IP on success |
| `purgeExpiredLoginAttempts()` | Cleanup old rows (call from cron) |

All rate limiting functions fail open — if the `login_attempts` table doesn't
exist yet, they log an error and allow the login attempt to proceed.

### Schema (migration 401)

```sql
CREATE TABLE login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_ip (email, ip_address),
    INDEX idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## Inline POST Handling

Login, forgot password, and reset password pages handle POST submissions inline
(same file serves GET form and processes POST). This is intentional:

- Simpler to reason about (one file = one flow)
- No routing framework needed
- CSRF validation happens at the top of each POST block
- Redirects on success (POST-Redirect-GET pattern)

The `/forms/` directory mentioned in earlier docs is legacy. Current flows use
inline POST in the page files themselves.

---

## User Model

Single unified identity model:
- `users` table
  - `id` (int, PK)
  - `email` (varchar, unique)
  - `password_hash` (varchar, bcrypt)
  - `full_name` (varchar)
  - `role` (enum: 'admin', 'staff', 'user')
  - `is_active` (tinyint, default 1)
  - `last_login` (datetime, nullable)

---

## Security Requirements (MANDATORY)

- Passwords: `password_hash()` / `password_verify()` only
- Rotate session ID on login: `session_regenerate_id(true)`
- CSRF protection on all POST actions
- Rate limiting on login endpoint (5 attempts / 10 minutes per email+IP)
- Uniform error messages (no email enumeration)
- Deny by default: missing role/status/session = unauthorized
- Prepared statements for all database queries

---

## Password Reset Flow

1. User submits email on `forgot_password.php`
2. Generate secure random token: `bin2hex(random_bytes(32))`
3. Store SHA256-hashed token with 1-hour expiry in `password_reset_tokens`
4. Email reset link: `/loginAuth/reset_password.php?token=...`
5. `reset_password.php` verifies token hash, enforces expiry
6. On valid POST: update password (min 12 chars), delete all tokens for user
7. Always show generic success message (no email enumeration)

---

## Redirect Policy

After login, redirect to `DASHBOARD_URL` (default: `/crm/dashboard_appstack.php`).

Constants defined in auth.php:
- `LOGIN_URL` → `/loginAuth/login.php`
- `LOGOUT_URL` → `/loginAuth/logout.php`
- `DASHBOARD_URL` → `/crm/dashboard_appstack.php`

---

## Stability Rules

- Avoid sweeping refactors
- Prefer additive, minimal changes
- Preserve backward compatibility where possible
- Document any cross-folder impact clearly
- Rate limiting fails open if the table is missing

---

## Long-Term Improvements (Optional)

- ~~Add IP-based throttling~~ (done — Feb 2026, email+IP rate limiting)
- Move /app_config outside web root entirely
- Add centralized auth event logging
- Introduce environment loaders (dev / stage / prod)
- Add account lockout after sustained brute-force attempts

---

## Architectural Goal

This system should be:
- Predictable
- Boring
- Secure
- Hard to misuse
- Easy for humans and AI to reason about
