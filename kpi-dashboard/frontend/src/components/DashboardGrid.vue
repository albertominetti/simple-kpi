<template>
  <section class="griglia">
    <h2>Metriche</h2>
    <div class="griglia-card">
      <article v-for="(def, key) in config.metriche" :key="key" class="card">
        <MetricGauge
          :valore="dato(key).score"
          :zona="dato(key).zona"
          :testo="formatoNumero(dato(key).valore)"
        />
        <h3>{{ def.nome }}</h3>
        <p v-if="def.perche" class="perche">{{ def.perche }}</p>
      </article>
    </div>
  </section>
</template>

<script setup>
import { formatoNumero } from '../metrics.js';
import MetricGauge from './MetricGauge.vue';

const props = defineProps({
  config: { type: Object, required: true },   // setup dal backend (metriche con nome/perche/…)
  metriche: { type: Object, required: true }, // dati puntuali: key -> {valore, score, zona}
});

function dato(key) {
  const d = props.metriche[key];
  return {
    valore: d ? d.valore : 0,
    score: d ? d.score : 0,
    zona: d ? d.zona : 'verde',
  };
}
</script>
