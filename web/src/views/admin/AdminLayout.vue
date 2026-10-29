<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api'
import Icon from '@/components/Icon.vue'
import itHubLogo from '@/assets/ithub-logo.png'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { auth } from '@/store'

const router = useRouter()
const route = useRoute()
const open = ref(false)

const nav = [
  { to: '/admin', label: 'ภาพรวม', icon: 'dashboard', exact: true },
  { to: '/admin/tickets', label: 'คำขอ / แจ้งซ่อม', icon: 'list' },
  { to: '/admin/assets', label: 'ทะเบียนอุปกรณ์', icon: 'desktop' },
  { to: '/admin/documents', label: 'เอกสารฝ่าย IT', icon: 'list' },
  { to: '/admin/borrows', label: 'บันทึกเบิก–ยืมอุปกรณ์', icon: 'list' },
  { to: '/admin/employees', label: 'ข้อมูลพนักงาน', icon: 'users' },
  { to: '/admin/admins', label: 'ผู้ดูแลระบบ', icon: 'users' },
  { to: '/admin/nas', label: 'Map NAS', icon: 'server' },
]

watch(() => route.fullPath, () => (open.value = false))

const isActive = (item: (typeof nav)[number]) => (item.exact ? route.path === item.to : route.path.startsWith(item.to))

async function logout() {
  await api.post('/auth/logout').catch(() => {})
  auth.admin = null
  router.replace({ name: 'login' })
}
</script>

<template>
  <div class="min-h-dvh lg:pl-60 print:pl-0">
    <!-- แถบบนมือถือ -->
    <header class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 lg:hidden no-print">
      <button class="btn-ghost -ml-2 p-2" aria-label="เมนู" @click="open = true"><Icon name="menu" /></button>
      <RouterLink to="/admin" class="flex items-center gap-3" aria-label="IT Hub Admin">
        <img :src="itHubLogo" alt="IT Hub" width="1721" height="914" class="h-10 w-auto rounded bg-white object-contain" />
        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">ADMIN</span>
      </RouterLink>
      <ThemeToggle class="ml-auto" />
    </header>

    <div v-if="open" class="fixed inset-0 z-40 bg-slate-900/40 lg:hidden" @click="open = false" />
    <aside
      :class="[
        'fixed inset-y-0 left-0 z-50 flex w-60 flex-col bg-slate-900 text-slate-300 transition-transform lg:translate-x-0 no-print',
        open ? 'translate-x-0' : '-translate-x-full',
      ]"
    >
      <RouterLink to="/admin" class="flex h-16 items-center gap-2.5 px-5 font-bold text-white" aria-label="IT Hub Admin">
        <img :src="itHubLogo" alt="IT Hub" width="1721" height="914" class="h-12 w-auto rounded bg-white p-1 object-contain" />
        <span class="rounded bg-white/10 px-1.5 py-0.5 text-[10px] font-semibold tracking-wider text-slate-300">ADMIN</span>
      </RouterLink>
      <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto px-3 py-2">
        <RouterLink
          v-for="item in nav"
          :key="item.to"
          :to="item.to"
          :class="['flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition', isActive(item) ? 'bg-white/10 text-white' : 'hover:bg-white/5 hover:text-white']"
        >
          <Icon :name="item.icon" :size="18" /> {{ item.label }}
        </RouterLink>
        <RouterLink to="/" target="_blank" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium hover:bg-white/5 hover:text-white">
          <Icon name="home" :size="18" /> หน้าผู้ใช้ทั่วไป
        </RouterLink>
      </nav>
      <div class="border-t border-white/10 p-3">
        <div class="mb-2 flex items-center justify-between px-2 text-sm">
          <span>ธีมการแสดงผล</span>
          <ThemeToggle class="text-slate-300 hover:bg-white/10 hover:text-white" />
        </div>
        <div class="flex items-center gap-3 px-2 py-2">
          <span class="grid size-8 place-items-center rounded-full bg-white/10 text-sm font-semibold text-white">{{ auth.admin?.display_name.slice(0, 1) }}</span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-white">{{ auth.admin?.display_name }}</p>
            <p class="truncate text-xs text-slate-400">{{ auth.admin?.username }}</p>
          </div>
          <button class="rounded-lg p-2 hover:bg-white/10 hover:text-white" title="ออกจากระบบ" aria-label="ออกจากระบบ" @click="logout"><Icon name="logout" :size="18" /></button>
        </div>
      </div>
    </aside>

    <main class="mx-auto max-w-6xl p-4 sm:p-6 lg:p-8 print:p-0">
      <RouterView />
    </main>
  </div>
</template>
