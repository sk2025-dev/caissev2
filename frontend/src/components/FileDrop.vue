<script setup>
// Zone de dépôt : glisser-déposer ou parcourir, aperçu des images, contrôle du type et de la taille côté client.
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import Icon from './Icon.vue'

const props = defineProps({
  modelValue: { type: File, default: null },
  id: String,
  accept: { type: String, default: '' },        // « .pdf,.jpg,.png »
  maxMb: { type: Number, default: 5 },
  current: { type: String, default: '' },        // fichier déjà enregistré
  currentUrl: { type: String, default: '' },
  invalid: Boolean,
})
const emit = defineEmits(['update:modelValue'])

const over = ref(false)
const problem = ref('')
const preview = ref('')
const input = ref(null)
const exts = computed(() => props.accept.split(',').map((x) => x.trim().replace('.', '').toLowerCase()).filter(Boolean))
const isImg = (name) => /\.(jpe?g|png|gif|webp)$/i.test(name || '')
const size = (b) => (b < 1024 * 1024 ? `${Math.max(1, Math.round(b / 1024))} Ko` : `${(b / 1024 / 1024).toFixed(1)} Mo`)

function take(file) {
  problem.value = ''
  if (!file) return
  const ext = file.name.split('.').pop().toLowerCase()
  if (exts.value.length && !exts.value.includes(ext)) { problem.value = `Format non accepté (${exts.value.join(', ').toUpperCase()})`; return }
  if (file.size > props.maxMb * 1024 * 1024) { problem.value = `Fichier trop lourd : ${size(file.size)} (maximum ${props.maxMb} Mo)`; return }
  emit('update:modelValue', file)
}
function clear() { problem.value = ''; emit('update:modelValue', null); if (input.value) input.value.value = '' }
function onDrop(e) { over.value = false; take(e.dataTransfer?.files?.[0]) }

watch(() => props.modelValue, (f) => {
  if (preview.value) URL.revokeObjectURL(preview.value)
  preview.value = f && isImg(f.name) ? URL.createObjectURL(f) : ''
})
onBeforeUnmount(() => preview.value && URL.revokeObjectURL(preview.value))
</script>

<template>
  <div class="drop-wrap">
    <div v-if="modelValue" class="drop-file">
      <img v-if="preview" :src="preview" alt="" class="thumb" />
      <span v-else class="thumb doc"><Icon name="file" :size="24" /></span>
      <div class="meta"><b :title="modelValue.name">{{ modelValue.name }}</b><span>{{ size(modelValue.size) }} · prêt à être enregistré</span></div>
      <span class="ok"><Icon name="check" :size="16" /></span>
      <button type="button" class="btn ghost icon sm" aria-label="Retirer le fichier" @click="clear"><Icon name="x" :size="16" /></button>
    </div>

    <label
      v-else class="dropzone" :class="{ over, invalid: invalid || problem }" @dragenter.prevent="over = true" @dragover.prevent="over = true"
      @dragleave.prevent="over = false" @drop.prevent="onDrop"
    >
      <input :id="id" ref="input" type="file" class="sr-only" :accept="accept" @change="take($event.target.files[0])" />
      <span class="icon"><Icon name="upload" :size="24" /></span>
      <span class="txt"><b>{{ over ? 'Relâchez pour déposer' : 'Glissez un fichier ici' }}</b><span>ou <u>parcourez vos dossiers</u></span></span>
      <span class="rules">{{ exts.map((e) => e.toUpperCase()).join(' · ') }} · {{ maxMb }} Mo max</span>
    </label>

    <a v-if="current && !modelValue" class="current" :href="currentUrl" target="_blank" rel="noopener">
      <Icon :name="isImg(current) ? 'image' : 'file'" :size="15" /> Fichier actuel : {{ current }}
    </a>
    <span v-if="problem" class="error" role="alert">{{ problem }}</span>
  </div>
</template>
