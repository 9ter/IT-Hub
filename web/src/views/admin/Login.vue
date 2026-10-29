<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError, type Admin } from '@/api'
import itHubLogo from '@/assets/ithub-logo.png'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { auth } from '@/store'

const router = useRouter()
const route = useRoute()
const f = reactive({ username: '', password: '' })
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    auth.admin = await api.post<Admin>('/auth/login', f)
    auth.checked = true
    const next = typeof route.query.next === 'string' && route.query.next.startsWith('/admin') ? route.query.next : '/admin'
    router.replace(next)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'เข้าสู่ระบบไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center bg-slate-100 dark:bg-slate-900 p-4">
    <ThemeToggle class="absolute top-4 right-4" />
    <form class="card w-full max-w-sm p-7" @submit.prevent="submit">
      <div class="text-center">
        <h1><img :src="itHubLogo" alt="IT Hub" width="1721" height="914" class="mx-auto h-auto w-40 rounded bg-white object-contain" /></h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">สำหรับเจ้าหน้าที่ IT</p>
      </div>
      <div class="mt-6 space-y-4">
        <div>
          <label class="label" for="username">ชื่อผู้ใช้</label>
          <input id="username" v-model="f.username" class="input" autocomplete="username" autofocus required />
        </div>
        <div>
          <label class="label" for="password">รหัสผ่าน</label>
          <input id="password" v-model="f.password" type="password" class="input" autocomplete="current-password" required />
        </div>
      </div>
      <p v-if="error" class="mt-4 rounded-lg bg-red-50 dark:bg-red-900/40 p-3 text-sm text-red-700 dark:text-red-300">{{ error }}</p>
      <button class="btn-primary mt-6 w-full" :disabled="loading">{{ loading ? 'กำลังเข้าสู่ระบบ...' : 'เข้าสู่ระบบ' }}</button>
      <RouterLink to="/" class="mt-4 block text-center text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">← กลับหน้า IT Hub</RouterLink>
    </form>
  </div>
</template>
