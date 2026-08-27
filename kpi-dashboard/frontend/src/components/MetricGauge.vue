<template>
  <div class="gauge">
    <svg
      :viewBox="'0 0 220 190'"
      class="gauge-svg"
      role="img"
      :aria-label="etichetta || 'Gauge'"
    >
      <!-- pista di fondo 0-100 -->
      <path :d="arco(150, 390)" class="pista" />

      <!-- segmenti colorati per zona -->
      <path
        v-for="seg in segmenti"
        :key="seg.zona"
        :d="seg.d"
        class="segmento"
        :stroke="seg.colore"
      />

      <!-- ago -->
      <line :x1="CX" :y1="CY" :x2="ago.x" :y2="ago.y" class="ago" />
      <circle :cx="CX" :cy="CY" r="8" class="perno" />
      <circle :cx="CX" :cy="CY" r="3.5" class="perno-centro" />

      <!-- Valore mostrato (sottostante) al centro, nel vano inferiore dell'arco:
           l'ago (che ruota sopra, sull'arco) non lo copre mai. -->
      <text :x="CX" :y="VALORE_Y" text-anchor="middle" class="testo-valore">
        {{ testoValore }}
      </text>
      <text :x="CX" :y="ZONA_Y" text-anchor="middle" class="testo-zona" :fill="coloreZona">
        {{ etichettaZona }}
      </text>
    </svg>
    <div v-if="etichetta" class="etichetta">{{ etichetta }}</div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { zona as zonaDi, ZONE } from '../metrics.js';

const props = defineProps({
  valore: { type: Number, default: 0 },     // 0-100 -> posizione dell'ago
  zona: { type: String, default: '' },       // zona già nota (es. dal server)
  testo: { type: String, default: '' },      // testo da mostrare al centro (default: valore)
  etichetta: { type: String, default: '' },
});

const CX = 110;
const CY = 105;
const R = 78;
// Il valore sta nel "vano" inferiore del gauge (sotto il perno dell'ago):
// l'ago punta da 150° a 390° (sweep 240° sopra), il suo estremo più basso
// è a y≈136, quindi il testo a y=150 non viene mai coperto.
const VALORE_Y = 150;
const ZONA_Y = 168;

// 0 -> 150° (in basso a sinistra), 100 -> 390° (=30°, in basso a destra),
// 50 -> 270° (in alto): sweep orario di 240°.
function angolo(v) {
  const val = Math.max(0, Math.min(100, Number(v) || 0));
  return 150 + (val / 100) * 240;
}

function punto(deg) {
  const rad = (deg * Math.PI) / 180;
  return { x: CX + R * Math.cos(rad), y: CY + R * Math.sin(rad) };
}

function arco(a, b) {
  const p1 = punto(a);
  const p2 = punto(b);
  const grande = b - a > 180 ? 1 : 0;
  return `M ${p1.x.toFixed(2)} ${p1.y.toFixed(2)} A ${R} ${R} 0 ${grande} 1 ${p2.x.toFixed(2)} ${p2.y.toFixed(2)}`;
}

const segmenti = computed(() => [
  { zona: 'verde', d: arco(150, 210), colore: ZONE.verde.colore },
  { zona: 'giallo', d: arco(210, 270), colore: ZONE.giallo.colore },
  { zona: 'arancione', d: arco(270, 330), colore: ZONE.arancione.colore },
  { zona: 'rosso', d: arco(330, 390), colore: ZONE.rosso.colore },
]);

const zonaCorrente = computed(() =>
  props.zona || zonaDi(props.valore)
);

const ago = computed(() => {
  const rad = (angolo(props.valore) * Math.PI) / 180;
  const lunghezza = R - 16;
  return {
    x: CX + lunghezza * Math.cos(rad),
    y: CY + lunghezza * Math.sin(rad),
  };
});

const testoValore = computed(() => {
  if (props.testo !== '') return props.testo;
  const v = Number(props.valore) || 0;
  return Number.isInteger(v) ? String(v) : v.toFixed(1);
});

const etichettaZona = computed(() => ZONE[zonaCorrente.value].etichetta);
const coloreZona = computed(() => ZONE[zonaCorrente.value].colore);
</script>
