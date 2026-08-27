<?php
/**
 * KPI Dashboard — metrics API (complete backend).
 *
 * Endpoints:
 *   POST /api/config              Save/update the metrics setup (Bearer token).
 *   GET  /api/config              Metrics setup for the frontend (Basic Auth).
 *   POST /api/metrics             Save a daily snapshot (Bearer token).
 *   GET  /api/metrics/latest      Latest snapshot for the gauges (Basic Auth).
 *   GET  /api/metrics?from=&to=   History for the charts (Basic Auth, default 30 days).
 *
 * Dependencies: none (PHP 7.4+/8.x + PDO_SQLITE). No framework.
 * The configuration (keys, names, thresholds, weights) is sent by the client
 * with POST /api/config and stored in the DB; if absent, the defaults in
 * config.php are used. Scores and the aggregate index are RECOMPUTED
 * server-side from the raw values and the active configuration, never
 * trusting the client.
 */

require_once __DIR__ . '/../data/config.php';

/* ------------------------------------------------------------------ *
 *  The metrics configuration (keys, names, G/Y/O thresholds, weights)
 *  can be sent by the client with POST /api/config and is stored in the
 *  DB (config table). If it has not been sent yet, the defaults in
 *  deploy/data/config.php (METRICS const) are used. The frontend reads
 *  it with GET /api/config — no metric is hardcoded in the code.
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
 * Active configuration: the one saved by the client (config table) if
 * present, otherwise the defaults in deploy/data/config.php (METRICS const).
 */
function active_config(): array
{
    $db = config_db();
    if ($db !== null && isset($db['metrics']) && is_array($db['metrics']) && $db['metrics'] !== []) {
        return $db;
    }

    // fallback: defaults in config.php
    $setup = [];
    foreach (METRICS as $key => $t) {
        $setup[$key] = [
            'name'   => $t['name'] ?? $key,
            'why'    => $t['why'] ?? '',
            'G'      => (float) $t['G'],
            'Y'      => (float) $t['Y'],
            'O'      => (float) $t['O'],
            'weight' => (float) $t['weight'],
        ];
    }
    return [
        'title'    => defined('DASHBOARD_TITLE') ? DASHBOARD_TITLE : 'Dashboard KPI',
        'subtitle' => defined('DASHBOARD_SUBTITLE') ? DASHBOARD_SUBTITLE : '',
        'metrics'  => $setup,
    ];
}

function init_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS metrics (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            date        DATE NOT NULL,
            metric_key  TEXT NOT NULL,
            value       REAL NOT NULL,
            score       REAL NOT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(date, metric_key)
        );'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS snapshot (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            date        DATE NOT NULL UNIQUE,
            aggregate   REAL NOT NULL,
            zone        TEXT NOT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS config (
            id          INTEGER PRIMARY KEY CHECK (id = 1),
            json        TEXT NOT NULL,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );'
    );
}

/**
 * Score 0–100 for a metric, with piecewise linear interpolation.
 * Bands:
 *   green   [0, G]            -> [0, 25]
 *   yellow  [G+1, Y]          -> [26, 50]
 *   orange  [Y+1, O]          -> [51, 75]
 *   red     [O+1, 2*(O+1)]    -> [76, 100]  (saturates at 2*(O+1))
 */
function score_metric(float $v, float $G, float $Y, float $O): float
{
    if ($v <= 0) {
        return 0.0; // green floor
    }

    // green band (only if G > 0, otherwise green applies only to v == 0)
    if ($G > 0 && $v <= $G) {
        return 25.0 * $v / $G;
    }

    // yellow band [G+1, Y] -> [26, 50]
    if ($v <= $Y) {
        $lo = $G + 1;
        $hi = $Y;
        if ($hi <= $lo) {
            return 50.0; // degenerate band: single value, yellow top
        }
        return 26.0 + ($v - $lo) / ($hi - $lo) * 24.0;
    }

    // orange band [Y+1, O] -> [51, 75]
    if ($v <= $O) {
        $lo = $Y + 1;
        $hi = $O;
        if ($hi <= $lo) {
            return 75.0; // degenerate band: single value, orange top
        }
        return 51.0 + ($v - $lo) / ($hi - $lo) * 24.0;
    }

    // red band [O+1, 2*(O+1)] -> [76, 100]
    $lo = $O + 1;
    $hi = 2 * ($O + 1);
    if ($v >= $hi) {
        return 100.0; // saturation
    }
    return 76.0 + ($v - $lo) / ($hi - $lo) * 24.0;
}

function zone(float $score): string
{
    if ($score <= 25) {
        return 'green';
    }
    if ($score <= 50) {
        return 'yellow';
    }
    if ($score <= 75) {
        return 'orange';
    }
    return 'red';
}

/* ------------------------------------------------------------------ *
 *  Authentication
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
    // If credentials are set in config.php, we also check them here
    // (defence in depth, on top of the dashboard .htaccess).
    if (defined('BASIC_AUTH_USER') && BASIC_AUTH_USER !== '' && BASIC_AUTH_USER !== 'CHANGE_ME') {
        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
        if (!hash_equals(BASIC_AUTH_USER, (string) $user)
            || !hash_equals(BASIC_AUTH_PASS, (string) $pass)) {
            header('WWW-Authenticate: Basic realm="KPI Dashboard"');
            json_response(['error' => 'unauthorized'], 401);
        }
    }
    // Otherwise authentication is already enforced by the .htaccess (Apache).
}

/* ------------------------------------------------------------------ *
 *  POST payload validation
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
        json_response(['error' => 'invalid JSON body'], 400);
    }

    // --- date ---
    if (!isset($payload['date']) || !is_string($payload['date']) || !valid_date($payload['date'])) {
        json_response(['error' => 'invalid date (expected YYYY-MM-DD)'], 400);
    }
    $date = $payload['date'];

    // --- metrics ---
    $metrics = isset($payload['metrics']) && is_array($payload['metrics'])
        ? $payload['metrics']
        : null;
    if ($metrics === null) {
        json_response(['error' => 'missing or invalid "metrics" field'], 400);
    }

    $config = active_config();
    $setup  = $config['metrics'];

    $values = [];
    foreach (array_keys($setup) as $key) {
        if (!array_key_exists($key, $metrics)) {
            json_response(['error' => 'missing metric: ' . $key], 400);
        }
        $v = $metrics[$key];
        if (!is_numeric($v) || (float) $v < 0) {
            json_response(['error' => 'invalid value for "' . $key . '" (numeric >= 0)'], 400);
        }
        $values[$key] = (float) $v;
    }

    // --- recompute scores + aggregate index server-side ---
    $scores = [];
    $index  = 0.0;
    foreach ($setup as $key => $t) {
        $s = round(score_metric($values[$key], $t['G'], $t['Y'], $t['O']), 1);
        $scores[$key] = $s;
        $index += $s * $t['weight'];
    }
    $index = round($index, 1);
    $zone  = zone($index);

    // --- upsert (replaces the snapshot of the same date) ---
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $delM = $pdo->prepare('DELETE FROM metrics WHERE date = :d');
        $delM->execute([':d' => $date]);
        $delS = $pdo->prepare('DELETE FROM snapshot WHERE date = :d');
        $delS->execute([':d' => $date]);

        $insM = $pdo->prepare(
            'INSERT INTO metrics (date, metric_key, value, score) VALUES (:d, :k, :v, :s)'
        );
        foreach ($values as $key => $val) {
            $insM->execute([':d' => $date, ':k' => $key, ':v' => $val, ':s' => $scores[$key]]);
        }

        $insS = $pdo->prepare(
            'INSERT INTO snapshot (date, aggregate, zone) VALUES (:d, :i, :z)'
        );
        $insS->execute([':d' => $date, ':i' => $index, ':z' => $zone]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => 'save error: ' . $e->getMessage()], 500);
    }

    json_response(['ok' => true, 'date' => $date, 'index' => $index, 'zone' => $zone]);
}

/* ------------------------------------------------------------------ *
 *  GET / POST config
 * ------------------------------------------------------------------ */
function handle_config(): void
{
    check_basic();
    json_response(active_config());
}

function handle_config_post(): void
{
    check_bearer();

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($payload)) {
        json_response(['error' => 'invalid JSON body'], 400);
    }

    $title    = isset($payload['title'])    ? (string) $payload['title']    : 'Dashboard KPI';
    $subtitle = isset($payload['subtitle']) ? (string) $payload['subtitle'] : '';

    $metrics = isset($payload['metrics']) && is_array($payload['metrics'])
        ? $payload['metrics']
        : null;
    if ($metrics === null || $metrics === []) {
        json_response(['error' => 'missing or empty "metrics" field'], 400);
    }

    $setup    = [];
    $weightTotal = 0.0;
    foreach ($metrics as $key => $m) {
        if (!is_string($key) || $key === '') {
            json_response(['error' => 'invalid metric key'], 400);
        }
        if (!is_array($m)) {
            json_response(['error' => 'invalid configuration for "' . $key . '"'], 400);
        }
        foreach (['G', 'Y', 'O'] as $f) {
            if (!isset($m[$f]) || !is_numeric($m[$f]) || (float) $m[$f] < 0) {
                json_response(['error' => 'threshold ' . $f . ' missing or invalid for "' . $key . '"'], 400);
            }
        }
        $weight = isset($m['weight']) && is_numeric($m['weight']) ? (float) $m['weight'] : 0.0;
        if ($weight < 0) {
            json_response(['error' => 'invalid weight for "' . $key . '"'], 400);
        }

        $setup[$key] = [
            'name'   => isset($m['name'])   ? (string) $m['name']   : $key,
            'why'    => isset($m['why'])    ? (string) $m['why']    : '',
            'G'      => (float) $m['G'],
            'Y'      => (float) $m['Y'],
            'O'      => (float) $m['O'],
            'weight' => $weight,
        ];
        $weightTotal += $weight;
    }

    if (abs($weightTotal - 1.0) > 0.0001) {
        json_response([
            'error' => 'weights must sum to 1.00 (current: ' . round($weightTotal, 4) . ')',
        ], 400);
    }

    $pdo  = db();
    $json = json_encode(
        ['title' => $title, 'subtitle' => $subtitle, 'metrics' => $setup],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $stmt = $pdo->prepare(
        'INSERT INTO config (id, json, updated_at) VALUES (1, :j, CURRENT_TIMESTAMP)
         ON CONFLICT(id) DO UPDATE SET json = :j2, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([':j' => $json, ':j2' => $json]);

    json_response(['ok' => true, 'metrics' => count($setup), 'title' => $title]);
}

function handle_latest(): void
{
    check_basic();
    $pdo = db();

    $snap = $pdo->query('SELECT date, aggregate, zone FROM snapshot ORDER BY date DESC LIMIT 1')->fetch();
    if (!$snap) {
        json_response(['date' => null, 'index' => null, 'zone' => null, 'metrics' => []]);
    }

    $stmt = $pdo->prepare('SELECT metric_key, value, score FROM metrics WHERE date = :d');
    $stmt->execute([':d' => $snap['date']]);

    $metrics = [];
    foreach ($stmt as $row) {
        $score = (float) $row['score'];
        $metrics[$row['metric_key']] = [
            'value' => (float) $row['value'],
            'score' => $score,
            'zone'  => zone($score),
        ];
    }

    json_response([
        'date'    => $snap['date'],
        'index'   => (float) $snap['aggregate'],
        'zone'    => $snap['zone'],
        'metrics' => $metrics,
    ]);
}

function handle_history(): void
{
    check_basic();

    $from = isset($_GET['from']) ? (string) $_GET['from'] : '';
    $to   = isset($_GET['to'])   ? (string) $_GET['to']   : '';

    if ($to === '') {
        $to = date('Y-m-d');
    }
    if ($from === '') {
        $from = date('Y-m-d', strtotime($to . ' -29 days')); // default: last 30 days (inclusive)
    }
    if (!valid_date($from) || !valid_date($to)) {
        json_response(['error' => 'invalid from/to parameters (expected YYYY-MM-DD)'], 400);
    }
    if ($from > $to) {
        json_response(['error' => '"from" cannot be later than "to"'], 400);
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT date, aggregate, zone FROM snapshot WHERE date BETWEEN :f AND :t ORDER BY date ASC'
    );
    $stmt->execute([':f' => $from, ':t' => $to]);

    $byDate = [];
    foreach ($stmt as $row) {
        $byDate[$row['date']] = [
            'date'    => $row['date'],
            'index'   => (float) $row['aggregate'],
            'zone'    => $row['zone'],
            'metrics' => [],
        ];
    }

    if ($byDate !== []) {
        $stmt2 = $pdo->prepare(
            'SELECT date, metric_key, value, score
             FROM metrics WHERE date BETWEEN :f AND :t ORDER BY date ASC'
        );
        $stmt2->execute([':f' => $from, ':t' => $to]);
        foreach ($stmt2 as $row) {
            if (isset($byDate[$row['date']])) {
                $score = (float) $row['score'];
                $byDate[$row['date']]['metrics'][$row['metric_key']] = [
                    'value' => (float) $row['value'],
                    'score' => $score,
                    'zone'  => zone($score),
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
// Endpoints reached: /api/metrics, /api/metrics/latest, /api/config
// (also works when the app is in a subfolder, e.g. /dashboard/api/...).
$isMetrics = $isLatest || $isConfig || substr($path, -8) === '/metrics';

if (!$isMetrics) {
    json_response(['error' => 'endpoint not found'], 404);
}

if ($method === 'POST') {
    if ($isLatest) {
        json_response(['error' => 'endpoint not found'], 404);
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
    json_response(['error' => 'method not allowed'], 405);
}
