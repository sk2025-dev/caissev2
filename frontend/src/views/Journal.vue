<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { lookups, loadLookups } from '../lookups'
import { toast } from '../toast'
import { MODULES, ACTIONS, ton, icone, lienFiche, lignesDetail } from '../journal'
import Icon from '../components/Icon.vue'
import SearchSelect from '../components/SearchSelect.vue'
import Pager from '../components/Pager.vue'

const route = useRoute()
const router = useRouter()
const rows = ref([])
const meta = reactive({ total: 0, page: 1, pages: 1, per_page: 50 })
const loading = ref(true)
const f = reactive({ q: '', module: '', iduser: '', from: '', to: '', entite: route.query.entite || '', entite_id: route.query.entite_id || '', page: 1 })
const ouvert = ref(null)

async function load() {
  loading.value = true
  try {
    const r = await api.get('journal', { q: f.q, module: f.module, iduser: f.iduser, from: f.from, to: f.to, entite: f.entite, entite_id: f.entite_id, page: f.page })
    rows.value = r.data; Object.assign(meta, r.meta)
  } catch (e) { toast(e.message, 'error') } finally { loading.value = false }
}
onMounted(() => { load(); loadLookups().catch(() => {}) })
let attente
watch(() => f.q, () => { clearTimeout(attente); attente = setTimeout(() => { f.page = 1; load() }, 300) })
watch(() => [f.module, f.iduser, f.from, f.to, f.entite, f.entite_id], () => { f.page = 1; load() })
const goto = (p) => { f.page = p; load() }

const modules = [{ value: '', label: 'Tous les modules' }, ...Object.entries(MODULES).map(([value, label]) => ({ value, label }))]
const utilisateurs = computed(() => [{ value: '', label: 'Tous les utilisateurs' }, ...lookups.caissiers.map((u) => ({ value: String(u.id), label: u.nom.trim() }))])
const surFiche = computed(() => !!(f.entite && f.entite_id))
function toutAfficher() { f.entite = ''; f.entite_id = ''; router.replace({ query: {} }) }

// Horodatage complet : un journal doit dater à la seconde près
const horodatage = (v) => new Date(v.replace(' ', 'T') + 'Z').toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })
const detailDe = (e) => lignesDetail(e)
const aDuDetail = (e) => { const d = detailDe(e); return d.changements.length > 0 || d.infos.length > 0 || !!lienFiche(e) }
const basculer = (e) => { if (aDuDetail(e)) ouvert.value = ouvert.value === e.id ? null : e.id }
</script>

<template>
  <main class="page">
    <div class="page-head"><div><h1>Journal d'activité</h1><p>Qui a fait quoi, quand et depuis quel poste. Lecture seule : aucune entrée ne peut être modifiée ni effacée.</p></div></div>
    <div v-if="surFiche" class="bandeau">
      <Icon name="history" :size="18" /> Historique d'une seule fiche.
      <button class="btn sm" @click="toutAfficher">Afficher tout le journal</button>
    </div>
    <div class="card">
      <div class="toolbar">
        <div class="search"><Icon name="search" :size="16" /><input v-model="f.q" class="input" type="search" placeholder="Action, utilisateur, n° de pièce, adresse IP…" aria-label="Rechercher dans le journal" /></div>
        <SearchSelect v-model="f.module" class="filter" label="Module" :options="modules" />
        <SearchSelect v-model="f.iduser" class="filter" label="Utilisateur" :options="utilisateurs" />
        <input v-model="f.from" class="input date" type="date" aria-label="Du" /><input v-model="f.to" class="input date" type="date" aria-label="Au" />
      </div>
      <div class="table-wrap" :aria-busy="loading">
        <table v-if="rows.length || loading" class="table stack journal">
          <thead><tr><th>Date et heure</th><th>Utilisateur</th><th>Action</th><th>Poste (IP)</th><th /></tr></thead>
          <tbody v-if="loading && !rows.length"><tr v-for="n in 8" :key="n"><td colspan="5"><div class="skeleton" style="height:22px" /></td></tr></tbody>
          <tbody v-else>
            <template v-for="e in rows" :key="e.id">
              <tr :class="{ cliquable: aDuDetail(e), ouvert: ouvert === e.id }" @click="basculer(e)">
                <td data-label="Date et heure" class="mono nowrap">{{ horodatage(e.created_at) }}</td>
                <td data-label="Utilisateur"><b>{{ e.acteur }}</b><small v-if="!e.user_id && e.ip !== 'cli'" class="sub">non connecté</small></td>
                <td data-label="Action">
                  <div class="quoi">
                    <span class="tile sm" :class="ton(e.action)"><Icon :name="icone(e.module)" :size="16" /></span>
                    <div><b>{{ e.resume || ACTIONS[e.action] || e.action }}</b><small class="sub">{{ MODULES[e.module] || e.module }} · {{ ACTIONS[e.action] || e.action }}</small></div>
                  </div>
                </td>
                <td data-label="Poste (IP)" class="mono muted">{{ e.ip === 'cli' ? 'Ligne de commande' : e.ip || '—' }}</td>
                <td class="actions"><Icon v-if="aDuDetail(e)" :name="ouvert === e.id ? 'up' : 'down'" :size="16" class="muted" /></td>
              </tr>
              <tr v-if="ouvert === e.id" class="detail-row">
                <td colspan="5">
                  <div class="detail">
                    <table v-if="detailDe(e).changements.length" class="table diff">
                      <thead><tr><th v-for="t in detailDe(e).titres" :key="t">{{ t }}</th></tr></thead>
                      <tbody><tr v-for="(c, i) in detailDe(e).changements" :key="i"><td>{{ c.champ }}</td><td class="avant">{{ c.avant }}</td><td class="apres">{{ c.apres }}</td></tr></tbody>
                    </table>
                    <dl v-if="detailDe(e).infos.length" class="infos"><template v-for="(x, i) in detailDe(e).infos" :key="i"><dt>{{ x.champ }}</dt><dd>{{ x.valeur }}</dd></template></dl>
                    <RouterLink v-if="lienFiche(e)" :to="lienFiche(e)" class="btn sm"><Icon name="eye" :size="15" /> Ouvrir la fiche</RouterLink>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
        <div v-else class="empty"><Icon name="inbox" :size="40" /><div>Aucune activité pour ces critères.</div></div>
      </div>
      <Pager :meta="meta" @goto="goto" />
    </div>
  </main>
</template>

<style scoped>
.bandeau { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; padding: 11px 15px; border-radius: 14px; background: var(--primary-soft); color: var(--primary); font-weight: 700; font-size: 14px; }
.mono { font-variant-numeric: tabular-nums; }
.journal tr.cliquable { cursor: pointer; }
.journal tr.ouvert > td { background: var(--primary-soft); }
.nowrap { white-space: nowrap; }
.quoi { display: flex; align-items: flex-start; gap: 10px; }
.quoi .tile.sm { width: 30px; height: 30px; border-radius: 10px; flex: none; }
.sub { display: block; color: var(--muted); font-size: 12.5px; font-weight: 400; }
.detail-row > td { background: var(--surface-2); }
.detail { display: flex; flex-direction: column; gap: 12px; align-items: flex-start; padding: 4px 0; }
.diff { width: auto; min-width: min(560px, 100%); background: var(--surface); border-radius: 12px; }
.diff td { word-break: break-word; }
.diff .avant { color: var(--danger); text-decoration: line-through; text-decoration-color: color-mix(in srgb, var(--danger) 45%, transparent); }
.diff .apres { color: var(--success); font-weight: 600; }
.infos { display: grid; grid-template-columns: max-content 1fr; gap: 6px 18px; margin: 0; }
.infos dt { color: var(--muted); }
.infos dd { margin: 0; word-break: break-word; }
</style>
