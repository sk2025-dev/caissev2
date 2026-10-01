<script setup>
// Téléphone avec indicatif pays et masque de saisie. v-model : « +225 07 12 34 56 78 » (ou '' si vide).
import { ref, computed, watch } from 'vue'
import { COUNTRIES, byIso, parsePhone, cleanDigits, applyMask, toValue, phoneIssue } from '../phone'
import SearchSelect from './SearchSelect.vue'

const props = defineProps({ modelValue: { type: String, default: '' }, id: String, invalid: Boolean })
const emit = defineEmits(['update:modelValue'])

const start = parsePhone(props.modelValue)
const iso = ref(start.iso)
const digits = ref(start.digits)
const input = ref(null)

// Changement de valeur venu de l'extérieur (ex. ouverture d'une fiche)
watch(() => props.modelValue, (v) => {
  if (v === toValue(iso.value, digits.value)) return
  const p = parsePhone(v)
  iso.value = p.iso; digits.value = p.digits
})

const country = computed(() => byIso(iso.value))
const shown = computed(() => (country.value.mask ? applyMask(iso.value, digits.value) : digits.value ? `+${applyMask('XX', digits.value)}` : ''))
const options = COUNTRIES.map((c) => ({ value: c.iso, label: `${c.flag}  ${c.name}${c.dial ? ` (+${c.dial})` : ''}`, short: `${c.flag} ${c.dial ? '+' + c.dial : '+…'}` }))
const placeholder = computed(() => (country.value.mask ? country.value.mask.replace(/#/g, '0') : '+49 1512 3456789'))
const issue = computed(() => phoneIssue(props.modelValue))

function publish() { emit('update:modelValue', toValue(iso.value, digits.value)) }

function onInput(e) {
  const el = e.target
  // Conserve le curseur : on compte les chiffres à sa gauche, reformate tout de suite (synchrone), puis on replace le curseur
  const before = el.value.slice(0, el.selectionStart).replace(/\D/g, '').length
  digits.value = cleanDigits(iso.value, el.value)
  const txt = shown.value
  let n = 0, pos = 0
  while (pos < txt.length && n < before) { if (/\d/.test(txt[pos])) n++; pos++ }
  if (before > digits.value.length) pos = txt.length
  el.value = txt
  el.setSelectionRange(pos, pos)
  publish()
}
function onPaste(e) {
  e.preventDefault()
  const t = (e.clipboardData || window.clipboardData).getData('text')
  if (t.trim().startsWith('+')) { const p = parsePhone(t); iso.value = p.iso; digits.value = p.digits }   // numéro international collé : le pays est reconnu
  else digits.value = cleanDigits(iso.value, t)
  publish()
}
watch(iso, () => { digits.value = cleanDigits(iso.value, digits.value); publish(); input.value?.focus() })
defineExpose({ issue })
</script>

<template>
  <div class="phone" :class="{ invalid }">
    <SearchSelect v-model="iso" class="phone-country" :options="options" :id="id ? id + '-pays' : undefined" />
    <input :id="id" ref="input" class="input" type="tel" inputmode="tel" autocomplete="tel-national" :placeholder="placeholder" :value="shown" :aria-invalid="!!issue || invalid" @input="onInput" @paste="onPaste" />
  </div>
</template>
