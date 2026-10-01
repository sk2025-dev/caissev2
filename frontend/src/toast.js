import { reactive } from 'vue'

export const toasts = reactive([])
let n = 0

export function toast(message, type = 'success', action = null) {
  const id = ++n
  toasts.push({ id, message, type, action })
  setTimeout(() => {
    const i = toasts.findIndex((t) => t.id === id)
    if (i >= 0) toasts.splice(i, 1)
  }, action ? 12000 : 4200)
}
