<template>
  <section class="history">
    <h2>{{ selectedKey ? `History · ${metricName}` : 'Trend' }}</h2>

    <div class="controls">
      <template v-if="selectedKey">
        <button class="btn back-btn" title="Back to the overall index" @click="emit('clear')">
          ← All metrics
        </button>
        <span class="metric-tag">Metric: {{ metricName }}</span>
      </template>

      <button
        v-for="v in views"
        :key="v.id"
        class="btn"
        :class="{ active: view === v.id }"
        @click="changeView(v.id)"
      >
        {{ v.label }}
      </button>

      <template v-if="view === 'custom'">
        <input v-model="fromInput" type="date" aria-label="From" />
        <span>→</span>
        <input v-model="toInput" type="date" aria-label="To" />
        <button class="btn" @click="applyCustom">Apply</button>
      </template>
    </div>

    <div class="chart-container">
      <canvas ref="canvas"></canvas>
      <p v-if="noData" class="empty">No data for the selected period.</p>
    </div>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Chart, registerables } from 'chart.js';
import { fetchHistory, fetchMetricHistory } from '../api.js';
import { ZONE, formatNumber } from '../metrics.js';

Chart.register(...registerables);

const props = defineProps({
  config: { type: Object, required: true },    // setup from the backend (metric names/definitions)
  selectedKey: { type: String, default: null }, // null = aggregate index; a key = that single metric
});
const emit = defineEmits(['clear']);

const views = [
  { id: '7', label: '7 days' },
  { id: '30', label: '30 days' },
  { id: 'custom', label: 'Custom' },
];

const view = ref('30');
const fromInput = ref('');
const toInput = ref('');
const canvas = ref(null);
const noData = ref(false);

let chart = null;
let currentData = [];   // normalized rows: { date, y, score?, zone }
let isMetric = false;   // whether currentData comes from a single metric

const metricName = computed(() => {
  if (!props.selectedKey) return '';
  const def = props.config?.metrics?.[props.selectedKey];
  return def ? def.name : props.selectedKey;
});

function localDate(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function today() {
  return localDate(new Date());
}

function range() {
  let from;
  let to;
  if (view.value === 'custom') {
    from = fromInput.value || today();
    to = toInput.value || today();
    if (from > to) [from, to] = [to, from];
  } else {
    const toD = new Date();
    const fromD = new Date();
    fromD.setDate(fromD.getDate() - (view.value === '7' ? 6 : 29));
    from = localDate(fromD);
    to = localDate(toD);
  }
  return { from, to };
}

async function load() {
  const { from, to } = range();
  try {
    if (props.selectedKey) {
      // Single metric: raw value over time + its zone/score.
      const res = await fetchMetricHistory(props.selectedKey, from, to);
      const points = res.points || [];
      currentData = points.map((p) => ({
        date: p.date,
        y: p.value,
        score: p.score,
        zone: p.zone,
      }));
      isMetric = true;
    } else {
      // Aggregate index of the whole dashboard.
      const rows = await fetchHistory(from, to);
      currentData = rows.map((r) => ({
        date: r.date,
        y: r.index,
        zone: r.zone,
      }));
      isMetric = false;
    }
    noData.value = currentData.length === 0;
    draw();
  } catch (e) {
    noData.value = true;
    if (chart) chart.destroy();
    chart = null;
    // eslint-disable-next-line no-console
    console.error('Error loading history:', e.message);
  }
}

function draw() {
  if (!canvas.value) return;
  const ctx = canvas.value.getContext('2d');

  if (chart) chart.destroy();

  const labels = currentData.map((d) => d.date);
  const values = currentData.map((d) => d.y);
  const pointColors = currentData.map((d) => (ZONE[d.zone] || ZONE.green).color);

  chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: isMetric ? metricName.value || props.selectedKey : 'Index',
          data: values,
          borderColor: '#111827',
          backgroundColor: 'rgba(17, 24, 39, 0.07)',
          fill: true,
          tension: 0.25,
          pointBackgroundColor: pointColors,
          pointBorderColor: pointColors,
          pointRadius: 5,
          pointHoverRadius: 7,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: isMetric
          ? { beginAtZero: true }
          : { min: 0, max: 100, ticks: { stepSize: 25 } },
        x: {
          ticks: { maxTicksLimit: 14, maxRotation: 45 },
        },
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: (items) => (items.length ? items[0].label : ''),
            label: (c) => {
              const d = currentData[c.dataIndex];
              const z = d && d.zone ? ZONE[d.zone].label : '';
              if (isMetric) {
                const scoreTxt = d && d.score != null ? ` · score ${formatNumber(d.score)}` : '';
                return ` ${formatNumber(d.y)} — zone ${z}${scoreTxt}`;
              }
              return ` Index: ${c.parsed.y} — zone ${z}`;
            },
          },
        },
      },
    },
  });
}

function changeView(id) {
  view.value = id;
  if (id === 'custom') {
    const to = new Date();
    const from = new Date();
    from.setDate(from.getDate() - 6);
    fromInput.value = localDate(from);
    toInput.value = localDate(to);
  }
  load();
}

function applyCustom() {
  load();
}

onMounted(load);
watch(() => props.selectedKey, load);
onBeforeUnmount(() => {
  if (chart) chart.destroy();
});
</script>
