<script setup>
// Mini-formulaire de création « à la volée » (ex. nouveau propriétaire depuis la fiche véhicule).
import { reactive, ref, onMounted } from 'vue'
import { api, ApiError } from '../api'
import { toast } from '../toast'
import Icon from './Icon.vue'
import Fenetre from './Fenetre.vue'
import PhoneInput from './PhoneInput.vue'
import { phoneIssue } from '../phone'

const props = defineProps({ spec: Object, name: { type: String, default: '' } })
const emit = defineEmits(['created', 'close'])

const form = reactive({})
for (const f of props.spec.fields) form[f.name] = f.name === props.spec.nameField ? props.name : ''
const errors = ref({})
const saving = ref(false)
const box = ref(null)
onMounted(() => box.value?.querySelector('input')?.focus())
const titre = `Nouveau ${props.spec.label}`

async function submit() {
  errors.value = {}
  if (!String(form[props.spec.nameField]).trim()) { errors.value = { [props.spec.nameField]: 'Champ obligatoire' }; return }
  for (const f of props.spec.fields) if (f.type === 'phone' && phoneIssue(form[f.name])) errors.value = { ...errors.value, [f.name]: phoneIssue(form[f.name]) }
  if (Object.keys(errors.value).length) return
  saving.value = true
  try {
    const res = await api.post(props.spec.resource, { ...form })
    toast(`${props.spec.label[0].toUpperCase()}${props.spec.label.slice(1)} créé`)
    emit('created', res.data)
  } catch (e) {
    if (!(e instanceof ApiError)) throw e
    errors.value = e.errors || {}
    if (!Object.keys(errors.value).length) toast(e.message, 'error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Fenetre :titre="titre" icone="plus" largeur="sm" formulaire :occupe="saving" @close="emit('close')" @submit="submit">
      <div ref="box" style="display:flex;flex-direction:column;gap:14px">
        <div v-for="f in spec.fields" :key="f.name" class="field" :class="{ invalid: errors[f.name] }">
          <label :for="'qc-' + f.name">{{ f.label }} <span v-if="f.name === spec.nameField" class="req">*</span></label>
          <PhoneInput v-if="f.type === 'phone'" :id="'qc-' + f.name" v-model="form[f.name]" :invalid="!!errors[f.name]" />
          <input v-else :id="'qc-' + f.name" v-model="form[f.name]" class="input" type="text" autocomplete="off" />
          <span v-if="errors[f.name]" class="error">{{ errors[f.name] }}</span>
        </div>
      </div>
      <template #pied>
        <button type="button" class="btn" :disabled="saving" @click="emit('close')">Annuler</button>
        <button type="submit" class="btn primary" :disabled="saving"><Icon name="plus" :size="16" /> {{ saving ? 'Création…' : 'Créer et sélectionner' }}</button>
      </template>
  </Fenetre>
</template>
