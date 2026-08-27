<template>
  <section class="grid">
    <h2>Metrics</h2>
    <div class="grid-cards">
      <article v-for="(def, key) in config.metrics" :key="key" class="card">
        <MetricGauge
          :value="metric(key).score"
          :zone="metric(key).zone"
          :text="formatNumber(metric(key).value)"
        />
        <h3>{{ def.name }}</h3>
        <p v-if="def.why" class="why">{{ def.why }}</p>
      </article>
    </div>
  </section>
</template>

<script setup>
import { formatNumber } from '../metrics.js';
import MetricGauge from './MetricGauge.vue';

const props = defineProps({
  config: { type: Object, required: true },   // setup from the backend (metrics with name/why/…)
  metrics: { type: Object, required: true },  // point data: key -> {value, score, zone}
});

function metric(key) {
  const d = props.metrics[key];
  return {
    value: d ? d.value : 0,
    score: d ? d.score : 0,
    zone: d ? d.zone : 'green',
  };
}
</script>
