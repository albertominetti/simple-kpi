<?php
/**
 * first_setup.php — one-time bootstrap for a brand-new server install.
 *
 * WHEN TO USE
 * -----------
 * After the first FTPS deploy on a server. The deploy ships the frontend,
 * api/, data/.htaccess, data/seed.sqlite and this script — but it NEVER
 * ships data/config.php, the root .htaccess, data/.htpasswd nor the live
 * data/kpi.sqlite (they are server-owned, generated exactly once here).
 *
 * Run it ONCE per server (over HTTPS):
 *
 *   curl -X POST https://your-domain.com/dashboard/first_setup.php \
 *        -H "Content-Type: application/json" \
 *        -d '{"user":"alice","pass":"SuperSecret123"}'
 *
 *   // optional: supply your own token instead of letting it generate one
 *   -d '{"user":"alice","pass":"SuperSecret123","token":"<your 64-hex-token>"}'
 *
 * WHAT IT DOES
 * ------------
 *   1. data/.htpasswd      <- Apache-compatible hash of the given user/pass
 *   2. ./.htaccess         <- generated from the template below, with
 *                             AuthUserFile pointing at data/.htpasswd
 *   3. data/kpi.sqlite     <- seeded from data/seed.sqlite, but ONLY if the
 *                             live DB is missing or empty (real data is
 *                             never overwritten)
 *   4. data/config.php     <- written LAST, with a real API_TOKEN. Its mere
 *                             existence with a non-placeholder token marks
 *                             the install as "initialized".
 *
 * SELF-SEALING / NEVER OVERRIDES
 * ------------------------------
 * If data/config.php already contains a real token, the script refuses to
 * run again (HTTP 409). Because config.php is written last, a failed run can
 * simply be retried. None of the server-owned files (config.php, .htpasswd,
 * .htaccess, kpi.sqlite) are ever uploaded by CI, so they cannot be
 * overwritten by a later deploy.
 *
 * AUTH
 * ----
 * POST is exempt from Basic Auth (see .htaccess <RequireAny>), so this can
 * run before any .htpasswd exists. You do NOT need a Bearer token (it is
 * created here) nor pre-existing Basic credentials.
 */

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function respond(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    if ($extra) {
        foreach ($extra as $k => $v) {
            echo "\n$k: $v";
        }
    }
    echo "\n";
    exit;
}

/** True when the live DB exists and already contains configuration/data. */
function live_db_has_data(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table'"
        )->fetchColumn();
        if ($tables === 0) {
            return false; // empty file / no schema yet
        }
        $rows = (int) $pdo->query(
            'SELECT (SELECT COUNT(*) FROM config_metrics) + (SELECT COUNT(*) FROM snapshot)'
        )->fetchColumn();
        return $rows > 0;
    } catch (Throwable $e) {
        return false; // unreadable -> treat as empty (will be replaced by seed)
    }
}

// ---------------------------------------------------------------------
// Locations (first_setup.php sits at the app root, data/ next to it)
// ---------------------------------------------------------------------
$root    = __DIR__;
$dataDir = $root . '/data';
$cfgFile   = $dataDir . '/config.php';
$htpasswd  = $dataDir . '/.htpasswd';
$htaccess  = $root . '/.htaccess';
$seedFile  = $dataDir . '/seed.sqlite';
$dbFile    = $dataDir . '/kpi.sqlite';

// ---------------------------------------------------------------------
// GET -> status only
// ---------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    $done = is_file($cfgFile)
        && (bool) preg_match(
            "/const API_TOKEN\s*=\s*'([^']+)'/",
            (string) @file_get_contents($cfgFile),
            $m
        )
        && $m[1] !== '' && strpos($m[1], 'CHANGE_ME') === false;
    respond(200,
        "first_setup.php — one-time server bootstrap.\n"
        . 'Initialized: ' . ($done ? 'yes' : 'no') . "\n"
        . "To set up, POST JSON with {\"user\":\"...\",\"pass\":\"...\"}\n"
        . 'e.g.  curl -X POST ' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com')
            . ($_SERVER['REQUEST_URI'] ?? '/first_setup.php') . ' -H "Content-Type: application/json"'
            . " -d '{\"user\":\"alice\",\"pass\":\"SuperSecret123\"}'\n"
    );
}

// ---------------------------------------------------------------------
// Guard: never overwrite an existing real token / initialized install
// ---------------------------------------------------------------------
if (is_file($cfgFile)) {
    $existing = (string) @file_get_contents($cfgFile);
    $hasReal  = (bool) preg_match(
        "/const API_TOKEN\s*=\s*'([^']+)'/",
        $existing,
        $m
    ) && $m[1] !== '' && strpos($m[1], 'CHANGE_ME') === false;
    if ($hasReal) {
        respond(409,
            "already initialized: data/config.php already contains an API token.\n"
            . "Refusing to overwrite the token, credentials, .htaccess or the live database."
        );
    }
}

// ---------------------------------------------------------------------
// Read + validate the request
// ---------------------------------------------------------------------
$raw    = (string) file_get_contents('php://input');
$body   = json_decode($raw, true);
if (!is_array($body)) {
    respond(400, 'invalid JSON body: send {"user":"...","pass":"..."}');
}

$user = trim((string) ($body['user'] ?? ''));
$pass = (string) ($body['pass'] ?? '');
if ($user === '' || strpbrk($user, ":\r\n") !== false) {
    respond(400, 'invalid "user": must be non-empty and must not contain ":" or newlines');
}
if (strlen($pass) < 8) {
    respond(400, '"pass" too short: use at least 8 characters');
}

$token = (string) ($body['token'] ?? '');
if ($token !== '' && !preg_match('/^[A-Za-z0-9]{16,128}$/', $token)) {
    respond(400, 'invalid "token": use 16-128 letters/digits (hex from `openssl rand -hex 32`)');
}
if ($token === '') {
    $token = bin2hex(random_bytes(32)); // 64 hex chars
}

// ---------------------------------------------------------------------
// data/ must exist and be writable
// ---------------------------------------------------------------------
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}
if (!is_dir($dataDir) || !is_writable($dataDir)) {
    respond(500,
        'data/ folder is not writable by PHP. Fix permissions and retry '
        . "(e.g. chmod 775 on '$dataDir')."
    );
}

// ---------------------------------------------------------------------
// 1) .htpasswd  (Apache 2.4 bcrypt: $2y$)
// ---------------------------------------------------------------------
$hash = password_hash($pass, PASSWORD_BCRYPT);
if ($hash === false) {
    respond(500, 'could not generate a password hash (bcrypt unavailable)');
}
if (@file_put_contents($htpasswd, $user . ':' . $hash . "\n") === false) {
    respond(500, "could not write '$htpasswd'");
}
@chmod($htpasswd, 0640);

// ---------------------------------------------------------------------
// 2) root .htaccess  (generated from the embedded template)
// ---------------------------------------------------------------------
$tpl = <<<'HTACCESS'
# Generated by first_setup.php — server-owned file. NEVER uploaded by CI,
# so this is the only place that knows the real AuthUserFile path.

# Basic Auth for the dashboard/GETs + Bearer-token access for the API.
# Apache 2.4 <RequireAny>: allowed if ANY holds:
#   1. Require valid-user            -> browser/dashboard + API GETs (Basic Auth)
#   2. Require env HAS_BEARER        -> request carries "Authorization: Bearer"
#                                      (the collector token; PHP validates it)
#   3. Require method POST/DELETE    -> collector writes (PHP validates token)
# PHP always validates the token/credentials; an invalid token still gets 401.
# Apache 2.2 (no mod_authz_core) -> plain Basic Auth for everything.

AuthType Basic
AuthName "KPI Dashboard"
AuthUserFile __AUTH_USER_FILE__

# Any request whose Authorization header starts with "Bearer " is let through
# Apache so PHP can validate the token (collector reads + writes).
SetEnvIf Authorization "^Bearer\s+" HAS_BEARER

<IfModule mod_authz_core.c>
    # Apache 2.4
    <RequireAny>
        Require valid-user
        Require env HAS_BEARER
        Require method POST
        Require method DELETE
    </RequireAny>
</IfModule>

<IfModule !mod_authz_core.c>
    # Apache 2.2 fallback
    Require valid-user
</IfModule>
HTACCESS;

$generated = str_replace('__AUTH_USER_FILE__', $htpasswd, $tpl);
if (strpos($generated, '__AUTH_USER_FILE__') !== false) {
    respond(500, 'internal error: .htaccess template placeholder was not replaced');
}
if (@file_put_contents($htaccess, $generated) === false) {
    respond(500, "could not write '$htaccess'");
}
@chmod($htaccess, 0644);

// ---------------------------------------------------------------------
// 3) seed the live DB from data/seed.sqlite (only if missing/empty)
// ---------------------------------------------------------------------
if (!live_db_has_data($dbFile)) {
    if (is_file($seedFile) && is_readable($seedFile)) {
        $tmp = $dbFile . '.seed-tmp';
        if (!@copy($seedFile, $tmp)) {
            respond(500, "could not copy seed to '$tmp' (is data/ writable?)");
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $dbFile)) {
            @unlink($tmp);
            respond(500, "could not move seed into place at '$dbFile'");
        }
    } else {
        // No seed shipped: leave the DB absent. The API will create an empty
        // schema on first use; the operator can define metrics via the API.
        // (When CI ships seed.sqlite this branch is never reached.)
    }
}

// ---------------------------------------------------------------------
// 4) config.php LAST — the "commit point" that marks the install as done
// ---------------------------------------------------------------------
$config = "<?php\n"
    . "// Generated by first_setup.php — server-owned. Never uploaded by CI.\n"
    . "// (A later FTPS deploy does NOT include this file and cannot overwrite it.)\n"
    . "\n"
    . "const API_TOKEN = " . var_export($token, true) . ";\n"
    . "const DB_PATH   = __DIR__ . '/kpi.sqlite';\n"
    . "\n"
    . "// Optional Basic Auth (defence in depth for GETs). Leave empty and let\n"
    . "// the .htaccess handle it (recommended when .htpasswd is configured).\n"
    . "const BASIC_AUTH_USER = '';\n"
    . "const BASIC_AUTH_PASS = '';\n"
    . "\n"
    . "// Fallback branding only (shown until a title/subtitle is stored in the\n"
    . "// seeded DB's config table — the seed already contains them).\n"
    . "const DASHBOARD_TITLE    = 'Dashboard KPI';\n"
    . "const DASHBOARD_SUBTITLE = 'updated daily at 20:00';\n";

if (@file_put_contents($cfgFile, $config) === false) {
    respond(500, "could not write '$cfgFile'");
}
@chmod($cfgFile, 0640);

// ---------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------
$seeded = is_file($dbFile);
respond(200,
    "Setup complete.\n"
    . 'API_TOKEN (Bearer) : ' . $token . "\n"
    . 'Dashboard user     : ' . $user . "\n"
    . 'Live database      : ' . ($seeded ? 'seeded from data/seed.sqlite' : 'not present (will be created empty by the API)') . "\n"
    . 'IMPORTANT          : save the API_TOKEN now - it is shown only once.'
);
