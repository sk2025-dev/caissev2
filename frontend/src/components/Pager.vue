<script setup>
import Icon from './Icon.vue'
defineProps({ meta: { type: Object, required: true } })
defineEmits(['goto'])
</script>

<template>
  <div v-if="meta.total" class="pager">
    <span>{{ (meta.page - 1) * meta.per_page + 1 }}–{{ Math.min(meta.total, meta.page * meta.per_page) }} sur {{ meta.total }}</span>
    <div class="btns">
      <button class="btn sm" :disabled="meta.page <= 1" @click="$emit('goto', meta.page - 1)"><Icon name="chevL" :size="14" /> Précédent</button>
      <button class="btn sm" :disabled="meta.page >= meta.pages" @click="$emit('goto', meta.page + 1)">Suivant <Icon name="chevR" :size="14" /></button>
    </div>
  </div>
</template>
