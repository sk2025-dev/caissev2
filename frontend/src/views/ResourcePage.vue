<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { api, auth, ApiError } from '../api'
import { resources } from '../resources'
import { loadLookups } from '../lookups'
import { toast } from '../toast'
import Icon from '../components/Icon.vue'
import FormDrawer from '../components/FormDrawer.vue'
import ConfirmModal from '../components/ConfirmModal.vue'

const props = defineProps({ name: { type: String, required: true } })
const config = resources[props.name]
const idKey = config.pk

const rows = ref([])
const meta = reactive({ total: 0, page: 1, pages: 1, per_page: 25 })
const loading = ref(true)
const error = ref('')
const route = useRoute()
const anciens = ref(false)
const query = reactive({ q: typeof route.query.q === 'string' ? route.query.q : '', sort: '', dir: 'desc', page: 1, from: '', to: '' })
const filtres = reactive({})
for (const f of config.filters || []) filtres[f.key] = f.default ?? ''
const editing = ref(undefined) // undefined = fermé, null = création, objet = édition
const toDelete = ref(null)
const deleting = ref(false)
const isAdmin = computed(() => auth.user?.admin)
const peutEcrire = computed(() => (config.write ? config.write(auth.user) : true))

let seq = 0
async function load() {
  const my = ++seq
  loading.value = true
  error.value = ''
  try {
    const res = await api.get(props.name, { statut: anciens.value ? 'tous' : undefined, q: query.q, sort: query.sort, dir: query.dir, page: query.page, from: query.from, to: query.to, ...filtres })
    if (my !== seq) return
    rows.value = res.data
    Object.assign(meta, res.meta)
  } catch (e) {
    if (my === seq && e instanceof ApiError) error.value = e.message
  } finally {
    if (my === seq) loading.value = false
  }
}

let timer
watch(() => route.query.q, (v) => { query.q = typeof v === 'string' ? v : '' })
watch(() => query.q, () => { clearTimeout(timer); timer = setTimeout(() => { query.page = 1; load() }, 300) })
watch(anciens, () => { query.page = 1; load() })
watch(() => [query.from, query.to], () => { query.page = 1; load() })
watch(filtres, () => { query.page = 1; load() })
onBeforeUnmount(() => clearTimeout(timer))

onMounted(async () => {
  if (config.needsLookups) await loadLookups().catch(() => {})
  load()
})

function sortBy(col) {
  if (!col.sort) return
  if (query.sort === col.sort) query.dir = query.dir === 'asc' ? 'desc' : 'asc'
  else { query.sort = col.sort; query.dir = 'asc' }
  query.page = 1
  load()
}
function goto(p) { query.page = Math.min(Math.max(1, p), meta.pages); load() }

async function saved() {
  editing.value = undefined
  if (config.refreshLookups) loadLookups(true).catch(() => {})
  await load()
}

async function confirmDelete() {
  deleting.value = true
  try {
    await api.del(props.name, toDelete.value[idKey])
    toast(config.deleteLabel ? 'Compte désactivé' : 'Élément supprimé')
    toDelete.value = null
    if (rows.value.length === 1 && query.page > 1) query.page--
    if (config.refreshLookups) loadLookups(true).catch(() => {})
    await load()
  } catch (e) {
    toast(e.message, 'error')
    toDelete.value = null
  } finally {
    deleting.value = false
  }
}

const cell = (col, row) => (col.format ? col.format(row) : row[col.key] || '—')
const from = computed(() => (meta.total ? (meta.page - 1) * meta.per_page + 1 : 0))
const to = computed(() => Math.min(meta.total, meta.page * meta.per_page))
</script>

<template>
  <main class="page">
    <div class="page-head">
      <div>
        <h1>{{ config.title }}</h1>
        <p>{{ config.subtitle }}</p>
      </div>
      <button v-if="peutEcrire" class="btn primary" @click="editing = null"><Icon name="plus" /> Ajouter</button>
    </div>

    <div class="card">
      <div class="toolbar">
        <div class="search">
          <Icon name="search" :size="16" />
          <input v-model="query.q" class="input" type="search" :placeholder="config.searchPlaceholder" :aria-label="config.searchPlaceholder" />
        </div>
        <label v-if="config.softStatus" class="check"><input v-model="anciens" type="checkbox" /><span>Afficher les anciens</span></label>
        <select v-for="f in config.filters || []" :key="f.key" v-model="filtres[f.key]" class="input filter" :aria-label="f.label">
          <option v-for="o in f.options" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
        <template v-if="config.dateFilter">
          <input v-model="query.from" class="input date" type="date" aria-label="Du" />
          <input v-model="query.to" class="input date" type="date" aria-label="Au" />
        </template>
      </div>

      <div v-if="error" class="empty"><div class="alert" style="display:inline-block">{{ error }}</div><div style="margin-top:12px"><button class="btn" @click="load">Réessayer</button></div></div>

      <div v-else class="table-wrap" :aria-busy="loading">
        <table v-if="rows.length || loading" class="table stack">
          <thead>
            <tr>
              <th v-for="c in config.columns" :key="c.key" :class="[{ sortable: c.sort, num: c.align === 'num' }]"
                  :aria-sort="query.sort === c.sort ? (query.dir === 'asc' ? 'ascending' : 'descending') : undefined" @click="sortBy(c)">
                {{ c.label }}
                <Icon v-if="query.sort === c.sort" :name="query.dir === 'asc' ? 'up' : 'down'" :size="12" />
              </th>
              <th />
            </tr>
          </thead>
          <tbody v-if="loading && !rows.length">
            <tr v-for="n in 6" :key="n"><td :colspan="config.columns.length + 1"><div class="skeleton" style="height:22px" /></td></tr>
          </tbody>
          <tbody v-else :style="{ opacity: loading ? .55 : 1 }">
            <tr v-for="r in rows" :key="r[idKey]">
              <td v-for="(c, ci) in config.columns" :key="c.key" :data-label="c.label" :class="[c.cls, { num: c.align === 'num' }]">
                <RouterLink v-if="ci === 0 && config.detail" class="rowlink" :to="config.detail(r)">{{ cell(c, r) }}</RouterLink>
                <template v-else-if="c.badge && c.badge(r)"><span class="badge" :class="c.badge(r).cls">{{ c.badge(r).text }}</span></template>
                <template v-else>{{ cell(c, r) }}</template>
              </td>
              <td class="actions">
                <RouterLink v-if="config.detail" class="btn ghost icon sm" :to="config.detail(r)" aria-label="Détails" title="Voir la fiche détaillée"><Icon name="eye" :size="16" /></RouterLink>
                 <button v-if="peutEcrire" class="btn ghost icon sm" :aria-label="`Modifier`" title="Modifier" @click="editing = r"><Icon name="edit" :size="16" /></button>
                <button v-if="isAdmin" class="btn ghost icon sm" :aria-label="config.deleteLabel || 'Supprimer'" :title="config.deleteLabel || 'Supprimer'" @click="toDelete = r"><Icon name="trash" :size="16" /></button>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-else class="empty">
          <Icon name="inbox" :size="40" />
          <div>{{ query.q || query.from || query.to ? 'Aucun résultat pour ces critères.' : `Aucun${config.feminine ? 'e' : ''} ${config.singular} enregistré${config.feminine ? 'e' : ''}.` }}</div>
          <button v-if="!query.q && peutEcrire" class="btn primary" style="margin-top:14px" @click="editing = null"><Icon name="plus" /> Ajouter {{ config.feminine ? 'une' : 'un' }} {{ config.singular }}</button>
        </div>
      </div>

      <div v-if="meta.total" class="pager">
        <span>{{ from }}–{{ to }} sur {{ meta.total }}</span>
        <div class="btns">
          <button class="btn sm" :disabled="meta.page <= 1" @click="goto(meta.page - 1)"><Icon name="chevL" :size="14" /> Précédent</button>
          <button class="btn sm" :disabled="meta.page >= meta.pages" @click="goto(meta.page + 1)">Suivant <Icon name="chevR" :size="14" /></button>
        </div>
      </div>
    </div>

    <FormDrawer v-if="editing !== undefined" :config="config" :route="name" :id-key="idKey" :row="editing" @close="editing = undefined" @saved="saved" />
    <ConfirmModal v-if="toDelete" :title="`${config.deleteLabel || 'Supprimer'} cet élément ?`"
                  :message="config.deleteLabel ? 'Le compte ne pourra plus se connecter. Vous pourrez le réactiver en le modifiant.' : 'Il n\'apparaîtra plus dans les listes. Cette action est enregistrée dans le journal.'"
                  :confirm-label="config.deleteLabel || 'Supprimer'" :busy="deleting" @cancel="toDelete = null" @confirm="confirmDelete" />
  </main>
</template>
