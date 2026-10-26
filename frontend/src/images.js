// Traitement d'images dans le navigateur : chargement, réglages (luminosité, contraste, saturation), export compressé.
// Rien ne part au serveur avant l'enregistrement : la photo d'un téléphone (4 à 8 Mo) est réduite à ~300 Ko sans perte visible.

export const TAILLE_MAX = 1600   // côté le plus long, en pixels : largement suffisant pour l'écran et le zoom

export function chargerImage(source) {
  return new Promise((resolve, reject) => {
    const img = new Image()
    const url = source instanceof Blob ? URL.createObjectURL(source) : source
    img.onload = () => { if (source instanceof Blob) URL.revokeObjectURL(url); resolve(img) }
    img.onerror = () => { if (source instanceof Blob) URL.revokeObjectURL(url); reject(new Error("Impossible de lire cette image")) }
    img.src = url
  })
}

/**
 * Réglages appliqués pixel par pixel, dans l'ordre et avec les formules des filtres CSS brightness → contrast → saturate :
 * l'image exportée est identique à l'aperçu affiché (qui, lui, utilise le filtre CSS).
 */
export function appliquerReglages(ctx, w, h, { luminosite = 100, contraste = 100, saturation = 100 }) {
  if (luminosite === 100 && contraste === 100 && saturation === 100) return
  const b = luminosite / 100, c = contraste / 100, s = saturation / 100
  const m = [0.213 + 0.787 * s, 0.715 - 0.715 * s, 0.072 - 0.072 * s, 0.213 - 0.213 * s, 0.715 + 0.285 * s, 0.072 - 0.072 * s, 0.213 - 0.213 * s, 0.715 - 0.715 * s, 0.072 + 0.928 * s]
  const data = ctx.getImageData(0, 0, w, h)
  const p = data.data
  for (let i = 0; i < p.length; i += 4) {
    let r = p[i] * b, g = p[i + 1] * b, bl = p[i + 2] * b
    r = (r - 127.5) * c + 127.5; g = (g - 127.5) * c + 127.5; bl = (bl - 127.5) * c + 127.5
    p[i] = m[0] * r + m[1] * g + m[2] * bl
    p[i + 1] = m[3] * r + m[4] * g + m[5] * bl
    p[i + 2] = m[6] * r + m[7] * g + m[8] * bl
  }
  ctx.putImageData(data, 0, 0)
}

export const filtreCss = ({ luminosite = 100, contraste = 100, saturation = 100 }) => `brightness(${luminosite}%) contrast(${contraste}%) saturate(${saturation}%)`

export function versFichier(canvas, nom, type = 'image/jpeg', qualite = 0.88) {
  return new Promise((resolve, reject) => canvas.toBlob((blob) => (blob ? resolve(new File([blob], nom, { type })) : reject(new Error("Export de l'image impossible"))), type, qualite))
}

export const nomJpeg = (nom, suffixe = '') => `${(nom || 'photo').replace(/\.[^.]+$/, '')}${suffixe}.jpg`

/** Réduit une photo trop grande ou trop lourde (JPEG, fond blanc sous les zones transparentes). Les autres fichiers passent tels quels. */
export async function compresser(file, max = TAILLE_MAX, seuilOctets = 1.5 * 1024 * 1024) {
  if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return file
  const img = await chargerImage(file)
  const k = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight))
  if (k === 1 && file.size <= seuilOctets) return file
  const cv = document.createElement('canvas')
  cv.width = Math.round(img.naturalWidth * k); cv.height = Math.round(img.naturalHeight * k)
  const ctx = cv.getContext('2d')
  ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cv.width, cv.height)
  ctx.drawImage(img, 0, 0, cv.width, cv.height)
  return versFichier(cv, nomJpeg(file.name))
}
