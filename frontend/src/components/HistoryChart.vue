<template>
  <section class="history">
    <h2>Trend</h2>

    <div class="controls">
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
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Chart, registerables } from 'chart.js';
import { fetchHistory } from '../api.js';
import { ZONE } from '../metrics.js';

Chart.register(...registerables);

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
let currentData = [];

function localDate(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function today() {
  return localDate(new Date());
}

async function load() {
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

  try {
    currentData = await fetchHistory(from, to);
    noData.value = currentData.length === 0;
    draw(currentData);
  } catch (e) {
    noData.value = true;
    // eslint-disable-next-line no-console
    console.error('Error loading history:', e.message);
  }
}

function draw(data) {
  if (!canvas.value) return;
  const ctx = canvas.value.getContext('2d');

  const labels = data.map((d) => d.date);
  const values = data.map((d) => d.index);
  const pointColors = data.map((d) => (ZONE[d.zone] || ZONE.green).color);

  if (chart) chart.destroy();

  chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Index',
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
        y: {
          min: 0,
          max: 100,
          ticks: { stepSize: 25 },
        },
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
              const d = data[c.dataIndex];
              const z = d && d.zone ? ZONE[d.zone].label : '';
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
onBeforeUnmount(() => {
  if (chart) chart.destroy();
});
</script>
