# KPI Dashboard — QUICK START (post-upload / server setup)

This guide explains **what to do once the files are already in their final
remote folder** (the folder your FTP/FTPS deploy targets — by default
`<FTP home>/dashboard/`). It only covers the **server-side tasks** needed to
make the dashboard work; no code or local build is required here.

> Full API reference: the self-contained `HELP.html` you uploaded in
> `/api/` (also `openapi.yaml`). This page is the condensed "make it live"
> checklist.

---

## 0 · What the deploy already put on the server

After the upload your remote folder contains:

```text
…/dashboard/               <- "APP_DIR" in this guide (webroot of the app)
├── index.html             # compiled Vue frontend
├── assets/…               # hashed JS/CSS bundles
├── .htaccess              # Basic Auth for the dashboard + Bearer pass-through
├── api/
│   ├── .htaccess          # rewrites /api/* → metrics.php
│   ├── metrics.php        # the whole backend (API)
│   ├── HELP.html          # API reference (this page's companion)
│   └── openapi.yaml       # OpenAPI spec
└── data/
    ├── .htaccess          # denies web access to this folder
    ├── config.php         # ⚠️ NOT uploaded — you must create it (step 2)
    └── kpi.sqlite         # ⚠️ NOT uploaded — created automatically on first call
```

Two files are **never** uploaded on purpose and are yours to manage **on the
server**: `data/config.php` (secrets) and `data/kpi.sqlite` (the SQLite DB,
created automatically). Whatever is in the repository for local dev does not
matter for production.

---

## 1 · Set your variables

Adapt these for every command below:

```bash
BASE=https://your-domain.com/dashboard    # full public URL of APP_DIR
# or, if installed at the webroot:
# BASE=https://your-domain.com
```

`API_TOKEN` and the `.htpasswd` user/password are created in the next steps —
the token is the only credential your feeder needs.

---

## 2 · Create `data/config.php` with a real `API_TOKEN`

The deploy never overwrites it, so on a **first install it does not exist**.
Create it on the server (hosting file manager, or upload once) inside the
`data/` folder:

```php
<?php
// .../dashboard/data/config.php   (server-owned secrets — never uploaded)

const API_TOKEN = 'REPLACE_WITH_LONG_RANDOM_STRING';
const DB_PATH   = __DIR__ . '/kpi.sqlite';

// Optional: PHP-level Basic Auth check for GETs, on top of the .htaccess.
// Leave empty to rely on Apache (recommended when .htpasswd is configured).
const BASIC_AUTH_USER = '';
const BASIC_AUTH_PASS = '';

// Fallback title/subtitle (only shown until the first POST /api/config).
const DASHBOARD_TITLE    = 'Operations Dashboard';
const DASHBOARD_SUBTITLE = 'updated daily at 20:00';
```

Generate the token locally (or on the server) with:

```bash
openssl rand -hex 32
```

Requirements:

- **No business metrics here** — metrics are defined through the API (step 5).
- `data/` must be **writable by PHP** (the SQLite DB is created there on the
  first API call). If you see permission errors, `chmod`/`chown` the folder
  (e.g. `chmod 775 data`) and make sure the DB file, once created, stays
  writable by the web-server user.

---

## 3 · Protect the dashboard with `.htpasswd`

The uploaded root `.htaccess` enables **Basic Auth** but references a
placeholder path. Make it real:

1. Create the password file (on the server, or locally and upload it —
   keep it **outside** the webroot if you can):

   ```bash
   htpasswd -c /path/on/server/.htpasswd alice   # prompts for a password
   # add more viewers later without -c:
   # htpasswd /path/on/server/.htpasswd bob
   ```

2. Edit the root `.htaccess` that was uploaded (in `APP_DIR`) and set the
   real absolute path:

   ```apache
   AuthUserFile /path/on/server/.htpasswd
   ```

3. Result — who gets in:

   - **Browser / dashboard / API GETs**: the `.htpasswd` user/password.
   - **API reads AND writes with the collector token**: requests carrying
     `Authorization: Bearer <API_TOKEN>` are let through by Apache and
     validated by PHP. An invalid token still gets `401`.
   - `.htaccess` changes apply immediately (no Apache restart needed on
     shared hosting).

> Apache 2.2 (no `<RequireAny>`)? The `.htaccess` falls back to plain Basic
> Auth for everything. To let the feeder read with the token there, move the
> `.htaccess` into a subfolder containing only the dashboard and set
> `BASIC_AUTH_USER`/`BASIC_AUTH_PASS` in `config.php` instead.

---

## 4 · Smoke test the API (creates the DB)

The first call to any `/api/*` endpoint creates the SQLite schema and the
DB file automatically. Try with **either** credential type:

```bash
# as the feeder/operator (Bearer — reads accept it since the latest release):
curl -H "Authorization: Bearer REPLACE_WITH_LONG_RANDOM_STRING" "$BASE/api/config"
# or as a human (Basic):
curl -u alice:yourpassword "$BASE/api/config"
```

Expected on a fresh install:

```json
{
  "title": "Operations Dashboard",
  "subtitle": "updated daily at 20:00",
  "metrics": {}
}
```

(empty `metrics` = nothing configured yet — normal). Confirm `data/kpi.sqlite`
now exists.

---

## 5 · Configure title & metrics (through the API, Bearer token)

The dashboard is **generic**: what it shows comes 100% from these API calls.
No code, no redeploy.

### 5a. Dashboard identity

```bash
curl -X POST "$BASE/api/config" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title": "Operations Dashboard", "subtitle": "updated daily at 20:00"}'
```

### 5b. One call per metric (upsert by key; repeat for each)

Thresholds: green `[0..G]`, yellow `[G+1..Y]`, orange `[Y+1..O]`, red `> O`.
`weight` is relative (does NOT need to sum to 1).

```bash
curl -X POST "$BASE/api/config/metrics" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"key":"orders","name":"Orders awaiting fulfilment","why":"fast fulfilment keeps trust","G":0,"Y":2,"O":5,"weight":0.6}'

curl -X POST "$BASE/api/config/metrics" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"key":"emails","name":"Emails to triage","why":"quick replies build trust","G":5,"Y":15,"O":30,"weight":0.4}'
```

Checkpoint — every active metric now appears:

```bash
curl -H "Authorization: Bearer $TOKEN" "$BASE/api/config"
```

---

## 6 · Publish the first data point (the feeder)

The feeder (your collector program) uses **only the Bearer token**. It can
also use it to read the configuration and verify what was stored.

1. **Discover which keys to send** (optional but recommended):

   ```bash
   curl -H "Authorization: Bearer $TOKEN" "$BASE/api/config"
   ```

2. **Publish a daily snapshot** — `metrics` must contain **every** active
   key (missing key → `400`); the `index` field is ignored (server recomputes
   scores/index):

   ```bash
   curl -X POST "$BASE/api/metrics" \
     -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"date":"2026-09-05","metrics":{"orders":2,"emails":10},"index":0}'
   ```

   Response: `{"ok":true,"date":"2026-09-05","index":44.7,"zone":"yellow"}`.
   Re-POSTing the **same date replaces** that day (use it to correct values).

3. **Verify it was stored correctly** (read-back with the same token):

   ```bash
   curl -H "Authorization: Bearer $TOKEN" "$BASE/api/metrics/latest"        # gauges
   curl -H "Authorization: Bearer $TOKEN" "$BASE/api/metrics"               # last 30 days
   curl -H "Authorization: Bearer $TOKEN" "$BASE/api/metrics?from=2026-09-01&to=2026-09-05"
   ```

   Every returned metric includes `value` (raw), `score` (0–100) and `zone` —
   this is how the feeder checks that the published data points are correct.

---

## 7 · Open the dashboard

Browse to `$BASE` in a browser and log in with the `.htpasswd` credentials.
You should see the main gauge, the per-metric mini-gauges (from step 5) and
the history chart (from step 6). If the page loads but says "No data
available", no snapshot has been posted yet for the current configuration.

---

## 8 · What to hand to the feeder operator

A single summary block:

| Item | Value |
|---|---|
| Write+read endpoint | `POST $BASE/api/metrics` · `GET $BASE/api/config` · `GET $BASE/api/metrics/*` |
| Auth | `Authorization: Bearer <API_TOKEN>` (reads and writes) |
| Snapshot payload | `{"date":"YYYY-MM-DD","metrics":{"<key>":<rawValue>, …}}` (all active keys) |
| Correction | re-`POST` the same `date` (replaces) |
| Full docs | `$BASE/api/HELP.html` |

---

## Checklist (after files are uploaded)

- [ ] `data/config.php` exists on the server with a **real** `API_TOKEN`
- [ ] `data/` writable by PHP → `kpi.sqlite` created on first call
- [ ] `.htpasswd` created and `AuthUserFile` path fixed in the root `.htaccess`
- [ ] `GET $BASE/api/config` answers `200` with your title and an empty/your `metrics`
- [ ] Metrics configured via `POST /api/config/metrics` (one per metric)
- [ ] First snapshot posted via `POST /api/metrics` → `200 {"ok":true,…}`
- [ ] `GET $BASE/api/metrics/latest` shows the expected values
- [ ] Dashboard opens in a browser with the `.htpasswd` credentials
- [ ] HTTPS is enabled (Basic/Bearer credentials must never travel in clear)
