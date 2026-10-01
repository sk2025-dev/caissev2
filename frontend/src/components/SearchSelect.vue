<script setup>
// Liste déroulante avec recherche (insensible à la casse et aux accents), utilisable au clavier.
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import Icon from './Icon.vue'

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, required: true }, // [{ value, label }]
  id: String,
  placeholder: { type: String, default: 'Choisir…' },
  invalid: Boolean,
  createLabel: { type: String, default: '' }, // ex. « propriétaire » : active l'option « Créer … »
})
const emit = defineEmits(['update:modelValue', 'create'])

const root = ref(null)
const input = ref(null)
const listEl = ref(null)
const open = ref(false)
const search = ref('')
const active = ref(0)
const up = ref(false)

const norm = (s) => String(s).normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
const selected = computed(() => props.options.find((o) => String(o.value) === String(props.modelValue)))
const filtered = computed(() => {
  const q = norm(search.value.trim())
  return q ? props.options.filter((o) => norm(o.label).includes(q)) : props.options
})
const listId = `${props.id || 'ss'}-list`

async function show() {
  if (open.value) return
  open.value = true
  search.value = ''
  active.value = Math.max(0, filtered.value.findIndex((o) => String(o.value) === String(props.modelValue)))
  const r = root.value.getBoundingClientRect()
  up.value = window.innerHeight - r.bottom < 280 && r.top > 280
  await nextTick()
  scrollActive()
  document.addEventListener('mousedown', outside)
}
function hide() {
  open.value = false
  document.removeEventListener('mousedown', outside)
}
const outside = (e) => { if (!root.value?.contains(e.target)) hide() }
onBeforeUnmount(() => document.removeEventListener('mousedown', outside))

const canCreate = computed(() => !!props.createLabel)
const exact = computed(() => { const q = norm(search.value.trim()); return !!q && props.options.some((o) => norm(o.label) === q) })
function create() {
  const name = search.value.trim()
  hide()
  emit('create', name)
}
function pick(o) {
  emit('update:modelValue', o.value)
  hide()
  input.value?.focus()
}
function scrollActive() {
  listEl.value?.children[active.value]?.scrollIntoView({ block: 'nearest' })
}
function onKey(e) {
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault()
    if (!open.value) return show()
    const n = filtered.value.length + (canCreate.value && !exact.value ? 1 : 0)
    if (n) active.value = (active.value + (e.key === 'ArrowDown' ? 1 : -1) + n) % n
    nextTick(scrollActive)
  } else if (e.key === 'Enter') {
    if (open.value) {
      e.preventDefault()
      if (filtered.value[active.value]) pick(filtered.value[active.value])
      else if (canCreate.value && !exact.value) create()
    }
  } else if (e.key === 'Escape') {
    if (open.value) { e.stopPropagation(); hide() }
  } else if (e.key === 'Tab') {
    hide()
  }
}
watch(search, () => { active.value = 0 })
</script>

<template>
  <div ref="root" class="ss" :class="{ open, invalid }">
    <input
      :id="id" ref="input" class="input ss-input" type="text" role="combobox" autocomplete="off"
      :aria-expanded="open" :aria-controls="listId" aria-autocomplete="list"
      :placeholder="open ? 'Rechercher…' : placeholder"
      :value="open ? search : selected?.short ?? selected?.label ?? ''"
      @focus="show" @click="show" @input="search = $event.target.value; show()" @keydown="onKey"
    />
    <Icon name="chevD" :size="16" class="ss-chev" />
    <ul v-if="open" :id="listId" ref="listEl" class="ss-list" :class="{ up }" role="listbox">
      <li
        v-for="(o, i) in filtered" :key="o.value" role="option" :aria-selected="String(o.value) === String(modelValue)"
        :class="{ active: i === active, current: String(o.value) === String(modelValue) }"
        @mousedown.prevent="pick(o)" @mousemove="active = i"
      >{{ o.label }}</li>
      <li v-if="!filtered.length && !canCreate" class="none">Aucun résultat</li>
      <li v-if="canCreate && !exact" class="create" :class="{ active: active === filtered.length }" @mousedown.prevent="create" @mousemove="active = filtered.length">
        <Icon name="plus" :size="15" />
        {{ search.trim() ? `Créer « ${search.trim()} »` : `Nouveau ${createLabel}…` }}
      </li>
    </ul>
  </div>
</template>
