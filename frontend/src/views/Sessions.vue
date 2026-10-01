<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import { api, auth } from '../api'
import { money, dateHeure } from '../format'
import { toast } from '../toast'
import Icon from '../components/Icon.vue'
import Pager from '../components/Pager.vue'
import ZReport from '../components/ZReport.vue'

const rows = ref([])
const meta = reactive({ total: 0, page: 1, pages: 1, per_page: 25 })
const loading = ref(true)
const f = reactive({ statut: '', from: '', to: '', page: 1 })
async function load() {
  loading.value = true
  try { const r = await api.get('sessions', { statut: f.statut, from: f.from, to: f.to, page: f.page }); rows.value = r.data; Object.assign(meta, r.meta) } catch (e) { toast(e.message, 'error') } finally { loading.value = false }
}
onMounted(load)
watch(() => [f.statut, f.from, f.to], () => { f.page = 1; load() })
const goto = (p) => { f.page = p; load() }
const rapport = ref(null)
async function voir(s) { try { rapport.value = await api.get('session-rapport', { id: s.idsession }) } catch (e) { toast(e.message, 'error') } }
const imprimer = () => window.print()
const ecartBadge = (s) => (s.statut === 'ouverte' ? { cls: 'primary', text: 'En cours' } : Number(s.ecart) === 0 ? { cls: 'success', text: 'Juste' } : { cls: 'danger', text: `${s.ecart > 0 ? '+' : ''}${money(s.ecart)}` })
</script>

<template>
  <main class="page">
    <div class="page-head"><div><h1>Sessions de caisse</h1><p>Ouvertures, clôtures et écarts de caisse{{ auth.user?.droits?.ventes_toutes ? '' : ' (vos sessions)' }}</p></div></div>
    <div class="card">
      <div class="toolbar">
        <select v-model="f.statut" class="input filter" aria-label="État"><option value="">Toutes</option><option value="ouverte">En cours</option><option value="cloturee">Clôturées</option></select>
        <input v-model="f.from" class="input date" type="date" aria-label="Du" /><input v-model="f.to" class="input date" type="date" aria-label="Au" />
      </div>
      <div class="table-wrap" :aria-busy="loading">
        <table v-if="rows.length || loading" class="table stack">
          <thead><tr><th>Ouverture</th><th>Caisse</th><th>Caissier</th><th class="num">Ventes</th><th class="num">Chiffre d'affaires</th><th>Écart</th><th /></tr></thead>
          <tbody v-if="loading && !rows.length"><tr v-for="n in 5" :key="n"><td colspan="7"><div class="skeleton" style="height:22px" /></td></tr></tbody>
          <tbody v-else>
            <tr v-for="s in rows" :key="s.idsession">
              <td data-label="Ouverture" class="strong"><a href="#" class="rowlink" @click.prevent="voir(s)">{{ dateHeure(s.ouverture_at) }}</a></td>
              <td data-label="Caisse">{{ s.caisse }}</td><td data-label="Caissier">{{ s.caissier }}</td>
              <td data-label="Ventes" class="num">{{ s.nb_ventes }}</td><td data-label="Chiffre d'affaires" class="num">{{ money(s.ca) }}</td>
              <td data-label="Écart"><span class="badge" :class="ecartBadge(s).cls">{{ ecartBadge(s).text }}</span></td>
              <td class="actions"><button class="btn ghost icon sm" aria-label="Rapport de caisse" title="Rapport de caisse" @click="voir(s)"><Icon name="eye" :size="16" /></button></td>
            </tr>
          </tbody>
        </table>
        <div v-else class="empty"><Icon name="inbox" :size="40" /><div>Aucune session.</div></div>
      </div>
      <Pager :meta="meta" @goto="goto" />
    </div>

    <div v-if="rapport" class="overlay center" @click.self="rapport = null" @keydown.esc="rapport = null">
      <div class="modal" role="dialog" aria-modal="true" aria-label="Rapport de caisse" style="width:min(540px,calc(100% - 32px));max-height:calc(100vh - 32px);overflow-y:auto">
        <ZReport :rapport="rapport" />
        <div class="row"><button class="btn" @click="rapport = null">Fermer</button><button class="btn primary" @click="imprimer"><Icon name="printer" :size="16" /> Imprimer</button></div>
      </div>
    </div>
  </main>
</template>
