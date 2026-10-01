<script setup>
// Décor de la page de connexion : devanture de boutique illustrée (auvent rayé, vitrines garnies, enseigne, lampadaire),
// aux couleurs de la palette choisie. Une photo « login-bg.jpg » déposée à côté de index.html la remplace automatiquement.
import { ref } from 'vue'
const photo = ref(true)
const photoUrl = 'login-bg.jpg'
const bandes = Array.from({ length: 12 }, (_, i) => i)
const etageres = [[0, '#ff922b', '#74c0fc', '#69db7c', '#ffd43b'], [1, '#f783ac', '#ffd43b', '#9775fa', '#63e6be']]
</script>

<template>
  <div class="scene" aria-hidden="true">
    <svg viewBox="0 0 1600 900" preserveAspectRatio="xMidYMax slice" role="presentation">
      <defs>
        <linearGradient id="ls-ciel" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--primary)" /><stop offset="1" stop-color="var(--primary-2)" /></linearGradient>
        <linearGradient id="ls-vitre" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#e3f4ff" stop-opacity=".95" /><stop offset="1" stop-color="#b8dcff" stop-opacity=".8" /></linearGradient>
        <linearGradient id="ls-lum" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff3bf" stop-opacity=".9" /><stop offset="1" stop-color="#fff3bf" stop-opacity="0" /></linearGradient>
      </defs>
      <rect width="1600" height="900" fill="url(#ls-ciel)" />
      <circle cx="1280" cy="170" r="90" fill="#fff" opacity=".18" /><circle cx="1280" cy="170" r="54" fill="#fff" opacity=".28" />
      <g fill="#fff" opacity=".1"><rect x="60" y="430" width="90" height="330" /><rect x="160" y="500" width="70" height="260" /><rect x="1180" y="420" width="110" height="340" /><rect x="1300" y="490" width="80" height="270" /><rect x="1400" y="450" width="130" height="310" /></g>
      <g fill="#fff" opacity=".7" class="nuage"><ellipse cx="260" cy="150" rx="90" ry="26" /><ellipse cx="320" cy="132" rx="60" ry="24" /><ellipse cx="880" cy="95" rx="80" ry="22" /><ellipse cx="930" cy="80" rx="50" ry="20" /></g>

      <!-- Trottoir -->
      <rect y="760" width="1600" height="140" fill="#2a2340" opacity=".55" /><rect y="760" width="1600" height="10" fill="#fff" opacity=".25" />

      <!-- Boutique principale -->
      <g transform="translate(130 345) scale(.82)">
        <rect x="0" y="40" width="860" height="470" rx="10" fill="#fdf6ee" />
        <rect x="0" y="40" width="860" height="70" fill="#f1e4d3" />
        <!-- enseigne -->
        <rect x="250" y="52" width="360" height="46" rx="10" fill="var(--primary)" /><rect x="262" y="62" width="336" height="26" rx="6" fill="#fff" opacity=".92" />
        <g fill="var(--primary)" opacity=".85"><circle cx="300" cy="75" r="7" /><rect x="320" y="69" width="150" height="12" rx="6" /><rect x="480" y="69" width="90" height="12" rx="6" opacity=".5" /></g>
        <!-- auvent rayé -->
        <g>
          <g v-for="i in bandes" :key="i"><path :d="`M${i * 72 - 6} 110 h72 l-8 54 q-28 18 -56 0 z`" :fill="i % 2 ? '#fff' : 'var(--primary-2)'" /></g>
          <rect x="-12" y="100" width="884" height="14" rx="7" fill="var(--primary)" />
        </g>
        <!-- vitrines -->
        <g v-for="(v, n) in [60, 540]" :key="n" :transform="`translate(${v} 190)`">
          <rect width="250" height="250" rx="12" fill="#cbb79f" /><rect x="10" y="10" width="230" height="230" rx="8" fill="url(#ls-vitre)" />
          <rect x="10" y="10" width="230" height="230" rx="8" fill="url(#ls-lum)" />
          <g v-for="e in etageres" :key="e[0]" :transform="`translate(0 ${e[0] * 100})`">
            <rect x="18" y="104" width="214" height="7" rx="3" fill="#a98c6b" />
            <rect x="30" y="52" width="38" height="52" rx="7" :fill="e[1]" /><rect x="76" y="40" width="30" height="64" rx="12" :fill="e[2]" /><rect x="114" y="62" width="48" height="42" rx="6" :fill="e[3]" /><circle cx="196" cy="84" r="20" :fill="e[4]" />
            <rect x="34" y="62" width="30" height="8" rx="3" fill="#fff" opacity=".55" /><rect x="82" y="52" width="18" height="6" rx="3" fill="#fff" opacity=".55" />
          </g>
          <path d="M20 30 L60 14 M30 60 L90 20" stroke="#fff" stroke-width="6" stroke-linecap="round" opacity=".45" />
        </g>
        <!-- porte -->
        <g transform="translate(340 190)">
          <rect width="180" height="320" rx="10" fill="var(--primary)" /><rect x="14" y="16" width="152" height="190" rx="8" fill="url(#ls-vitre)" />
          <rect x="14" y="16" width="152" height="190" rx="8" fill="url(#ls-lum)" />
          <rect x="130" y="230" width="10" height="46" rx="5" fill="#fff" opacity=".85" />
          <g transform="translate(90 120)"><rect x="-44" y="-18" width="88" height="36" rx="18" fill="#fff" opacity=".95" /><circle cx="-24" cy="0" r="7" fill="var(--success)" /><rect x="-10" y="-5" width="42" height="10" rx="5" fill="var(--primary)" opacity=".7" /></g>
        </g>
        <!-- plantes -->
        <g transform="translate(-50 440)"><rect x="0" y="40" width="46" height="60" rx="8" fill="#d9480f" opacity=".85" /><path d="M23 40 C0 10 6 -20 23 -34 C40 -20 46 10 23 40Z" fill="#40c057" /><path d="M23 40 C-10 28 -14 0 -2 -10 C16 0 24 18 23 40Z" fill="#2f9e44" /></g>
        <g transform="translate(868 440)"><rect x="0" y="40" width="46" height="60" rx="8" fill="#d9480f" opacity=".85" /><path d="M23 40 C0 10 6 -20 23 -34 C40 -20 46 10 23 40Z" fill="#40c057" /><path d="M23 40 C56 28 60 0 48 -10 C30 0 22 18 23 40Z" fill="#2f9e44" /></g>
        <!-- caisses et sacs devant -->
        <g transform="translate(40 470)"><rect width="120" height="56" rx="8" fill="#ffa94d" /><rect x="8" y="8" width="104" height="14" rx="4" fill="#fff" opacity=".35" /><circle cx="30" cy="2" r="16" fill="#ff6b6b" /><circle cx="62" cy="-4" r="16" fill="#ff8787" /><circle cx="94" cy="2" r="16" fill="#ff6b6b" /></g>
        <g transform="translate(700 466)"><rect width="110" height="60" rx="8" fill="#a9e34b" opacity=".95" /><rect x="8" y="8" width="94" height="12" rx="4" fill="#fff" opacity=".35" /></g>
      </g>

      <!-- Lampadaire -->
      <g transform="translate(1040 330)"><rect x="-6" y="0" width="12" height="440" rx="6" fill="#3b3354" /><path d="M0 8 q70 -12 80 40" stroke="#3b3354" stroke-width="10" fill="none" stroke-linecap="round" /><ellipse cx="80" cy="62" rx="26" ry="14" fill="#ffe066" /><ellipse cx="80" cy="120" rx="70" ry="90" fill="#ffe066" opacity=".13" /></g>
      <!-- Chariot sur le trottoir -->
      <g transform="translate(1110 640)" opacity=".95"><path d="M0 0h26l16 74h86l14-54H44" stroke="#fff" stroke-width="9" stroke-linecap="round" stroke-linejoin="round" fill="none" /><path d="M48 24h92l-12 46H58z" fill="var(--primary)" opacity=".85" /><circle cx="68" cy="102" r="12" fill="#fff" /><circle cx="122" cy="102" r="12" fill="#fff" /></g>
    </svg>
    <img v-if="photo" class="photo" :src="photoUrl" alt="" @error="photo = false" />
  </div>
</template>

<style scoped>
.scene { position: absolute; inset: 0; overflow: hidden; }
.scene svg, .photo { position: absolute; inset: 0; width: 100%; height: 100%; }
.photo { object-fit: cover; }
.nuage { animation: derive 60s linear infinite alternate; }
@keyframes derive { to { transform: translateX(120px); } }
@media (prefers-reduced-motion: reduce) { .nuage { animation: none; } }
</style>
