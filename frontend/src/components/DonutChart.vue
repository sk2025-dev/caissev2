<script setup>
// Anneau de répartition : les segments se dessinent à l'arrivée, survol = mise en avant synchronisée avec la légende.
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { money } from '../format'

const props = defineProps({ items: { type: Array, required: true }, total: { type: Number, default: 0 }, centerLabel: { type: String, default: 'Total' } })
const COLORS = ['var(--primary)', '#2b9e6b', '#f59f00', '#e64980', '#3b82f6', '#adb5bd']
const R = 80, C = 2 * Math.PI * R, GAP = 3
const hot = ref(-1)
const ready = ref(false)

const segs = computed(() => {
  let acc = 0
  return props.items.map((it, i) => {
    const frac = props.total > 0 ? it.montant / props.total : 0
    const len = Math.max(0, frac * C - (props.items.length > 1 ? GAP : 0))
    const seg = { ...it, color: COLORS[Math.min(i, COLORS.length - 1)], len, offset: -acc * C }
    acc += frac
    return seg
  })
})

// Démarre à longueur 0 puis laisse la transition CSS dessiner chaque arc
function play() { ready.value = false; nextTick(() => requestAnimationFrame(() => (ready.value = true))) }
onMounted(play)
watch(() => props.items, play)
</script>

<template>
  <div class="donut-wrap">
    <div class="donut" :class="{ dim: hot >= 0 }" role="img" :aria-label="`Répartition : ${items.map((i) => `${i.label} ${i.pct} %`).join(', ')}`">
      <svg viewBox="0 0 200 200">
        <circle class="track" cx="100" cy="100" r="80" />
        <circle
          v-for="(s, i) in segs" :key="s.label" class="seg" :class="{ hot: hot === i }" cx="100" cy="100" r="80"
          :stroke="s.color" :stroke-dasharray="ready ? `${s.len} ${C - s.len}` : `0 ${C}`" :stroke-dashoffset="s.offset"
          :style="{ transitionDelay: ready ? `${i * 110}ms` : '0ms' }"
          @mouseenter="hot = i" @mouseleave="hot = -1"
        />
      </svg>
      <div class="center"><small>{{ hot >= 0 ? segs[hot].label : centerLabel }}</small><b>{{ money(hot >= 0 ? segs[hot].montant : total) }}</b></div>
    </div>
    <div class="dlegend">
      <div v-for="(s, i) in segs" :key="s.label" class="row" :class="{ hot: hot === i }" @mouseenter="hot = i" @mouseleave="hot = -1">
        <i :style="{ background: s.color }" /><span><span class="nm" :title="s.label">{{ s.label }}</span><span class="mt">{{ money(s.montant) }}</span></span><span class="pc">{{ Math.round(s.pct) }} %</span>
      </div>
    </div>
  </div>
</template>
