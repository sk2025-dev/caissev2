<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { login, auth, ApiError } from '../api'
import { logoUrl, nomApp } from '../theme'
import Icon from '../components/Icon.vue'
import LoginScene from '../components/LoginScene.vue'

const route = useRoute()
const router = useRouter()
const email = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)
const voir = ref(false)
const majuscules = ref(false)
const secoue = ref(0)   // incrémenté à chaque échec : relance l'animation de secousse
const touche = (e) => { majuscules.value = !!e.getModifierState?.('CapsLock') }

async function submit() {
  busy.value = true
  error.value = ''
  try {
    await login(email.value, password.value)
    const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/'
    router.replace(next)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Connexion impossible'
    password.value = ''; secoue.value++
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="login">
    <LoginScene />
    <span class="l-veil" aria-hidden="true" />

    <section class="l-hero">
      <div class="l-hero-in">
        <div class="medal" :class="{ vide: !logoUrl }">
          <span class="halo" /><span class="halo h2" />
          <span class="plate"><img v-if="logoUrl" :src="logoUrl" :alt="`Logo ${nomApp}`" /><Icon v-else name="cart" :size="46" /></span>
        </div>
        <h2>{{ nomApp }}</h2>
        <p class="tag">Votre boutique, votre caisse, vos clients.</p>
        <div class="chips-liv"><span><Icon name="cash" :size="15" /> Caisse</span><span><Icon name="box" :size="15" /> Stock</span><span><Icon name="truck" :size="15" /> Livraisons</span></div>
      </div>
    </section>

    <section class="l-form">
      <div class="l-card" :key="secoue" :class="{ shake: secoue }">
        <div class="medal mini" :class="{ vide: !logoUrl }"><span class="halo" /><span class="plate"><img v-if="logoUrl" :src="logoUrl" :alt="`Logo ${nomApp}`" /><Icon v-else name="cart" :size="30" /></span></div>
        <h1>Bon retour 👋</h1>
        <p class="muted">Connectez-vous pour ouvrir votre caisse.</p>
        <form @submit.prevent="submit">
          <div v-if="auth.notice" class="alert" role="alert">{{ auth.notice }}</div>
          <div v-if="error" class="alert" role="alert">{{ error }}</div>
          <div class="field">
            <label for="email">Adresse e-mail</label>
            <div class="with-ico"><Icon name="mail" :size="18" /><input id="email" v-model="email" class="input" type="email" required autocomplete="username" autofocus placeholder="vous@exemple.com" /></div>
          </div>
          <div class="field">
            <label for="password">Mot de passe</label>
            <div class="with-ico">
              <Icon name="lock" :size="18" />
              <input id="password" v-model="password" class="input" :type="voir ? 'text' : 'password'" required autocomplete="current-password" placeholder="••••••••" @keyup="touche" @keydown="touche" @blur="majuscules = false" />
              <button type="button" class="reveal" :aria-pressed="voir" :aria-label="voir ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="voir = !voir">{{ voir ? 'Masquer' : 'Afficher' }}</button>
            </div>
            <span v-if="majuscules" class="caps"><Icon name="alert" :size="14" /> Verrouillage majuscules activé</span>
          </div>
          <button class="btn primary go" :disabled="busy"><span v-if="busy" class="spin" aria-hidden="true" /> {{ busy ? 'Connexion…' : 'Se connecter' }}</button>
        </form>
      </div>
    </section>
  </main>
</template>
