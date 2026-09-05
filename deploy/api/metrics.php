<?php
/**
 * KPI Dashboard — metrics API (complete backend).
 *
 * Endpoints:
 *   GET    /api/config                     Read the active setup (Basic Auth).
 *   POST   /api/config                     Update title/subtitle (Bearer token).
 *   POST   /api/config/metrics             Create or update a metric (Bearer token).
 *   DELETE /api/config/metrics/{key}       Delete a metric (Bearer token).
 *   POST   /api/metrics                    Save a daily snapshot (Bearer token).
 *   GET    /api/metrics/latest             Latest snapshot (Basic Auth).
 *   GET    /api/metrics?from=&to=          History (Basic Auth, default 30 days).
 *
 * Dependencies: none (PHP 7.4+/8.x + PDO_SQLITE). No framework.
 *
 * Configuration model
 * -------------------
 * There are NO hardcoded/business metric defaults in the code. Metric
 * definitions live in their own table `config_metrics` (one row per metric:
 * key, name, why, G/Y/O thresholds, weight) and are managed EXCLUSIVELY
 * through the API (POST/DELETE /api/config/metrics). The dashboard title and
 * subtitle live in the single-row `config` table as separate columns (no JSON
 * blob holding the metrics).
 *
 * Scoring / trust model
 * ---------------------
 * The server NEVER trusts the client: the `index` field in the POST payload
 * is ignored and scores are recomputed from raw values + the ACTIVE
 * configuration. Weights do NOT need to sum to 1.00: the aggregate index is
 * the weighted mean
 *        index = round( Σ(score_i × weight_i) / Σ(weight_i) , 1 )
 * If Σ(weight) = 0 (or weight is missing and defaults to 1 for every metric
 * is not the case), the plain average of the scores is used.
 */

require_once __DIR__ . '/../data/config.php';

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

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($payload)) {
        json_response(['error' => 'invalid JSON body'], 400);
    }
    return $payload;
}

function default_title(): string
{
    return defined('DASHBOARD_TITLE') ? DASHBOARD_TITLE : 'Dashboard KPI';
}

function default_subtitle(): string
{
    return defined('DASHBOARD_SUBTITLE') ? DASHBOARD_SUBTITLE : '';
}

/* ------------------------------------------------------------------ *
 *  Database + schema (with legacy migration)
 * ------------------------------------------------------------------ */
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

function table_columns(PDO $pdo, string $table): array
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $r) {
        $cols[$r['name']] = true;
    }
    return $cols;
}

function init_schema(PDO $pdo): void
{
    // --- daily snapshots (raw values + stored score per metric/day) ---
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
    // --- aggregate snapshot per day ---
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS snapshot (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            date        DATE NOT NULL UNIQUE,
            aggregate   REAL NOT NULL,
            zone        TEXT NOT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );'
    );
    // --- metric definitions (managed via the API, NOT hardcoded) ---
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS config_metrics (
            metric_key  TEXT PRIMARY KEY,
            name        TEXT NOT NULL,
            why         TEXT NOT NULL DEFAULT \'\',
            G           REAL NOT NULL,
            Y           REAL NOT NULL,
            O           REAL NOT NULL,
            weight      REAL NOT NULL DEFAULT 1,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );'
    );

    // Detect a legacy install: the old `config` table stored the whole setup
    // (title/subtitle + metrics) as a JSON blob in a `json` column.
    $hasConfig = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='config'"
    )->fetch();
    $legacy = $hasConfig && isset(table_columns($pdo, 'config')['json']);

    if ($legacy) {
        $row = $pdo->query('SELECT json FROM config WHERE id = 1')->fetch();
        $migrated = false;
        if ($row && is_string($row['json'])) {
            $j = json_decode($row['json'], true);
            if (is_array($j)) {
                $title    = isset($j['title'])    ? (string) $j['title']    : default_title();
                $subtitle = isset($j['subtitle']) ? (string) $j['subtitle'] : default_subtitle();
                if (isset($j['metrics']) && is_array($j['metrics']) && $j['metrics'] !== []) {
                    $pdo->beginTransaction();
                    try {
                        $up = $pdo->prepare(
                            'INSERT INTO config_metrics (metric_key, name, why, G, Y, O, weight, updated_at)
                             VALUES (:k, :n, :w, :g, :y, :o, :wt, CURRENT_TIMESTAMP)
                             ON CONFLICT(metric_key) DO UPDATE SET
                                name = :n2, why = :w2, G = :g2, Y = :y2, O = :o2,
                                weight = :wt2, updated_at = CURRENT_TIMESTAMP'
                        );
                        foreach ($j['metrics'] as $k => $m) {
                            if (!is_string($k) || !is_array($m)) {
                                continue;
                            }
                            $up->execute([
                                ':k'  => $k,
                                ':n'  => (string) ($m['name'] ?? $k),   ':n2' => (string) ($m['name'] ?? $k),
                                ':w'  => (string) ($m['why'] ?? ''),     ':w2' => (string) ($m['why'] ?? ''),
                                ':g'  => (float) ($m['G'] ?? 0),          ':g2' => (float) ($m['G'] ?? 0),
                                ':y'  => (float) ($m['Y'] ?? 0),          ':y2' => (float) ($m['Y'] ?? 0),
                                ':o'  => (float) ($m['O'] ?? 0),          ':o2' => (float) ($m['O'] ?? 0),
                                ':wt' => (float) ($m['weight'] ?? 1),     ':wt2' => (float) ($m['weight'] ?? 1),
                            ]);
                        }
                        $pdo->commit();
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
                $migrated = true;
            }
        }
        // Rebuild `config` without the JSON blob (title/subtitle columns).
        $pdo->exec('DROP TABLE config;');
        $pdo->exec(
            'CREATE TABLE config (
                id          INTEGER PRIMARY KEY CHECK (id = 1),
                title       TEXT NOT NULL DEFAULT \'Dashboard KPI\',
                subtitle    TEXT NOT NULL DEFAULT \'\',
                updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );'
        );
        if ($migrated) {
            $ins = $pdo->prepare(
                'INSERT INTO config (id, title, subtitle, updated_at)
                 VALUES (1, :t, :s, CURRENT_TIMESTAMP)'
            );
            $ins->execute([':t' => $title ?? default_title(), ':s' => $subtitle ?? default_subtitle()]);
        }
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS config (
                id          INTEGER PRIMARY KEY CHECK (id = 1),
                title       TEXT NOT NULL DEFAULT \'Dashboard KPI\',
                subtitle    TEXT NOT NULL DEFAULT \'\',
                updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );'
        );
    }
}

/* ------------------------------------------------------------------ *
 *  Configuration access
 * ------------------------------------------------------------------ */
/** Dashboard title/subtitle (single-row config table or constants fallback). */
function config_meta(): array
{
    $pdo = db();
    $row = $pdo->query('SELECT title, subtitle FROM config WHERE id = 1')->fetch();
    if (!$row) {
        return ['title' => default_title(), 'subtitle' => default_subtitle()];
    }
    return ['title' => $row['title'], 'subtitle' => $row['subtitle']];
}

/** All configured metrics: metric_key => [name, why, G, Y, O, weight]. */
function metric_list(): array
{
    $pdo = db();
    $rows = $pdo->query(
        'SELECT metric_key, name, why, G, Y, O, weight FROM config_metrics ORDER BY metric_key'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['metric_key']] = [
            'name'   => $r['name'],
            'why'    => $r['why'],
            'G'      => (float) $r['G'],
            'Y'      => (float) $r['Y'],
            'O'      => (float) $r['O'],
            'weight' => (float) $r['weight'],
        ];
    }
    return $out;
}

/** Active configuration exposed by GET /api/config and used by the snapshot logic. */
function active_config(): array
{
    $meta = config_meta();
    return [
        'title'    => $meta['title'],
        'subtitle' => $meta['subtitle'],
        // Always a JSON object ({} when empty), keyed by metric_key.
        'metrics'  => (object) metric_list(),
    ];
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
 *  Validation helpers
 * ------------------------------------------------------------------ */
function valid_date(string $d): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

function valid_metric_key(string $key): bool
{
    // Keys travel in URLs (DELETE /api/config/metrics/{key}) so they must be
    // URL-safe. snake_case / kebab-case / dotted keys are fine.
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $key);
}

/**
 * Validate a metric definition (array with key/name/why/G/Y/O/weight).
 * On failure it emits a 400 and exits. Returns a normalized definition.
 */
function validate_metric(array $m): array
{
    $key = isset($m['key']) ? $m['key'] : ($m['metric_key'] ?? null);
    if (!is_string($key) || $key === '' || !valid_metric_key($key)) {
        json_response(['error' => 'invalid metric key (use letters, digits, _ . -)'], 400);
    }
    foreach (['G', 'Y', 'O'] as $f) {
        if (!isset($m[$f]) || !is_numeric($m[$f]) || (float) $m[$f] < 0) {
            json_response([
                'error' => 'threshold ' . $f . ' missing or invalid for "' . $key . '" (numeric >= 0)',
            ], 400);
        }
    }
    $weight = isset($m['weight']) && is_numeric($m['weight']) ? (float) $m['weight'] : 1.0;
    if ($weight < 0) {
        json_response(['error' => 'invalid weight for "' . $key . '" (numeric >= 0)'], 400);
    }
    return [
        'key'    => $key,
        'name'   => isset($m['name']) ? (string) $m['name'] : $key,
        'why'    => isset($m['why'])  ? (string) $m['why']  : '',
        'G'      => (float) $m['G'],
        'Y'      => (float) $m['Y'],
        'O'      => (float) $m['O'],
        'weight' => $weight,
    ];
}

/* ------------------------------------------------------------------ *
 *  Score formula (unchanged: 4 zones, red is open-ended above O)
 * ------------------------------------------------------------------ */
function score_metric(float $v, float $G, float $Y, float $O): float
{
    if ($v <= 0) {
        return 0.0; // green floor
    }
    if ($G > 0 && $v <= $G) {
        return 25.0 * $v / $G;
    }
    if ($v <= $Y) {                       // yellow band [G+1, Y] -> [26, 50]
        $lo = $G + 1;
        $hi = $Y;
        if ($hi <= $lo) {
            return 50.0;
        }
        return 26.0 + ($v - $lo) / ($hi - $lo) * 24.0;
    }
    if ($v <= $O) {                       // orange band [Y+1, O] -> [51, 75]
        $lo = $Y + 1;
        $hi = $O;
        if ($hi <= $lo) {
            return 75.0;
        }
        return 51.0 + ($v - $lo) / ($hi - $lo) * 24.0;
    }
    // red band [O+1, 2*(O+1)] -> [76, 100], saturates at 2*(O+1)
    $lo = $O + 1;
    $hi = 2 * ($O + 1);
    if ($v >= $hi) {
        return 100.0;
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
 *  Title / subtitle (GET + POST /api/config)
 * ------------------------------------------------------------------ */
function handle_config(): void
{
    check_basic();
    json_response(active_config());
}

function handle_config_post(): void
{
    check_bearer();
    $payload = read_json_body();

    if (isset($payload['metrics'])) {
        json_response([
            'error' => 'metrics are managed with POST/DELETE /api/config/metrics, not inside /api/config',
        ], 400);
    }
    if (!isset($payload['title']) && !isset($payload['subtitle'])) {
        json_response(['error' => 'nothing to update: provide "title" and/or "subtitle"'], 400);
    }

    $meta     = config_meta();
    $title    = isset($payload['title'])    ? (string) $payload['title']    : $meta['title'];
    $subtitle = isset($payload['subtitle']) ? (string) $payload['subtitle'] : $meta['subtitle'];

    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO config (id, title, subtitle, updated_at)
         VALUES (1, :t, :s, CURRENT_TIMESTAMP)
         ON CONFLICT(id) DO UPDATE SET title = :t2, subtitle = :s2, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([':t' => $title, ':s' => $subtitle, ':t2' => $title, ':s2' => $subtitle]);

    json_response(['ok' => true, 'title' => $title, 'subtitle' => $subtitle]);
}

/* ------------------------------------------------------------------ *
 *  Metric CRUD (POST + DELETE /api/config/metrics[/{key}])
 * ------------------------------------------------------------------ */
function handle_metric_post(): void
{
    check_bearer();
    $payload = read_json_body();
    $def = validate_metric($payload);

    $pdo = db();
    $exists = $pdo->prepare('SELECT 1 FROM config_metrics WHERE metric_key = :k');
    $exists->execute([':k' => $def['key']]);
    $created = $exists->fetch() === false;

    $stmt = $pdo->prepare(
        'INSERT INTO config_metrics (metric_key, name, why, G, Y, O, weight, updated_at)
         VALUES (:k, :n, :w, :g, :y, :o, :wt, CURRENT_TIMESTAMP)
         ON CONFLICT(metric_key) DO UPDATE SET
            name = :n2, why = :w2, G = :g2, Y = :y2, O = :o2,
            weight = :wt2, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':k'   => $def['key'],
        ':n'   => $def['name'],   ':n2'  => $def['name'],
        ':w'   => $def['why'],    ':w2'  => $def['why'],
        ':g'   => $def['G'],      ':g2'  => $def['G'],
        ':y'   => $def['Y'],      ':y2'  => $def['Y'],
        ':o'   => $def['O'],      ':o2'  => $def['O'],
        ':wt'  => $def['weight'], ':wt2' => $def['weight'],
    ]);

    $metric = [
        'name'   => $def['name'],
        'why'    => $def['why'],
        'G'      => $def['G'],
        'Y'      => $def['Y'],
        'O'      => $def['O'],
        'weight' => $def['weight'],
    ];
    json_response(['ok' => true, 'created' => $created, 'key' => $def['key'], 'metric' => $metric]);
}

function handle_metric_delete(string $key): void
{
    check_bearer();
    $key = urldecode($key);
    if (!valid_metric_key($key)) {
        json_response(['error' => 'invalid metric key'], 400);
    }

    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM config_metrics WHERE metric_key = :k');
    $stmt->execute([':k' => $key]);
    if ($stmt->rowCount() === 0) {
        json_response(['error' => 'metric not found: ' . $key], 404);
    }

    // Historical rows in `metrics` for this key are kept (they are simply no
    // longer part of the active configuration and are filtered out of the
    // GET responses). Re-creating the same key later restores the history.
    json_response(['ok' => true, 'key' => $key, 'deleted' => true]);
}

/* ------------------------------------------------------------------ *
 *  Daily snapshots
 * ------------------------------------------------------------------ */
function handle_post(): void
{
    check_bearer();
    $payload = read_json_body();

    if (!isset($payload['date']) || !is_string($payload['date']) || !valid_date($payload['date'])) {
        json_response(['error' => 'invalid date (expected YYYY-MM-DD)'], 400);
    }
    $date = $payload['date'];

    $metrics = isset($payload['metrics']) && is_array($payload['metrics'])
        ? $payload['metrics']
        : null;
    if ($metrics === null) {
        json_response(['error' => 'missing or invalid "metrics" field'], 400);
    }

    $setup = metric_list(); // metric_key => [name, why, G, Y, O, weight]
    if ($setup === []) {
        json_response([
            'error' => 'no metrics configured yet: create them first with POST /api/config/metrics',
        ], 400);
    }

    // Every ACTIVE metric must be present (unknown extra keys are ignored).
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

    // Recompute scores + weighted aggregate index (weights are normalized).
    $scores = [];
    $weighted = 0.0;
    $totalWeight = 0.0;
    foreach ($setup as $key => $t) {
        $s = round(score_metric($values[$key], $t['G'], $t['Y'], $t['O']), 1);
        $scores[$key] = $s;
        $weighted += $s * $t['weight'];
        $totalWeight += $t['weight'];
    }
    if ($totalWeight > 0) {
        $index = $weighted / $totalWeight;
    } elseif ($scores !== []) {
        $index = array_sum($scores) / count($scores); // no weights: plain average
    } else {
        $index = 0.0;
    }
    $index = round($index, 1);
    $zone  = zone($index);

    // Upsert (replaces the snapshot of the same date, atomically).
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

function handle_latest(): void
{
    check_basic();
    $pdo = db();

    $snap = $pdo->query('SELECT date, aggregate, zone FROM snapshot ORDER BY date DESC LIMIT 1')->fetch();
    if (!$snap) {
        json_response(['date' => null, 'index' => null, 'zone' => null, 'metrics' => []]);
    }

    $activeKeys = array_fill_keys(array_keys(metric_list()), true);

    $stmt = $pdo->prepare('SELECT metric_key, value, score FROM metrics WHERE date = :d');
    $stmt->execute([':d' => $snap['date']]);

    $metrics = [];
    foreach ($stmt as $row) {
        if (!isset($activeKeys[$row['metric_key']])) {
            continue; // deleted/no-longer-active metrics are not exposed
        }
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
    $activeKeys = array_fill_keys(array_keys(metric_list()), true);

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
            if (isset($byDate[$row['date']]) && isset($activeKeys[$row['metric_key']])) {
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
 *  Routing (no framework)
 * ------------------------------------------------------------------ */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path   = rtrim((string) $path, '/');

// Order matters: metric-key paths before the generic /config and /metrics.
$m = null;
if (preg_match('#/config/metrics/([^/]+)$#', $path, $m)) {
    // single metric resource: DELETE /api/config/metrics/{key}
    if ($method === 'DELETE') {
        handle_metric_delete($m[1]);
    } elseif ($method === 'GET') {
        json_response(['error' => 'read the full setup with GET /api/config'], 405);
    } else {
        json_response(['error' => 'method not allowed'], 405);
    }
} elseif (substr($path, -15) === '/config/metrics') {
    // metric collection: POST /api/config/metrics
    if ($method === 'POST') {
        handle_metric_post();
    } elseif ($method === 'GET') {
        json_response(['error' => 'read the full setup with GET /api/config'], 405);
    } else {
        json_response(['error' => 'method not allowed'], 405);
    }
} elseif (substr($path, -7) === '/config') {
    if ($method === 'GET') {
        handle_config();
    } elseif ($method === 'POST') {
        handle_config_post();
    } else {
        json_response(['error' => 'method not allowed'], 405);
    }
} elseif (substr($path, -7) === '/latest') {
    if ($method === 'GET') {
        handle_latest();
    } else {
        json_response(['error' => 'method not allowed'], 405);
    }
} elseif (substr($path, -8) === '/metrics') {
    if ($method === 'GET') {
        handle_history();
    } elseif ($method === 'POST') {
        handle_post();
    } else {
        json_response(['error' => 'method not allowed'], 405);
    }
} else {
    json_response(['error' => 'endpoint not found'], 404);
}
