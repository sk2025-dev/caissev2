<script setup>
import { appConfig } from '../theme'
const year = new Date().getFullYear()
</script>

<template>
  <footer class="copyright">
    © {{ year }}
    <a v-if="appConfig.copyright.url" :href="/^https?:\/\//i.test(appConfig.copyright.url) ? appConfig.copyright.url : 'https://' + appConfig.copyright.url" target="_blank" rel="noopener" title="Visiter Dav'Consulting">{{ appConfig.copyright.nom }}</a>
    <span v-else>{{ appConfig.copyright.nom }}</span>
    · Tous droits réservés
  </footer>
</template>
