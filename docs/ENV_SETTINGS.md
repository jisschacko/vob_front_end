# .env settings - Vendor Onboarding Platform (VOB)

Every key in `.env.example`, with the application it belongs to and what it does. A test (`tests/Feature/EnvSettingsDocTest.php`) fails when a key is
added to `.env.example` without a row here. The Admin Panel repo should keep the same kind of list (see `docs/ADMIN_PANEL_CHANGES.md`, entry 3), so the two
applications can be read side by side.

Application column: **VOB** = this app (vendor front end), **Both** = a value that must be set consistently in VOB and the Admin Panel.

## Connection to the Admin Panel

| Variable | App | Purpose | Notes |
|---|---|---|---|
| `ADMIN_API_BASE_URL` | VOB | Admin API base, including the `/api/v1` prefix | e.g. `http://127.0.0.1:8000/api/v1`. The path is part of the HMAC-signed path |
| `ADMIN_API_HMAC_SECRET` | Both | Shared secret that signs every call (`API_HMAC_SECRET` on the Admin side) | Owner generates (`openssl rand -base64 48`); never committed |
| `ADMIN_API_RSA_PUBLIC_KEY_PATH` | VOB | Admin's RSA **public** key (`vob_public.pem`) used to encrypt login / password headers | Relative to the project root. Prefer `storage/app/keys/`, not a web-served folder |
| `ADMIN_API_RSA_KEY_ID` | Both | Id of that key pair (`API_RSA_KEY_ID` on the Admin side) | e.g. `k1` |
| `ADMIN_API_TIMEOUT` | VOB | Seconds to wait for the Admin API | default 15 |
| `ADMIN_API_RETRIES` | VOB | Extra attempts for GET / PUT / DELETE on connection failure or 5xx (never POST) | default 2 |

## Behaviour switches (development / demo)

| Variable | App | Purpose | Notes |
|---|---|---|---|
| `VOB_ENFORCE_REQUIRED` | VOB | `false` = VOB does not block Submit on missing mandatory fields | Admin counterpart: `TOT_ENFORCE_REQUIRED`. Always on when `APP_ENV=production`. Formats are always validated |
| `VOB_ENFORCE_DOCUMENTS` | VOB | `false` = VOB does not block Submit on missing documents / unverified bank | Admin counterpart: `TOT_ENFORCE_DOCUMENTS`. Always on in production |
| `VOB_SKIP_THIRD_PARTY` | VOB | `true` = skip every third-party service: Cashfree bank verification (reported to the Admin as verified with a `DEMO-` reference) and S3 (documents stored on the local disk). Also: PAN, GST number and bank details are not validated and show a green "Verified" (see DemoLenientFields). Yellow banner on every page | Demo / testing only. Always off in production. Admin counterpart: `TOT_SKIP_THIRD_PARTY` (to be added) |
| `VOB_DISPLAY_TIMEZONE` | VOB | Timezone for "Draft saved ..." and submitted times | default `Asia/Kolkata` |
| `VOB_SESSION_IDLE_MINUTES` | VOB | Vendor signed out after this many minutes without a request | default 30 (the Admin token also has a 30 minute idle limit) |
| `VOB_SESSION_ABSOLUTE_MINUTES` | VOB | Vendor signed out this long after signing in, even if active | default 480 (Admin token: 8 h) |
| `VOB_MASTERS_CACHE_MINUTES` | VOB | Cache time for `/masters` dropdown data | default 10 |
| `API_LOG_RETENTION_MONTHS` | VOB | `api_logs` rows older than this are purged daily (`logs:purge-api`) | default 2 |

## Third-party services

| Variable | App | Purpose | Notes |
|---|---|---|---|
| `VOB_DOCS_DISK` | VOB | Disk for vendor PDFs, `s3` for the private bucket | Same bucket the Admin reads (`TOT_DOCS_DISK=s3`) |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | VOB | Credentials to write to the bucket | Use least-privilege keys; secrets manager in production |
| `AWS_DEFAULT_REGION` / `AWS_BUCKET` | VOB | Bucket region and name | Bucket must be private |
| `AWS_USE_PATH_STYLE_ENDPOINT` | VOB | S3-compatible endpoints (MinIO) | default false |
| `CASHFREE_ENV` | VOB | `sandbox` or `production` Cashfree endpoint | Test credentials are rejected in production |
| `CASHFREE_CLIENT_ID` / `CASHFREE_CLIENT_SECRET` | VOB | Cashfree Verification Suite credentials | Never commit; whitelist the server IP in the Cashfree dashboard |
| `CASHFREE_NAME_MATCH` | VOB | `ignore` or `reject_no_match`: whether a NO_MATCH account name fails the verification | Account number + IFSC must always match |

## Application, database, session, mail (Laravel)

| Variable | App | Purpose | Notes |
|---|---|---|---|
| `APP_NAME` | VOB | Application name | |
| `APP_ENV` | VOB | `local`, `staging`, `production`. `production` forces all checks on and the demo flag off | |
| `APP_KEY` | VOB | Encrypts sessions and temporary Cashfree data | `php artisan key:generate`; owner runs it |
| `APP_DEBUG` | VOB | Error pages with details | Must be `false` outside local development |
| `APP_URL` | VOB | Public URL of VOB (used in absolute links) | |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` / `APP_FAKER_LOCALE` | VOB | Laravel language settings | defaults are fine |
| `APP_MAINTENANCE_DRIVER` | VOB | Where maintenance mode is stored | |
| `BCRYPT_ROUNDS` | VOB | Hashing cost (no local passwords are stored) | |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_DEPRECATIONS_CHANNEL` / `LOG_LEVEL` | VOB | Laravel log output | |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | VOB | Small local database: sessions, cache, jobs, `api_logs`, `cashfree_verifications` (no business data) | Local: MySQL `vob_front_end`; production: least-privilege user |
| `SESSION_DRIVER` | VOB | Where sessions (and the Admin access token) live, server side | `database` locally, `redis` in production |
| `SESSION_LIFETIME` | VOB | Storage lifetime in minutes; keep >= `VOB_SESSION_ABSOLUTE_MINUTES` | Sign-out timing is the `VOB_SESSION_*` keys |
| `SESSION_ENCRYPT` | VOB | Encrypt session payloads | keep `true` (holds the Admin token) |
| `SESSION_PATH` / `SESSION_DOMAIN` | VOB | Cookie scope | |
| `BROADCAST_CONNECTION` / `QUEUE_CONNECTION` / `FILESYSTEM_DISK` | VOB | Laravel drivers (not used for business features) | |
| `CACHE_STORE` | VOB | Cache driver (masters cache) | `redis` in production |
| `MEMCACHED_HOST` / `REDIS_CLIENT` / `REDIS_HOST` / `REDIS_PASSWORD` / `REDIS_PORT` | VOB | Redis / Memcached connection when those drivers are used | |
| `MAIL_MAILER` / `MAIL_SCHEME` / `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | VOB | Mail settings (VOB sends no email today; the Admin sends the invitations) | |
