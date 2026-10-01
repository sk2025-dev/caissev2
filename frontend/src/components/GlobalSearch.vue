<script setup>
// Recherche globale : produits, clients, ventes, fournisseurs. Raccourci « / », flèches + Entrée, Échap.
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'
import Icon from './Icon.vue'

const router = useRouter()
const q = ref('')
const res = ref(null)
const open = ref(false)
const active = ref(0)
const input = ref(null)
const root = ref(null)

const groups = [['produits', 'Produits et services', '/produits'], ['clients', 'Clients', '/clients'], ['ventes', 'Ventes', '/ventes'], ['fournisseurs', 'Fournisseurs', '/fournisseurs']]
const flat = computed(() => (res.value ? groups.flatMap(([k, , path]) => res.value[k].map((r) => ({ ...r, path }))) : []))

let timer, seq = 0
watch(q, (v) => {
  clearTimeout(timer)
  if (v.trim().length < 2) { res.value = null; return }
  timer = setTimeout(async () => {
    const my = ++seq
    try { const r = await api.get('search', { q: v.trim() }); if (my === seq) { res.value = r; active.value = 0 } } catch { /* ignoré */ }
  }, 220)
})

function go(item) {
  router.push({ path: item.path, query: { q: item.label } })
  q.value = ''; res.value = null; open.value = false; input.value?.blur()
}
function onKey(e) {
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault()
    const n = flat.value.length
    if (n) active.value = (active.value + (e.key === 'ArrowDown' ? 1 : -1) + n) % n
  } else if (e.key === 'Enter' && flat.value[active.value]) { e.preventDefault(); go(flat.value[active.value]) }
  else if (e.key === 'Escape') { open.value = false; input.value?.blur() }
}
const shortcut = (e) => { if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName)) { e.preventDefault(); input.value?.focus() } }
const outside = (e) => { if (!root.value?.contains(e.target)) open.value = false }
onMounted(() => { document.addEventListener('keydown', shortcut); document.addEventListener('mousedown', outside) })
onBeforeUnmount(() => { document.removeEventListener('keydown', shortcut); document.removeEventListener('mousedown', outside) })
const empty = computed(() => res.value && !flat.value.length)
</script>

<template>
  <div ref="root" class="gsearch">
    <Icon name="search" :size="18" />
    <input ref="input" v-model="q" class="input" type="search" placeholder="Rechercher…  ( / )" aria-label="Recherche globale" autocomplete="off" @focus="open = true" @keydown="onKey" />
    <div v-if="open && (res || q.length >= 2)" class="results" role="listbox">
      <template v-if="res">
        <template v-for="[k, title] in groups" :key="k">
          <template v-if="res[k].length">
            <h5>{{ title }}</h5>
            <a v-for="r in res[k]" :key="k + r.id" href="#" :class="{ active: flat[active] && flat[active].id === r.id && flat[active].path === groups.find((g) => g[0] === k)[2] }" @click.prevent="go({ ...r, path: groups.find((g) => g[0] === k)[2] })">
              <b>{{ r.label }}</b><span v-if="r.sub">{{ r.sub }}</span>
            </a>
          </template>
        </template>
        <div v-if="empty" class="none">Aucun résultat pour « {{ q }} »</div>
      </template>
      <div v-else class="none">Recherche…</div>
    </div>
  </div>
</template>
