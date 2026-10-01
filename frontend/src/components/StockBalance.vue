<script setup>
// Balance des stocks du mois : stock initial + entrées − sorties ± ajustements = stock final, par produit, valorisée.
import { ref, computed, watch, onMounted } from 'vue'
import { api } from '../api'
import { number, qty, getDevise, uniteAccord } from '../format'
import ExportButtons from './ExportButtons.vue'

const mois = ref(new Date().toISOString().slice(0, 7))
const d = ref(null)
const loading = ref(false)
const error = ref('')
const recherche = ref('')
const mouvementes = ref(false)   // seulement les produits qui ont bougé dans le mois

let seq = 0
async function load() {
  const my = ++seq
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('stock-balance', { mois: mois.value })
    if (my === seq) d.value = res
  } catch (e) {
    if (my === seq) error.value = e.message
  } finally {
    if (my === seq) loading.value = false
  }
}
onMounted(load)
watch(mois, (m) => m && load())

const rows = computed(() => {
  const q = recherche.value.trim().toLowerCase()
  return (d.value?.produits || []).filter((p) => (!mouvementes.value || p.nb > 0) && (!q || p.nom.toLowerCase().includes(q) || p.categorie.toLowerCase().includes(q)))
})
const t = computed(() => d.value?.totaux)
const q = (v) => (v ? qty(v) : '–')
const n = (v) => (v ? number(Math.round(v)) : '–')
const signe = (v) => (v > 0 ? `+${qty(v)}` : v < 0 ? `−${qty(-v)}` : '–')
const cls = (v) => (v < 0 ? 'neg' : v > 0 ? 'pos' : 'nul')
</script>

<template>
  <section class="card journal">
    <div class="journal-head">
      <div>
        <h2>Balance des stocks</h2>
        <p class="muted">Stock initial + entrées − sorties ± ajustements = stock final — valorisé au prix d'achat, en {{ getDevise() }}</p>
      </div>
      <div class="journal-tools">
        <label class="sr-only" for="balance-recherche">Rechercher</label>
        <input id="balance-recherche" v-model="recherche" type="search" class="input date" placeholder="Produit ou catégorie…" />
        <label class="check"><input v-model="mouvementes" type="checkbox" /><span>Produits mouvementés seulement</span></label>
        <label class="sr-only" for="balance-mois">Mois</label>
        <input id="balance-mois" v-model="mois" type="month" class="input date" />
        <ExportButtons kind="balance" :mois="mois" />
      </div>
    </div>

    <div v-if="error" class="alert" style="margin:0 20px 20px">{{ error }}</div>
    <div v-else-if="!d" class="skeleton" style="height:260px;margin:0 20px 20px" />

    <div v-else class="table-wrap journal-wrap" :aria-busy="loading" :style="{ opacity: loading ? .6 : 1 }">
      <table class="table matrix journal-table synth">
        <thead>
          <tr>
            <th class="sticky">Produit</th>
            <th class="num">Stock initial</th><th class="num">Entrées</th><th class="num">Sorties</th><th class="num">Ajustements</th>
            <th class="num tot">Stock final</th><th class="num tot">Valeur finale</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in rows" :key="p.idprod" :class="{ blank: !p.nb }">
            <td class="sticky"><RouterLink :to="`/produits/${p.idprod}`" style="color:inherit">{{ p.nom }}</RouterLink><br /><small class="muted">{{ p.categorie }}</small></td>
            <td class="num">{{ q(p.initial) }}</td>
            <td class="num pos">{{ p.entrees ? `+${qty(p.entrees)}` : '–' }}</td>
            <td class="num neg">{{ p.sorties ? `−${qty(p.sorties)}` : '–' }}</td>
            <td class="num" :class="cls(p.ajustements)">{{ signe(p.ajustements) }}</td>
            <td class="num strong" :class="p.final < 0 ? 'neg' : ''">{{ q(p.final) }} <small class="muted">{{ uniteAccord(p.final, p.unite) }}</small></td>
            <td class="num strong">{{ n(p.valeur_finale) }}</td>
          </tr>
          <tr v-if="!rows.length"><td colspan="7" class="empty" style="padding:26px">Aucun produit à afficher.</td></tr>
        </tbody>
        <tfoot>
          <tr>
            <td class="sticky">Valeur totale</td>
            <td class="num">{{ n(t.valeur_initiale) }}</td><td class="num pos">{{ n(t.valeur_entrees) }}</td><td class="num neg">{{ n(t.valeur_sorties) }}</td>
            <td class="num" :class="cls(t.valeur_ajustements)">{{ n(t.valeur_ajustements) }}</td>
            <td class="num" /><td class="num">{{ n(t.valeur_finale) }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </section>
</template>
