<template>
  <section class="storico">
    <h2>Andamento</h2>

    <div class="controlli">
      <button
        v-for="v in viste"
        :key="v.id"
        class="btn"
        :class="{ attivo: vista === v.id }"
        @click="cambiaVista(v.id)"
      >
        {{ v.label }}
      </button>

      <template v-if="vista === 'custom'">
        <input v-model="daInput" type="date" aria-label="Dal" />
        <span>→</span>
        <input v-model="aInput" type="date" aria-label="Al" />
        <button class="btn" @click="applicaCustom">Applica</button>
      </template>
    </div>

    <div class="chart-container">
      <canvas ref="canvas"></canvas>
      <p v-if="nessunDato" class="vuoto">Nessun dato nel periodo selezionato.</p>
    </div>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Chart, registerables } from 'chart.js';
import { fetchStorico } from '../api.js';
import { ZONE } from '../metrics.js';

Chart.register(...registerables);

const viste = [
  { id: '7', label: '7 giorni' },
  { id: '30', label: '30 giorni' },
  { id: 'custom', label: 'Personalizzato' },
];

const vista = ref('30');
const daInput = ref('');
const aInput = ref('');
const canvas = ref(null);
const nessunDato = ref(false);

let chart = null;
let datiCorrenti = [];

function dataLocale(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const g = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${g}`;
}

function dataOggi() {
  return dataLocale(new Date());
}

async function carica() {
  let da;
  let a;

  if (vista.value === 'custom') {
    da = daInput.value || dataOggi();
    a = aInput.value || dataOggi();
    if (da > a) [da, a] = [a, da];
  } else {
    const aD = new Date();
    const daD = new Date();
    daD.setDate(daD.getDate() - (vista.value === '7' ? 6 : 29));
    da = dataLocale(daD);
    a = dataLocale(aD);
  }

  try {
    datiCorrenti = await fetchStorico(da, a);
    nessunDato.value = datiCorrenti.length === 0;
    disegna(datiCorrenti);
  } catch (e) {
    nessunDato.value = true;
    // eslint-disable-next-line no-console
    console.error('Errore caricamento storico:', e.message);
  }
}

function disegna(dati) {
  if (!canvas.value) return;
  const ctx = canvas.value.getContext('2d');

  const etichette = dati.map((d) => d.data);
  const valori = dati.map((d) => d.indice);
  const coloriPunti = dati.map((d) => (ZONE[d.zona] || ZONE.verde).colore);

  if (chart) chart.destroy();

  chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: etichette,
      datasets: [
        {
          label: 'Valore',
          data: valori,
          borderColor: '#111827',
          backgroundColor: 'rgba(17, 24, 39, 0.07)',
          fill: true,
          tension: 0.25,
          pointBackgroundColor: coloriPunti,
          pointBorderColor: coloriPunti,
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
              const d = dati[c.dataIndex];
              const z = d && d.zona ? ZONE[d.zona].etichetta : '';
              return ` Valore: ${c.parsed.y} — zona ${z}`;
            },
          },
        },
      },
    },
  });
}

function cambiaVista(id) {
  vista.value = id;
  if (id === 'custom') {
    const a = new Date();
    const da = new Date();
    da.setDate(da.getDate() - 6);
    daInput.value = dataLocale(da);
    aInput.value = dataLocale(a);
  }
  carica();
}

function applicaCustom() {
  carica();
}

onMounted(carica);
onBeforeUnmount(() => {
  if (chart) chart.destroy();
});
</script>
