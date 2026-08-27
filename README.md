# Dashboard KPI

Dashboard KPI generica e configurabile: un indice aggregato 0–100 ottenuto
dalla somma pesata di più metriche, con gauge, mini-gauge e storico.

Il frontend è **completamente generico**: non contiene alcuna metrica
specifica né riferimenti aziendali. Tutto il setup arriva dal backend.

## Architettura

```
Collector ──(ogni giorno, POST Bearer)──▶ PHP API + SQLite
  raccoglie i dati                        (shared hosting, no SSH)
                                          GET /api/config        (setup)
                                          GET /api/metriche/*    (dati)
                                          Dashboard statico (Vue)
```

- **`deploy/`** — parte server, da caricare **così com'è** via FTP:
  - `api/metriche.php` — l'intero backend (PHP 7.4+/8.x, PDO_SQLITE, zero dipendenze)
  - `api/.htaccess` — rewrite per `/api/metriche`, `/api/metriche/latest`, `/api/config`
  - `data/config.php` — **unica fonte di verità del setup**:
    `API_TOKEN`, `DB_PATH`, credenziali Basic opzionali, `METRICHE`
    (chiavi, nomi, descrizioni, soglie G/Y/O, pesi), titolo/sottotitolo
  - `data/.htaccess` — blocca l'accesso a `data/`
  - `.htaccess` — Basic Auth per dashboard + GET API (POST esentata)
- **`frontend/`** — app **Vue 3 + Vite + Chart.js**, build solo in locale:
  `npm install && npm run build` → caricare il contenuto di `dist/` in webroot.
  Non contiene alcuna metrica hardcoded: carica il setup da `GET /api/config`.
- **`.github/workflows/prod-deploy.yml`** — (opzionale) CI: build + deploy FTP.

## API

| Metodo | Path | Auth | Descrizione |
|---|---|---|---|
| POST | `/api/config` | `Authorization: Bearer <API_TOKEN>` | **Invia/aggiorna il setup** (chiavi, nomi, descrizioni, pesi, range G/Y/O, titolo) |
| GET | `/api/config` | Basic Auth | **Recupera il setup** (stesso formato del POST) |
| POST | `/api/metriche` | `Authorization: Bearer <API_TOKEN>` | Salva/sostituisce lo snapshot del giorno (idempotente per data) |
| GET | `/api/metriche/latest` | Basic Auth | Ultimo snapshot per i gauges |
| GET | `/api/metriche?da=YYYY-MM-DD&a=YYYY-MM-DD` | Basic Auth | Storico (default ultimi 30 giorni) |

Il server **ricalcola** sempre punteggi (0–100) e indice aggregato dai valori
grezzi usando le soglie e i pesi della configurazione **attiva**: quella
inviata con `POST /api/config` se presente, altrimenti i default in
`deploy/data/config.php`. Non si fida del client. Lo snapshot di una data
già esistente viene **sostituito** (UNIQUE su `data`+`metrica_key`).

### GET /api/config — esempio di risposta

```json
{
  "titolo": "Dashboard KPI",
  "sottotitolo": "aggiornamento giornaliero alle 20:00",
  "metriche": {
    "esempio_uno":  { "nome": "Esempio uno",  "perche": "perché conta", "G": 0, "Y": 3, "O": 6, "peso": 0.6 },
    "esempio_due":  { "nome": "Esempio due",  "perche": "perché conta", "G": 0, "Y": 2, "O": 5, "peso": 0.4 }
  }
}
```

Il frontend renderizza i gauge dalle chiavi/nomi/descrizioni di questa
risposta: per cambiare le metriche basta inviare una nuova configurazione
con `POST /api/config` (o, come default, modificare `METRICHE` in
`deploy/data/config.php`) — nessuna modifica al frontend.

### POST /api/config — invio della configurazione

Formato identico a quello di GET. I **pesi devono sommare a 1.00**,
ogni metrica richiede le soglie `G`, `Y`, `O` (numeriche ≥ 0); `nome` e
`perche` sono opzionali (se mancano si usa la chiave / stringa vuota).

```bash
curl -X POST https://esempio.it/api/config \
  -H "Authorization: Bearer IL_TUO_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "titolo": "Dashboard Produzione",
    "sottotitolo": "aggiornato ogni giorno alle 20:00",
    "metriche": {
      "esempio_uno": { "nome": "Esempio uno", "perche": "perché conta", "G": 0, "Y": 3, "O": 6, "peso": 0.6 },
      "esempio_due": { "nome": "Esempio due", "perche": "perché conta", "G": 0, "Y": 2, "O": 5, "peso": 0.4 }
    }
  }'
```

Risposta: `200` `{"ok": true, "metriche": 2, "titolo": "Dashboard Produzione"}`.
Rifare la POST **sostituisce** la configurazione (nessuna duplicazione).

### GET /api/config — recupero della configurazione

```bash
curl -u utente:password https://esempio.it/api/config
```

Restituisce la configurazione **attiva** (quella inviata con POST, o i
default di `config.php` se non è mai stata inviata).

### POST /api/metriche — esempio

```bash
curl -X POST https://esempio.it/api/metriche \
  -H "Authorization: Bearer IL_TUO_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "data": "2026-08-24",
    "metriche": {
      "esempio_uno": 2,
      "esempio_due": 1
    },
    "indice": 0
  }'
```

Le chiavi del payload `metriche` devono corrispondere a quelle della
configurazione attiva (`GET /api/config`). Il campo `indice` è l'indice
aggregato (opzionale: il server lo ricalcola comunque).

## Guida all'uso per il cliente (collector)

Il **cliente** (il programma che raccoglie i dati) usa
l'API in 5 passi. Tutti gli esempi usano:

```bash
BASE=http://nuc.home.arpa:8888            # in produzione: https://tuodominio.it (o il tuo dominio)
TOKEN='IL_TUO_TOKEN_BEARER'               # consegnato dall'operatore (API_TOKEN in config.php)
USER='utente'; PASS='password'            # credenziali Basic Auth per le GET
```

### 1) Inviare la configurazione (chiavi, pesi, range)

`POST /api/config` (Bearer token) definisce il **setup**: chiavi delle
metriche, nomi, descrizioni, **pesi** e **range** (soglie G/Y/O). Si invia
una sola volta (o quando cambiano le metriche); il backend la salva nel DB.

```bash
curl -X POST "$BASE/api/config" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "titolo": "Dashboard Produzione",
    "metriche": {
      "ordini": { "nome": "Ordini da evadere", "perche": "evadere in fretta", "G": 0, "Y": 2, "O": 5, "peso": 0.6 },
      "email":  { "nome": "Email da smistare", "perche": "risposte rapide",   "G": 5, "Y": 15, "O": 30, "peso": 0.4 }
    }
  }'
```

### 2) Recuperare la configurazione (cosa deve inviare)

`GET /api/config` (Basic Auth) restituisce la configurazione **attiva**:
il cliente la legge per sapere **quali chiavi** usare nel POST e **come**
verranno valutate.

```bash
curl -u "$USER:$PASS" "$BASE/api/config"
```

Significato dei campi di ogni metrica:

| Campo | Cosa significa |
|---|---|
| `nome` | Etichetta mostrata nella dashboard |
| `perche` | Breve descrizione ("perché conta") mostrata sotto il nome |
| `G`, `Y`, `O` | **Range** delle zone: verde `[0..G]`, gialla `[G+1..Y]`, arancione `[Y+1..O]`, rossa `[O+1..∞]` |
| `peso` | **Peso** nella somma pesata (i pesi sommano a 1.00 = 100%) |

### 3) Inviare (o aggiornare) i valori del giorno

`POST /api/metriche` (Bearer token) salva uno snapshot giornaliero.
Il payload contiene la data e **una chiave per ogni metrica** con il suo
valore grezzo:

```bash
curl -X POST "$BASE/api/metriche" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "data": "2026-08-24",
    "metriche": {
      "ordini": 2,
      "email": 10
    },
    "indice": 0
  }'
```

Regole:
- `data` è la data dello snapshot (`YYYY-MM-DD`).
- Le chiavi in `metriche` **devono** essere esattamente quelle della
  configurazione attiva: se manca una chiave o c'è una chiave sconosciuta → `400`.
- I valori sono **numerici ≥ 0**.
- Il campo `indice` è opzionale e viene ignorato: il server **ricalcola**
  punteggi (0–100) e indice aggregato dalle soglie e dai pesi della
  configurazione attiva.
- Risposta di successo: `200` `{"ok": true, "data": "...", "indice": 55.8, "zona": "arancione"}`.

### 4) Aggiornare/correggere i valori di un giorno già inviato

L'inserimento è **idempotente per data**: rifare la POST con la **stessa
data** **sostituisce** lo snapshot (nessun duplicato, grazie al vincolo
UNIQUE su `data` + chiave). È il modo per correggere un errore o per
l'aggiornamento serale:

```bash
# Errore di battitura: invio di nuovo la stessa data con il valore giusto
curl -X POST "$BASE/api/metriche" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"data":"2026-08-24","metriche":{"ordini":5,"email":10},"indice":0}'
```

Lo snapshot del 24 diventa quello nuovo (ordini = 5), non si aggiunge
una seconda riga.

### 5) Recuperare i dati (per verificare o per il collector)

```bash
# Ultimo snapshot (per i gauges)
curl -u "$USER:$PASS" "$BASE/api/metriche/latest"

# Storico — default ultimi 30 giorni
curl -u "$USER:$PASS" "$BASE/api/metriche"

# Storico personalizzato
curl -u "$USER:$PASS" "$BASE/api/metriche?da=2026-08-01&a=2026-08-24"
```

Ogni metrica restituita include `valore` (grezzo), `score` (0–100) e
`zona` (verde/giallo/arancione/rosso), più l'`indice` aggregato e la sua zona.

### Riepilogo del flusso

```
1. POST /api/config            -> invia la configurazione (chiavi, pesi, range)
2. GET  /api/config            -> recupera la configurazione attiva
3. POST /api/metriche          -> invia i valori del giorno (Bearer)
4. POST /api/metriche (stessa data) -> aggiorna/corregge lo snapshot
5. GET  /api/metriche/latest e /api/metriche -> recupera dati e verifica
```

## Compilare il frontend

Prerequisiti: **Node.js ≥ 18** e **npm** (la build è locale/CI, non sul server).

```bash
cd frontend
npm install        # o npm ci (riproduzione esatta dal lockfile)
npm run build      # genera frontend/dist/ (HTML/CSS/JS minificati + .htaccess)
```

Per lo sviluppo: `npm run dev` (server Vite con hot-reload su :5173) e
`npm run preview` (serve il `dist/` su :4173). Dettagli, struttura del
`dist/` e risoluzione problemi: vedi **`TECH.md` §8**.

## CI / Deploy automatico (GitHub Actions)

Il repository include due workflow in `.github/workflows/`:

- **`ci.yml`** — a ogni push/PR compila il frontend, verifica l'output e
  carica `dist/` come **artifact** scaricabile (nessun deploy).
- **`prod-deploy.yml`** — a ogni push su `main` (o manuale) compila e
  **pubblica su FTP**: `dist/` in webroot + `api/` e `data/`. Esclude
  `.htaccess` di root e `data/config.php` per non sovrascrivere la
  configurazione di produzione.

Per attivare il deploy FTP servono i **secrets** del repository
(Settings → Secrets and variables → Actions): `FTP_HOST`, `FTP_USERNAME`,
`FTP_PASSWORD`, `FTP_DIR`. Dettagli: vedi **`TECH.md` §8.7**.

## Deploy su hosting condiviso (senza SSH)

1. **Build locale del frontend**: `cd frontend && npm install && npm run build`.
2. **Carica via FTP / file manager**:
   - il contenuto di `frontend/dist/` → webroot (o `/dashboard/`);
   - `deploy/api/` → `/api/`;
   - `deploy/data/` → fuori dalla webroot se possibile, altrimenti `/data/`
     (protetta comunque dal suo `.htaccess`);
   - `deploy/.htaccess` → webroot (un solo `.htaccess` di root: quello del
     `dist/` e quello di `deploy/` sono identici, non caricarli entrambi).
3. **Genera `.htpasswd`** (es. `htpasswd -c .htpasswd utente`) e aggiorna
   `AuthUserFile` nel `.htaccess` di root con il percorso reale.
4. **Imposta `API_TOKEN`** in `deploy/data/config.php` con una stringa casuale
   lunga (`openssl rand -hex 32`) e consegnala al collector.
5. **Personalizza il setup** — o inviando la configurazione con
   `POST /api/config` (vedi "Guida all'uso"), oppure modificando i default
   in `deploy/data/config.php` (`METRICHE`, titolo) — il frontend si adatta
   da solo.
6. **Smoke test**:

```bash
# Setup (Basic Auth)
curl -u utente:password https://esempio.it/api/config

# GET protetta (Basic Auth)
curl -u utente:password https://esempio.it/api/metriche/latest

# POST di prova (Bearer)
curl -X POST https://esempio.it/api/metriche \
  -H "Authorization: Bearer IL_TUO_TOKEN" -H "Content-Type: application/json" \
  -d '{"data":"2026-08-25","metriche":{...},"indice":0}'
```

**Nota sulla Basic Auth e la POST**: il `.htaccess` di root protegge dashboard
e GET ma **esenta le POST** (`<RequireAny> … <Require method POST>`). Se il
collettore non riuscisse a fare POST (Apache 2.2), sposta il `.htaccess` di
root in una sottocartella che contiene solo il dashboard e imposta
`BASIC_AUTH_USER`/`BASIC_AUTH_PASS` in `config.php` (la GET dell'API verrà
protetta dal PHP stesso).

## Note sulla formula

- Punteggio per metrica: interpolazione lineare a tratti su bande
  `verde [0,G]→[0,25]`, `gialla [G+1,Y]→[26,50]`, `arancio [Y+1,O]→[51,75]`,
  `rossa [O+1, 2·(O+1)]→[76,100]`; saturazione a `2·(O+1)` = 100.
- Indice aggregato = Σ punteggio × peso (pesi = 100%).
- Il backend (`score_metric` in PHP) calcola punteggi e indice; il frontend
  usa direttamente i valori restituiti dall'API (score e zona per il gauge,
  valore grezzo al centro).

## Struttura

```
├── .github/workflows/prod-deploy.yml
├── README.md
├── deploy/
│   ├── .htaccess
│   ├── api/{.htaccess, metriche.php}
│   └── data/{.htaccess, config.php}
└── frontend/
    ├── package.json
    ├── vite.config.js
    ├── index.html
    ├── public/.htaccess
    └── src/{main.js, App.vue, api.js, metrics.js, style.css, components/…}
```
