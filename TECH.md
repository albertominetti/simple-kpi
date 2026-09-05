# TECH.md — Technologies and technical logic

Technical document of the **Dashboard KPI** project: stack, architecture,
design choices, formulas and implementation details. It is the reference
for anyone maintaining or extending the code.

---

## 1. Technology stack

### Backend (server side, `deploy/`)

| Technology | Version | Usage |
|---|---|---|
| **PHP** | 7.4+ / 8.x (tested on 8.5) | Complete REST API in a single file (`metrics.php`) |
| **PDO + SQLite** | standard extension | Persistence (zero configuration, no DB server) |
| **Apache** | 2.2 / 2.4 | Shared hosting: `.htaccess`, `mod_rewrite`, Basic Auth |
| **JSON / YAML** | — | API data-exchange contract (`JSON`), OpenAPI spec (`YAML`) |

Minimum hosting requirements: PHP with `PDO_SQLITE` (standard in
PHP 7.4+/8.x), Apache with `mod_rewrite` and `.htaccess` support. **No**
Composer, frameworks, MySQL or server-side build.

### Frontend (static part, `frontend/`)

| Technology | Version | Usage |
|---|---|---|
| **Vue 3** | ^3.5 | UI framework (Composition API, `<script setup>`) |
| **Vite** | ^6 | Bundler for the local build (`npm run build` → `dist/`) |
| **Chart.js** | ^4.4 | History chart (line chart) |
| **SVG** | — | Hand-drawn needle gauges (no gauge library) |
| **CSS Grid / Flexbox** | — | Fluid full-window layout |

The frontend is **static** (no server-side runtime): it is uploaded to the
webroot as ready files. It is **completely generic**: it contains no
hardcoded metric, everything comes from the backend via `GET /api/config`.

---

## 2. Architecture

```
┌──────────────────┐   writes (Bearer): POST /api/config,             ┌───────────────────────────┐
│   Client/feeder  │   POST/DELETE /api/config/metrics,                │  PHP API + SQLite (host)  │
│   (collector)    │   POST /api/metrics                               │  - metrics.php (backend,  │
│                  │ ─────────────────────────────────────────────────▶│    routing included)      │
│   gathers data   │   reads (Basic or Bearer):                        │  - data/kpi.sqlite        │
│   from sources   │   GET /api/config, GET /api/metrics/latest,       │  - data/config.php (keys) │
│                  │   GET /api/metrics?from=&to=                      │  - .htaccess (rewrite +   │
│                  │                                                  │    auth: Basic/Bearer)    │
└──────────────────┘                                                  │  - api/HELP.html + openapi│
                                                                       └────────────┬──────────────┘
                                                             GET /api/* (Basic or Bearer)  │
                                                                                         ▼
                                                                              ┌──────────────────────┐
                                                                              │  Browser (static Vue, │
                                                                              │  dist/ in the webroot)│
                                                                              └──────────────────────┘
```

- **Metric configuration** lives in the `config_metrics` table and is managed
  **exclusively through the API** (`POST /api/config/metrics`,
  `DELETE /api/config/metrics/{key}`). There are **no business metric
  defaults in the code** (`deploy/data/config.php` has no `METRICS` anymore).
- **Dashboard identity** (`title`, `subtitle`) lives in the single-row
  `config` table as plain columns, managed with `POST /api/config`.
- **Data**: the client sends daily raw values with `POST /api/metrics`; the
  server recomputes scores and the aggregate index and exposes them via the
  GET endpoints.
- **Documentation ships with the API**: `api/HELP.html` (human/AI readable
  reference) and `api/openapi.yaml` (OpenAPI 3.0 machine-readable spec).

---

## 3. API — contract and logic

### 3.1 Endpoints

| Method | Path | Auth | Function |
|---|---|---|---|
| POST | `/api/config` | Bearer | Set dashboard title/subtitle (only) |
| GET | `/api/config` | Basic or Bearer | Read the active setup (title/subtitle + all metrics) |
| POST | `/api/config/metrics` | Bearer | Create or update one metric (upsert by key) |
| DELETE | `/api/config/metrics/{key}` | Bearer | Delete a metric |
| POST | `/api/metrics` | Bearer | Save/replace the daily snapshot |
| GET | `/api/metrics/latest` | Basic or Bearer | Latest snapshot (gauges) |
| GET | `/api/metrics?from=&to=` | Basic or Bearer | History (default last 30 days) |

### 3.2 Routing

`metrics.php` acts as a **front controller** without a framework: it parses
`$_SERVER['REQUEST_URI']` with regex/`substr()` and routes by path suffix and
method. Order matters: `/config/metrics/{key}` is matched before the generic
`/config` and `/metrics` suffixes. In Apache the `/api/*` requests reach
`metrics.php` via `mod_rewrite` (`.htaccess`); it also works in a subfolder
(e.g. `/dashboard/api/...`).

The PHP built-in server (local development) does not process `.htaccess`:
the file `deploy/router.php` (dev only) emulates the rewrite for the same set
of endpoints.

### 3.3 Authentication — two modes, enforced in PHP

- **Writes (POST + DELETE)**: always **Bearer token**, compared in constant
  time with `hash_equals()` against `API_TOKEN` (in `config.php`) by
  `check_bearer()`. Prevents timing attacks. The `Authorization` header is
  made available to PHP with
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` in the `.htaccess`
  (essential on many shared CGI/FastCGI hosts).
- **Reads (GET)**: accept **either** a valid **Bearer token** (the same
  `API_TOKEN`, checked by `check_read()`) **or** HTTP Basic Auth. Basic Auth
  is managed by the root `.htaccess` (`Require valid-user` + `.htpasswd`)
  for human/browser access; as defence in depth, if
  `BASIC_AUTH_USER`/`BASIC_AUTH_PASS` are set in `config.php`, PHP also
  verifies the credentials.
- **Why reads accept the token**: the collector/feeder uses one credential
  (its token) for everything — it can `GET /api/config` to discover the
  active metrics and `GET /api/metrics/latest` / `GET /api/metrics` to
  verify the published data points, without needing `.htpasswd` credentials.
- The root `.htaccess` lets a request reach PHP when **any** of these holds
  (Apache 2.4, `<RequireAny>`): a valid Basic user, a `Bearer`
  Authorization header (`Require env HAS_BEARER`, set via `SetEnvIf`), or the
  method is POST/DELETE. PHP is the real enforcer: an invalid token still
  gets a `401`, so bypassing Apache with a fake `Bearer` header does not
  expose any data.
- **The auth files themselves are never downloadable.** The generated root
  `.htaccess` forbids HTTP access to `.htaccess`, `.htpasswd` and
  `example.htaccess` with two independent layers: a `<FilesMatch>` deny
  (`Require all denied`, case-insensitive) and a `mod_rewrite` rule that
  returns `403` (`[F]`). `.htpasswd` sits next to `.htaccess` by design, so
  this is what keeps the viewer credential hashes out of reach of the
  browser even though they are inside the web-accessible folder.

### 3.4 Validations (all server-side)

- `date` must be a real `YYYY-MM-DD` (regex + `DateTime::createFromFormat`).
- Snapshot `POST /api/metrics`: every **active** metric key must be present
  with a numeric value ≥ 0 → otherwise **400** (unknown extra keys ignored).
- `POST /api/config/metrics`: `key` URL-safe
  (`^[A-Za-z0-9][A-Za-z0-9_.-]*$`), thresholds `G`/`Y`/`O` numeric ≥ 0,
  `weight` numeric ≥ 0 (default `1`) → otherwise **400**.
- `POST /api/config`: only `title`/`subtitle` strings; a `metrics` field is
  rejected with a **400** hint pointing to `/api/config/metrics`.
- Missing/wrong token → **401** `{"error":"unauthorized"}`.
- Unknown endpoint → **404**; disallowed method on a known path → **405**.

### 3.5 API documentation

The API is documented twice, both shipped next to the backend in
`deploy/api/` (and included in the deploy):

- `HELP.html` — a self-contained reference written for **humans and AI
  agents** (purpose, auth, endpoint-by-endpoint schemas/examples, workflow,
  errors).
- `openapi.yaml` — an **OpenAPI 3.0.3** machine-readable specification of the
  same contract (can be fed to Swagger/Redoc/AI tooling).

---

## 4. SQLite database

File: `deploy/data/kpi.sqlite` (outside the webroot; if inside, blocked by
the `data/` `.htaccess`). `PDO` connection with `ERRMODE_EXCEPTION`,
`PRAGMA journal_mode = WAL` (concurrent reads/writes),
`PRAGMA foreign_keys = ON`.

### Schema

```sql
-- Raw values + score for each metric and day
CREATE TABLE metrics (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    date        DATE NOT NULL,          -- snapshot day
    metric_key  TEXT NOT NULL,          -- e.g. "orders"
    value       REAL NOT NULL,          -- raw value sent by the client
    score       REAL NOT NULL,          -- 0-100 recomputed score
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(date, metric_key)            -- idempotency: 1 row per (date, key)
);

-- Aggregate snapshot per day (index + zone)
CREATE TABLE snapshot (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    date        DATE NOT NULL UNIQUE,   -- 1 snapshot per day
    aggregate   REAL NOT NULL,          -- aggregate index 0-100
    zone        TEXT NOT NULL,          -- green|yellow|orange|red
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Dashboard title/subtitle (single row, plain columns, NOT a JSON blob)
CREATE TABLE config (
    id          INTEGER PRIMARY KEY CHECK (id = 1),  -- single row
    title       TEXT NOT NULL DEFAULT 'Dashboard KPI',
    subtitle    TEXT NOT NULL DEFAULT '',
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Metric definitions (managed via the API, NOT hardcoded)
CREATE TABLE config_metrics (
    metric_key  TEXT PRIMARY KEY,       -- URL-safe id, e.g. "orders"
    name        TEXT NOT NULL,          -- display label (fallback: key)
    why         TEXT NOT NULL DEFAULT '',-- "why it matters"
    G           REAL NOT NULL,          -- green threshold
    Y           REAL NOT NULL,          -- yellow threshold
    O           REAL NOT NULL,          -- orange threshold (above O -> red)
    weight      REAL NOT NULL DEFAULT 1,-- relative weight (normalized)
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Design choices

- **Metrics in their own table, not a JSON blob**: each metric is one row in
  `config_metrics`. This makes create/update/delete trivial and queryable,
  avoids parsing a whole JSON document per request, and keeps the `config`
  table for the dashboard identity only.
- **Key-value data rows** (one row per metric/day instead of fixed columns):
  when metrics change, no schema migration is needed — "zero migration". One
  day = N rows in `metrics` + 1 row in `snapshot`.
- **Upsert per date**: `DELETE` + `INSERT` in a transaction (keys can change
  between configurations, so DELETE is safer than row-by-row
  `INSERT OR REPLACE`). The transaction guarantees atomicity: either the
  whole snapshot is replaced or nothing is.
- **Metric upsert**: `INSERT ... ON CONFLICT(metric_key) DO UPDATE` — one
  statement both creates and updates a metric.
- **Deleting a metric** only removes its `config_metrics` row. Historical
  `metrics` rows are kept but filtered out of the GET responses (a metric no
  longer in the active configuration is not exposed). Re-creating the same
  key later restores the history.
- **Legacy migration**: on first access, `init_schema()` detects an old
  install (a `config` table with a `json` column), imports any metrics found
  into `config_metrics`, keeps title/subtitle, then rebuilds `config` with
  plain columns.

---

## 5. Calculation formula

### 5.1 Score per metric (0–100)

Each raw value `v` is mapped to a 0–100 score via **piecewise linear
interpolation** on 4 bands defined by the G (green), Y (yellow), O (orange)
thresholds (red is open-ended above O):

| Band | Value range | Score range |
|---|---|---|
| green | `0 … G` | `0 – 25` |
| yellow | `G+1 … Y` | `26 – 50` |
| orange | `Y+1 … O` | `51 – 75` |
| red | `O+1 … ∞` | `76 – 100` |

```text
score(v):
  v == 0            → 0
  v ≤ G (and G > 0) → 25 · (v / G)
  v ≤ Y             → 26 + (v − (G+1)) / (Y − (G+1)) · 24
  v ≤ O             → 51 + (v − (Y+1)) / (O − (Y+1)) · 24
  v ≥ 2·(O+1)       → 100            (saturation)
  otherwise         → 76 + (v − (O+1)) / (O+1) · 24
```

Special cases:
- If `G = 0`, green applies **only** to `v == 0` (score 0); the yellow band
  starts at `v = 1`.
- "Degenerate" bands (e.g. `Y == G+1`): a single value → top of the band
  (50 for yellow, 75 for orange).
- The red band saturates at `2·(O+1)`: beyond that value the score is 100.
- Final rounding to **1 decimal**.

### 5.2 Aggregate index (weighted mean, weights are NORMALIZED)

```text
index = Σ ( score(metric_i) × weight_i ) / Σ ( weight_i )
```

- Weights do **NOT** need to sum to 1.00 (or 100): the sum is used as the
  normalizing divisor at scoring time. Omitting a weight defaults it to `1`
  (then the index is a simple average).
- If `Σ(weight) = 0` (all weights zero), the plain average of the scores is
  used as a safe fallback.
- Rounding to 1 decimal.

### 5.3 Zones

| Zone | Score | |
|---|---|---|
| green | 0 – 25 | |
| yellow | 26 – 50 | |
| orange | 51 – 75 | |
| red | 76 – 100 | |

The zone is computed both for the aggregate index (`zone` column in
`snapshot`) and for every metric (in the responses, from `zone(score)`).

### 5.4 Trust model

The server **never trusts the client**: the `index` field in the POST
payload is ignored, and scores are recomputed from the raw values + active
configuration at snapshot time. The frontend uses directly the
`score`/`zone`/`index` returned by the API (no client-side recomputation).

---

## 6. Frontend — logic

### 6.1 Data flow (App.vue)

1. `onMounted` → `Promise.all([fetchConfig(), fetchLatest()])`:
   - `GET /api/config` → `config` (title + metrics with name/why/G/Y/O/weight)
   - `GET /api/metrics/latest` → `latest` (`{date, index, zone, metrics}`)
2. Header rendered from `config.title` (white-label).
3. Large `MetricGauge`: needle on `latest.index`, zone from `latest.zone`.
4. `DashboardGrid`: iterates over `config.metrics` (not hardcoded keys) and
   for each one shows a mini-gauge with `score` (needle) and raw `value`
   (center text).
5. `HistoryChart`: loads the history from `fetchHistory(from, to)`.

### 6.2 SVG gauge (MetricGauge.vue)

- `viewBox 0 0 220 190`, center `(110, 105)`, radius 78.
- **Angular scale**: 0 → 150°, 100 → 390° (=30°), 50 → 270° (top).
  Clockwise **240°** sweep. The needle is a `<line>` from the center to
  `point(angle(value))`, with length `R − 16`.
- **Colored arc**: 4 SVG segments (`<path>` with `A` arc) per zone
  (green 150–210°, yellow 210–270°, orange 270–330°, red 330–390°).
- **Center text without overlap**: the value is placed at `y=150` (lower
  well of the arc), while the lowest end of the needle is at `y≈136`; so the
  number is never covered by the needle, whatever the score.
- The text shown at the center is a **prop** (`text`): for mini-gauges it is
  the raw value formatted (`formatNumber`), for the main gauge it is the
  index. If `text` is absent, `value` is formatted.

### 6.3 History (HistoryChart.vue)

- Chart.js line chart: Y axis 0–100 (step 25), X axis dates.
- Points colored by zone (from the `zone` field of each snapshot).
- Views: 7 days / 30 days / custom (two `<input type="date">`).
- Default: last 30 days (backend: `from = to − 29`).
- The chart is destroyed when the component is unmounted (no memory leak).

### 6.4 Fluid layout

- `.app`: `min-height: 100vh`, flex column, gap.
- `.body`: grid `minmax(300px, .85fr) / minmax(0, 2fr)` → main gauge on the
  left, metrics grid on the right (internal scroll if needed).
- `.history`: `flex: 1.1` below, full width.
- Media query `< 920px`: everything in one column.

---

## 7. Security

| Aspect | Implementation |
|---|---|
| Writes | Bearer token (`check_bearer`), `hash_equals` comparison (constant time) |
| Reads | Bearer token (`check_read`) **or** Basic Auth via `.htaccess` + optional PHP check |
| Apache gate | `<RequireAny>` allows valid-user, `Bearer` header (`Require env HAS_BEARER`), POST and DELETE; PHP always validates the token/credentials |
| Auth files (`.htaccess`, `.htpasswd`) | The generated root `.htaccess` refuses to serve itself, `.htpasswd` and `example.htaccess` on **two independent layers**: `<FilesMatch>` `Require all denied` (case-insensitive, 2.2 fallback `Deny from all`) **and** `mod_rewrite` `[F]` 403. Apache core also blocks `.htaccess` by default; `first_setup.php` self-checks that these guards are present before activating the file |
| `data/` | `.htaccess` with `Require all denied` (Apache 2.4) + `Deny from all` (2.2) and `<FilesMatch>` on php/sqlite/db |
| Authorization header | `SetEnvIf` in the API `.htaccess` (CGI/FastCGI hosts) |
| SQL injection | PDO prepared statements everywhere (no concatenation) |
| XSS | Vue escapes text in templates; API returns only JSON |
| Path traversal | The dev router blocks `/data` and paths with `..` |
| Metric keys | Restricted to URL-safe charset before any DB/URL use |
| Secrets | `API_TOKEN`/`BASIC_AUTH_*` in `data/config.php` (outside webroot, never served, never uploaded by CI) |

---

## 8. Build and deploy

### 8.1 Prerequisites for building the frontend

- **Node.js ≥ 20** (22 LTS recommended) and **npm ≥ 9**.
  Check: `node -v && npm -v`.
- The build is **local only** (or CI): there is no npm or SSH on the
  server, so you build elsewhere and upload the resulting `dist/`.

### 8.2 Building the frontend

```bash
cd frontend

# 1) Install dependencies (first time, or after a package.json change)
npm install
#   or, in CI / for exact reproductions from the lockfile:
npm ci

# 2) Production build -> generates the dist/ folder
npm run build
```

What `npm run build` (Vite) produces:

```
frontend/dist/
├── index.html                 # final HTML referencing the assets
└── assets/
    ├── index-<hash>.js        # minified bundled JS (Vue + Chart.js + app)
    └── index-<hash>.css       # minified CSS
```

Details:
- `base: './'` in `vite.config.js` → assets referenced with **relative**
  paths: `dist/` works both in the webroot and in a subfolder
  (e.g. `/dashboard/`).
- The files in `assets/` contain a **content hash**: different builds get
  different names, so the browser never serves stale cached versions.
- `emptyOutDir: true` → `dist/` is emptied before every build.
- The build output is **static only** (no `.htaccess`): Basic Auth for the
  dashboard is handled on the server by `example.htaccess` +
  `first_setup.php` (§8.5/8.7), not by a file inside `dist/`.

### 8.3 Useful development commands

```bash
cd frontend

npm run dev          # Vite dev server (HMR) on http://localhost:5173
npm run preview      # serves the freshly built dist/ on http://localhost:4173
```

> Note: `npm run dev` serves **only the frontend** (no API). To try the
> complete app (frontend + backend) use the PHP built-in server described
> in 8.6, which serves `dist/` and `/api/*` from the same origin.

### 8.4 Build troubleshooting

| Problem | Likely cause | Solution |
|---|---|---|
| `npm ERR! code ERESOLVE` | incompatible dependencies in `node_modules` | `rm -rf node_modules package-lock.json && npm install` |
| syntax error in a `.vue` | corrupted file | `npm run build` reports file and line; fix and retry |
| `dist/` with old assets | browser cache | clear the cache / open incognito (hashed names change every build) |
| port 5173/4173 busy | another process | `npm run dev -- --port 5174` |

### 8.5 Deploy on shared hosting (no SSH)

The recommended flow uses the **CI assemble + FTPS deploy** (8.7) which ships
`first_setup.php` + `example.htaccess` + `data/seed.sqlite` and **never**
uploads the four server-owned files (`.htaccess`, `.htpasswd`,
`data/config.php`, `data/kpi.sqlite`). After the first deploy, run the
one-time bootstrap once:

```bash
curl -X POST https://your-domain.com/dashboard/first_setup.php \
  -H "Content-Type: application/json" \
  -d '{"user":"alice","pass":"SuperSecret123"}'
```

This copies `example.htaccess` → the live `.htaccess` (real `AuthUserFile`),
generates `.htpasswd` in the same folder and `data/config.php` (real
`API_TOKEN`), and seeds `data/kpi.sqlite` from `data/seed.sqlite`. It is
self-sealing: a second POST returns `409`. See `QUICK_START.md` for the full
walk-through.

Manual alternative (no CI): upload the content of `frontend/dist/`,
`deploy/example.htaccess`, `deploy/api/`, `deploy/data/.htaccess`,
`deploy/data/kpi.sqlite` (renamed `seed.sqlite`) and `deploy/first_setup.php`,
then run the same POST. Do not upload `deploy/data/config.php` or
`deploy/router.php`.

### 8.6 Local development (backend + frontend together)

```bash
cd kpi-dashboard
php -S 0.0.0.0:8888 -t deploy deploy/router.php
```

`router.php` serves `dist/` and `/api/*` from a single origin (like
production). It is a **development-only** file: do not upload it to the
server.

### 8.7 GitHub Actions workflows

The repository includes two workflows in `.github/workflows/`:

| Workflow | When it runs | What it does |
|---|---|---|
| `ci.yml` | push/PR on any branch | Builds the frontend and uploads `dist/` as a downloadable **artifact** |
| `prod-deploy.yml` | push to `prod` (or manual) | Builds the SPA and **publishes via FTPS** (compiled frontend + `api/` + `data/` protection + seed + bootstrap) into a single remote folder |

`prod-deploy.yml` first **assembles a single release folder** with the exact
content of the remote deploy folder and then uploads it in **one FTPS pass**
(`SamKirkland/FTP-Deploy-Action`). The remote layout is:

```text
<FTP home>/dashboard/        <- default target (configurable)
├── index.html               # compiled SPA frontend (served to the browser)
├── assets/…                 # hashed JS/CSS
├── example.htaccess         # Basic Auth TEMPLATE (deployed; copied to the
│                            #   live .htaccess by first_setup.php)
├── first_setup.php          # one-time bootstrap (POST), see 8.5 / QUICK_START
├── api/
│   ├── .htaccess            # rewrite config/config/metrics/metrics/... -> metrics.php
│   ├── metrics.php          # the whole backend
│   ├── HELP.html            # API reference (humans / AI agents)
│   └── openapi.yaml         # OpenAPI 3.0 spec
└── data/
    ├── .htaccess            # denies web access (secrets + DB live here)
    └── seed.sqlite          # seed for new installs (copied -> kpi.sqlite once)
```

**Server-owned files** (never uploaded; generated once by `first_setup.php`).
They are not part of the release bundle, so they never enter the FTPS
action's sync-state file and can never be overwritten or deleted by a
deploy. (`data/config.php` and `data/kpi.sqlite` are also listed in the
deploy `exclude` as extra belt-and-suspenders; `.htaccess`/`.htpasswd` must
NOT be added there as bare names, see 8.7):

```text
dashboard/
├── .htaccess               # generated by first_setup.php from example.htaccess
├── .htpasswd               # created by first_setup.php (viewer credentials)
└── data/
    ├── config.php          # created by first_setup.php (API_TOKEN)
    └── kpi.sqlite          # LIVE database (seeded from seed.sqlite once)
```

The default target is `dashboard/` (relative to the FTP home). To change it:

| Mechanism | Where | Example |
|---|---|---|
| Manual run input | `workflow_dispatch` → `deploy_dir` | `kpi/dashboard/` |
| Repository **variable** | Settings → Variables → `DEPLOY_DIR` | `kpi/dashboard/` |
| Default | — | `dashboard/` |

Secrets and variables required in the repository
(Settings → Secrets and variables → Actions):

| Kind | Name | Description |
|---|---|---|
| Secret | `ftp_host` | FTPS host |
| Secret | `ftp_username` | FTPS username |
| Secret | `ftp_password` | FTPS password |
| Secret | `ftp_port` *(optional)* | FTPS port (default `21`) |
| Variable | `DEPLOY_DIR` *(optional)* | target folder relative to the FTP home, must end with `/` (default `dashboard/`) |

Note: the four server-owned files (`.htaccess`, `.htpasswd`,
`data/config.php`, `data/kpi.sqlite`) are **never uploaded** — `.htaccess` is
generated once by `first_setup.php` from the deployed `example.htaccess`.
They are not in the release bundle, so the FTPS action never has them in its
sync-state and can never overwrite or delete them (`.htaccess`/`.htpasswd`
are deliberately **not** added to the deploy `exclude`: the action matches
bare names at any depth, which would also exclude `api/.htaccess` and
`data/.htaccess`). The dev-only `deploy/router.php` is not uploaded either.
To rotate the token or change the viewer password, regenerate on the server
(delete `data/config.php`, `.htaccess`, `.htpasswd` and re-run
`first_setup.php`, or edit the files directly).

---

## 9. Dependencies and files

```
├── .github/workflows/
│   ├── ci.yml                       # CI: build + artifact (push/PR)
│   └── prod-deploy.yml              # CI: build + FTPS deploy (prod/manual)
├── README.md                        # user/client guide
├── QUICK_START.md                   # post-upload server setup (first_setup flow)
├── TECH.md                          # this document
├── deploy/                          # server side (uploaded via CI/manually)
│   ├── first_setup.php              # one-time bootstrap: token + htpasswd + htaccess + seed
│   ├── example.htaccess             # Basic Auth template (deployed; copied to .htaccess by first_setup)
│   ├── router.php                   # DEV ONLY (PHP built-in server router)
│   ├── api/
│   │   ├── .htaccess                # rewrite + Authorization header
│   │   ├── metrics.php              # the WHOLE backend (routing included)
│   │   ├── HELP.html                # API reference (humans / AI agents)
│   │   └── openapi.yaml             # OpenAPI 3.0 spec of the API
│   └── data/
│       ├── .htaccess                # deny all
│       ├── config.php               # template (CHANGE_ME) - real one is generated on the server
│       └── kpi.sqlite               # seed DB (shipped as data/seed.sqlite by CI)
└── frontend/
    ├── package.json                 # vue, chart.js, vite
    ├── vite.config.js               # base './', outDir dist
    ├── index.html
    └── src/
        ├── main.js                  # Vue bootstrap
        ├── App.vue                  # layout + data orchestration
        ├── api.js                   # fetch wrapper
        ├── metrics.js               # ZONE, zone(), formatNumber (utilities)
        ├── style.css                # fluid layout
        └── components/
            ├── MetricGauge.vue      # SVG needle gauge
            ├── DashboardGrid.vue    # dynamic grid from the config
            └── HistoryChart.vue     # Chart.js history
```

**Backend runtime dependencies**: none besides PHP + PDO_SQLITE.
**Frontend dependencies**: Vue 3, Chart.js (declared in `package.json`).

---

## 10. Extensibility

- **New metrics / threshold / weight changes**: `POST /api/config/metrics`
  (create/update) and `DELETE /api/config/metrics/{key}` (remove). No code or
  frontend change.
- **Title/subtitle changes**: `POST /api/config` (no code change).
- **New metric fields**: add a column to `config_metrics` + expose it in the
  API responses and in `HELP.html`/`openapi.yaml` (e.g. a unit of measure).
  Because config is no longer an opaque JSON blob, keep the schema in sync
  deliberately.
- **New endpoints**: add a route block in `metrics.php` + a rewrite in
  `deploy/api/.htaccess` + an entry in the dev `router.php` + document them
  in `HELP.html`/`openapi.yaml`.
- **Alerts / notifications**: the natural hook is `handle_post()` (after
  saving), comparing the index with the previous snapshots.
