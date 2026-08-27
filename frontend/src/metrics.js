/**
 * Generic UI utilities.
 *
 * IMPORTANT: the frontend does NOT contain any specific metric (no keys,
 * names, thresholds or weights). The whole setup comes from the backend via
 * GET /api/config; point data via GET /api/metrics*.
 * Only universal utilities live here (zones and number formatting).
 */

/** Colors and labels of the zones (universal green/yellow/orange/red semantics). */
export const ZONE = {
  green:  { color: '#22c55e', label: 'Green' },
  yellow: { color: '#eab308', label: 'Yellow' },
  orange: { color: '#f97316', label: 'Orange' },
  red:    { color: '#ef4444', label: 'Red' },
};

/** Zone (string) for a 0–100 score (fallback if the data does not provide it). */
export function zone(score) {
  const s = Number(score) || 0;
  if (s <= 25) return 'green';
  if (s <= 50) return 'yellow';
  if (s <= 75) return 'orange';
  return 'red';
}

/** Formats a number: integer without decimals, otherwise 1 decimal. */
export function formatNumber(v) {
  const n = Number(v) || 0;
  if (Number.isInteger(n)) return String(n);
  return n.toFixed(1);
}
