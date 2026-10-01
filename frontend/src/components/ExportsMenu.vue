<script setup>
// Bouton « Mes exports » de l'en-tête : suivi des générations en arrière-plan et téléchargement des fichiers prêts.
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { exportsStore, enCours, downloadUrl, formatLabel } from '../exports'
import { date } from '../format'
import Icon from './Icon.vue'

const open = ref(false)
const root = ref(null)
const outside = (e) => { if (!root.value?.contains(e.target)) open.value = false }
onMounted(() => document.addEventListener('mousedown', outside))
onBeforeUnmount(() => document.removeEventListener('mousedown', outside))

const size = (b) => (b < 1024 * 1024 ? `${Math.max(1, Math.round(b / 1024))} Ko` : `${(b / 1024 / 1024).toFixed(1)} Mo`)
const heure = (d) => new Date(d.replace(' ', 'T') + 'Z').toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
const titre = (j) => (j.kind === 'database' ? 'Sauvegarde de la base de données' : `${j.kind === 'stock' ? 'État du stock' : 'Ventes'} · ${formatLabel[j.format]}${j.from ? ` · ${j.from.slice(5)} → ${j.to.slice(5)}` : ''}`)
const label = computed(() => (enCours.value.length ? `Exports : ${enCours.value.length} en cours` : 'Mes exports'))
</script>

<template>
  <div ref="root" class="anchor">
    <button class="pillbtn" :aria-label="label" :aria-expanded="open" @click="open = !open">
      <Icon name="download" :size="20" :class="{ busy: enCours.length }" />
      <span v-if="enCours.length" class="dot work">{{ enCours.length }}</span>
    </button>
    <div v-if="open" class="menu wide" role="dialog" aria-label="Mes exports">
      <div class="who"><b>Mes exports</b><span>{{ enCours.length ? 'Génération en arrière-plan — vous pouvez continuer à travailler' : 'Les fichiers sont conservés 7 jours' }}</span></div>
      <div v-if="!exportsStore.jobs.length" class="none-exp">Aucun export pour l'instant. Utilisez le bouton « Exporter » des ventes ou du stock.</div>
      <div v-for="j in exportsStore.jobs" :key="j.id" class="exp-item">
        <span class="tile" :class="j.status === 'termine' ? 'success' : j.status === 'echec' ? 'danger' : 'primary'"><Icon :name="j.kind === 'database' ? 'database' : j.format === 'xlsx' ? 'sheet' : 'file'" :size="18" /></span>
        <div class="grow">
          <b>{{ titre(j) }}</b>
          <span v-if="j.status === 'termine'">{{ j.filename }} · {{ size(j.size) }} · {{ heure(j.created_at) }}</span>
          <span v-else-if="j.status === 'echec'" class="neg">{{ j.error || 'Échec' }}</span>
          <template v-else>
            <span>{{ j.status === 'en_attente' ? 'En attente…' : 'Génération…' }} {{ j.progress }} %</span>
            <div class="mini-bar" role="progressbar" :aria-valuenow="j.progress" aria-valuemin="0" aria-valuemax="100"><i :style="{ width: Math.max(6, j.progress) + '%' }" /></div>
          </template>
        </div>
        <a v-if="j.status === 'termine'" class="btn sm primary" :href="downloadUrl(j.id)" download><Icon name="download" :size="14" /> Télécharger</a>
      </div>
    </div>
  </div>
</template>
