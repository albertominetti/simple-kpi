/**
 * Wrapper fetch per l'API.
 * I path sono relativi ("api/...") così il dashboard funziona sia in
 * webroot sia in una sottocartella.
 */

const BASE = 'api';

async function richiedi(path) {
  const res = await fetch(`${BASE}/${path}`, {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
  });
  if (!res.ok) {
    let msg = `Errore HTTP ${res.status}`;
    try {
      const body = await res.json();
      if (body && body.error) msg = body.error;
    } catch (_) { /* corpo non JSON */ }
    throw new Error(msg);
  }
  return res.json();
}

/** GET /api/config — setup metriche (chiavi, nomi, descrizioni, pesi, range). */
export function fetchConfig() {
  return richiedi('config');
}

/** GET /api/metriche/latest — ultimo snapshot. */
export function fetchUltimo() {
  return richiedi('metriche/latest');
}

/** GET /api/metriche?da=…&a=… — storico (parametri opzionali). */
export function fetchStorico(da, a) {
  const qs = new URLSearchParams();
  if (da) qs.set('da', da);
  if (a) qs.set('a', a);
  const query = qs.toString();
  return richiedi(`metriche${query ? `?${query}` : ''}`);
}
