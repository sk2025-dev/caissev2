<script setup>
defineProps({ steps: { type: Array, required: true }, current: { type: Number, required: true } })
</script>

<template>
  <ol class="steps" aria-label="Étapes">
    <li v-for="(s, i) in steps" :key="s" :class="{ done: i < current, on: i === current }" :aria-current="i === current ? 'step' : undefined">
      <span class="dot">{{ i < current ? '✓' : i + 1 }}</span><span class="lbl">{{ s }}</span>
    </li>
  </ol>
</template>
