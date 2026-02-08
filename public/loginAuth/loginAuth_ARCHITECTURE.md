# loginAuth — Architecture (Mowology Web App)

This document defines the authentication, session, and authorization architecture
for the Mowology web application.

It is the single source of truth for how login, sessions, roles, and access
control must be implemented and used across the system.

---

## Purpose

- Provide a single, secure authentication system
- Support multiple user roles:
  - client
  - staff
  - admin
- Centralize session handling and authorization logic
- Expose stable guard/helper functions for the rest of the app
- Ensure paths and includes are deterministic and environment-safe

---

## Folder Layout (AUTHORITATIVE)

/loginAuth/
  ARCHITECTURE.md
  PROMPT.MD
  auth.php
  login.php
  logout.php
  forgot_password.php
  reset_password.php

/app_config/
  config.php
  session_config.php
  secrets.php

---

## Responsibility Boundaries

### /loginAuth/ OWNS
- Login and logout flows
- Session creation, rotation, and destruction
- Password verification and reset workflows
- Role and status authorization
- Guard/helper functions used by other folders

### /loginAuth/ MUST NOT OWN
- Business logic (jobs, quotes, invoices, CRM actions)
- Profile editing or domain-specific data changes
- Database migrations or schema ownership
- CMS logic or UI theming
- Environment configuration or secrets

---

## Configuration Location (NON-NEGOTIABLE)

All runtime configuration lives in:

/app_config/
  config.php
  session_config.php
  secrets.php

Rules:
- Auth files must never redefine config or secrets
- Secrets must never be echoed, logged, or exposed
- /app_config should not be web-accessible
- All paths must be absolute and anchored

---

## Include Contract

Every file inside /loginAuth/ MUST include:

require_once dirname(__DIR__) . '/app_config/session_config.php';
require_once dirname(__DIR__) . '/app_config/config.php';

Any protected page elsewhere in the app MUST include:

require_once __DIR__ . '/../loginAuth/auth.php';

Rules:
- Always anchor paths using __DIR__ and dirname(__DIR__)
- Never rely on the working directory
- Never use fragile relative paths without anchoring

---

## Session Model

Canonical session key:

$_SESSION['auth']

Required fields:
- user_id (int)
- role (client | staff | admin)
- email (string)
- status (active | disabled)
- login_at (unix timestamp)

Optional:
- name (string)

Fail-closed rule:
If the session is missing, malformed, or invalid → user is unauthorized.

---

## Authorization Model

Required helpers (defined in auth.php):
- require_login()
- require_role(array|string $roles)
- current_user()
- is_logged_in()
- auth_redirect_after_login()
- logoutUser()

Authorization must be enforced server-side.
Redirects alone are never sufficient security.

---

## User Model (Recommended)

Preferred approach is a single unified identity model:
- users table (or view)
  - id
  - email
  - password_hash
  - role
  - status
  - optional foreign keys (client_id, staff_id)

Legacy role-specific tables may exist, but must map into one canonical session identity.

---

## Security Requirements (MANDATORY)

- Passwords: password_hash() / password_verify() only
- Rotate session ID on login:
  session_regenerate_id(true);
- CSRF protection on all POST actions
- Rate limiting / throttling on authentication endpoints
- Uniform error messages (no email enumeration)
- Deny by default: missing role/status/session = unauthorized

---

## Password Reset Flow

1. User submits email
2. Generate secure random token
3. Store hashed token with expiry
4. Email or log reset link:
   /loginAuth/reset_password.php?token=...
5. Verify token, update password, invalidate token

---

## Redirect Policy

All post-login redirects are centralized in:
auth_redirect_after_login()

Default mapping:
- client → /clientDashboard/
- staff  → /staffDashboard/
- admin  → /adminDashboard/

Actual paths may be overridden explicitly.

---

## Stability Rules

- Avoid sweeping refactors
- Prefer additive, minimal changes
- Preserve backward compatibility where possible
- Document any cross-folder impact clearly

---

## Long-Term Improvements (Optional)

- Move /app_config outside web root entirely
- Add centralized auth event logging
- Add IP-based throttling
- Introduce environment loaders (dev / stage / prod)

---

## Architectural Goal

This system should be:
- Predictable
- Boring
- Secure
- Hard to misuse
- Easy for humans and AI to reason about
