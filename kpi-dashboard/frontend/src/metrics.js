/**
 * Utility UI generiche.
 *
 * IMPORTANTE: il frontend NON contiene alcuna metrica specifica (né chiavi,
 * né nomi, né soglie, né pesi). Tutto il setup arriva dal backend via
 * GET /api/config; i dati puntuali via GET /api/metriche*.
 * Qui restano solo utility universali (zone e formattazione numeri).
 */

/** Colori e label delle zone (semantica universale verde/giallo/arancio/rosso). */
export const ZONE = {
  verde: { colore: '#22c55e', etichetta: 'Verde' },
  giallo: { colore: '#eab308', etichetta: 'Giallo' },
  arancione: { colore: '#f97316', etichetta: 'Arancione' },
  rosso: { colore: '#ef4444', etichetta: 'Rosso' },
};

/** Zona (stringa) per un punteggio 0–100 (fallback se il dato non la fornisce). */
export function zona(score) {
  const s = Number(score) || 0;
  if (s <= 25) return 'verde';
  if (s <= 50) return 'giallo';
  if (s <= 75) return 'arancione';
  return 'rosso';
}

/** Formatta un numero: intero senza decimali, altrimenti 1 decimale. */
export function formatoNumero(v) {
  const n = Number(v) || 0;
  if (Number.isInteger(n)) return String(n);
  return n.toFixed(1);
}
