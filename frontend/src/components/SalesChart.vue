<script setup>
// Histogramme du chiffre d'affaires par jour (30 jours), en SVG, sans dépendance. La marge est tracée en surimpression.
import { computed } from 'vue'
import { compact, money } from '../format'

const props = defineProps({ data: { type: Array, required: true } })
const W = 720, H = 240, P = { t: 12, r: 8, b: 28, l: 44 }

const max = computed(() => {
  const m = Math.max(1, ...props.data.map((d) => d.ca))
  const mag = Math.pow(10, Math.floor(Math.log10(m)))
  return Math.ceil(m / mag) * mag
})
const ticks = computed(() => [0, .25, .5, .75, 1].map((r) => ({ v: max.value * r, y: P.t + (H - P.t - P.b) * (1 - r) })))
const slot = computed(() => (W - P.l - P.r) / props.data.length)
const bar = (v) => ((H - P.t - P.b) * Math.max(0, v)) / max.value
const jour = (d) => d.slice(8) + '/' + d.slice(5, 7)
</script>

<template>
  <svg :viewBox="`0 0 ${W} ${H}`" width="100%" role="img" aria-label="Chiffre d'affaires des 30 derniers jours">
    <g v-for="t in ticks" :key="t.y">
      <line :x1="P.l" :x2="W - P.r" :y1="t.y" :y2="t.y" stroke="var(--border)" />
      <text :x="P.l - 8" :y="t.y + 4" text-anchor="end" font-size="11" fill="var(--muted)">{{ compact(t.v) }}</text>
    </g>
    <g v-for="(d, i) in data" :key="d.jour">
      <title>{{ jour(d.jour) }} — {{ money(d.ca) }} · {{ d.nb }} vente{{ d.nb > 1 ? 's' : '' }} · marge {{ money(d.marge) }}</title>
      <rect class="bar" :style="{ animationDelay: i * 18 + 'ms' }" :x="P.l + i * slot + slot * .16" :y="H - P.b - bar(d.ca)" :width="slot * .68" :height="bar(d.ca)" rx="4" fill="var(--primary)" />
      <rect class="bar" :style="{ animationDelay: i * 18 + 120 + 'ms' }" :x="P.l + i * slot + slot * .16" :y="H - P.b - bar(d.marge)" :width="slot * .68" :height="bar(d.marge)" rx="4" fill="var(--success)" opacity=".85" />
      <text v-if="i % 5 === 0 || i === data.length - 1" :x="P.l + i * slot + slot / 2" :y="H - 8" text-anchor="middle" font-size="10.5" fill="var(--muted)">{{ jour(d.jour) }}</text>
    </g>
  </svg>
</template>

<style scoped>
.bar { transform-box: fill-box; transform-origin: center bottom; animation: grow .8s cubic-bezier(.2, .75, .25, 1) both; transition: filter .2s; }
g:hover > .bar { filter: brightness(1.12) saturate(1.1); }
</style>
