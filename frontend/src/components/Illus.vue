<script setup>
// Petites illustrations « 3D douces » en SVG (dégradés + reflets), sans image externe.
defineProps({ name: { type: String, required: true }, size: { type: Number, default: 76 } })
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 96 96" fill="none" aria-hidden="true">
    <defs>
      <linearGradient id="g-purple" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#9775fa" /><stop offset="1" stop-color="#5f3dc4" /></linearGradient>
      <linearGradient id="g-green" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#63e6a4" /><stop offset="1" stop-color="#2b9e6b" /></linearGradient>
      <linearGradient id="g-coral" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff8fab" /><stop offset="1" stop-color="#e64980" /></linearGradient>
      <linearGradient id="g-gold" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffe066" /><stop offset="1" stop-color="#f59f00" /></linearGradient>
      <linearGradient id="g-blue" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#74c0fc" /><stop offset="1" stop-color="#3b82f6" /></linearGradient>
    </defs>

    <!-- Chariot (caisse) -->
    <g v-if="name === 'cart'">
      <ellipse cx="48" cy="86" rx="30" ry="5" fill="#000" opacity=".09" />
      <path d="M10 20h13l9 40h38l8-30H27" stroke="#5f3dc4" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" fill="none" />
      <path d="M29 33h50l-7 25H35z" fill="url(#g-purple)" />
      <path d="M31 38h46" stroke="#fff" stroke-opacity=".4" stroke-width="3" stroke-linecap="round" />
      <rect x="40" y="19" width="14" height="14" rx="3" fill="url(#g-gold)" /><rect x="57" y="23" width="12" height="10" rx="3" fill="url(#g-green)" />
      <circle cx="40" cy="73" r="7" fill="#2a2340" /><circle cx="40" cy="73" r="3" fill="#dcd2ff" />
      <circle cx="68" cy="73" r="7" fill="#2a2340" /><circle cx="68" cy="73" r="3" fill="#dcd2ff" />
    </g>

    <!-- Carton (stock) -->
    <g v-else-if="name === 'box'">
      <ellipse cx="48" cy="86" rx="30" ry="5" fill="#000" opacity=".09" />
      <path d="M48 14L12 32v34l36 18 36-18V32z" fill="url(#g-gold)" />
      <path d="M48 14L12 32l36 18 36-18z" fill="#fff" opacity=".28" />
      <path d="M48 50v34L12 66V32z" fill="#000" opacity=".08" />
      <path d="M30 23l36 18v13" stroke="#b36b00" stroke-opacity=".45" stroke-width="3" fill="none" />
      <rect x="54" y="58" width="18" height="6" rx="3" fill="#fff" opacity=".6" transform="rotate(-27 63 61)" />
    </g>

    <!-- Camion (approvisionnement) -->
    <g v-else-if="name === 'truck'">
      <ellipse cx="48" cy="82" rx="36" ry="5" fill="#000" opacity=".09" />
      <rect x="6" y="26" width="52" height="40" rx="7" fill="url(#g-blue)" />
      <path d="M58 38h18l12 14v14H58z" fill="url(#g-purple)" /><path d="M64 43h10l7 9H64z" fill="#fff" opacity=".7" />
      <circle cx="26" cy="68" r="9" fill="#2a2340" /><circle cx="26" cy="68" r="4" fill="#dcd2ff" />
      <circle cx="70" cy="68" r="9" fill="#2a2340" /><circle cx="70" cy="68" r="4" fill="#dcd2ff" />
    </g>

    <!-- Portefeuille (solde) -->
    <g v-else-if="name === 'wallet'">
      <ellipse cx="48" cy="86" rx="28" ry="5" fill="#000" opacity=".08" />
      <rect x="12" y="26" width="70" height="52" rx="14" fill="url(#g-purple)" />
      <path d="M12 40c0-8 6-14 14-14h40c8 0 14 6 14 14v2H12z" fill="#fff" opacity=".14" />
      <rect x="58" y="44" width="30" height="22" rx="11" fill="#4c2fb0" />
      <circle cx="69" cy="55" r="4.5" fill="url(#g-gold)" />
      <circle cx="60" cy="20" r="11" fill="url(#g-gold)" /><circle cx="60" cy="20" r="6.5" fill="none" stroke="#fff" stroke-opacity=".6" stroke-width="2" />
    </g>

    <!-- Sac d'argent (recettes) -->
    <g v-else-if="name === 'sack'">
      <ellipse cx="48" cy="87" rx="26" ry="5" fill="#000" opacity=".08" />
      <path d="M36 22c4 6 20 6 24 0l5-10c-6 3-10 2-17-1-7 3-11 4-17 1z" fill="#2b9e6b" />
      <path d="M36 26C14 40 8 62 18 76c6 9 54 9 60 0 10-14 4-36-18-50-6 4-18 4-24 0z" fill="url(#g-green)" />
      <path d="M26 44c4-8 10-13 16-15" stroke="#fff" stroke-opacity=".45" stroke-width="4" stroke-linecap="round" />
      <circle cx="48" cy="58" r="15" fill="#fff" opacity=".92" />
      <path d="M41 52h14M41 58h14M44 52c7 0 7 7 0 7l8 8" stroke="#2b9e6b" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" />
      <rect x="38" y="20" width="20" height="6" rx="3" fill="#e8890c" />
    </g>

    <!-- Sac shopping (dépenses) -->
    <g v-else-if="name === 'bag'">
      <ellipse cx="48" cy="87" rx="26" ry="5" fill="#000" opacity=".08" />
      <path d="M34 34v-8a14 14 0 0 1 28 0v8" stroke="#c2255c" stroke-width="5" stroke-linecap="round" />
      <path d="M16 34h64l5 44a8 8 0 0 1-8 9H19a8 8 0 0 1-8-9z" fill="url(#g-coral)" />
      <path d="M16 34h64l1 9H15z" fill="#fff" opacity=".16" />
      <circle cx="36" cy="46" r="3.5" fill="#fff" /><circle cx="60" cy="46" r="3.5" fill="#fff" />
      <path d="M26 58c6 6 38 6 44 0" stroke="#fff" stroke-opacity=".5" stroke-width="3.5" stroke-linecap="round" />
    </g>

    <!-- Voiture (flotte) -->
    <g v-else-if="name === 'car'">
      <ellipse cx="48" cy="80" rx="34" ry="5" fill="#000" opacity=".09" />
      <path d="M14 58l8-20c2-5 6-8 12-8h28c6 0 10 3 13 8l9 20z" fill="url(#g-blue)" />
      <path d="M28 36h40l7 16H21z" fill="#fff" opacity=".75" />
      <path d="M48 36v16" stroke="#3b82f6" stroke-width="3" />
      <rect x="8" y="54" width="80" height="18" rx="9" fill="url(#g-purple)" />
      <circle cx="28" cy="72" r="9" fill="#2a2340" /><circle cx="28" cy="72" r="4" fill="#dcd2ff" />
      <circle cx="68" cy="72" r="9" fill="#2a2340" /><circle cx="68" cy="72" r="4" fill="#dcd2ff" />
      <circle cx="86" cy="60" r="3.5" fill="#ffe066" />
    </g>

    <!-- Pile de pièces -->
    <g v-else-if="name === 'coins'">
      <ellipse cx="48" cy="86" rx="28" ry="5" fill="#000" opacity=".08" />
      <g v-for="(y, i) in [70, 58, 46, 34]" :key="i">
        <ellipse cx="48" :cy="y + 8" rx="26" ry="9" fill="#d98a00" />
        <rect x="22" :y="y" width="52" height="8" fill="#f2a900" />
        <ellipse cx="48" :cy="y" rx="26" ry="9" fill="url(#g-gold)" />
        <ellipse cx="48" :cy="y" rx="16" ry="5" fill="none" stroke="#fff" stroke-opacity=".5" stroke-width="2" />
      </g>
    </g>

    <!-- Ampoule (astuce) -->
    <g v-else-if="name === 'bulb'">
      <circle cx="48" cy="40" r="26" fill="url(#g-gold)" />
      <circle cx="48" cy="40" r="26" fill="#fff" opacity=".12" />
      <path d="M40 34c2-5 6-7 10-7" stroke="#fff" stroke-opacity=".7" stroke-width="4" stroke-linecap="round" />
      <path d="M40 54c0-6 16-6 16 0v10H40z" fill="#c9c2dd" />
      <rect x="38" y="64" width="20" height="8" rx="4" fill="#8f86ad" /><rect x="42" y="72" width="12" height="6" rx="3" fill="#6f668e" />
      <path d="M48 52v-10M42 44l6 4 6-4" stroke="#e8890c" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" />
    </g>
  </svg>
</template>
