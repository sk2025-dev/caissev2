<script setup>
import Fenetre from './Fenetre.vue'
defineProps({ title: String, message: String, confirmLabel: { type: String, default: 'Confirmer' }, busy: Boolean })
defineEmits(['confirm', 'cancel'])
</script>

<template>
  <Fenetre :titre="title" icone="alert" largeur="sm" alerte :reductible="false" :occupe="busy" @close="$emit('cancel')">
    <p class="muted" style="margin:0">{{ message }}</p>
    <template #pied>
      <button class="btn" :disabled="busy" @click="$emit('cancel')">Annuler</button>
      <button class="btn danger" :disabled="busy" autofocus @click="$emit('confirm')">{{ confirmLabel }}</button>
    </template>
  </Fenetre>
</template>
