import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { setUnauthorizedHandler } from './api'
import { auth } from './store'
import './style.css'

// session หมดอายุระหว่างใช้งานหน้า admin → กลับไปหน้า login
setUnauthorizedHandler(() => {
  auth.admin = null
  const current = router.currentRoute.value
  if (current.matched.some((r) => r.meta.admin)) {
    router.replace({ name: 'login', query: { next: current.fullPath } })
  }
})

createApp(App).use(router).mount('#app')
