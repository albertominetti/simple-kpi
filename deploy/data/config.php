<?php
/**
 * KPI Dashboard — backend configuration (deployment secret only).
 *
 * This file lives OUTSIDE the webroot (or in a protected folder via
 * deploy/data/.htaccess with "Require all denied"). It must NEVER be served
 * directly by the web server. It is generated once on the server by
 * first_setup.php and is never uploaded by CI.
 *
 * The ONLY secret stored here is API_TOKEN (the Bearer token used by the
 * collector). Everything else lives elsewhere:
 *   - SQLite path      -> derived by api/metrics.php from its own __DIR__
 *                         (deploy/data/kpi.sqlite)
 *   - title/subtitle   -> POST /api/config      (stored in the `config` table)
 *   - metric definitions -> POST/DELETE /api/config/metrics
 *                         (stored in the `config_metrics` table)
 *   - Basic Auth       -> handled by the root .htaccess (.htpasswd)
 *
 * Deployment instructions:
 *   Replace API_TOKEN with a long random string (e.g. generated with
 *   `openssl rand -hex 32`). Give the same token to the collector.
 */

const API_TOKEN = 'CHANGE_ME_long_random_string_use_openssl_rand_hex_32';
