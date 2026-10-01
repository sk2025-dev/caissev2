// Exports asynchrones : création instantanée, suivi en arrière-plan (interrogation régulière), notification à la fin.
import { reactive, computed } from 'vue'
import { api, auth, ApiError } from './api'
import { toast } from './toast'

export const exportsStore = reactive({ jobs: [], loaded: false })
export const enCours = computed(() => exportsStore.jobs.filter((j) => j.status === 'en_attente' || j.status === 'en_cours'))

const API = import.meta.env.DEV ? '/api/index.php' : '../api/index.php'
export const downloadUrl = (id) => `${API}?r=export-download&id=${id}`
export const formatLabel = { xlsx: 'Excel', csv: 'CSV', pdf: 'PDF', 'sql.gz': 'Sauvegarde' }

let timer = null
const known = new Map()   // id -> statut déjà notifié

async function refresh() {
  if (!auth.user) return stop()
  try {
    const { exports: jobs } = await api.get('exports')
    for (const j of jobs) {
      const before = known.get(j.id)
      if (before && before !== j.status) {
        if (j.status === 'termine') toast(`${j.kind === 'database' ? 'Sauvegarde' : 'Export ' + formatLabel[j.format]} prête : ${j.filename}`, 'success', { label: 'Télécharger', href: downloadUrl(j.id) })
        else if (j.status === 'echec') toast(`L'export ${formatLabel[j.format]} a échoué. ${j.error || ''}`, 'error')
      }
      known.set(j.id, j.status)
    }
    exportsStore.jobs = jobs
    exportsStore.loaded = true
  } catch (e) {
    if (!(e instanceof ApiError)) throw e
  }
  if (enCours.value.length) timer = setTimeout(refresh, 1100)
  else timer = null
}
function stop() { clearTimeout(timer); timer = null }

/** Charge l'historique au démarrage (sans notifier les exports déjà terminés) et reprend le suivi s'il y en a en cours. */
export async function initExports() {
  stop(); known.clear()
  try {
    const { exports: jobs } = await api.get('exports')
    jobs.forEach((j) => known.set(j.id, j.status))
    exportsStore.jobs = jobs; exportsStore.loaded = true
    if (enCours.value.length) timer = setTimeout(refresh, 1100)
  } catch { /* non bloquant */ }
}

/** Lance un export : la réponse est immédiate, la génération se poursuit côté serveur. */
export async function startExport({ format, kind, from, to }) {
  const res = await api.post('export-create', { format, kind, from, to })
  known.set(res.job.id, res.job.status)
  exportsStore.jobs = [res.job, ...exportsStore.jobs.filter((j) => j.id !== res.job.id)].slice(0, 10)
  toast(kind === 'database' ? 'Sauvegarde de la base lancée — vous pouvez continuer à travailler' : `Export ${formatLabel[format]} lancé — vous pouvez continuer à travailler`, 'success')
  clearTimeout(timer); timer = setTimeout(refresh, 600)
  return res.job
}
