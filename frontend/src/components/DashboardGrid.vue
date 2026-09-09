<template>
  <section class="grid">
    <h2>Metrics</h2>
    <p v-if="selectedKey" class="grid-hint">
      Showing history of “{{ nameOf(selectedKey) }}”. Click another metric to switch, or the
      “All metrics” button in the chart to go back.
    </p>
    <div class="grid-cards">
      <article
        v-for="(def, key) in config.metrics"
        :key="key"
        class="card"
        :class="{ active: selectedKey === key }"
        role="button"
        tabindex="0"
        :aria-pressed="selectedKey === key"
        :aria-label="`Show history of ${def.name || key}`"
        @click="emit('select', key)"
        @keydown.enter="emit('select', key)"
        @keydown.space.prevent="emit('select', key)"
      >
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
  selectedKey: { type: String, default: null }, // metric currently shown in the chart ('' / null = aggregate)
});

const emit = defineEmits(['select']);

function metric(key) {
  const d = props.metrics[key];
  return {
    value: d ? d.value : 0,
    score: d ? d.score : 0,
    zone: d ? d.zone : 'green',
  };
}

function nameOf(key) {
  const def = props.config && props.config.metrics && props.config.metrics[key];
  return def ? def.name : key;
}
</script>
