/**
 * Fetch wrapper for the API.
 * Paths are relative ("api/...") so the dashboard works both in the webroot
 * and in a subfolder.
 */

const BASE = 'api';

async function request(path) {
  const res = await fetch(`${BASE}/${path}`, {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
  });
  if (!res.ok) {
    let msg = `HTTP error ${res.status}`;
    try {
      const body = await res.json();
      if (body && body.error) msg = body.error;
    } catch (_) { /* non-JSON body */ }
    throw new Error(msg);
  }
  return res.json();
}

/** GET /api/config — metrics setup (keys, names, descriptions, weights, ranges). */
export function fetchConfig() {
  return request('config');
}

/** GET /api/metrics/latest — latest snapshot. */
export function fetchLatest() {
  return request('metrics/latest');
}

/** GET /api/metrics?from=…&to=… — history (optional parameters). */
export function fetchHistory(from, to) {
  const qs = new URLSearchParams();
  if (from) qs.set('from', from);
  if (to) qs.set('to', to);
  const query = qs.toString();
  return request(`metrics${query ? `?${query}` : ''}`);
}
