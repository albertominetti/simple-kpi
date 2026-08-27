<template>
  <div class="app">
    <header class="header">
      <div>
        <h1>{{ config.titolo || 'Dashboard KPI' }}</h1>
        <p v-if="config.sottotitolo" class="sottotitolo">{{ config.sottotitolo }}</p>
      </div>
      <button class="btn" :disabled="caricamento" @click="ricarica">
        {{ caricamento ? 'Aggiornamento…' : '⟳ Aggiorna' }}
      </button>
    </header>

    <p v-if="errore" class="errore">{{ errore }}</p>

    <!-- Riga superiore: gauge principale a sinistra, mini-gauge a destra -->
    <div class="corpo">
      <section class="sezione-principale">
        <MetricGauge
          v-if="ultimo"
          :valore="ultimo.indice"
          :zona="ultimo.zona"
          :etichetta="'Snapshot del ' + ultimo.data"
        />
        <div v-else-if="!caricamento" class="vuoto">
          Nessun dato disponibile: attendi la prima POST del collector.
        </div>
      </section>

      <DashboardGrid
        v-if="ultimo && ultimo.metriche && config.metriche"
        :config="config"
        :metriche="ultimo.metriche"
      />
      <div v-else-if="!caricamento" class="vuoto">
        Nessun dato disponibile: attendi la prima POST del collector.
      </div>
    </div>

    <!-- Andamento sotto, tutta larghezza -->
    <HistoryChart />
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import MetricGauge from './components/MetricGauge.vue';
import DashboardGrid from './components/DashboardGrid.vue';
import HistoryChart from './components/HistoryChart.vue';
import { fetchConfig, fetchUltimo } from './api.js';

const config = ref({ titolo: 'Dashboard KPI', sottotitolo: '', metriche: {} });
const ultimo = ref(null);
const caricamento = ref(false);
const errore = ref('');

async function ricarica() {
  caricamento.value = true;
  errore.value = '';
  try {
    const [cfg, dati] = await Promise.all([fetchConfig(), fetchUltimo()]);
    config.value = cfg;
    ultimo.value = dati;
  } catch (e) {
    errore.value = `Impossibile caricare i dati: ${e.message}`;
  } finally {
    caricamento.value = false;
  }
}

onMounted(ricarica);
</script>
