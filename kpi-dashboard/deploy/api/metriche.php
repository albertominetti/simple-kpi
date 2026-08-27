<?php
/**
 * KPI Dashboard — API metriche (backend completo).
 *
 * Endpoint:
 *   POST /api/config                Salva/aggiorna il setup metriche (Bearer token).
 *   GET  /api/config                Setup metriche per il frontend (Basic Auth).
 *   POST /api/metriche              Salva uno snapshot giornaliero (Bearer token).
 *   GET  /api/metriche/latest       Ultimo snapshot per i gauges (Basic Auth).
 *   GET  /api/metriche?da=&a=       Storico per i grafici (Basic Auth, default 30gg).
 *
 * Dipendenze: nessuna (PHP 7.4+/8.x + PDO_SQLITE). Nessun framework.
 * La configurazione (chiavi, nomi, soglie, pesi) è inviata dal cliente con
 * POST /api/config e salvata nel DB; se assente si usano i default in
 * config.php. I punteggi e l'indice vengono RICALCOLATI lato server a partire
 * dai valori grezzi e dalla configurazione attiva, mai fidandosi del client.
 */

require_once __DIR__ . '/../data/config.php';

/* ------------------------------------------------------------------ *
 *  La configurazione delle metriche (chiavi, nomi, soglie G/Y/O, pesi)
 *  può essere inviata dal cliente con POST /api/config e viene salvata
 *  nel DB (tabella config). Se non è ancora stata inviata si usano i
 *  default in deploy/data/config.php (const METRICHE). Il frontend la
 *  riceve con GET /api/config — nessuna metrica è hardcoded nel codice.
 * ------------------------------------------------------------------ */

/* ------------------------------------------------------------------ *
 *  Helpers
 * ------------------------------------------------------------------ */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');
        init_schema($pdo);
    }
    return $pdo;
}

function config_db(): ?array
{
    $pdo = db();
    $row = $pdo->query('SELECT json FROM config WHERE id = 1')->fetch();
    if (!$row) {
        return null;
    }
    $dec = json_decode($row['json'], true);
    return is_array($dec) ? $dec : null;
}

/**
 * Configurazione attiva: quella salvata dal cliente (tabella config) se
 * presente, altrimenti il default in deploy/data/config.php (const METRICHE).
 */
function config_attiva(): array
{
    $db = config_db();
    if ($db !== null && isset($db['metriche']) && is_array($db['metriche']) && $db['metriche'] !== []) {
        return $db;
    }

    // fallback: default in config.php
    $setup = [];
    foreach (METRICHE as $key => $t) {
        $setup[$key] = [
            'nome'   => $t['nome'] ?? $key,
            'perche' => $t['perche'] ?? '',
            'G'      => (float) $t['G'],
            'Y'      => (float) $t['Y'],
            'O'      => (float) $t['O'],
            'peso'   => (float) $t['peso'],
        ];
    }
    return [
        'titolo'      => defined('DASHBOARD_TITOLO') ? DASHBOARD_TITOLO : 'Dashboard KPI',
        'sottotitolo' => defined('DASHBOARD_SOTTOTITOLO') ? DASHBOARD_SOTTOTITOLO : '',
        'metriche'    => $setup,
    ];
}

function init_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS metriche (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            data          DATE NOT NULL,
            metrica_key   TEXT NOT NULL,
            valore        REAL NOT NULL,
            score         REAL NOT NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(data, metrica_key)
        );'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS snapshot (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            data          DATE NOT NULL UNIQUE,
            indice           REAL NOT NULL,
            zona          TEXT NOT NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS config (
            id          INTEGER PRIMARY KEY CHECK (id = 1),
            json        TEXT NOT NULL,
            aggiornata  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );'
    );
}

/**
 * Punteggio 0–100 per una metrica, con interpolazione lineare a tratti.
 * Bande (§4.1 + suggerimento implementativo):
 *   verde   [0, G]            -> [0, 25]
 *   gialla  [G+1, Y]          -> [26, 50]
 *   arancio [Y+1, O]          -> [51, 75]
 *   rossa   [O+1, 2*(O+1)]    -> [76, 100]  (satura a 2*(O+1))
 */
function score_metric(float $v, float $G, float $Y, float $O): float
{
    if ($v <= 0) {
        return 0.0; // pavimento verde
    }

    // banda verde (solo se G > 0, altrimenti il verde vale solo per v == 0)
    if ($G > 0 && $v <= $G) {
        return 25.0 * $v / $G;
    }

    // banda gialla [G+1, Y] -> [26, 50]
    if ($v <= $Y) {
        $lo = $G + 1;
        $hi = $Y;
        if ($hi <= $lo) {
            return 50.0; // banda degenere: un solo valore, cima gialla
        }
        return 26.0 + ($v - $lo) / ($hi - $lo) * 24.0;
    }

    // banda arancio [Y+1, O] -> [51, 75]
    if ($v <= $O) {
        $lo = $Y + 1;
        $hi = $O;
        if ($hi <= $lo) {
            return 75.0; // banda degenere: un solo valore, cima arancio
        }
        return 51.0 + ($v - $lo) / ($hi - $lo) * 24.0;
    }

    // banda rossa [O+1, 2*(O+1)] -> [76, 100]
    $lo = $O + 1;
    $hi = 2 * ($O + 1);
    if ($v >= $hi) {
        return 100.0; // saturazione
    }
    return 76.0 + ($v - $lo) / ($hi - $lo) * 24.0;
}

function zona(float $score): string
{
    if ($score <= 25) {
        return 'verde';
    }
    if ($score <= 50) {
        return 'giallo';
    }
    if ($score <= 75) {
        return 'arancione';
    }
    return 'rosso';
}

/* ------------------------------------------------------------------ *
 *  Autenticazione
 * ------------------------------------------------------------------ */
function check_bearer(): void
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!is_string($auth) || !preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
        json_response(['error' => 'unauthorized'], 401);
    }
    if (!hash_equals(API_TOKEN, $m[1])) {
        json_response(['error' => 'unauthorized'], 401);
    }
}

function check_basic(): void
{
    // Se sono configurate credenziali nel config.php, verifichiamo anche qui
    // (difesa in profondità oltre al .htaccess del dashboard).
    if (defined('BASIC_AUTH_USER') && BASIC_AUTH_USER !== '' && BASIC_AUTH_USER !== 'CHANGE_ME') {
        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
        if (!hash_equals(BASIC_AUTH_USER, (string) $user)
            || !hash_equals(BASIC_AUTH_PASS, (string) $pass)) {
            header('WWW-Authenticate: Basic realm="KPI Dashboard"');
            json_response(['error' => 'unauthorized'], 401);
        }
    }
    // Altrimenti l'autenticazione è già garantita dal .htaccess (Apache).
}

/* ------------------------------------------------------------------ *
 *  Validazione payload POST
 * ------------------------------------------------------------------ */
function valid_date(string $d): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

function handle_post(): void
{
    check_bearer();

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($payload)) {
        json_response(['error' => 'body JSON non valido'], 400);
    }

    // --- data ---
    if (!isset($payload['data']) || !is_string($payload['data']) || !valid_date($payload['data'])) {
        json_response(['error' => 'data non valida (atteso YYYY-MM-DD)'], 400);
    }
    $data = $payload['data'];

    // --- metriche ---
    $metriche = isset($payload['metriche']) && is_array($payload['metriche'])
        ? $payload['metriche']
        : null;
    if ($metriche === null) {
        json_response(['error' => 'campo "metriche" mancante o non valido'], 400);
    }

    $config = config_attiva();
    $setup  = $config['metriche'];

    $values = [];
    foreach (array_keys($setup) as $key) {
        if (!array_key_exists($key, $metriche)) {
            json_response(['error' => 'metrica mancante: ' . $key], 400);
        }
        $v = $metriche[$key];
        if (!is_numeric($v) || (float) $v < 0) {
            json_response(['error' => 'valore non valido per "' . $key . '" (numerico >= 0)'], 400);
        }
        $values[$key] = (float) $v;
    }

    // --- ricalcolo punteggi + indice lato server ---
    $scores = [];
    $indice = 0.0;
    foreach ($setup as $key => $t) {
        $s = round(score_metric($values[$key], $t['G'], $t['Y'], $t['O']), 1);
        $scores[$key] = $s;
        $indice += $s * $t['peso'];
    }
    $indice = round($indice, 1);
    $zona = zona($indice);

    // --- upsert (sostituisce lo snapshot della stessa data) ---
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $delM = $pdo->prepare('DELETE FROM metriche WHERE data = :d');
        $delM->execute([':d' => $data]);
        $delS = $pdo->prepare('DELETE FROM snapshot WHERE data = :d');
        $delS->execute([':d' => $data]);

        $insM = $pdo->prepare(
            'INSERT INTO metriche (data, metrica_key, valore, score) VALUES (:d, :k, :v, :s)'
        );
        foreach ($values as $key => $val) {
            $insM->execute([':d' => $data, ':k' => $key, ':v' => $val, ':s' => $scores[$key]]);
        }

        $insS = $pdo->prepare(
            'INSERT INTO snapshot (data, indice, zona) VALUES (:d, :i, :z)'
        );
        $insS->execute([':d' => $data, ':i' => $indice, ':z' => $zona]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => 'errore salvataggio: ' . $e->getMessage()], 500);
    }

    json_response(['ok' => true, 'data' => $data, 'indice' => $indice, 'zona' => $zona]);
}

/* ------------------------------------------------------------------ *
 *  GET / POST config
 * ------------------------------------------------------------------ */
function handle_config(): void
{
    check_basic();
    json_response(config_attiva());
}

function handle_config_post(): void
{
    check_bearer();

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($payload)) {
        json_response(['error' => 'body JSON non valido'], 400);
    }

    $titolo      = isset($payload['titolo'])      ? (string) $payload['titolo']      : 'Dashboard KPI';
    $sottotitolo = isset($payload['sottotitolo']) ? (string) $payload['sottotitolo'] : '';

    $metriche = isset($payload['metriche']) && is_array($payload['metriche'])
        ? $payload['metriche']
        : null;
    if ($metriche === null || $metriche === []) {
        json_response(['error' => 'campo "metriche" mancante o vuoto'], 400);
    }

    $setup    = [];
    $pesoTot  = 0.0;
    foreach ($metriche as $key => $m) {
        if (!is_string($key) || $key === '') {
            json_response(['error' => 'chiave metrica non valida'], 400);
        }
        if (!is_array($m)) {
            json_response(['error' => 'configurazione non valida per "' . $key . '"'], 400);
        }
        foreach (['G', 'Y', 'O'] as $f) {
            if (!isset($m[$f]) || !is_numeric($m[$f]) || (float) $m[$f] < 0) {
                json_response(['error' => 'soglia ' . $f . ' mancante o non valida per "' . $key . '"'], 400);
            }
        }
        $peso = isset($m['peso']) && is_numeric($m['peso']) ? (float) $m['peso'] : 0.0;
        if ($peso < 0) {
            json_response(['error' => 'peso non valido per "' . $key . '"'], 400);
        }

        $setup[$key] = [
            'nome'   => isset($m['nome'])   ? (string) $m['nome']   : $key,
            'perche' => isset($m['perche']) ? (string) $m['perche'] : '',
            'G'      => (float) $m['G'],
            'Y'      => (float) $m['Y'],
            'O'      => (float) $m['O'],
            'peso'   => $peso,
        ];
        $pesoTot += $peso;
    }

    if (abs($pesoTot - 1.0) > 0.0001) {
        json_response([
            'error' => 'i pesi devono sommare a 1.00 (attuale: ' . round($pesoTot, 4) . ')',
        ], 400);
    }

    $pdo  = db();
    $json = json_encode(
        ['titolo' => $titolo, 'sottotitolo' => $sottotitolo, 'metriche' => $setup],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $stmt = $pdo->prepare(
        'INSERT INTO config (id, json, aggiornata) VALUES (1, :j, CURRENT_TIMESTAMP)
         ON CONFLICT(id) DO UPDATE SET json = :j2, aggiornata = CURRENT_TIMESTAMP'
    );
    $stmt->execute([':j' => $json, ':j2' => $json]);

    json_response(['ok' => true, 'metriche' => count($setup), 'titolo' => $titolo]);
}

function handle_latest(): void
{
    check_basic();
    $pdo = db();

    $snap = $pdo->query('SELECT data, indice, zona FROM snapshot ORDER BY data DESC LIMIT 1')->fetch();
    if (!$snap) {
        json_response(['data' => null, 'indice' => null, 'zona' => null, 'metriche' => []]);
    }

    $stmt = $pdo->prepare('SELECT metrica_key, valore, score FROM metriche WHERE data = :d');
    $stmt->execute([':d' => $snap['data']]);

    $metriche = [];
    foreach ($stmt as $row) {
        $score = (float) $row['score'];
        $metriche[$row['metrica_key']] = [
            'valore' => (float) $row['valore'],
            'score'  => $score,
            'zona'   => zona($score),
        ];
    }

    json_response([
        'data'     => $snap['data'],
        'indice'      => (float) $snap['indice'],
        'zona'     => $snap['zona'],
        'metriche' => $metriche,
    ]);
}

function handle_history(): void
{
    check_basic();

    $da = isset($_GET['da']) ? (string) $_GET['da'] : '';
    $a  = isset($_GET['a'])  ? (string) $_GET['a']  : '';

    if ($a === '') {
        $a = date('Y-m-d');
    }
    if ($da === '') {
        $da = date('Y-m-d', strtotime($a . ' -29 days')); // default: ultimi 30 giorni (inclusi)
    }
    if (!valid_date($da) || !valid_date($a)) {
        json_response(['error' => 'parametri da/a non validi (atteso YYYY-MM-DD)'], 400);
    }
    if ($da > $a) {
        json_response(['error' => 'il parametro "da" non può essere successivo ad "a"'], 400);
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT data, indice, zona FROM snapshot WHERE data BETWEEN :da AND :a ORDER BY data ASC'
    );
    $stmt->execute([':da' => $da, ':a' => $a]);

    $byDate = [];
    foreach ($stmt as $row) {
        $byDate[$row['data']] = [
            'data'     => $row['data'],
            'indice'      => (float) $row['indice'],
            'zona'     => $row['zona'],
            'metriche' => [],
        ];
    }

    if ($byDate !== []) {
        $stmt2 = $pdo->prepare(
            'SELECT data, metrica_key, valore, score
             FROM metriche WHERE data BETWEEN :da AND :a ORDER BY data ASC'
        );
        $stmt2->execute([':da' => $da, ':a' => $a]);
        foreach ($stmt2 as $row) {
            if (isset($byDate[$row['data']])) {
                $score = (float) $row['score'];
                $byDate[$row['data']]['metriche'][$row['metrica_key']] = [
                    'valore' => (float) $row['valore'],
                    'score'  => $score,
                    'zona'   => zona($score),
                ];
            }
        }
    }

    json_response(array_values($byDate));
}

/* ------------------------------------------------------------------ *
 *  Routing
 * ------------------------------------------------------------------ */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path   = rtrim((string) $path, '/');

$isLatest = substr($path, -7) === '/latest';
$isConfig = substr($path, -7) === '/config';
// Endpoint raggiunti: /api/metriche, /api/metriche/latest, /api/config
// (funziona anche se l'app è in una sottocartella, es. /dashboard/api/...).
$isMetriche = $isLatest || $isConfig || substr($path, -9) === '/metriche';

if (!$isMetriche) {
    json_response(['error' => 'endpoint non trovato'], 404);
}

if ($method === 'POST') {
    if ($isLatest) {
        json_response(['error' => 'endpoint non trovato'], 404);
    }
    if ($isConfig) {
        handle_config_post();
    } else {
        handle_post();
    }
} elseif ($method === 'GET') {
    if ($isConfig) {
        handle_config();
    } elseif ($isLatest) {
        handle_latest();
    } else {
        handle_history();
    }
} else {
    json_response(['error' => 'metodo non consentito'], 405);
}
