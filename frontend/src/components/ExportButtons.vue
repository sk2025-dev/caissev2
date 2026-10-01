<script setup>
// Bouton « Exporter » : lance une génération asynchrone (Excel, CSV ou PDF), le suivi se fait dans « Mes exports ».
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { startExport } from '../exports'
import { toast } from '../toast'
import Icon from './Icon.vue'

const props = defineProps({ kind: { type: String, required: true }, from: { type: String, default: '' }, to: { type: String, default: '' } })
const open = ref(false)
const root = ref(null)
const busy = ref(false)
const outside = (e) => { if (!root.value?.contains(e.target)) open.value = false }
onMounted(() => document.addEventListener('mousedown', outside))
onBeforeUnmount(() => document.removeEventListener('mousedown', outside))

async function lancer(format) {
  open.value = false; busy.value = true
  try { await startExport({ kind: props.kind, format, from: props.from, to: props.to }) } catch (e) { toast(e.message, 'error') } finally { busy.value = false }
}
</script>

<template>
  <div ref="root" class="anchor">
    <button class="btn" :disabled="busy" :aria-expanded="open" @click="open = !open"><Icon name="download" :size="16" /> Exporter</button>
    <div v-if="open" class="menu" role="menu">
      <button role="menuitem" @click="lancer('xlsx')"><Icon name="sheet" :size="18" /> Excel (.xlsx)</button>
      <button role="menuitem" @click="lancer('csv')"><Icon name="file" :size="18" /> CSV</button>
      <button role="menuitem" @click="lancer('pdf')"><Icon name="file" :size="18" /> PDF</button>
    </div>
  </div>
</template>
