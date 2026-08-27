# TECH.md — Tecnologie e logiche tecniche

Documento tecnico del progetto **Dashboard KPI**: stack, architettura,
scelte di design, formule e dettagli implementativi. È il riferimento per
chi deve mantenere o estendere il codice.

---

## 1. Stack tecnologico

### Backend (parte server, `deploy/`)

| Tecnologia | Versione | Uso |
|---|---|---|
| **PHP** | 7.4+ / 8.x (testato su 8.5) | API REST completa in un singolo file (`metriche.php`) |
| **PDO + SQLite** | estensione standard | Persistenza (zero configurazione, nessun DB server) |
| **Apache** | 2.2 / 2.4 | Hosting condiviso: `.htaccess`, `mod_rewrite`, Basic Auth |
| **JSON** | — | Contratto di scambio dati API e configurazione |

Requisiti minimi lato hosting: PHP con `PDO_SQLITE` (standard in PHP 7.4+/8.x),
Apache con `mod_rewrite` e supporto `.htaccess`. **Niente** Composer, framework,
MySQL o build server-side.

### Frontend (parte statica, `frontend/`)

| Tecnologia | Versione | Uso |
|---|---|---|
| **Vue 3** | ^3.5 | Framework UI (Composition API, `<script setup>`) |
| **Vite** | ^6 | Bundler per la build locale (`npm run build` → `dist/`) |
| **Chart.js** | ^4.4 | Grafico storico (line chart) |
| **SVG** | — | Gauge con ago disegnati a mano (nessuna libreria gauge) |
| **CSS Grid / Flexbox** | — | Layout fluido a tutta finestra |

Il frontend è **statico** (nessun runtime server-side): si carica in webroot
come file pronti. È **completamente generico**: non contiene metriche
hardcoded, riceve tutto dal backend.

---

## 2. Architettura

```
┌──────────────────┐   POST /api/config (Bearer)     ┌──────────────────────────────┐
│   Cliente        │   POST /api/metriche (Bearer)   │   PHP API + SQLite (shared hosting)  │
│   (collector)    │ ───────────────────────────────▶ │  - metriche.php (tutto il    │
│                  │                                  │    backend, routing incluso) │
│   raccoglie i    │   GET /api/config (Basic)        │  - data/kpi.sqlite           │
│   dati dalle     │   GET /api/metriche/* (Basic)    │  - data/config.php (default) │
│   sorgenti       │ ◀─────────────────────────────── │  - .htaccess (rewrite/auth)  │
└──────────────────┘                                  └──────────────┬───────────────┘
                                                                     │ GET (Basic Auth)
                                                             ┌───────▼────────┐
                                                             │  Browser       │
                                                             │  Vue 3 statico │
                                                             │  (dist/)       │
                                                             └────────────────┘
```

- **Configurazione** (chiavi, nomi, descrizioni, pesi, range G/Y/O, titolo):
  inviata dal cliente con `POST /api/config`, salvata nel DB e letta dal
  frontend con `GET /api/config`. In assenza, si usano i **default** in
  `deploy/data/config.php` (const `METRICHE`).
- **Dati**: il cliente invia ogni giorno i valori grezzi con
  `POST /api/metriche`; il server ricalcola punteggi e indice aggregato e
  li espone con le GET.

---

## 3. API — contratto e logica

### 3.1 Endpoint

| Metodo | Path | Auth | Funzione |
|---|---|---|---|
| POST | `/api/config` | Bearer | Salva/aggiorna la configurazione |
| GET | `/api/config` | Basic | Legge la configurazione attiva |
| POST | `/api/metriche` | Bearer | Salva/sostituisce lo snapshot del giorno |
| GET | `/api/metriche/latest` | Basic | Ultimo snapshot (gauge) |
| GET | `/api/metriche?da=&a=` | Basic | Storico (default ultimi 30 giorni) |

### 3.2 Routing

Il file `metriche.php` fa da **front controller** senza framework: parsa
`$_SERVER['REQUEST_URI']` con `substr()` e instrada su metodo
(POST/GET). In Apache le richieste `/api/*` arrivano a `metriche.php`
grazie a `mod_rewrite` (`.htaccess`); funziona anche in sottocartella
(es. `/dashboard/api/...`).

Il PHP built-in server (sviluppo locale) non processa `.htaccess`: il file
`deploy/router.php` (solo sviluppo) emula il rewrite.

### 3.3 Autenticazione — due livelli

- **POST** (scritture): **Bearer token** confrontato in tempo costante con
  `hash_equals()` contro `API_TOKEN` (in `config.php`). Previene attacchi
  timing. Header `Authorization` reso disponibile a PHP con
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` nel `.htaccess`
  (indispensabile su molti hosting condivisi CGI/FastCGI).
- **GET** (letture): **HTTP Basic Auth** gestita dal `.htaccess` di root
  (`Require valid-user` + `.htpasswd`). Come difesa in profondità, se
  `BASIC_AUTH_USER`/`BASIC_AUTH_PASS` sono impostati in `config.php`, anche
  il PHP verifica le credenziali.
- La POST è **esentata** dalla Basic Auth nel `.htaccess`
  (`<RequireAny> Require valid-user / Require method POST </RequireAny>`),
  perché usa il Bearer token.

### 3.4 Validazioni (tutte lato server)

- `data` deve essere `YYYY-MM-DD` reale (regex + `DateTime::createFromFormat`).
- Tutte le chiavi della configurazione attiva devono essere presenti nel
  payload `metriche`, con valori numerici ≥ 0 → altrimenti **400**.
- `POST /api/config`: soglie G/Y/O numeriche ≥ 0, chiavi stringa non vuote,
  **pesi devono sommare a 1.00** (tolleranza 0.0001) → altrimenti **400**.
- Token mancante/errato → **401** `{"error":"unauthorized"}`.
- Endpoint sconosciuto → **404**; metodo non consentito → **405**.

---

## 4. Database SQLite

File: `deploy/data/kpi.sqlite` (fuori dalla webroot; se dentro, bloccato
dal `.htaccess` di `data/`). Connessione `PDO` con
`ERRMODE_EXCEPTION`, `PRAGMA journal_mode = WAL` (letture/scritture
concorrenti), `PRAGMA foreign_keys = ON`.

### Schema

```sql
-- Valori grezzi + score per ogni metrica e giorno
CREATE TABLE metriche (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    data        DATE NOT NULL,          -- giorno dello snapshot
    metrica_key TEXT NOT NULL,          -- es. "ordini"
    valore      REAL NOT NULL,          -- valore grezzo inviato dal cliente
    score       REAL NOT NULL,          -- punteggio 0-100 ricalcolato
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(data, metrica_key)           -- idempotenza: 1 riga per (data, chiave)
);

-- Snapshot aggregato per giorno (indice + zona)
CREATE TABLE snapshot (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    data        DATE NOT NULL UNIQUE,   -- 1 snapshot per giorno
    indice         REAL NOT NULL,          -- indice aggregato 0-100
    zona        TEXT NOT NULL,          -- verde|giallo|arancione|rosso
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Configurazione inviata dal cliente (singola riga)
CREATE TABLE config (
    id          INTEGER PRIMARY KEY CHECK (id = 1),  -- riga singola
    json        TEXT NOT NULL,          -- {titolo, sottotitolo, metriche:{...}}
    aggiornata  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Scelte di design

- **Key-value per le metriche** (una riga per chiave invece di colonne
  fisse): quando il cliente cambia le metriche non serve alcuna migrazione
  dello schema — "zero migration". Un giorno = 13 righe in `metriche` +
  1 riga in `snapshot`.
- **Upsert per data**: `DELETE` + `INSERT` in transazione (le chiavi
  possono cambiare tra configurazioni, quindi DELETE è più sicuro di
  `INSERT OR REPLACE` riga per riga). La transazione garantisce atomicità:
  o tutto lo snapshot viene sostituito, o niente.
- **Config come JSON**: la configurazione è un documento opaco per il DB;
  il JSON permette di cambiare struttura (es. nuove proprietà per metrica)
  senza modificare lo schema. Validazione avviene in PHP prima del salvataggio.

---

## 5. Formula di calcolo

### 5.1 Score per metrica (0–100)

Ogni valore grezzo `v` è mappato a uno score 0–100 tramite **interpolazione
lineare a tratti** su 4 bande definite dalle soglie G (verde), Y (giallo),
O (arancione):

| Banda | Range valore | Range score |
|---|---|---|
| verde | `0 … G` | `0 – 25` |
| gialla | `G+1 … Y` | `26 – 50` |
| arancione | `Y+1 … O` | `51 – 75` |
| rossa | `O+1 … ∞` | `76 – 100` |

```text
score(v):
  v == 0            → 0
  v ≤ G (e G > 0)   → 25 · (v / G)
  v ≤ Y             → 26 + (v − (G+1)) / (Y − G) · 25
  v ≤ O             → 51 + (v − (Y+1)) / (O − Y) · 25
  v ≥ 2·(O+1)       → 100            (saturazione)
  altrimenti        → 76 + (v − (O+1)) / (O+1) · 24
```

Casi particolari:
- Se `G = 0`, il verde vale **solo** per `v == 0` (score 0); la banda
  gialla parte da `v = 1`.
- Bande "degenere" (es. `Y == G+1`): un solo valore → cima della banda
  (50 per gialla, 75 per arancione).
- La banda rossa satura a `2·(O+1)`: oltre quel valore lo score è 100.
- Arrotondamento finale a **1 decimale**.

### 5.2 Indice aggregato

```text
indice = Σ ( score(metrica_i) × peso_i )
```

- I **pesi** sono definiti nella configurazione e devono sommare a **1.00**.
- Arrotondamento a 1 decimale.

### 5.3 Zone

| Zona | Score | |
|---|---|---|
| verde | 0 – 25 | |
| giallo | 26 – 50 | |
| arancione | 51 – 75 | |
| rosso | 76 – 100 | |

La zona è calcolata sia per l'indice aggregato (colonna `zona` in
`snapshot`) sia per ogni metrica (in risposta, da `zona(score)`).

### 5.4 Trust model

Il server **non si fida mai del client**: il campo `indice` nel payload POST
è ignorato, e gli score sono ricalcolati dai valori grezzi + configurazione
attiva. Il frontend usa direttamente `score`/`zona`/`indice` restituiti
dall'API (nessun ricalcolo lato client).

---

## 6. Frontend — logica

### 6.1 Flusso dati (App.vue)

1. `onMounted` → `Promise.all([fetchConfig(), fetchUltimo()])`:
   - `GET /api/config` → `config` (titolo + metriche con nome/perche/G/Y/O/peso)
   - `GET /api/metriche/latest` → `ultimo` (`{data, indice, zona, metriche}`)
2. Header renderizzato dal `config.titolo` (white-label).
3. `MetricGauge` grande: ago su `ultimo.indice`, zona da `ultimo.zona`.
4. `DashboardGrid`: itera su `config.metriche` (non su chiavi hardcoded) e
   per ognuna mostra un mini-gauge con `score` (ago) e `valore` grezzo (testo).
5. `HistoryChart`: carica lo storico da `fetchStorico(da, a)`.

### 6.2 Gauge SVG (MetricGauge.vue)

- `viewBox 0 0 220 190`, centro `(110, 105)`, raggio 78.
- **Scala angolare**: 0 → 150°, 100 → 390° (=30°), 50 → 270° (alto).
  Sweep di **240°** in senso orario. L'ago è un `<line>` dal centro a
  `punto(angolo(valore))`, con lunghezza `R − 16`.
- **Arco colorato**: 4 segmenti SVG (`<path>` con `A` arc) per zona
  (verde 150–210°, giallo 210–270°, arancione 270–330°, rosso 330–390°).
- **Testo al centro senza overlap**: il valore è posto a `y=150` (vano
  inferiore dell'arco), mentre l'estremo più basso dell'ago è a `y≈136`;
  così il numero non viene mai coperto dall'ago, qualunque sia lo score.
- Il testo mostrato al centro è una **prop** (`testo`): per i mini-gauge è
  il valore grezzo formattato (`formatoNumero`), per il gauge principale è
  l'indice. In assenza di `testo`, formatta `valore`.

### 6.3 Storico (HistoryChart.vue)

- Chart.js line chart: asse Y 0–100 (step 25), asse X date.
- Punti colorati per zona (dal campo `zona` di ogni snapshot).
- Viste: 7 giorni / 30 giorni / personalizzato (due `<input type="date">`).
- Default: ultimi 30 giorni (back-end: `da = a − 29`).
- Alla distruzione del componente il chart viene distrutto (no memory leak).

### 6.4 Layout fluido

- `.app`: `min-height: 100vh`, flex column, gap.
- `.corpo`: grid `minmax(300px, .85fr) / minmax(0, 2fr)` → gauge grande a
  sinistra, griglia metriche a destra (scroll interno se serve).
- `.storico`: `flex: 1.1` sotto, a tutta larghezza.
- Media query `< 920px`: tutto in una colonna.

---

## 7. Sicurezza

| Aspetto | Implementazione |
|---|---|
| Scritture | Bearer token, confronto `hash_equals` (tempo costante) |
| Letture | Basic Auth via `.htaccess` + controllo PHP opzionale |
| `data/` | `.htaccess` con `Require all denied` (Apache 2.4) + `Deny from all` (2.2) e `<FilesMatch>` su php/sqlite/db |
| Header Authorization | `SetEnvIf` nel `.htaccess` dell'API (hosting CGI/FastCGI) |
| SQL injection | Prepared statement PDO ovunque (nessuna concatenazione) |
| XSS | Vue escapizza il testo nei template; API restituisce solo JSON |
| Path traversal | Il router di sviluppo blocca `/data` e i percorsi con `..` |
| Segreti | `API_TOKEN`/`BASIC_AUTH_*` in `data/config.php` (fuori webroot, mai servito) |

---

## 8. Build e deploy

### 8.1 Prerequisiti per compilare il frontend

- **Node.js ≥ 18** (consigliato 20 LTS) e **npm ≥ 9**.
  Verifica: `node -v && npm -v`.
- La build è **solo locale** (o CI): sul server hosting non c'è né npm né
  SSH, quindi si compila altrove e si carica il `dist/` risultante.

### 8.2 Compilazione del frontend

```bash
cd frontend

# 1) Installa le dipendenze (la prima volta, o dopo un cambio di package.json)
npm install
#   oppure, in CI / per riproduzioni esatte dal lockfile:
npm ci

# 2) Compila la produzione -> genera la cartella dist/
npm run build
```

Cosa produce `npm run build` (Vite):

```
frontend/dist/
├── .htaccess                  # Basic Auth (copiato da public/.htaccess)
├── index.html                 # HTML finale con asset referenziati
└── assets/
    ├── index-<hash>.js        # JS minificato e bundled (Vue + Chart.js + app)
    └── index-<hash>.css       # CSS minificato
```

Dettagli:
- `base: './'` in `vite.config.js` → gli asset sono referenziati con
  percorsi **relativi**: il `dist/` funziona sia in webroot sia in una
  sottocartella (es. `/dashboard/`).
- Il nome dei file in `assets/` contiene un **hash del contenuto**: a ogni
  build diversa cambia nome, così il browser non serve versioni in cache.
- `emptyOutDir: true` → il `dist/` viene svuotato prima di ogni build.
- L'estensione `.htaccess` di `public/` viene copiata automaticamente in
  `dist/`.

### 8.3 Comandi utili durante lo sviluppo

```bash
cd frontend

npm run dev          # server di sviluppo Vite (HMR) su http://localhost:5173
npm run preview      # serve il dist/ appena compilato su http://localhost:4173
```

> Nota: `npm run dev` serve **solo il frontend** (senza API). Per provare
> l'app completa (frontend + backend) usa il PHP built-in server descritto
> in 8.5, che serve `dist/` e `/api/*` sulla stessa origin.

### 8.4 Risoluzione problemi di build

| Problema | Causa probabile | Soluzione |
|---|---|---|
| `npm ERR! code ERESOLVE` | dipendenze incompatibili in `node_modules` | `rm -rf node_modules package-lock.json && npm install` |
| errore di sintassi in un `.vue` | file corrotto | `npm run build` riporta file e riga; correggere e ripetere |
| `dist/` con asset vecchi | cache browser | svuotare la cache / aprire in incognito (i nomi con hash cambiano a ogni build) |
| porta 5173/4173 occupata | altro processo | `npm run dev -- --port 5174` |

### 8.5 Deploy su shared hosting (no SSH)

1. **Compila** il frontend (8.2) → `frontend/dist/`.
2. **Carica via FTP / file manager**:
   - il contenuto di `frontend/dist/` → webroot (o `/dashboard/`);
   - `deploy/api/` → `/api/`;
   - `deploy/data/` → fuori dalla webroot se possibile, altrimenti `/data/`
     (protetta comunque dal suo `.htaccess`);
   - `deploy/.htaccess` → webroot (un solo `.htaccess` di root: quello del
     `dist/` e quello di `deploy/` sono identici, non caricarli entrambi).
3. **Genera `.htpasswd`** e imposta `AuthUserFile` nel `.htaccess` di root.
4. **Imposta `API_TOKEN`** in `config.php` (e, se serve, credenziali Basic).
5. (Facoltativo) invia la configurazione con `POST /api/config`, oppure
   modifica i default in `config.php`.
6. **Smoke test**: `curl -u utente:password https://esempio.it/api/config`
   e `curl ... /api/metriche/latest`.

### 8.6 Sviluppo locale (backend + frontend insieme)

```bash
cd kpi-dashboard
/home/zurich/.local/bin/php -S 0.0.0.0:8888 -t deploy deploy/router.php
```

Il `router.php` serve `dist/` e `/api/*` su una singola origin (come in
produzione). È un file **solo di sviluppo**: non va caricato sul server.

### 8.7 Workflow GitHub Actions

Il repository include due workflow in `.github/workflows/`:

| Workflow | Quando parte | Cosa fa |
|---|---|---|
| `ci.yml` | push/PR su qualsiasi ramo | Compila il frontend e carica `dist/` come **artifact** scaricabile |
| `prod-deploy.yml` | push su `main` (o manuale) | Compila il frontend e **pubblica su FTP** (dist + api + data) |

Per usare `prod-deploy.yml` servono i **secrets** nel repository
(Settings → Secrets and variables → Actions):

| Secret | Descrizione |
|---|---|
| `FTP_HOST` | host FTP (es. `ftp.tuodominio.it`) |
| `FTP_USERNAME` | utente FTP |
| `FTP_PASSWORD` | password FTP |
| `FTP_DIR` | directory remota (es. `/` o `/dashboard/`) |

Nota: `deploy/data/config.php` è **escluso** dal deploy FTP (non deve
sovrascrivere la configurazione di produzione già impostata sul server).
Per aggiornare token/credenziali/modificare il setup si agisce direttamente
sul file remoto o via `POST /api/config`.

---

## 9. Dipendenze e file

```
kpi-dashboard/
├── .github/workflows/
│   ├── ci.yml                       # CI: build + artifact (push/PR)
│   └── prod-deploy.yml              # CI: build + FTP (main/manuale)
├── README.md                        # guida utente/cliente
├── TECH.md                          # questo documento
├── deploy/                             # parte server (upload AS-IS)
│   ├── .htaccess                       # Basic Auth + esenzione POST
│   ├── api/
│   │   ├── .htaccess                   # rewrite + header Authorization
│   │   ├── metriche.php                # TUTTO il backend (routing incluso)
│   │   └── router.php                  # SOLO sviluppo (built-in server)
│   └── data/
│       ├── .htaccess                   # deny all
│       ├── config.php                  # API_TOKEN, credenziali, METRICHE (default)
│       └── kpi.sqlite                  # creato al primo accesso (non versionato)
└── frontend/
    ├── package.json                    # vue, chart.js, vite
    ├── vite.config.js                  # base './', outDir dist
    ├── index.html
    ├── public/.htaccess                # Basic Auth (copiato in dist/)
    └── src/
        ├── main.js                     # bootstrap Vue
        ├── App.vue                     # layout + orchestrazione dati
        ├── api.js                      # wrapper fetch
        ├── metrics.js                  # ZONE, zona(), formatoNumero (utility)
        ├── style.css                   # layout fluido
        └── components/
            ├── MetricGauge.vue         # gauge SVG con ago
            ├── DashboardGrid.vue       # griglia dinamica dalle config
            └── HistoryChart.vue        # Chart.js storico
```

**Dipendenze runtime del backend**: nessuna oltre PHP + PDO_SQLITE.
**Dipendenze frontend**: Vue 3, Chart.js (dichiarate in `package.json`).

---

## 10. Estendibilità

- **Nuove metriche / cambio pesi-range**: `POST /api/config` (o default in
  `config.php`). Nessuna modifica al codice né al frontend.
- **Nuovi campi per metrica**: si aggiungono al JSON di configurazione e
  al rendering (es. unità di misura). Il DB `config` è già pronto (JSON).
- **Nuovi endpoint**: si aggiunge un blocco `if` nel routing di
  `metriche.php` + una rewrite in `.htaccess`.
- **Alert / notifiche**: punto di aggancio naturale è `handle_post()`
  (dopo il salvataggio), confrontando l'indice con gli snapshot precedenti.
