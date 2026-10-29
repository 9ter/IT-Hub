import { ref } from 'vue'

type Theme = 'light' | 'dark'
const key = 'ithub.theme'
const system = window.matchMedia('(prefers-color-scheme: dark)')

function readPreference(): Theme | null {
  try {
    const value = localStorage.getItem(key)
    return value === 'light' || value === 'dark' ? value : null
  } catch {
    return null
  }
}

let preference = readPreference()
export const isDark = ref(document.documentElement.classList.contains('dark'))

function applyTheme() {
  isDark.value = (preference ?? (system.matches ? 'dark' : 'light')) === 'dark'
  document.documentElement.classList.toggle('dark', isDark.value)
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', isDark.value ? '#0f172a' : '#1d4ed8')
}

export function toggleTheme() {
  preference = isDark.value ? 'light' : 'dark'
  try {
    localStorage.setItem(key, preference)
  } catch {
    // The toggle still works when browser storage is unavailable.
  }
  applyTheme()
}

system.addEventListener('change', () => { if (!preference) applyTheme() })
window.addEventListener('storage', (event) => {
  if (event.key === key || event.key === null) {
    preference = readPreference()
    applyTheme()
  }
})
applyTheme()
