<script setup>
// Compteur animé (easeOutExpo). Respecte prefers-reduced-motion : affichage direct de la valeur.
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({ value: { type: Number, default: 0 }, format: { type: Function, default: (v) => String(Math.round(v)) }, duration: { type: Number, default: 1100 } })
const shown = ref(0)
let raf = 0

function run(from, to) {
  cancelAnimationFrame(raf)
  if (matchMedia('(prefers-reduced-motion: reduce)').matches || from === to) { shown.value = to; return }
  const t0 = performance.now()
  const step = (t) => {
    const p = Math.min(1, (t - t0) / props.duration)
    const eased = p === 1 ? 1 : 1 - Math.pow(2, -10 * p)
    shown.value = from + (to - from) * eased
    if (p < 1) raf = requestAnimationFrame(step)
  }
  raf = requestAnimationFrame(step)
}
onMounted(() => run(0, props.value))
watch(() => props.value, (n, o) => run(shown.value, n))
onBeforeUnmount(() => cancelAnimationFrame(raf))
</script>

<template><span>{{ format(shown) }}</span></template>
