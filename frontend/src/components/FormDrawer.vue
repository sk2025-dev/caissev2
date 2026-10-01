<script setup>
import { reactive, ref, computed, watch, onMounted, nextTick } from 'vue'
import { api, ApiError, fileUrl } from '../api'
import { lookups } from '../lookups'
import { toast } from '../toast'
import Icon from './Icon.vue'
import SearchSelect from './SearchSelect.vue'
import QuickCreate from './QuickCreate.vue'
import PhoneInput from './PhoneInput.vue'
import FileDrop from './FileDrop.vue'
import { phoneIssue, parsePhone, toValue } from '../phone'

const props = defineProps({ config: Object, route: String, idKey: String, row: { type: Object, default: null } })
const emit = defineEmits(['close', 'saved'])

const editing = computed(() => !!props.row)
const form = reactive({})
const fields = computed(() => props.config.fields.filter((f) => !(f.editOnly && !editing.value) && !(f.createOnly && editing.value) && !(f.showIf && !f.showIf(form))))
const files = reactive({})
const errors = ref({})
const hints = reactive({})
const quick = ref(null) // { field, name } : création à la volée en cours
const saving = ref(false)
const general = ref('')

for (const f of props.config.fields) {
  if (f.type === 'file') continue
  const v = props.row ? props.row[f.name] : f.default ? f.default() : ''
  // Anciennes saisies (sans indicatif) : normalisées au format international dès l'ouverture de la fiche
  form[f.name] = f.type === 'password' ? '' : f.type === 'phone' ? (v ? toValue(parsePhone(v).iso, parsePhone(v).digits) : '') : v ?? ''
}

// Indications dynamiques (ex. véhicule actif du chauffeur choisi)
for (const f of props.config.fields.filter((x) => x.hint)) {
  watch(() => [form[f.name], ...(f.hintDeps || []).map((k) => form[k])], async ([v]) => {
    try { hints[f.name] = await f.hint(v, lookups, form) } catch { hints[f.name] = '' }
  }, { immediate: true })
}

// Un élément vient d'être créé depuis la liste : on l'ajoute aux listes partagées et on le sélectionne
function onQuickCreated(row) {
  const { field } = quick.value
  lookups[field.create.lookup].push({ id: row[field.create.idKey], [field.create.nameField]: row[field.create.nameField] })
  lookups[field.create.lookup].sort((a, b) => String(a[field.create.nameField]).localeCompare(String(b[field.create.nameField]), 'fr'))
  form[field.name] = row[field.create.idKey]
  errors.value = { ...errors.value, [field.name]: undefined }
  quick.value = null
}
const isRequired = (f) => f.required || (f.requiredOnCreate && !editing.value)
const options = (f) => f.options(lookups)

async function submit() {
  errors.value = {}
  general.value = ''
  const missing = {}
  for (const f of fields.value) if (f.type !== 'file' && isRequired(f) && (form[f.name] === '' || form[f.name] == null)) missing[f.name] = 'Champ obligatoire'
  for (const f of fields.value) if (f.type === 'phone' && phoneIssue(form[f.name])) missing[f.name] = phoneIssue(form[f.name])
  if (Object.keys(missing).length) {
    errors.value = missing
    await nextTick()
    first.value?.querySelector('.field.invalid input, .field.invalid textarea')?.focus()
    return
  }
  saving.value = true
  try {
    const hasFile = Object.values(files).some(Boolean)
    let body
    if (hasFile) {
      body = new FormData()
      for (const [k, v] of Object.entries(form)) body.append(k, v ?? '')
      for (const [k, f] of Object.entries(files)) if (f) body.append(k, f)
    } else {
      body = { ...form }
    }
    const res = await api.post(props.route, body, editing.value ? { id: props.row[props.idKey] } : undefined)
    toast(editing.value ? 'Modifications enregistrées' : `${props.config.singular[0].toUpperCase()}${props.config.singular.slice(1)} ${props.config.feminine ? 'ajoutée' : 'ajouté'}`)
    emit('saved', res.data)
  } catch (e) {
    if (!(e instanceof ApiError)) throw e
    errors.value = e.errors || {}
    general.value = Object.keys(errors.value).length ? '' : e.message
    if (!Object.keys(errors.value).length) toast(e.message, 'error')
  } finally {
    saving.value = false
  }
}

const first = ref(null)
onMounted(() => first.value?.querySelector('input,select,textarea')?.focus())
const title = computed(() => (editing.value ? `Modifier ${props.config.singular}` : `${props.config.feminine ? 'Nouvelle' : 'Nouveau'} ${props.config.singular}`))
</script>

<template>
  <div class="overlay" @click.self="emit('close')" @keydown.esc="emit('close')">
    <form class="drawer" novalidate role="dialog" aria-modal="true" :aria-label="title" @submit.prevent="submit">
      <header>
        <h2>{{ title }}</h2>
        <button type="button" class="btn ghost icon" aria-label="Fermer" @click="emit('close')"><Icon name="x" /></button>
      </header>

      <div ref="first" class="body">
        <div v-if="general" class="alert full">{{ general }}</div>
        <div v-for="f in fields" :key="f.name" class="field" :class="{ full: f.full || f.type === 'textarea', invalid: errors[f.name] }">
          <label :for="'f-' + f.name">{{ f.label }} <span v-if="isRequired(f)" class="req" aria-hidden="true">*</span></label>

          <SearchSelect v-if="f.type === 'select'" :id="'f-' + f.name" v-model="form[f.name]" :options="options(f)" :invalid="!!errors[f.name]"
                        :create-label="f.create?.label" @create="(name) => (quick = { field: f, name })" />
          <textarea v-else-if="f.type === 'textarea'" :id="'f-' + f.name" v-model="form[f.name]" class="input" />
          <FileDrop v-else-if="f.type === 'file'" :id="'f-' + f.name" v-model="files[f.name]" :accept="f.accept" :current="row ? row[f.name] : ''" :current-url="row && row[f.name] ? fileUrl(f.dir, row[f.name]) : ''" :invalid="!!errors[f.name]" />
          <PhoneInput v-else-if="f.type === 'phone'" :id="'f-' + f.name" v-model="form[f.name]" :invalid="!!errors[f.name]" />
          <input v-else :id="'f-' + f.name" v-model="form[f.name]" class="input" :required="isRequired(f)"
                 :type="f.type === 'number' ? 'number' : f.type" :step="f.type === 'number' ? (f.step || '0.01') : undefined" :min="f.type === 'number' ? (f.min ?? 0) : undefined" :max="f.max"
                 :autocomplete="f.type === 'password' ? 'new-password' : 'off'" :minlength="f.type === 'password' ? 6 : undefined" />

          <span v-if="errors[f.name]" class="error">{{ errors[f.name] }}</span>
          <span v-else-if="hints[f.name]" class="hint">{{ hints[f.name] }}</span>
          <span v-else-if="editing && f.editHint" class="hint">{{ f.editHint }}</span>
        </div>
      </div>

      <footer>
        <button type="button" class="btn" @click="emit('close')">Annuler</button>
        <button type="submit" class="btn primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </footer>
    </form>
    <QuickCreate v-if="quick" :spec="quick.field.create" :name="quick.name" @close="quick = null" @created="onQuickCreated" />
  </div>
</template>
