<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { api, auth, ApiError } from '../api'
import { lookups, loadLookups } from '../lookups'
import { money, dateHeure } from '../format'
import { toast } from '../toast'
import Icon from '../components/Icon.vue'
import Pager from '../components/Pager.vue'
import Ticket from '../components/Ticket.vue'
import ExportButtons from '../components/ExportButtons.vue'

const route = useRoute()
const gerant = computed(() => !!auth.user?.droits?.ventes_toutes)
const isAdmin = computed(() => !!auth.user?.admin)
const rows = ref([])
const meta = reactive({ total: 0, page: 1, pages: 1, per_page: 25, somme: 0 })
const loading = ref(true)
const error = ref('')
const f = reactive({ q: typeof route.query.q === 'string' ? route.query.q : '', statut: '', credit: false, from: '', to: '', iduser: '', page: 1 })

let seq = 0
async function load() {
  const my = ++seq
  loading.value = true; error.value = ''
  try {
    const res = await api.get('ventes', { q: f.q, statut: f.statut, credit: f.credit ? 1 : '', from: f.from, to: f.to, iduser: f.iduser, page: f.page })
    if (my !== seq) return
    rows.value = res.data; Object.assign(meta, res.meta)
  } catch (e) { if (my === seq) error.value = e.message } finally { if (my === seq) loading.value = false }
}
let timer
watch(() => f.q, () => { clearTimeout(timer); timer = setTimeout(() => { f.page = 1; load() }, 300) })
watch(() => [f.statut, f.credit, f.from, f.to, f.iduser], () => { f.page = 1; load() })
onMounted(async () => { await loadLookups().catch(() => {}); load() })
onBeforeUnmount(() => clearTimeout(timer))
const goto = (p) => { f.page = p; load() }

/* ---- Détail, ticket, annulation ---- */
const detail = ref(null)
const annul = reactive({ ouvert: false, motif: '', busy: false, erreur: '' })
async function voir(r) {
  try { detail.value = (await api.get('vente', { id: r.idvente })).vente } catch (e) { toast(e.message, 'error') }
}
async function annuler() {
  annul.busy = true; annul.erreur = ''
  try {
    detail.value = (await api.post('vente-annuler', { idvente: detail.value.idvente, motif: annul.motif })).vente
    annul.ouvert = false; toast('Vente annulée — le stock a été remis en rayon'); load()
  } catch (e) {
    if (!(e instanceof ApiError)) throw e
    annul.erreur = e.errors?.motif || e.message
  } finally { annul.busy = false }
}
const imprimer = () => window.print()
const badge = (r) => (r.statut === 'annulee' ? ['danger', 'Annulée'] : Number(r.reste) > 0 ? ['warning', `Crédit ${money(r.reste)}`] : ['success', 'Payée'])
</script>

<template>
  <main class="page">
    <div class="page-head">
      <div><h1>Ventes</h1><p>{{ gerant ? 'Toutes les ventes de la boutique' : 'Vos ventes' }}</p></div>
      <ExportButtons v-if="gerant" kind="ventes" :from="f.from" :to="f.to" />
    </div>

    <div class="card">
      <div class="toolbar">
        <div class="search"><Icon name="search" :size="16" /><input v-model="f.q" class="input" type="search" placeholder="N° de ticket ou client…" aria-label="Rechercher une vente" /></div>
        <select v-model="f.statut" class="input filter" aria-label="Statut"><option value="">Tous les statuts</option><option value="validee">Validées</option><option value="annulee">Annulées</option></select>
        <select v-if="gerant" v-model="f.iduser" class="input filter" aria-label="Caissier"><option value="">Tous les caissiers</option><option v-for="c in lookups.caissiers" :key="c.id" :value="c.id">{{ c.nom }}</option></select>
        <label class="check"><input v-model="f.credit" type="checkbox" /><span>À crédit</span></label>
        <input v-model="f.from" class="input date" type="date" aria-label="Du" /><input v-model="f.to" class="input date" type="date" aria-label="Au" />
      </div>

      <div v-if="error" class="empty"><div class="alert" style="display:inline-block">{{ error }}</div><div style="margin-top:12px"><button class="btn" @click="load">Réessayer</button></div></div>
      <div v-else class="table-wrap" :aria-busy="loading">
        <table v-if="rows.length || loading" class="table stack">
          <thead><tr><th>Ticket</th><th>Date</th><th v-if="gerant">Caissier</th><th>Client</th><th>Paiement</th><th class="num">Total</th><th>État</th><th /></tr></thead>
          <tbody v-if="loading && !rows.length"><tr v-for="n in 6" :key="n"><td colspan="8"><div class="skeleton" style="height:22px" /></td></tr></tbody>
          <tbody v-else :style="{ opacity: loading ? .55 : 1 }">
            <tr v-for="r in rows" :key="r.idvente" :class="{ cancelled: r.statut === 'annulee' }">
              <td data-label="Ticket" class="strong"><a href="#" class="rowlink" @click.prevent="voir(r)">{{ r.numero }}</a></td>
              <td data-label="Date">{{ dateHeure(r.date_vente) }}</td>
              <td v-if="gerant" data-label="Caissier">{{ r.caissier }}</td>
              <td data-label="Client">{{ r.client || '—' }}</td>
              <td data-label="Paiement">{{ (r.modes || '').split(',').filter(Boolean).join(', ') || '—' }}</td>
              <td data-label="Total" class="num">{{ money(r.total) }}</td>
              <td data-label="État"><span class="badge" :class="badge(r)[0]">{{ badge(r)[1] }}</span></td>
              <td class="actions"><button class="btn ghost icon sm" aria-label="Voir le ticket" title="Voir le ticket" @click="voir(r)"><Icon name="receipt" :size="16" /></button></td>
            </tr>
          </tbody>
        </table>
        <div v-else class="empty"><Icon name="inbox" :size="40" /><div>Aucune vente pour ces critères.</div></div>
      </div>
      <div v-if="meta.total" class="sum-line">Total des ventes validées affichées : <b>{{ money(meta.somme) }}</b></div>
      <Pager :meta="meta" @goto="goto" />
    </div>

    <div v-if="detail" class="overlay center" @click.self="detail = null" @keydown.esc="detail = null">
      <div class="modal" role="dialog" aria-modal="true" :aria-label="`Vente ${detail.numero}`" style="width:min(460px,calc(100% - 32px));max-height:calc(100vh - 32px);display:flex;flex-direction:column">
        <div style="overflow-y:auto;background:var(--surface-2);border-radius:16px;padding:4px"><Ticket :vente="detail" /></div>
        <p v-if="detail.statut === 'annulee'" class="muted" style="margin:10px 0 0;font-size:13px">Annulée : {{ detail.annule_motif }}</p>
        <div class="row">
          <button class="btn" @click="detail = null">Fermer</button>
          <button v-if="isAdmin && detail.statut === 'validee'" class="btn danger" @click="Object.assign(annul, { ouvert: true, motif: '', erreur: '' })">Annuler la vente</button>
          <button class="btn primary" @click="imprimer"><Icon name="printer" :size="16" /> Imprimer</button>
        </div>
      </div>
    </div>

    <div v-if="annul.ouvert" class="overlay center" style="z-index:70" @click.self="annul.ouvert = false" @keydown.esc.stop="annul.ouvert = false">
      <form class="modal" novalidate @submit.prevent="annuler">
        <h3>Annuler la vente {{ detail.numero }} ?</h3>
        <p class="muted">Le stock est remis en rayon et, pour une vente à crédit, la dette du client est reprise. Possible tant que la caisse n'est pas clôturée.</p>
        <div class="field" :class="{ invalid: annul.erreur }"><label for="a-m">Motif <span class="req">*</span></label><input id="a-m" v-model="annul.motif" class="input" maxlength="200" autofocus /><span v-if="annul.erreur" class="error">{{ annul.erreur }}</span></div>
        <div class="row"><button type="button" class="btn" @click="annul.ouvert = false">Retour</button><button class="btn danger" :disabled="annul.busy">{{ annul.busy ? 'Annulation…' : 'Annuler la vente' }}</button></div>
      </form>
    </div>
  </main>
</template>

<style scoped>
.sum-line { padding: 10px 18px; text-align: right; color: var(--muted); border-top: 1px solid var(--border); }
tr.cancelled td { opacity: .6; text-decoration: line-through; }
tr.cancelled td.actions, tr.cancelled td:nth-last-child(2) { text-decoration: none; }
</style>
