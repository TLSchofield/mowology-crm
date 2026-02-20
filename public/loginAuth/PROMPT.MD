# PROMPT.MD — loginAuth (Mowology Web App)

You are working inside the **mowologyWebApp** project.

This prompt governs how you reason, what you are allowed to change, and how you output code
when working on authentication, sessions, and access control.

---

## 0) Read Order (MANDATORY)

1) Read `/loginAuth/loginAuth_ARCHITECTURE.md`
2) Then read **only** the files required for the task
3) If context is missing, ask for the **smallest possible file(s)**

Do not preload or reason about the entire repository.

---

## 1) Scope Guardrails

You may ONLY modify files inside:
- `/app/Core/Auth/` (canonical auth logic)
- `/loginAuth/` (login pages, shim)
- `/app_config/`

Unless explicit permission is given:
- Do NOT refactor the whole app
- Do NOT introduce frameworks or third-party auth libraries
- Do NOT redesign UI flows

### Includes & Sessions (MANDATORY)
- Never `require_once 'config.php'` directly inside `/loginAuth` pages.
- Always include only: `require_once __DIR__ . '/auth.php';`
  (the shim forwards to `/app/Core/Auth/auth.php`)
- Sessions must be started only by `/app/Core/session_config.php` (no `startSession()` anywhere).

### Auth File Locations
- **Canonical logic:** `/app/Core/Auth/auth.php` — all functions live here
- **Compatibility shim:** `/public/loginAuth/auth.php` — DO NOT add logic here
- When editing auth functions, edit the canonical file, not the shim

---

## 2) Working Method

- Make **minimal, surgical changes**
- Prefer extending existing logic over rewriting flows
- Centralize shared logic in `/app/Core/Auth/auth.php`
- If you detect a security issue:
  - Explain briefly
  - Propose the smallest safe fix
- Never duplicate helpers across files
- Login pages handle POST inline (same file serves form + processes submission)
- Token landing pages (reset link) live at `/loginAuth/reset_password.php`

### Naming Convention
- Canonical functions use **camelCase** (e.g., `isLoggedIn()`, `getCurrentUser()`)
- Snake_case wrappers exist for compatibility (e.g., `is_logged_in()`, `current_user()`)
- When adding new helpers, define the camelCase version first, then add a snake_case wrapper

---

## 3) Output Rules

- When modifying code: output **THE FULL FILE**
- Preserve formatting and comments
- Add comments only where they prevent future mistakes
- Never invent or echo real credentials, secrets, or keys
- Never print passwords, tokens, or hashes to the browser (even for debugging)

### Password Reset Rules
- Password reset tokens must be hashed in DB and compared with `hash('sha256', $token)`
- All POST forms must include CSRF and verify via `verifyCSRFToken()`
- On password reset success, delete token rows for that user

### Rate Limiting Rules
- Rate limiting uses `login_attempts` table (migration 401)
- Check `isLoginRateLimited($email)` BEFORE calling `loginUser()`
- Rate limiting fails open if the table doesn't exist (logs error, allows login)
- Never block legitimate users permanently — window is 10 minutes

---

## 4) Folder Layout (AUTHORITATIVE)

```
/app/Core/Auth/
  auth.php              ← Canonical auth (all functions + wrappers)
  authz.php             ← RBAC permissions

/loginAuth/
  ARCHITECTURE.md
  PROMPT.MD
  auth.php              ← Shim → /app/Core/Auth/auth.php
  login.php
  logout.php
  forgot_password.php
  reset_password.php

/app_config/
  config.php
  secrets.php
  session_config.php
```
