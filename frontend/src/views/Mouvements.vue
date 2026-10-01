<script setup>
// État des mouvements d'espèces (entrées / sorties de caisse) : journal numéroté, synthèse par catégorie et par jour,
// état imprimable signé et export comptable (Excel, CSV, PDF).
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { api, auth } from '../api'
import { lookups, loadLookups } from '../lookups'
import { money, dateHeure, date, today } from '../format'
import { logoUrl, nomApp } from '../theme'
import { toast } from '../toast'
import Icon from '../components/Icon.vue'
import Pager from '../components/Pager.vue'
import ExportButtons from '../components/ExportButtons.vue'

const gerant = computed(() => !!auth.user?.droits?.ventes_toutes)
const debutMois = () => `${today().slice(0, 8)}01`
const f = reactive({ from: debutMois(), to: today(), type: '', categorie: '', idcaisse: '', iduser: '', q: '', page: 1 })
const rows = ref([])
const meta = reactive({ total: 0, page: 1, pages: 1, per_page: 50 })
const totaux = ref({ nb: 0, entrees: 0, sorties: 0, net: 0 })
const parCategorie = ref([])
const parJour = ref([])
const loading = ref(true)
const error = ref('')

const params = () => ({ from: f.from, to: f.to, type: f.type, categorie: f.categorie, idcaisse: f.idcaisse, iduser: f.iduser, q: f.q })
let seq = 0
async function load() {
  const my = ++seq
  loading.value = true; error.value = ''
  try {
    const r = await api.get('mouvements-caisse', { ...params(), page: f.page })
    if (my !== seq) return
    rows.value = r.data; Object.assign(meta, r.meta); totaux.value = r.totaux; parCategorie.value = r.par_categorie; parJour.value = r.par_jour
  } catch (e) { if (my === seq) error.value = e.message } finally { if (my === seq) loading.value = false }
}
let timer
watch(() => f.q, () => { clearTimeout(timer); timer = setTimeout(() => { f.page = 1; load() }, 300) })
watch(() => [f.from, f.to, f.type, f.categorie, f.idcaisse, f.iduser], () => { f.page = 1; load() })
watch(() => f.type, () => { f.categorie = '' })
onMounted(async () => { await loadLookups().catch(() => {}); load() })
onBeforeUnmount(() => clearTimeout(timer))

const categoriesFiltre = computed(() => Object.entries(f.type ? lookups.categories_mvt[f.type] || {} : { ...lookups.categories_mvt.entree, ...lookups.categories_mvt.sortie }))
const sens = (t) => (t === 'entree' ? 'Entrée' : 'Sortie')

/* ---- État imprimable : toute la période (jusqu'à 1000 pièces), pas seulement la page affichée ---- */
const etat = ref(null)
const periode = computed(() => `du ${date(f.from)} au ${date(f.to)}`)
const filtresTexte = computed(() => [
  f.type && sens(f.type) + 's', f.categorie && categoriesFiltre.value.find(([c]) => c === f.categorie)?.[1],
  f.idcaisse && lookups.caisses.find((c) => String(c.id) === String(f.idcaisse))?.nom, f.iduser && lookups.caissiers.find((c) => String(c.id) === String(f.iduser))?.nom, f.q && `recherche « ${f.q} »`,
].filter(Boolean).join(' · ') || 'Tous les mouvements')
async function imprimerEtat() {
  try {
    etat.value = await api.get('mouvements-caisse', { ...params(), per_page: 1000 })
    if (etat.value.meta.total > 1000) toast('Plus de 1000 pièces : l\'état imprimé est limité aux 1000 plus récentes — affinez la période ou exportez.', 'error')
    await nextTick(); window.print()
  } catch (e) { toast(e.message, 'error') }
}
const entrees = (l) => l.filter((c) => c.type === 'entree')
const sorties = (l) => l.filter((c) => c.type === 'sortie')
const edite = new Date().toISOString().slice(0, 19).replace('T', ' ')
</script>

<template>
  <main class="page">
    <div class="page-head">
      <div><h1>Mouvements de caisse</h1><p>{{ gerant ? 'Entrées et sorties d\'espèces de toutes les caisses' : 'Vos entrées et sorties d\'espèces' }} — pièces numérotées, classées par catégorie.</p></div>
      <div class="head-actions">
        <button class="btn" :disabled="!totaux.nb" @click="imprimerEtat"><Icon name="printer" :size="16" /> Imprimer l'état</button>
        <ExportButtons v-if="gerant" kind="mouvements" :from="f.from" :to="f.to" />
      </div>
    </div>

    <div class="kpis">
      <div class="kpi in"><span><Icon name="plus" :size="14" /> Entrées</span><b>{{ money(totaux.entrees) }}</b></div>
      <div class="kpi out"><span><Icon name="minus" :size="14" /> Sorties</span><b>{{ money(totaux.sorties) }}</b></div>
      <div class="kpi" :class="totaux.net < 0 ? 'neg' : 'pos'"><span>Solde net</span><b>{{ totaux.net > 0 ? '+' : '' }}{{ money(totaux.net) }}</b></div>
      <div class="kpi"><span>Pièces</span><b>{{ totaux.nb }}</b></div>
    </div>

    <div class="grid2">
      <div class="card">
        <div class="card-head"><h3>Par catégorie</h3></div>
        <div v-if="!parCategorie.length" class="empty small">Aucun mouvement sur la période.</div>
        <table v-else class="synth">
          <tbody>
            <tr v-for="c in parCategorie" :key="c.type + c.categorie"><td><span class="dot" :class="c.type" />{{ c.libelle }}<small>{{ c.nb }} pièce{{ c.nb > 1 ? 's' : '' }}</small></td><td class="n" :class="c.type">{{ c.type === 'entree' ? '+' : '−' }}{{ money(c.total) }}</td></tr>
          </tbody>
        </table>
      </div>
      <div class="card">
        <div class="card-head"><h3>Par jour</h3></div>
        <div v-if="!parJour.length" class="empty small">—</div>
        <table v-else class="synth">
          <thead><tr><th>Jour</th><th class="n">Entrées</th><th class="n">Sorties</th><th class="n">Solde</th></tr></thead>
          <tbody><tr v-for="j in parJour" :key="j.jour"><td>{{ date(j.jour) }}</td><td class="n in">{{ j.entrees ? money(j.entrees) : '' }}</td><td class="n out">{{ j.sorties ? money(j.sorties) : '' }}</td><td class="n">{{ money(j.net) }}</td></tr></tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="toolbar">
        <div class="search"><Icon name="search" :size="16" /><input v-model="f.q" class="input" type="search" placeholder="N° de pièce, libellé, justificatif…" aria-label="Rechercher un mouvement" /></div>
        <input v-model="f.from" class="input date" type="date" aria-label="Du" /><input v-model="f.to" class="input date" type="date" aria-label="Au" />
        <select v-model="f.type" class="input filter" aria-label="Sens"><option value="">Entrées et sorties</option><option value="entree">Entrées</option><option value="sortie">Sorties</option></select>
        <select v-model="f.categorie" class="input filter" aria-label="Catégorie"><option value="">Toutes les catégories</option><option v-for="[c, l] in categoriesFiltre" :key="c" :value="c">{{ l }}</option></select>
        <select v-if="lookups.caisses.length > 1" v-model="f.idcaisse" class="input filter" aria-label="Caisse"><option value="">Toutes les caisses</option><option v-for="c in lookups.caisses" :key="c.id" :value="c.id">{{ c.nom }}</option></select>
        <select v-if="gerant" v-model="f.iduser" class="input filter" aria-label="Saisi par"><option value="">Tous les utilisateurs</option><option v-for="c in lookups.caissiers" :key="c.id" :value="c.id">{{ c.nom }}</option></select>
      </div>

      <div v-if="error" class="empty"><div class="alert" style="display:inline-block">{{ error }}</div><div style="margin-top:12px"><button class="btn" @click="load">Réessayer</button></div></div>
      <div v-else class="table-wrap" :aria-busy="loading">
        <table v-if="rows.length || loading" class="table stack">
          <thead><tr><th>N° pièce</th><th>Date</th><th>Catégorie / libellé</th><th>Caisse</th><th>Saisi par</th><th class="num">Entrée</th><th class="num">Sortie</th></tr></thead>
          <tbody v-if="loading && !rows.length"><tr v-for="n in 6" :key="n"><td colspan="7"><div class="skeleton" style="height:22px" /></td></tr></tbody>
          <tbody v-else :style="{ opacity: loading ? .55 : 1 }">
            <tr v-for="r in rows" :key="r.idop">
              <td data-label="N° pièce" class="mono strong">{{ r.numero }}</td>
              <td data-label="Date">{{ dateHeure(r.created_at) }}</td>
              <td data-label="Libellé"><b>{{ r.categorie_libelle }}</b><small class="sub">{{ r.motif }}<template v-if="r.reference"> · Justif. {{ r.reference }}</template></small></td>
              <td data-label="Caisse">{{ r.caisse }}</td>
              <td data-label="Saisi par">{{ r.auteur }}</td>
              <td data-label="Entrée" class="num in">{{ r.type === 'entree' ? money(r.montant) : '' }}</td>
              <td data-label="Sortie" class="num out">{{ r.type === 'sortie' ? money(r.montant) : '' }}</td>
            </tr>
          </tbody>
          <tfoot v-if="rows.length"><tr><td colspan="5">Total de la période ({{ totaux.nb }} pièce{{ totaux.nb > 1 ? 's' : '' }})</td><td class="num in">{{ money(totaux.entrees) }}</td><td class="num out">{{ money(totaux.sorties) }}</td></tr></tfoot>
        </table>
        <div v-else class="empty"><Icon name="swap" :size="40" /><div>Aucun mouvement pour ces critères.</div></div>
      </div>
      <Pager :meta="meta" @goto="(p) => { f.page = p; load() }" />
    </div>

    <!-- État imprimable : toute la période, présentation comptable (entrées / sorties en colonnes) -->
    <div v-if="etat" class="print-area etat">
      <header><div class="who"><img v-if="logoUrl" :src="logoUrl" alt="" /><b>{{ etat.entreprise?.nom || nomApp }}</b></div><div class="t"><h2>État des mouvements d'espèces</h2><p>{{ periode }}</p><p class="f">{{ filtresTexte }}</p></div></header>
      <table class="recap">
        <tr><td>Total des entrées ({{ entrees(etat.par_categorie).reduce((n, c) => n + c.nb, 0) }})</td><td class="n">{{ money(etat.totaux.entrees) }}</td></tr>
        <tr><td>Total des sorties ({{ sorties(etat.par_categorie).reduce((n, c) => n + c.nb, 0) }})</td><td class="n">{{ money(etat.totaux.sorties) }}</td></tr>
        <tr class="net"><td>Solde net (entrées − sorties)</td><td class="n">{{ etat.totaux.net > 0 ? '+' : '' }}{{ money(etat.totaux.net) }}</td></tr>
      </table>
      <h3>Synthèse par catégorie</h3>
      <table class="cats"><thead><tr><th>Sens</th><th>Catégorie</th><th class="n">Pièces</th><th class="n">Montant</th></tr></thead>
        <tbody><tr v-for="c in etat.par_categorie" :key="c.type + c.categorie"><td>{{ sens(c.type) }}</td><td>{{ c.libelle }}</td><td class="n">{{ c.nb }}</td><td class="n">{{ money(c.total) }}</td></tr></tbody></table>
      <h3>Détail des pièces</h3>
      <table class="det"><thead><tr><th>N° pièce</th><th>Date</th><th>Caisse</th><th>Catégorie et libellé</th><th>Saisi par</th><th class="n">Entrées</th><th class="n">Sorties</th></tr></thead>
        <tbody><tr v-for="r in [...etat.data].reverse()" :key="r.idop"><td class="mono">{{ r.numero }}</td><td>{{ dateHeure(r.created_at) }}</td><td>{{ r.caisse }}</td><td><b>{{ r.categorie_libelle }}</b> — {{ r.motif }}<template v-if="r.reference"> (justif. {{ r.reference }})</template></td><td>{{ r.auteur }}</td><td class="n">{{ r.type === 'entree' ? money(r.montant) : '' }}</td><td class="n">{{ r.type === 'sortie' ? money(r.montant) : '' }}</td></tr></tbody>
        <tfoot><tr><td colspan="5">TOTAL</td><td class="n">{{ money(etat.totaux.entrees) }}</td><td class="n">{{ money(etat.totaux.sorties) }}</td></tr></tfoot></table>
      <div class="sign"><div><span>Établi par</span><i /><small>{{ auth.user?.name }} — le {{ dateHeure(edite) }}</small></div><div><span>Vérifié et approuvé par</span><i /><small>Nom, date et signature</small></div></div>
    </div>
  </main>
</template>

<style scoped>
.head-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 14px 18px; display: flex; flex-direction: column; gap: 4px; box-shadow: var(--shadow); }
.kpi span { color: var(--muted); font-size: 13px; display: inline-flex; align-items: center; gap: 6px; } .kpi b { font-size: 22px; letter-spacing: -.02em; }
.kpi.in b, .in { color: var(--success); } .kpi.out b, .out { color: var(--danger); } .kpi.neg b { color: var(--danger); } .kpi.pos b { color: var(--success); }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.card-head { padding: 14px 18px 4px; } .card-head h3 { margin: 0; font-size: 15px; }
.synth { width: 100%; border-collapse: collapse; margin-bottom: 8px; } .synth td, .synth th { padding: 8px 18px; } .synth tr + tr { border-top: 1px dashed var(--border); }
.synth th { text-align: left; font-size: 12px; color: var(--muted); font-weight: 600; } .n { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.synth small { display: block; color: var(--muted); font-size: 12px; margin-left: 18px; }
.dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 9px; } .dot.entree { background: var(--success); } .dot.sortie { background: var(--danger); }
.empty.small { padding: 22px; }
.mono { font-family: ui-monospace, Menlo, monospace; font-size: 12.5px; white-space: nowrap; }
.sub { display: block; color: var(--muted); font-weight: 400; font-size: 12.5px; }
tfoot td { font-weight: 800; padding: 12px; border-top: 2px solid var(--border); }
.etat { display: none; font-size: 12px; color: #000; }
@media (max-width: 860px) { .kpis { grid-template-columns: 1fr 1fr; } .grid2 { grid-template-columns: 1fr; } }
@media print {
  .etat { display: block !important; width: 100%; }
  .etat header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #000; padding-bottom: 8px; margin-bottom: 12px; }
  .etat .who { display: flex; align-items: center; gap: 10px; font-size: 16px; } .etat .who img { height: 38px; }
  .etat .t { text-align: right; } .etat h2 { margin: 0; font-size: 18px; } .etat .t p { margin: 2px 0; } .etat .f { font-size: 11px; color: #444; }
  .etat h3 { margin: 16px 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; }
  .etat table { width: 100%; border-collapse: collapse; } .etat th, .etat td { padding: 4px 5px; text-align: left; vertical-align: top; border-bottom: 1px solid #bbb; } .etat .n { text-align: right; }
  .etat th { border-bottom: 2px solid #000; font-size: 11px; text-transform: uppercase; }
  .etat tfoot td { border-top: 2px solid #000; border-bottom: 0; font-weight: 700; } .etat .recap { width: 60%; margin-bottom: 4px; } .etat .recap .net td { font-weight: 800; font-size: 14px; border-top: 2px solid #000; }
  .etat .det { font-size: 11px; } .etat tr { break-inside: avoid; } .etat thead { display: table-header-group; }
  .etat .mono { font-family: monospace; }
  .etat .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 36px; break-inside: avoid; } .etat .sign span { font-weight: 700; } .etat .sign i { display: block; height: 50px; border-bottom: 1px solid #000; } .etat .sign small { color: #444; }
}
</style>
