<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { login, auth, ApiError } from '../api'
import { logoUrl, nomApp } from '../theme'
import Icon from '../components/Icon.vue'
import Illus from '../components/Illus.vue'

const route = useRoute()
const router = useRouter()
const email = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
  busy.value = true
  error.value = ''
  try {
    await login(email.value, password.value)
    const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/'
    router.replace(next)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Connexion impossible'
    password.value = ''
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="login">
    <div class="card">
      <div class="art"><img v-if="logoUrl" :src="logoUrl" alt="" style="max-height:92px;max-width:180px;object-fit:contain" /><Illus v-else name="cart" :size="104" /></div>
      <div class="brand"><span v-if="!logoUrl" class="logo"><Icon name="cart" :size="20" /></span> {{ nomApp }}</div>
      <p class="muted" style="margin:0">Connectez-vous pour ouvrir votre caisse.</p>
      <form @submit.prevent="submit">
        <div v-if="auth.notice" class="alert" role="alert">{{ auth.notice }}</div>
        <div v-if="error" class="alert" role="alert">{{ error }}</div>
        <div class="field">
          <label for="email">Adresse e-mail</label>
          <input id="email" v-model="email" class="input" type="email" required autocomplete="username" autofocus />
        </div>
        <div class="field">
          <label for="password">Mot de passe</label>
          <input id="password" v-model="password" class="input" type="password" required autocomplete="current-password" />
        </div>
        <button class="btn primary" :disabled="busy">{{ busy ? 'Connexion…' : 'Se connecter' }}</button>
      </form>
    </div>
  </main>
</template>
