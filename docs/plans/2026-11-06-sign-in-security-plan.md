# Sign-in security — captcha, two-step sign-in, phishing (plan, 2026-11-06)

Tim: "work on 2 factor auth and captcha for the site entry … either way we should secure it." Prompted by the Intuit app
assessment (MFA and captcha answered No; Intuit's publishing requirements). Ittai will hold several businesses' books and
QuickBooks access, so an owner's password alone is not enough.

## Today (mapped 2026-11-06, file:line)
- Web: `public/loginAuth/login.php` and `public/crm/login_secure.php` → `loginUser()` (`app/Core/Auth/auth.php:275-370`).
  Throttle: 5 per email+IP / 20 per IP / 15 per email in 10 min (`auth.php:192`, `:222`). 30-min idle timeout, 9-h session. No remember-me.
- Phones: `public/api/auth/token.php` — password → JWT, 30 days (mobile), 8 h (web). No refresh token. Sign-up also issues one
  (`signup-confirm.php`); `tracking-token.php` turns a web session into a 16-h token.
- No MFA columns or tables. No captcha on sign-in; reCAPTCHA v3 only on forgot-password (`ResetCaptcha`, fails open without keys).
- Email: SPF and DKIM fine on mowology.ca and ittai.ca, but **DMARC is `p=none` on both** — mail faking our addresses is still delivered.

## What we build (phishing-resistant first)
1. **Captcha on web sign-in** — reCAPTCHA v3 (invisible, keys already in secrets.php) on both sign-in forms. A low score does
   not block outright; it asks for the second step (or, for someone without one, a short wait). Google unreachable → sign-in still
   works (logged), so an outage can't lock everyone out. Phones: no captcha (not possible in the native apps); the throttle and the
   second step cover them.
2. **Two-step sign-in, web** —
   - **Authenticator app (TOTP, 6-digit, RFC 6238)**: written in PHP, tested against the RFC's own test values; secret stored
     encrypted with the existing vault (like the QuickBooks keys). Setup shows a QR code + the key as text.
   - **10 one-time backup codes** (stored hashed).
   - **"Trust this device for 30 days"** (signed cookie, hashed in the database, revocable).
   - After the password is right, the session holds only "password OK, second step owed" until the code passes — no CRM page opens.
3. **Who must use it** — owners/admins, the accountant login, and anyone allowed to change settings or see the books. Crew phones
   untouched in phase 1. Rollout: optional first (Tim + rehearsal2), then required per business by a switch, so nobody is locked out.
   Lost phone: backup code, or another admin of that business resets it; Mowology support as last resort.
4. **Phones** — `token.php` answers "second step needed" with a 5-minute challenge; the app asks for the code; the token is issued
   only after it. Same old-app shield as the onboarding steps: required only once an app version that can show the screen is out.
5. **Passkeys (Face ID / Touch ID / phone lock)** — the phishing-proof option: a fake site cannot use them. Web first
   (WebAuthn, small PHP library, relying party `ittai.ca` so one passkey works for every business address), then the phone apps
   (need `apple-app-site-association` / `assetlinks.json` on ittai.ca and app updates).
6. **Phishing hygiene** — email the owner when a new device signs in ("wasn't you? reset your password"); DMARC from `p=none` to
   `p=quarantine` after a week of reports to dmarc@mowology.ca show our own mail passing, then `p=reject` (Tim, cPanel Zone Editor).

## Unknowns, and how each is settled (cheapest first)
| Unknown | Settled by |
|---|---|
| reCAPTCHA v3 site key allows `*.ittai.ca` (keys were made for mowology.ca) | Tim checks the key's domain list in Google's reCAPTCHA admin; until then the check skips on ittai.ca (logged) |
| TOTP maths correct | RFC 6238 appendix B test values as unit tests |
| QR code without a heavy library | small composer QR package (PHP 7.4+) or the `otpauth://` link + key as text first |
| New tables exist in every business database | migration run per business (backlog item "migrations per business"); code treats missing tables as "nobody enrolled" — never as a pass for someone enrolled |
| Passkey relying-party ID across `*.ittai.ca` and mowology.ca | WebAuthn spec (RP ID = registrable suffix); Mowology keeps its own on mowology.ca |
| Lockout | opt-in first; per-business switch; admin reset path tested before any "required" switch |

## Steps (each proven before the next)
1. Captcha on web sign-in → test locally, deploy, sign in on rehearsal2.
2. TOTP + backup codes + trusted device on web, opt-in → unit tests, Tim enrols on rehearsal2 first.
3. Required-for-owners switch per business + admin reset → tested on rehearsal2, then Mowology on Tim's go.
4. Phone API + Android/iPhone screens (old-app shield).
5. Passkeys web, then phones.
6. New-device email; DMARC tightening (Tim).
Codex is out until 11 Nov: web-builder builds, code-reviewer reviews; sign-in security merits a stronger review (Fable) on Tim's say.
