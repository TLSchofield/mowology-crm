# Android ↔ iOS parity — the daily routine (Tim, 2026-11-20)

iOS is the spec. Every user-visible iPhone change gets a native Android half. `scripts/parity/` does the mechanical part daily.

**Daily 06:30 (launchd `ca.mowology.parity-daily`, installed by `scripts/parity/install.sh`):**
1. `detect` — user-visible iOS commits (feat/fix/perf touching Swift) on `origin/feature/language` since 2026-11-14 → ledger.
2. `queue` — up to `PARITY_MAX` (2) oldest gaps go to `scripts/codex-build.sh` (Codex writes Android on `codex/native`, tests, self-reviews). Codex out of usage (exit 75 / fresh LIMIT note) → gaps wait as `deferred`. No `claude` CLI exists on this Mac, so there is no unattended Claude fallback: Claude agents pick up deferred work in a chat.
3. `shots` — same screen on the iPhone simulator and an Android device, side-by-side PNG + rough layout score. Screens are listed in `scripts/parity/screens.json`; add an entry per screen. Skipped (and said so) when no emulator is running; `PARITY_START_ANDROID=1` starts one via `scripts/local-android.sh up`.
4. `report` — `~/.mowology-parity/reports/<date>.md` + a macOS notification when something needs review.

**Never automatic:** merging `codex/native`, `scripts/android-field-release.sh`, pushing. Claude reviews the diff (sign-in, GPS, queues first), then Tim approves a release.

**Ledger** `~/.mowology-parity/ledger.json` (outside the repo — the nightly checkout resets). Statuses: new → ported-unreviewed → done; deferred, blocked, skipped.
- `python3 ~/.mowology-parity/bin/parity.py status` — what is open
- `python3 ~/.mowology-parity/bin/parity.py mark <sha8> done "released 2.2.x"` — after Claude's review and release

**Known limits:** a port task for a change Android already has costs one Codex run (the task says "if already present, change nothing"). Screenshot pairs differ in status bar and fonts, so the layout score is a pointer, not a verdict — look at the pair PNG.
