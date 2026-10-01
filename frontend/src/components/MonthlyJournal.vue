<script setup>
// Journal du mois : ventes, sorties d'espèces et résultat par jour et par caisse, en un seul tableau.
import { ref, computed, watch, onMounted } from 'vue'
import { api } from '../api'
import { number, getDevise } from '../format'
import ExportButtons from './ExportButtons.vue'

const mois = ref(new Date().toISOString().slice(0, 7))
const vue = ref('caisses')          // 'caisses' | 'synthese'
const d = ref(null)
const loading = ref(false)
const error = ref('')

let seq = 0
async function load() {
  const my = ++seq
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('dashboard-mensuel', { mois: mois.value })
    if (my === seq) d.value = res
  } catch (e) {
    if (my === seq) error.value = e.message
  } finally {
    if (my === seq) loading.value = false
  }
}
onMounted(load)
watch(mois, (m) => m && load())

// Jours UTC, comme le reste de l'application
const today = new Date().toISOString().slice(0, 10)
const jourLabel = (j) => new Date(`${j}T00:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: 'short' })
const C = computed(() => d.value?.caisses || [])
const hasHors = computed(() => !!d.value && Object.keys(d.value.hors_caisse || {}).length > 0)

// Lignes du journal : résultat du jour = ventes − sorties ; cumul = somme progressive des résultats
const table = computed(() => {
  if (!d.value) return null
  let cumul = 0
  const totC = C.value.map(() => ({ rec: 0, dep: 0, nb: 0 }))
  let totHors = 0
  const rows = d.value.jours.map((j) => {
    const cells = C.value.map((c, i) => {
      const v = d.value.ventes[j]?.[c.idcaisse]
      const rec = v?.ca || 0
      const dep = d.value.sorties[j]?.[c.idcaisse] || 0
      totC[i].rec += rec; totC[i].dep += dep; totC[i].nb += v?.nb || 0
      return { rec, dep }
    })
    const hors = d.value.hors_caisse?.[j] || 0
    totHors += hors
    const rec = cells.reduce((a, c) => a + c.rec, 0)
    const dep = cells.reduce((a, c) => a + c.dep, 0) + hors
    cumul += rec - dep
    return { jour: j, cells, hors, rec, dep, res: rec - dep, cumul, blank: !rec && !dep }
  })
  const rec = totC.reduce((a, c) => a + c.rec, 0)
  const dep = totC.reduce((a, c) => a + c.dep, 0) + totHors
  const nb = totC.reduce((a, c) => a + c.nb, 0)
  return { rows, totC, totHors, rec, dep, nb, res: rec - dep }
})

const masquer = ref(false)   // masquer les jours sans mouvement
const rows = computed(() => (table.value ? table.value.rows.filter((r) => !masquer.value || !r.blank) : []))
const n = (v) => (v ? number(v) : '–')
const cls = (v) => (v < 0 ? 'neg' : v > 0 ? 'pos' : 'nul')
</script>

<template>
  <section class="card journal">
    <div class="journal-head">
      <div>
        <h2>Journal du mois</h2>
        <p class="muted">Ventes, sorties et dépenses, résultat par jour et par caisse — montants en {{ getDevise() }}<template v-if="table"> · {{ table.nb }} vente{{ table.nb > 1 ? 's' : '' }}</template></p>
      </div>
      <div class="journal-tools">
        <div class="seg" role="tablist" aria-label="Affichage">
          <button :class="{ on: vue === 'caisses' }" role="tab" @click="vue = 'caisses'">Par caisse</button>
          <button :class="{ on: vue === 'synthese' }" role="tab" @click="vue = 'synthese'">Synthèse</button>
        </div>
        <label class="check"><input v-model="masquer" type="checkbox" /><span>Masquer les jours sans mouvement</span></label>
        <label class="sr-only" for="journal-mois">Mois</label>
        <input id="journal-mois" v-model="mois" type="month" class="input date" />
        <ExportButtons kind="journal" :mois="mois" />
      </div>
    </div>

    <div v-if="error" class="alert" style="margin:0 20px 20px">{{ error }}</div>
    <div v-else-if="!table" class="skeleton" style="height:260px;margin:0 20px 20px" />

    <div v-else class="table-wrap journal-wrap" :aria-busy="loading" :style="{ opacity: loading ? .6 : 1 }">
      <!-- Vue par caisse : pour chaque caisse ventes / sorties / résultat, après le total du jour et le cumul -->
      <table v-if="vue === 'caisses'" class="table matrix journal-table">
        <thead>
          <tr class="l1">
            <th rowspan="2" class="sticky">Date</th>
            <th colspan="3" class="grp tot">Total du jour</th>
            <th rowspan="2" class="num tot">Cumul</th>
            <th v-for="c in C" :key="c.idcaisse" colspan="3" class="grp gstart">{{ c.nom }}</th>
            <th v-if="hasHors" class="grp gstart" title="Dépenses réglées par le gérant (coffre, banque, mobile money)">Hors caisse</th>
          </tr>
          <tr class="l2">
            <th class="num tot">Ventes</th><th class="num tot">Sorties</th><th class="num tot">Résultat</th>
            <template v-for="c in C" :key="c.idcaisse"><th class="num gstart">Ventes</th><th class="num">Sorties</th><th class="num">Résultat</th></template>
            <th v-if="hasHors" class="num gstart">Dépenses</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.jour" :class="{ today: r.jour === today, blank: r.blank }">
            <td class="sticky">{{ jourLabel(r.jour) }}</td>
            <td class="num pos">{{ n(r.rec) }}</td><td class="num neg">{{ n(r.dep) }}</td><td class="num strong" :class="cls(r.res)">{{ n(r.res) }}</td>
            <td class="num strong" :class="cls(r.cumul)">{{ n(r.cumul) }}</td>
            <template v-for="(c, i) in r.cells" :key="i">
              <td class="num gstart pos">{{ n(c.rec) }}</td><td class="num neg">{{ n(c.dep) }}</td><td class="num strong" :class="cls(c.rec - c.dep)">{{ n(c.rec - c.dep) }}</td>
            </template>
            <td v-if="hasHors" class="num gstart neg">{{ n(r.hors) }}</td>
          </tr>
          <tr v-if="!rows.length"><td :colspan="5 + C.length * 3 + (hasHors ? 1 : 0)" class="empty" style="padding:26px">Aucun mouvement ce mois-ci.</td></tr>
        </tbody>
        <tfoot>
          <tr>
            <td class="sticky">Total du mois</td>
            <td class="num pos">{{ n(table.rec) }}</td><td class="num neg">{{ n(table.dep) }}</td><td class="num" :class="cls(table.res)">{{ n(table.res) }}</td>
            <td class="num" :class="cls(table.res)">{{ n(table.res) }}</td>
            <template v-for="(t, i) in table.totC" :key="i">
              <td class="num gstart pos">{{ n(t.rec) }}</td><td class="num neg">{{ n(t.dep) }}</td><td class="num" :class="cls(t.rec - t.dep)">{{ n(t.rec - t.dep) }}</td>
            </template>
            <td v-if="hasHors" class="num gstart neg">{{ n(table.totHors) }}</td>
          </tr>
        </tfoot>
      </table>

      <!-- Vue synthèse : un seul bloc de totaux par jour -->
      <table v-else class="table matrix journal-table synth">
        <thead><tr><th class="sticky">Date</th><th class="num">Ventes</th><th class="num">Sorties</th><th class="num">Résultat</th><th class="num">Cumul</th></tr></thead>
        <tbody>
          <tr v-for="r in rows" :key="r.jour" :class="{ today: r.jour === today, blank: r.blank }">
            <td class="sticky">{{ jourLabel(r.jour) }}</td>
            <td class="num pos">{{ n(r.rec) }}</td><td class="num neg">{{ n(r.dep) }}</td><td class="num strong" :class="cls(r.res)">{{ n(r.res) }}</td><td class="num strong" :class="cls(r.cumul)">{{ n(r.cumul) }}</td>
          </tr>
          <tr v-if="!rows.length"><td colspan="5" class="empty" style="padding:26px">Aucun mouvement ce mois-ci.</td></tr>
        </tbody>
        <tfoot><tr><td class="sticky">Total du mois</td><td class="num pos">{{ n(table.rec) }}</td><td class="num neg">{{ n(table.dep) }}</td><td class="num" :class="cls(table.res)">{{ n(table.res) }}</td><td class="num" :class="cls(table.res)">{{ n(table.res) }}</td></tr></tfoot>
      </table>
    </div>
  </section>
</template>
