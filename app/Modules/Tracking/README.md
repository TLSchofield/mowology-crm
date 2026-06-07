# Tracking Module — Trackimo Truck GPS

Real-time GPS for the company truck via Trackimo's REST API. Pings get
written to `vehicle_location_pings`, with the most recent ping mirrored to
a JSON cache file for fast reads from the day-view map.

## Components

| File | Role |
|---|---|
| `Services/TrackimoService.php` | OAuth2 client, REST calls |
| `Services/TruckLocationService.php` | DB writes/reads + cache file |
| `Cron/trackimo_poll.php` | Cron entry point |
| `Api/truck-location.php` | GET — current position (cache read) |
| `Api/truck-trail.php` | GET — today's pings as a polyline source |

## Configuration

Five constants must be defined in `public/app_config/secrets.php`:

```php
define('TRACKIMO_CLIENT_ID',     '...');   // from the Trackimo developer portal
define('TRACKIMO_CLIENT_SECRET', '...');
define('TRACKIMO_REDIRECT_URI',  'https://mowology.ca/crm/api/trackimo-oauth-callback.php');
define('TRACKIMO_USERNAME',      '...');   // Trackimo account login
define('TRACKIMO_PASSWORD',      '...');
define('TRACKIMO_DEVICE_ID',     '...');   // Tracker ID from the Trackimo URL
```

The `TRACKIMO_REDIRECT_URI` is registered with Trackimo but **never actually
navigated to**. The OAuth flow runs entirely server-side: we POST a login,
intercept the 302 from `/oauth2/auth` to read the code out of the Location
header, then exchange the code for an access token. No browser dance, no
callback handler.

## Auth Flow

```
1. POST /api/internal/v2/user/login    {username, password}    → JSESSIONID cookie
2. GET  /api/v3/oauth2/auth?...        + cookie, no-follow     → 302 Location: ...?code=XYZ
3. POST /api/v3/oauth2/token           {client_id, client_secret, code}
                                                                → access_token (+ expires_in)
```

Tokens are cached to `app/Storage/cache/trackimo_token.json` with their
expiry. On any 401 the cache is dropped and the call is retried once.

## Cron Setup

```cron
* * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Tracking/Cron/trackimo_poll.php >/dev/null 2>&1
```

The script runs every minute but **decides internally whether to poll the
API** based on time of day:
- 06:00–20:00 Pacific: every minute
- Off-hours: every 5 minutes

The script always exits 0 so cron failures don't spam the cPanel mail relay
— inspect `app/Storage/logs/trackimo.log` for errors.

## Storage Files (gitignored)

```
app/Storage/cache/trackimo_token.json          OAuth access_token + expiry
app/Storage/cache/trackimo_last_position.json  Most recent ping (read by /api)
app/Storage/logs/trackimo.log                  Append-only operational log
```

If a file is missing or corrupt the service falls back gracefully — the
token cache rebuilds on the next call, and the position cache falls back
to a `SELECT … ORDER BY recorded_at DESC LIMIT 1`.

## Schema

`vehicle_location_pings` — one row per ping. Dedup is enforced by a unique
key on `(device_id, recorded_at)` so a stationary truck doesn't produce
duplicate rows. See `database/migrations/1060_vehicle_location_pings.sql`.

## Future Work (deferred)

- **Webhooks** — `api@trackimo.com` can enable a webhook on the account. If
  enabled, build `Api/trackimo-webhook.php` that calls
  `TruckLocationService::savePing($id, $payload, 'webhook')` and drop the
  poll cron.
- **SQS push** — heavier infrastructure (AWS account, IAM, SDK); only
  worth it for a fleet of 10+ trucks.
- **`vehicles` table** — needed once there's more than one truck. The
  current `device_id` is hardcoded to `TRACKIMO_DEVICE_ID`.
- **Geofence-triggered job arrival** — once the Trackimo geozones API is
  wired, jobs can auto-transition to `in_progress` when the truck enters a
  property's perimeter.
- **Purge cron** — `TruckLocationService::purgeOlderThan(90)` is ready
  to wire when the table grows past ~6 months of data.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `Missing required Trackimo constant` | A define is absent or empty in `secrets.php` |
| `Trackimo login failed: HTTP 401` | Wrong username/password in `secrets.php` |
| `Trackimo did not return auth code` | Redirect URI mismatch (must EXACTLY match the one registered on plus.trackimo.com) |
| `Truck marker is grey on the map` | Last ping is >10 min old — check the cron is running and `trackimo.log` |
| Marker never appears | `getLastKnownPosition()` returns null — no pings recorded yet. Run the cron manually once. |
