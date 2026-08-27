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
| **JSON** | — | API data-exchange contract and configuration |

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
hardcoded metric, everything comes from the backend.

---

## 2. Architecture

```
┌──────────────────┐   POST /api/config (Bearer)     ┌──────────────────────────────┐
│   Client         │   POST /api/metrics (Bearer)    │   PHP API + SQLite (hosting) │
│   (collector)    │ ───────────────────────────────▶ │  - metrics.php (the whole    │
│                  │                                  │    backend, routing included)│
│   gathers data   │   GET /api/config (Basic)        │  - data/kpi.sqlite           │
│   from sources   │   GET /api/metrics/* (Basic)     │  - data/config.php (default) │
│                  │ ◀─────────────────────────────── │  - .htaccess (rewrite/auth)  │
└──────────────────┘                                  └──────────────┬───────────────┘
                                                                     │ GET (Basic Auth)
                                                             ┌───────▼────────┐
                                                             │  Browser       │
                                                             │  static Vue    │
                                                             │  (dist/)       │
                                                             └────────────────┘
```

- **Configuration** (keys, names, descriptions, weights, G/Y/O ranges,
  title): sent by the client with `POST /api/config`, stored in the DB and
  read by the frontend with `GET /api/config`. If absent, the **defaults**
  in `deploy/data/config.php` (const `METRICS`) are used.
- **Data**: the client sends the daily raw values with
  `POST /api/metrics`; the server recomputes scores and the aggregate index
  and exposes them via the GET endpoints.

---

## 3. API — contract and logic

### 3.1 Endpoints

| Method | Path | Auth | Function |
|---|---|---|---|
| POST | `/api/config` | Bearer | Save/update the configuration |
| GET | `/api/config` | Basic | Read the active configuration |
| POST | `/api/metrics` | Bearer | Save/replace the daily snapshot |
| GET | `/api/metrics/latest` | Basic | Latest snapshot (gauges) |
| GET | `/api/metrics?from=&to=` | Basic | History (default last 30 days) |

### 3.2 Routing

`metrics.php` acts as a **front controller** without a framework: it parses
`$_SERVER['REQUEST_URI']` with `substr()` and routes by method (POST/GET).
In Apache the `/api/*` requests reach `metrics.php` via `mod_rewrite`
(`.htaccess`); it also works in a subfolder (e.g. `/dashboard/api/...`).

The PHP built-in server (local development) does not process `.htaccess`:
the file `deploy/router.php` (dev only) emulates the rewrite.

### 3.3 Authentication — two levels

- **POST** (writes): **Bearer token**, compared in constant time with
  `hash_equals()` against `API_TOKEN` (in `config.php`). Prevents timing
  attacks. The `Authorization` header is made available to PHP with
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` in the `.htaccess`
  (essential on many shared CGI/FastCGI hosts).
- **GET** (reads): **HTTP Basic Auth** managed by the root `.htaccess`
  (`Require valid-user` + `.htpasswd`). As defence in depth, if
  `BASIC_AUTH_USER`/`BASIC_AUTH_PASS` are set in `config.php`, PHP also
  verifies the credentials.
- POST is **exempted** from Basic Auth in the `.htaccess`
  (`<RequireAny> Require valid-user / Require method POST </RequireAny>`),
  because it uses the Bearer token.

### 3.4 Validations (all server-side)

- `date` must be a real `YYYY-MM-DD` (regex + `DateTime::createFromFormat`).
- All keys of the active configuration must be present in the `metrics`
  payload, with numeric values ≥ 0 → otherwise **400**.
- `POST /api/config`: G/Y/O thresholds numeric ≥ 0, non-empty string keys,
  **weights must sum to 1.00** (tolerance 0.0001) → otherwise **400**.
- Missing/wrong token → **401** `{"error":"unauthorized"}`.
- Unknown endpoint → **404**; disallowed method → **405**.

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

-- Configuration sent by the client (single row)
CREATE TABLE config (
    id          INTEGER PRIMARY KEY CHECK (id = 1),  -- single row
    json        TEXT NOT NULL,          -- {title, subtitle, metrics:{...}}
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Design choices

- **Key-value metrics** (one row per key instead of fixed columns): when the
  client changes the metrics, no schema migration is needed — "zero
  migration". One day = N rows in `metrics` + 1 row in `snapshot`.
- **Upsert per date**: `DELETE` + `INSERT` in a transaction (keys can change
  between configurations, so DELETE is safer than row-by-row
  `INSERT OR REPLACE`). The transaction guarantees atomicity: either the
  whole snapshot is replaced or nothing is.
- **Config as JSON**: the configuration is an opaque document for the DB;
  JSON lets you change the structure (e.g. new metric properties) without
  touching the schema. Validation happens in PHP before saving.

---

## 5. Calculation formula

### 5.1 Score per metric (0–100)

Each raw value `v` is mapped to a 0–100 score via **piecewise linear
interpolation** on 4 bands defined by the G (green), Y (yellow), O (orange)
thresholds:

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
  v ≤ Y             → 26 + (v − (G+1)) / (Y − G) · 25
  v ≤ O             → 51 + (v − (Y+1)) / (O − Y) · 25
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

### 5.2 Aggregate index

```text
index = Σ ( score(metric_i) × weight_i )
```

- The **weights** are defined in the configuration and must sum to **1.00**.
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
configuration. The frontend uses directly the `score`/`zone`/`index`
returned by the API (no client-side recomputation).

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
| Writes | Bearer token, `hash_equals` comparison (constant time) |
| Reads | Basic Auth via `.htaccess` + optional PHP check |
| `data/` | `.htaccess` with `Require all denied` (Apache 2.4) + `Deny from all` (2.2) and `<FilesMatch>` on php/sqlite/db |
| Authorization header | `SetEnvIf` in the API `.htaccess` (CGI/FastCGI hosts) |
| SQL injection | PDO prepared statements everywhere (no concatenation) |
| XSS | Vue escapes text in templates; API returns only JSON |
| Path traversal | The dev router blocks `/data` and paths with `..` |
| Secrets | `API_TOKEN`/`BASIC_AUTH_*` in `data/config.php` (outside webroot, never served) |

---

## 8. Build and deploy

### 8.1 Prerequisites for building the frontend

- **Node.js ≥ 18** (20 LTS recommended) and **npm ≥ 9**.
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
├── .htaccess                  # Basic Auth (copied from public/.htaccess)
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
- The `.htaccess` from `public/` is copied automatically into `dist/`.

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

1. **Build** the frontend (8.2) → `frontend/dist/`.
2. **Upload via FTP / file manager**:
   - the content of `frontend/dist/` → webroot (or `/dashboard/`);
   - `deploy/api/` → `/api/`;
   - `deploy/data/` → outside the webroot if possible, otherwise `/data/`
     (still protected by its `.htaccess`);
   - `deploy/.htaccess` → webroot (a single root `.htaccess`: the one in
     `dist/` and the one in `deploy/` are identical, do not upload both).
3. **Generate `.htpasswd`** and set `AuthUserFile` in the root `.htaccess`.
4. **Set `API_TOKEN`** in `config.php` (and, if needed, Basic credentials).
5. (Optional) send the configuration with `POST /api/config`, or edit the
   defaults in `config.php`.
6. **Smoke test**: `curl -u user:password https://example.com/api/config`
   and `curl ... /api/metrics/latest`.

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
| `prod-deploy.yml` | push to `main` (or manual) | Builds the frontend and **publishes via FTP** (dist + api + data) |

To use `prod-deploy.yml` you need **secrets** in the repository
(Settings → Secrets and variables → Actions):

| Secret | Description |
|---|---|
| `FTP_HOST` | FTP host |
| `FTP_USERNAME` | FTP username |
| `FTP_PASSWORD` | FTP password |
| `FTP_DIR` | remote directory (e.g. `/` or `/dashboard/`) |

Note: `deploy/data/config.php` is **excluded** from the FTP deploy (it must
not overwrite the production configuration already set on the server). To
update token/credentials/setup, act directly on the remote file or use
`POST /api/config`.

---

## 9. Dependencies and files

```
├── .github/workflows/
│   ├── ci.yml                       # CI: build + artifact (push/PR)
│   └── prod-deploy.yml              # CI: build + FTP (main/manual)
├── README.md                        # user/client guide
├── TECH.md                          # this document
├── deploy/                          # server side (upload AS-IS)
│   ├── .htaccess                    # Basic Auth + POST exemption
│   ├── api/
│   │   ├── .htaccess                # rewrite + Authorization header
│   │   ├── metrics.php              # the WHOLE backend (routing included)
│   │   └── router.php               # DEV ONLY (built-in server)
│   └── data/
│       ├── .htaccess                # deny all
│       ├── config.php               # API_TOKEN, credentials, METRICS (default)
│       └── kpi.sqlite               # created on first access (not versioned)
└── frontend/
    ├── package.json                 # vue, chart.js, vite
    ├── vite.config.js               # base './', outDir dist
    ├── index.html
    ├── public/.htaccess             # Basic Auth (copied to dist/)
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

- **New metrics / weight-range changes**: `POST /api/config` (or the
  defaults in `config.php`). No code or frontend change.
- **New metric fields**: add them to the configuration JSON and to the
  rendering (e.g. unit of measure). The `config` DB is already JSON-ready.
- **New endpoints**: add an `if` block in the `metrics.php` routing + a
  rewrite in `.htaccess`.
- **Alerts / notifications**: the natural hook is `handle_post()` (after
  saving), comparing the index with the previous snapshots.
