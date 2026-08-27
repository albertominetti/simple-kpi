<?php
/**
 * KPI Dashboard — configurazione backend.
 *
 * Questo file vive FUORI dalla webroot (o comunque in una cartella protetta
 * da deploy/data/.htaccess con "Require all denied"). Non deve MAI essere
 * servito direttamente dal web server.
 *
 * Questo file è l'UNICA fonte di verità del setup: il frontend è generico e
 * riceve tutto via GET /api/config (chiavi, nomi, descrizioni, pesi, range)
 * e i dati puntuali via GET /api/metriche*.
 *
 * Istruzioni di deploy:
 *  1. Sostituisci API_TOKEN con una stringa casuale lunga (es. generata con
 *     `openssl rand -hex 32`). Consegnare lo stesso token al collector.
 *  2. (Opzionale) Imposta BASIC_AUTH_USER / BASIC_AUTH_PASS se vuoi che anche
 *     il PHP verifichi la Basic Auth in aggiunta al .htaccess del dashboard.
 *     Se li lasci vuoti, l'autenticazione GET resta gestita dal solo .htaccess.
 *  3. Adatta METRICHE al tuo contesto: ogni voce definisce chiave, nome,
 *     descrizione ("perche"), soglie G/Y/O e peso. I pesi devono sommare a 1.
 */

const API_TOKEN = 'CHANGE_ME_long_random_string_use_openssl_rand_hex_32';
const DB_PATH   = __DIR__ . '/kpi.sqlite';

// Autenticazione Basic opzionale (difesa in profondità per le GET).
// Lasciare vuoto per affidarsi al .htaccess del dashboard.
const BASIC_AUTH_USER = '';
const BASIC_AUTH_PASS = '';

// Titolo/sottotitolo mostrati dal frontend (nessun riferimento aziendale).
const DASHBOARD_TITOLO      = 'Dashboard KPI';
const DASHBOARD_SOTTOTITOLO = 'aggiornamento giornaliero alle 20:00';

/**
 * Setup delle metriche (inviato al frontend via GET /api/config).
 *
 * Chiave => [
 *   'nome'   => nome descrittivo mostrato nella UI,
 *   'perche' => breve "perché conta" mostrato nella UI,
 *   'G'/'Y'/'O' => soglie delle zone (verde/giallo/arancio),
 *   'peso'   => peso nella somma pesata (totale = 1.00),
 * ]
 */
const METRICHE = [
    'esempio_uno' => ['nome' => 'Esempio uno', 'perche' => 'perché conta',  'G' => 0, 'Y' => 3, 'O' => 6, 'peso' => 0.6],
    'esempio_due' => ['nome' => 'Esempio due', 'perche' => 'perché conta',  'G' => 0, 'Y' => 2, 'O' => 5, 'peso' => 0.4],
];
/* Le somme dei pesi danno esattamente 1.00 (100%). */
