<template>
  <div class="gauge">
    <svg
      :viewBox="'0 0 220 190'"
      class="gauge-svg"
      role="img"
      :aria-label="label || 'Gauge'"
    >
      <!-- background track 0-100 -->
      <path :d="arc(150, 390)" class="track" />

      <!-- colored zone segments -->
      <path
        v-for="seg in segments"
        :key="seg.zone"
        :d="seg.d"
        class="segment"
        :stroke="seg.color"
      />

      <!-- needle -->
      <line :x1="CX" :y1="CY" :x2="needle.x" :y2="needle.y" class="needle" />
      <circle :cx="CX" :cy="CY" r="8" class="pivot" />
      <circle :cx="CX" :cy="CY" r="3.5" class="pivot-center" />

      <!-- Displayed value (raw value) centered, in the lower area of the arc:
           the needle (which rotates above, on the arc) never covers it. -->
      <text :x="CX" :y="VALUE_Y" text-anchor="middle" class="value-text">
        {{ valueText }}
      </text>
      <text :x="CX" :y="ZONE_Y" text-anchor="middle" class="zone-text" :fill="zoneColor">
        {{ zoneLabel }}
      </text>
    </svg>
    <div v-if="label" class="label">{{ label }}</div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { zone as zoneOf, ZONE } from '../metrics.js';

const props = defineProps({
  value: { type: Number, default: 0 },     // 0-100 -> needle position
  zone: { type: String, default: '' },      // known zone (e.g. from the server)
  text: { type: String, default: '' },      // text to show at the center (default: value)
  label: { type: String, default: '' },
});

const CX = 110;
const CY = 105;
const R = 78;
// The value sits in the lower "well" of the gauge (below the needle pivot):
// the needle points from 150° to 390° (240° sweep above), its lowest end is
// at y≈136, so the text at y=150 is never covered.
const VALUE_Y = 150;
const ZONE_Y = 168;

// 0 -> 150° (bottom left), 100 -> 390° (=30°, bottom right),
// 50 -> 270° (top): clockwise 240° sweep.
function angle(v) {
  const val = Math.max(0, Math.min(100, Number(v) || 0));
  return 150 + (val / 100) * 240;
}

function point(deg) {
  const rad = (deg * Math.PI) / 180;
  return { x: CX + R * Math.cos(rad), y: CY + R * Math.sin(rad) };
}

function arc(a, b) {
  const p1 = point(a);
  const p2 = point(b);
  const large = b - a > 180 ? 1 : 0;
  return `M ${p1.x.toFixed(2)} ${p1.y.toFixed(2)} A ${R} ${R} 0 ${large} 1 ${p2.x.toFixed(2)} ${p2.y.toFixed(2)}`;
}

const segments = computed(() => [
  { zone: 'green',  d: arc(150, 210), color: ZONE.green.color },
  { zone: 'yellow', d: arc(210, 270), color: ZONE.yellow.color },
  { zone: 'orange', d: arc(270, 330), color: ZONE.orange.color },
  { zone: 'red',    d: arc(330, 390), color: ZONE.red.color },
]);

const currentZone = computed(() =>
  props.zone || zoneOf(props.value)
);

const needle = computed(() => {
  const rad = (angle(props.value) * Math.PI) / 180;
  const length = R - 16;
  return {
    x: CX + length * Math.cos(rad),
    y: CY + length * Math.sin(rad),
  };
});

const valueText = computed(() => {
  if (props.text !== '') return props.text;
  const v = Number(props.value) || 0;
  return Number.isInteger(v) ? String(v) : v.toFixed(1);
});

const zoneLabel = computed(() => ZONE[currentZone.value].label);
const zoneColor = computed(() => ZONE[currentZone.value].color);
</script>
