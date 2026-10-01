<script setup>
// Histogramme groupé (recettes / dépenses par mois) en SVG, sans dépendance.
import { computed } from 'vue'
import { monthLabel, compact, money } from '../format'

const props = defineProps({ data: { type: Array, required: true } })
const W = 640, H = 240, P = { t: 12, r: 8, b: 28, l: 44 }

const max = computed(() => {
  const m = Math.max(1, ...props.data.flatMap((d) => [d.recettes, d.depenses]))
  const mag = Math.pow(10, Math.floor(Math.log10(m)))
  return Math.ceil(m / mag) * mag
})
const ticks = computed(() => [0, .25, .5, .75, 1].map((r) => ({ v: max.value * r, y: P.t + (H - P.t - P.b) * (1 - r) })))
const slot = computed(() => (W - P.l - P.r) / props.data.length)
const bar = (v) => ((H - P.t - P.b) * v) / max.value
</script>

<template>
  <svg :viewBox="`0 0 ${W} ${H}`" width="100%" role="img" aria-label="Recettes et dépenses des 12 derniers mois">
    <g v-for="t in ticks" :key="t.y">
      <line :x1="P.l" :x2="W - P.r" :y1="t.y" :y2="t.y" stroke="var(--border)" />
      <text :x="P.l - 8" :y="t.y + 4" text-anchor="end" font-size="11" fill="var(--muted)">{{ compact(t.v) }}</text>
    </g>
    <g v-for="(d, i) in data" :key="d.mois">
      <g>
        <title>{{ monthLabel(d.mois) }} — Recettes {{ money(d.recettes) }} · Dépenses {{ money(d.depenses) }}</title>
        <rect class="bar" :style="{ animationDelay: i * 45 + 'ms' }" :x="P.l + i * slot + slot * .14" :y="H - P.b - bar(d.recettes)" :width="slot * .34" :height="bar(d.recettes)" rx="5" fill="var(--success)" />
        <rect class="bar" :style="{ animationDelay: i * 45 + 90 + 'ms' }" :x="P.l + i * slot + slot * .52" :y="H - P.b - bar(d.depenses)" :width="slot * .34" :height="bar(d.depenses)" rx="5" fill="var(--danger)" />
        <text :x="P.l + i * slot + slot / 2" :y="H - 8" text-anchor="middle" font-size="11" fill="var(--muted)">{{ monthLabel(d.mois) }}</text>
      </g>
    </g>
  </svg>
</template>

<style scoped>
.bar { transform-box: fill-box; transform-origin: center bottom; animation: grow .8s cubic-bezier(.2, .75, .25, 1) both; transition: filter .2s, transform .2s; }
g:hover > .bar { filter: brightness(1.12) saturate(1.1); }
</style>
