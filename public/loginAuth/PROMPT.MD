# PROMPT.MD — loginAuth (Mowology Web App)

You are working inside the **mowologyWebApp** project.

This prompt governs how you reason, what you are allowed to change, and how you output code
when working on authentication, sessions, and access control.

---

## 0) Read Order (MANDATORY)

1) Read `/loginAuth/ARCHITECTURE.md`
2) Then read **only** the files required for the task
3) If context is missing, ask for the **smallest possible file(s)**

Do not preload or reason about the entire repository.

---

## 1) Scope Guardrails

You may ONLY modify files inside:
- `/loginAuth/`
- `/app_config/`

Unless explicit permission is given:
- Do NOT refactor the whole app
- Do NOT introduce frameworks or third-party auth libraries
- Do NOT redesign UI flows

### Includes & Sessions (MANDATORY)
- Never `require_once 'config.php'` directly inside `/loginAuth` pages.
- Always include only: `require_once __DIR__ . '/auth.php';`
- Sessions must be started only by `/app_config/session_config.php` (no `startSession()` anywhere).

---

## 2) Working Method

- Make **minimal, surgical changes**
- Prefer extending existing logic over rewriting flows
- Centralize shared logic in `/loginAuth/auth.php`
- If you detect a security issue:
  - Explain briefly
  - Propose the smallest safe fix
- Never duplicate helpers across files
- `/loginAuth/forms/` is POST-only handlers (no UI, no direct visits, must redirect)
- Token landing pages (reset link) must be one level up (e.g., `/loginAuth/reset_password.php`)

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

---

## 4) Folder Layout (AUTHORITATIVE)

/loginAuth/
auth.php
login.php
logout.php
forgot_password.php
reset_password.php
change_password.php
/forms/
login_submit.php
forgot_password_submit.php
change_password_submit.php

/app_config/
config.php
secrets.php
session_config.php

