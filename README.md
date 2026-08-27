# Dashboard KPI

Generic, configurable KPI dashboard: a 0–100 aggregate index obtained from
the weighted sum of several metrics, with gauges, mini-gauges and history.

The frontend is **completely generic**: it contains no specific metric and
no company references. The whole setup comes from the backend.

## Architecture

```
Collector ──(daily, POST Bearer)──▶ PHP API + SQLite
  gathers data                     (shared hosting, no SSH)
                                   GET /api/config        (setup)
                                   GET /api/metrics/*     (data)
                                   Static dashboard (Vue)
```

- **`deploy/`** — server side, to be uploaded **as-is** via FTP:
  - `api/metrics.php` — the whole backend (PHP 7.4+/8.x, PDO_SQLITE, zero dependencies)
  - `api/.htaccess` — rewrites for `/api/metrics`, `/api/metrics/latest`, `/api/config`
  - `data/config.php` — **default setup**: `API_TOKEN`, `DB_PATH`, optional
    Basic credentials, `METRICS` (keys, names, descriptions, G/Y/O
    thresholds, weights), title/subtitle
  - `data/.htaccess` — blocks access to `data/`
  - `.htaccess` — Basic Auth for dashboard + GET API (POST exempted)
- **`frontend/`** — **Vue 3 + Vite + Chart.js** app, build only locally:
  `npm install && npm run build` → upload the content of `dist/` to the
  webroot. It contains no hardcoded metric: it loads the setup from
  `GET /api/config`.
- **`.github/workflows/prod-deploy.yml`** — (optional) CI: build + FTP deploy.

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/api/config` | `Authorization: Bearer <API_TOKEN>` | **Send/update the setup** (keys, names, descriptions, weights, G/Y/O ranges, title) |
| GET | `/api/config` | Basic Auth | **Read the setup** (same format as the POST) |
| POST | `/api/metrics` | `Authorization: Bearer <API_TOKEN>` | Save/replace the daily snapshot (idempotent per date) |
| GET | `/api/metrics/latest` | Basic Auth | Latest snapshot for the gauges |
| GET | `/api/metrics?from=YYYY-MM-DD&to=YYYY-MM-DD` | Basic Auth | History (default last 30 days) |

The server **always recomputes** scores (0–100) and the aggregate index from
the raw values using the thresholds and weights of the **active**
configuration: the one sent with `POST /api/config` if present, otherwise
the defaults in `deploy/data/config.php`. It never trusts the client. A
snapshot for an existing date is **replaced** (UNIQUE on `date`+`metric_key`).

### GET /api/config — example response

```json
{
  "title": "Dashboard KPI",
  "subtitle": "updated daily at 20:00",
  "metrics": {
    "example_one": { "name": "Example one", "why": "why it matters", "G": 0, "Y": 3, "O": 6, "weight": 0.6 },
    "example_two": { "name": "Example two", "why": "why it matters", "G": 0, "Y": 2, "O": 5, "weight": 0.4 }
  }
}
```

The frontend renders the gauges from the keys/names/descriptions of this
response: to change the metrics, send a new configuration with
`POST /api/config` (or, as a default, edit `METRICS` in
`deploy/data/config.php`) — no frontend change needed.

### POST /api/config — sending the configuration

Same format as the GET. The **weights must sum to 1.00**, every metric
requires the `G`, `Y`, `O` thresholds (numeric ≥ 0); `name` and `why` are
optional (key / empty string used if missing).

```bash
curl -X POST https://example.com/api/config \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Production Dashboard",
    "subtitle": "updated daily at 20:00",
    "metrics": {
      "example_one": { "name": "Example one", "why": "why it matters", "G": 0, "Y": 3, "O": 6, "weight": 0.6 },
      "example_two": { "name": "Example two", "why": "why it matters", "G": 0, "Y": 2, "O": 5, "weight": 0.4 }
    }
  }'
```

Response: `200` `{"ok": true, "metrics": 2, "title": "Production Dashboard"}`.
Re-POSTing **replaces** the configuration (no duplication).

### GET /api/config — reading the configuration

```bash
curl -u user:password https://example.com/api/config
```

Returns the **active** configuration (the one sent with POST, or the
defaults from `config.php` if none has been sent yet).

### POST /api/metrics — example

```bash
curl -X POST https://example.com/api/metrics \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2026-08-24",
    "metrics": {
      "example_one": 2,
      "example_two": 1
    },
    "index": 0
  }'
```

The keys of the `metrics` payload must match those of the active
configuration (`GET /api/config`). The `index` field is the aggregate index
(optional: the server recomputes it anyway).

## Client usage guide (collector)

The **client** (the program that gathers the data) uses the API in 5 steps.
All examples use:

```bash
BASE=http://nuc.home.arpa:8888            # in production: https://your-domain.com
TOKEN='YOUR_BEARER_TOKEN'                 # handed over by the operator (API_TOKEN in config.php)
USER='user'; PASS='password'              # Basic Auth credentials for the GETs
```

### 1) Send the configuration (keys, weights, ranges)

`POST /api/config` (Bearer token) defines the **setup**: metric keys, names,
descriptions, **weights** and **ranges** (G/Y/O thresholds). Send it once
(or when the metrics change); the backend stores it in the DB.

```bash
curl -X POST "$BASE/api/config" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Production Dashboard",
    "metrics": {
      "orders": { "name": "Orders to fulfil", "why": "fast fulfilment keeps trust", "G": 0, "Y": 2, "O": 5, "weight": 0.6 },
      "emails": { "name": "Emails to triage", "why": "quick replies improve trust", "G": 5, "Y": 15, "O": 30, "weight": 0.4 }
    }
  }'
```

### 2) Read the configuration (what to send)

`GET /api/config` (Basic Auth) returns the **active** configuration: the
client reads it to know **which keys** to use in the POST and **how** they
will be evaluated.

```bash
curl -u "$USER:$PASS" "$BASE/api/config"
```

Meaning of each metric field:

| Field | Meaning |
|---|---|
| `name` | Label shown in the dashboard |
| `why` | Short "why it matters" shown under the name |
| `G`, `Y`, `O` | **Ranges** of the zones: green `[0..G]`, yellow `[G+1..Y]`, orange `[Y+1..O]`, red `[O+1..∞]` |
| `weight` | **Weight** in the weighted sum (weights sum to 1.00 = 100%) |

### 3) Send (or update) the values of the day

`POST /api/metrics` (Bearer token) saves a daily snapshot. The payload
contains the date and **one key for each metric** with its raw value:

```bash
curl -X POST "$BASE/api/metrics" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2026-08-24",
    "metrics": {
      "orders": 2,
      "emails": 10
    },
    "index": 0
  }'
```

Rules:
- `date` is the snapshot date (`YYYY-MM-DD`).
- The keys in `metrics` **must** be exactly those of the active
  configuration: a missing key or an unknown key → `400`.
- Values are **numeric ≥ 0**.
- The `index` field is optional and ignored: the server **recomputes**
  scores (0–100) and the aggregate index from the thresholds and weights of
  the active configuration.
- Success response: `200` `{"ok": true, "date": "...", "index": 55.8, "zone": "orange"}`.

### 4) Update/correct the values of an already-sent day

The insert is **idempotent per date**: re-POSTing with the **same date**
**replaces** the snapshot (no duplicate, thanks to the UNIQUE constraint on
`date` + key). This is how you correct a mistake or do the evening update:

```bash
# Typo: re-POST the same date with the right value
curl -X POST "$BASE/api/metrics" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"date":"2026-08-24","metrics":{"orders":5,"emails":10},"index":0}'
```

The snapshot of the 24th becomes the new one (orders = 5), no second row is
added.

### 5) Read the data (to verify or for the collector)

```bash
# Latest snapshot (for the gauges)
curl -u "$USER:$PASS" "$BASE/api/metrics/latest"

# History — default last 30 days
curl -u "$USER:$PASS" "$BASE/api/metrics"

# Custom history
curl -u "$USER:$PASS" "$BASE/api/metrics?from=2026-08-01&to=2026-08-24"
```

Every returned metric includes `value` (raw), `score` (0–100) and `zone`
(green/yellow/orange/red), plus the aggregate `index` and its zone.

### Flow summary

```
1. POST /api/config            -> send the configuration (keys, weights, ranges)
2. GET  /api/config            -> read the active configuration
3. POST /api/metrics           -> send the values of the day (Bearer)
4. POST /api/metrics (same date) -> update/correct the snapshot
5. GET  /api/metrics/latest and /api/metrics -> read data and verify
```

## Building the frontend

Prerequisites: **Node.js ≥ 18** and **npm** (the build is local/CI, not on
the server).

```bash
cd frontend
npm install        # or npm ci (exact reproduction from the lockfile)
npm run build      # generates frontend/dist/ (minified HTML/CSS/JS + .htaccess)
```

For development: `npm run dev` (Vite dev server with hot reload on :5173)
and `npm run preview` (serves `dist/` on :4173). Details, `dist/` structure
and troubleshooting: see **`TECH.md` §8**.

## CI / Automatic deploy (GitHub Actions)

The repository includes two workflows in `.github/workflows/`:

- **`ci.yml`** — on every push/PR it builds the frontend, verifies the
  output and uploads `dist/` as a downloadable **artifact** (no deploy).
- **`prod-deploy.yml`** — on every push to `main` (or manually) it builds
  and **publishes via FTP**: `dist/` to the webroot + `api/` and `data/`.
  It excludes the root `.htaccess` and `data/config.php` so the production
  configuration is not overwritten.

To enable the FTP deploy you need repository **secrets**
(Settings → Secrets and variables → Actions): `FTP_HOST`, `FTP_USERNAME`,
`FTP_PASSWORD`, `FTP_DIR`. Details: see **`TECH.md` §8.7**.

## Deploy on shared hosting (no SSH)

1. **Build the frontend locally**: `cd frontend && npm install && npm run build`.
2. **Upload via FTP / file manager**:
   - the content of `frontend/dist/` → webroot (or `/dashboard/`);
   - `deploy/api/` → `/api/`;
   - `deploy/data/` → outside the webroot if possible, otherwise `/data/`
     (still protected by its `.htaccess`);
   - `deploy/.htaccess` → webroot (a single root `.htaccess`: the one in
     `dist/` and the one in `deploy/` are identical, do not upload both).
3. **Generate `.htpasswd`** (e.g. `htpasswd -c .htpasswd user`) and update
   `AuthUserFile` in the root `.htaccess` with the real path.
4. **Set `API_TOKEN`** in `deploy/data/config.php` with a long random string
   (`openssl rand -hex 32`) and hand it to the collector.
5. **Customize the setup** — either by sending the configuration with
   `POST /api/config` (see "Client usage guide"), or by editing the
   defaults in `deploy/data/config.php` (`METRICS`, title) — the frontend
   adapts by itself.
6. **Smoke test**:

```bash
# Setup (Basic Auth)
curl -u user:password https://example.com/api/config

# Protected GET (Basic Auth)
curl -u user:password https://example.com/api/metrics/latest

# Test POST (Bearer)
curl -X POST https://example.com/api/metrics \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Content-Type: application/json" \
  -d '{"date":"2026-08-25","metrics":{...},"index":0}'
```

**Note on Basic Auth and POST**: the root `.htaccess` protects the dashboard
and the GETs but **exempts POSTs** (`<RequireAny> … <Require method POST>`).
If the collector cannot POST (Apache 2.2), move the root `.htaccess` into a
subfolder that contains only the dashboard and set
`BASIC_AUTH_USER`/`BASIC_AUTH_PASS` in `config.php` (the API GETs will then
be protected by PHP itself).

## Formula notes

- Score per metric: piecewise linear interpolation on bands
  `green [0,G]→[0,25]`, `yellow [G+1,Y]→[26,50]`, `orange [Y+1,O]→[51,75]`,
  `red [O+1, 2·(O+1)]→[76,100]`; saturation at `2·(O+1)` = 100.
- Aggregate index = Σ score × weight (weights = 100%).
- The backend (`score_metric` in PHP) computes scores and the index; the
  frontend uses directly the values returned by the API (score and zone for
  the gauge, raw value at the center).

## Structure

```
├── .github/workflows/ci.yml
├── .github/workflows/prod-deploy.yml
├── README.md
├── TECH.md
├── deploy/
│   ├── .htaccess
│   ├── api/{.htaccess, metrics.php}
│   └── data/{.htaccess, config.php}
└── frontend/
    ├── package.json
    ├── vite.config.js
    ├── index.html
    ├── public/.htaccess
    └── src/{main.js, App.vue, api.js, metrics.js, style.css, components/…}
```
