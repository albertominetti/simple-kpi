<template>
  <div class="app">
    <header class="header">
      <div>
        <h1>{{ config.title || 'Dashboard KPI' }}</h1>
        <p v-if="config.subtitle" class="subtitle">{{ config.subtitle }}</p>
      </div>
      <button class="btn" :disabled="loading" @click="reload">
        {{ loading ? 'Loading…' : '⟳ Refresh' }}
      </button>
    </header>

    <p v-if="error" class="error">{{ error }}</p>

    <!-- Top row: main gauge on the left, mini-gauges on the right -->
    <div class="body">
      <section class="main-section">
        <MetricGauge
          v-if="latest"
          :value="latest.index"
          :zone="latest.zone"
          :label="'Snapshot of ' + latest.date"
        />
        <div v-else-if="!loading" class="empty">
          No data available: wait for the first POST from the collector.
        </div>
      </section>

      <DashboardGrid
        v-if="latest && latest.metrics && config.metrics"
        :config="config"
        :metrics="latest.metrics"
      />
      <div v-else-if="!loading" class="empty">
        No data available: wait for the first POST from the collector.
      </div>
    </div>

    <!-- Trend below, full width -->
    <HistoryChart />
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import MetricGauge from './components/MetricGauge.vue';
import DashboardGrid from './components/DashboardGrid.vue';
import HistoryChart from './components/HistoryChart.vue';
import { fetchConfig, fetchLatest } from './api.js';

const config = ref({ title: 'Dashboard KPI', subtitle: '', metrics: {} });
const latest = ref(null);
const loading = ref(false);
const error = ref('');

async function reload() {
  loading.value = true;
  error.value = '';
  try {
    const [cfg, data] = await Promise.all([fetchConfig(), fetchLatest()]);
    config.value = cfg;
    latest.value = data;
  } catch (e) {
    error.value = `Unable to load data: ${e.message}`;
  } finally {
    loading.value = false;
  }
}

onMounted(reload);
</script>
