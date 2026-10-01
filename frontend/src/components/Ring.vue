<script setup>
// Anneau de progression 0-100 (indice de performance) : se remplit à l'affichage, couleur selon le niveau.
import { ref, computed, onMounted, watch, nextTick } from 'vue'
const props = defineProps({ value: { type: Number, default: null }, size: { type: Number, default: 52 }, stroke: { type: Number, default: 6 }, label: { type: Boolean, default: true } })
const R = 40, C = 2 * Math.PI * R
const shown = ref(0)
const color = computed(() => (props.value === null ? 'var(--border)' : props.value >= 75 ? 'var(--success)' : props.value >= 50 ? 'var(--primary)' : props.value >= 30 ? 'var(--warning)' : 'var(--danger)'))
const go = () => { shown.value = 0; nextTick(() => requestAnimationFrame(() => (shown.value = props.value || 0))) }
onMounted(go)
watch(() => props.value, go)
</script>

<template>
  <span class="ring" :style="{ width: size + 'px', height: size + 'px' }" role="img" :aria-label="value === null ? 'Indice non disponible' : `Indice ${value} sur 100`">
    <svg viewBox="0 0 100 100">
      <circle cx="50" cy="50" :r="R" fill="none" stroke="var(--surface-2)" :stroke-width="stroke * 100 / size * 1.6" />
      <circle cx="50" cy="50" :r="R" fill="none" :stroke="color" :stroke-width="stroke * 100 / size * 1.6" stroke-linecap="round" :stroke-dasharray="`${(shown / 100) * C} ${C}`" />
    </svg>
    <b v-if="label" :style="{ fontSize: size * 0.3 + 'px', color }">{{ value === null ? '–' : value }}</b>
  </span>
</template>
