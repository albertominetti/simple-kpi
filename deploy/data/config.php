<?php
/**
 * KPI Dashboard — backend configuration (deployment secrets + generic
 * fallback branding only). NO business metrics live here.
 *
 * This file lives OUTSIDE the webroot (or in a protected folder via
 * deploy/data/.htaccess with "Require all denied"). It must NEVER be served
 * directly by the web server.
 *
 * Metric definitions (key, name, why, G/Y/O thresholds, weight) are NOT in
 * this file. They are managed EXCLUSIVELY through the API and stored in the
 * config_metrics table:
 *   POST   /api/config/metrics            create / update one metric
 *   DELETE /api/config/metrics/{key}      delete a metric
 *   GET    /api/config                    read the active setup
 * The dashboard title/subtitle can be set with POST /api/config and are
 * stored in the single-row `config` table; the DASHBOARD_* constants below
 * are only a fallback used before any POST /api/config has been stored.
 *
 * See deploy/api/HELP.html and deploy/api/openapi.yaml (shipped with the
 * backend) for the full API reference.
 *
 * Deployment instructions:
 *  1. Replace API_TOKEN with a long random string (e.g. generated with
 *     `openssl rand -hex 32`). Give the same token to the collector.
 *  2. (Optional) Set BASIC_AUTH_USER / BASIC_AUTH_PASS if you want PHP to
 *     also verify Basic Auth in addition to the dashboard .htaccess.
 *     Leave them empty to rely on the .htaccess for GET authentication.
 */

const API_TOKEN = 'CHANGE_ME_long_random_string_use_openssl_rand_hex_32';
const DB_PATH   = __DIR__ . '/kpi.sqlite';

// Optional Basic Auth (defence in depth for the GETs).
// Leave empty to rely on the dashboard .htaccess.
const BASIC_AUTH_USER = '';
const BASIC_AUTH_PASS = '';

// Generic fallback title/subtitle shown before any POST /api/config is
// stored (NOT business metrics — only used as a white-label default).
const DASHBOARD_TITLE    = 'Dashboard KPI';
const DASHBOARD_SUBTITLE = 'updated daily at 20:00';
