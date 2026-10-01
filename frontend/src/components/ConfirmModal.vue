<script setup>
defineProps({ title: String, message: String, confirmLabel: { type: String, default: 'Confirmer' }, busy: Boolean })
defineEmits(['confirm', 'cancel'])
</script>

<template>
  <div class="overlay center" @click.self="$emit('cancel')" @keydown.esc="$emit('cancel')">
    <div class="modal" role="alertdialog" aria-modal="true" :aria-label="title">
      <h3>{{ title }}</h3>
      <p class="muted" style="margin:0">{{ message }}</p>
      <div class="row">
        <button class="btn" @click="$emit('cancel')">Annuler</button>
        <button class="btn danger" :disabled="busy" @click="$emit('confirm')">{{ confirmLabel }}</button>
      </div>
    </div>
  </div>
</template>
