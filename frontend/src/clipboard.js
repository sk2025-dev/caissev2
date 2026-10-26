// Images collées (Ctrl+V / ⌘V ou bouton « Coller ») : converties en fichier nommé, prêt à être envoyé comme un fichier choisi.
const EXT = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp', 'image/gif': 'gif' }

export const raccourciColler = /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘V' : 'Ctrl+V'
export const collageDisponible = !!navigator.clipboard?.read   // bouton « Coller » : navigateurs récents, en HTTPS ou en local

/** Garde le nom d'un fichier copié depuis le Finder / l'Explorateur ; nomme les captures d'écran (« image.png »). */
function nommer(blob) {
  if (blob.name && /\.\w+$/.test(blob.name) && !/^image\.\w+$/i.test(blob.name)) return blob
  const d = new Date(), p = (n) => String(n).padStart(2, '0')
  const horodatage = `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`
  return new File([blob], `image-collee-${horodatage}.${EXT[blob.type] || 'png'}`, { type: blob.type })
}

/** Image contenue dans un événement « paste », ou null (un texte collé reste au champ de saisie). */
export function imageCollee(e) {
  const item = [...(e.clipboardData?.items || [])].find((i) => i.kind === 'file' && i.type.startsWith('image/'))
  const f = item?.getAsFile()
  return f ? nommer(f) : null
}

/** Lecture du presse-papiers à la demande (bouton) ; l'erreur levée est affichable telle quelle. */
export async function lireImage() {
  let items
  try { items = await navigator.clipboard.read() } catch { throw new Error(`Le navigateur refuse l'accès au presse-papiers : cliquez dans la zone puis faites ${raccourciColler}`) }
  for (const it of items) {
    const type = it.types.find((t) => t.startsWith('image/'))
    if (type) return nommer(await it.getType(type))
  }
  throw new Error('Aucune image dans le presse-papiers : copiez d’abord une image ou une capture d’écran')
}

/* Un seul écouteur pour la page. La cible est la zone survolée ou touchée en dernier, sinon la plus récemment affichée ;
   une zone masquée (onglet inactif, tiroir fermé) n'est jamais servie. */
const cibles = []
let active = null
const visible = (c) => { const el = c.element(); return !!el && el.isConnected && el.getClientRects().length > 0 }

function surCollage(e) {
  const cible = active && visible(active) ? active : [...cibles].reverse().find(visible)
  if (!cible) return
  const f = imageCollee(e)
  if (!f) return
  e.preventDefault()
  cible.recevoir(f)
}

/** Fait recevoir les images collées à une zone : `element` renvoie son élément racine, `recevoir` reçoit le File. */
export function recevoirCollage(element, recevoir) {
  const c = { element, recevoir }
  if (!cibles.length) document.addEventListener('paste', surCollage)
  cibles.push(c)
  return {
    activer: () => { active = c },
    retirer: () => {
      cibles.splice(cibles.indexOf(c), 1)
      if (active === c) active = null
      if (!cibles.length) document.removeEventListener('paste', surCollage)
    },
  }
}
