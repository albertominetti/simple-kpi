<?php
/**
 * KPI Dashboard — backend configuration.
 *
 * This file lives OUTSIDE the webroot (or in a protected folder via
 * deploy/data/.htaccess with "Require all denied"). It must NEVER be served
 * directly by the web server.
 *
 * This file provides the DEFAULT metrics setup (const METRICS). The client
 * can override it at runtime by sending POST /api/config; the active
 * configuration is then stored in the DB and exposed via GET /api/config.
 *
 * Deployment instructions:
 *  1. Replace API_TOKEN with a long random string (e.g. generated with
 *     `openssl rand -hex 32`). Give the same token to the collector.
 *  2. (Optional) Set BASIC_AUTH_USER / BASIC_AUTH_PASS if you want PHP to
 *     also verify Basic Auth in addition to the dashboard .htaccess.
 *     Leave them empty to rely on the .htaccess for GET authentication.
 *  3. Adapt METRICS to your context: each entry defines the key, name,
 *     description ("why"), G/Y/O thresholds and weight. Weights must sum
 *     to 1.
 */

const API_TOKEN = 'CHANGE_ME_long_random_string_use_openssl_rand_hex_32';
const DB_PATH   = __DIR__ . '/kpi.sqlite';

// Optional Basic Auth (defence in depth for the GETs).
// Leave empty to rely on the dashboard .htaccess.
const BASIC_AUTH_USER = '';
const BASIC_AUTH_PASS = '';

// Title/subtitle shown by the frontend (no company references).
const DASHBOARD_TITLE    = 'Dashboard KPI';
const DASHBOARD_SUBTITLE = 'updated daily at 20:00';

/**
 * Default metrics setup (sent to the frontend via GET /api/config).
 *
 * Key => [
 *   'name'   => display name shown in the UI,
 *   'why'    => short "why it matters" shown in the UI,
 *   'G'/'Y'/'O' => zone thresholds (green/yellow/orange),
 *   'weight' => weight in the weighted sum (total = 1.00),
 * ]
 */
const METRICS = [
    'quotes_to_prepare'          => ['name' => 'Quotes to prepare',               'why' => 'quick replies generate more orders',         'G' => 0,    'Y' => 3,     'O' => 6,     'weight' => 0.15],
    'orders_to_fulfil'           => ['name' => 'Orders to fulfil',                'why' => 'fast fulfilment keeps trust',                'G' => 0,    'Y' => 2,     'O' => 5,     'weight' => 0.12],
    'emails_to_triage'           => ['name' => 'Emails to triage',                'why' => 'quick replies improve trust',                'G' => 5,    'Y' => 15,    'O' => 30,    'weight' => 0.12],
    'overdue_invoices'           => ['name' => 'Overdue invoices (over 2 months)','why' => 'slow payments, tolerated up to 2 months',    'G' => 0,    'Y' => 2,     'O' => 5,     'weight' => 0.06],
    'overdue_invoices_amount'    => ['name' => 'Overdue invoices amount',         'why' => 'the higher the amount, the more it weighs',   'G' => 0,    'Y' => 1000,  'O' => 5000,  'weight' => 0.06],
    'delivery_notes_to_invoice'  => ['name' => 'Delivery notes to invoice',       'why' => 'to be invoiced by the 10th of next month',    'G' => 0,    'Y' => 2,     'O' => 5,     'weight' => 0.08],
    'upcoming_deadlines'         => ['name' => 'Upcoming deadlines (7 days)',     'why' => 'planning ahead avoids surprises',             'G' => 2,    'Y' => 5,     'O' => 10,    'weight' => 0.08],
    'purchase_quotes_to_review'  => ['name' => 'Purchase quotes to review',       'why' => 'closing quickly unlocks supplies',            'G' => 0,    'Y' => 2,     'O' => 5,     'weight' => 0.06],
    'digital_work_in_progress'   => ['name' => 'Digital work in progress',        'why' => 'active projects to complete',                'G' => 0,    'Y' => 2,     'O' => 5,     'weight' => 0.05],
    'marketing_tasks_to_do'      => ['name' => 'Marketing tasks to do',           'why' => 'constant communication = visibility',         'G' => 0,    'Y' => 3,     'O' => 7,     'weight' => 0.05],
    'office_tasks_to_do'         => ['name' => 'Office tasks to do',              'why' => 'administrative backlog to clear',             'G' => 0,    'Y' => 2,     'O' => 5,     'weight' => 0.05],
    'open_management_issues'     => ['name' => 'Open management issues',          'why' => 'pending decisions to close',                 'G' => 0,    'Y' => 2,     'O' => 5,     'weight' => 0.06],
    'machinery_to_fix'           => ['name' => 'Machinery to fix',                'why' => 'machine downtime = production at risk',      'G' => 0,    'Y' => 1,     'O' => 3,     'weight' => 0.06],
];
/* The weights sum to exactly 1.00 (100%). */
