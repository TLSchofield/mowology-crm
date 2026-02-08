# LoginAuth System – Mowology

## Purpose
Handles authentication for admins, staff, and clients.

## Folder rules
- Pages users visit (UI, email links) live in /loginAuth
- POST-only handlers live in /loginAuth/forms
- /forms files must never be accessed directly

## Session & config
- Sessions are started in /app_config/session_config.php
- Config is loaded via auth.php (do not require config directly)
- Never call startSession()

## Password flows
- forgot_password.php → reset_password.php (token-based, permanent)
- change_password.php for logged-in users

## Database
- Use getDB() (wrapper for Database::pdo())
- Password column: users.password_hash
