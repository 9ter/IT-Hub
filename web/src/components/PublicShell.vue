<script setup lang="ts">
import Icon from './Icon.vue'
import itHubLogo from '@/assets/ithub-logo.png'
import ThemeToggle from './ThemeToggle.vue'
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { DEFAULT_COMPANY_NAME, companyLogoUrl, loadCompanies, selectedCompany } from '@/companies'

defineProps<{ back?: string | object }>()
const route = useRoute()
// ตรวจรายการบริษัทล่าสุดเบื้องหลัง (บริษัทที่ถูกปิดใช้งานจะถูกล้าง ชื่อ/โลโก้ที่เปลี่ยนจะอัปเดต)
onMounted(() => { if (selectedCompany.value) loadCompanies().catch(() => {}) })
</script>

<template>
  <div class="flex min-h-dvh flex-col print:block print:min-h-0">
    <header class="sticky top-0 z-30 border-b border-slate-200 dark:border-slate-700 bg-white/90 dark:bg-slate-900/90 backdrop-blur no-print">
      <div class="mx-auto flex h-14 max-w-3xl items-center gap-3 px-4">
        <RouterLink v-if="back" :to="back" class="btn-ghost -ml-2 p-2" aria-label="ย้อนกลับ"><Icon name="back" /></RouterLink>
        <RouterLink to="/" class="shrink-0" aria-label="IT Hub หน้าหลัก">
          <img :src="itHubLogo" alt="IT Hub" width="1721" height="914" class="h-11 w-auto object-contain" />
        </RouterLink>
        <RouterLink
          v-if="selectedCompany"
          :to="{ name: 'choose-company', query: { next: route.fullPath } }"
          class="ml-1 flex min-w-0 items-center gap-2 rounded-xl border border-slate-200 py-1 pr-2.5 pl-1 text-sm hover:border-amber-400 dark:border-slate-700 dark:hover:border-amber-500"
          :title="`${selectedCompany.name} — กดเพื่อเปลี่ยนบริษัท`"
          :aria-label="`บริษัท ${selectedCompany.name} กดเพื่อเปลี่ยนบริษัท`"
        >
          <span class="grid size-8 shrink-0 place-items-center overflow-hidden rounded-lg bg-white ring-1 ring-slate-200 dark:ring-slate-700">
            <img v-if="companyLogoUrl(selectedCompany)" :src="companyLogoUrl(selectedCompany)!" alt="" class="size-full object-contain p-0.5" />
            <span v-else class="text-xs font-bold text-amber-600">{{ selectedCompany.code.slice(0, 2) }}</span>
          </span>
          <span class="hidden min-w-0 flex-col leading-tight md:flex">
            <span class="truncate font-medium">{{ selectedCompany.code }}</span>
            <span class="text-xs text-slate-500 dark:text-slate-400">เปลี่ยนบริษัท</span>
          </span>
        </RouterLink>
        <nav class="ml-auto flex items-center gap-1 text-sm">
          <RouterLink to="/track" class="btn-ghost px-3 py-2" aria-label="ติดตามสถานะ"><Icon name="search" :size="16" /><span class="hidden sm:inline">ติดตามสถานะ</span></RouterLink>
          <ThemeToggle />
        </nav>
      </div>
    </header>
    <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-6 print:max-w-none print:p-0">
      <slot />
    </main>
    <footer class="border-t border-slate-200 dark:border-slate-700 py-6 text-center text-xs text-slate-400 no-print">
      ฝ่ายเทคโนโลยีสารสนเทศ · {{ selectedCompany?.name ?? DEFAULT_COMPANY_NAME }}
      <RouterLink to="/admin" class="ml-2 underline-offset-2 hover:text-slate-600 dark:hover:text-slate-300 hover:underline">สำหรับเจ้าหน้าที่ IT</RouterLink>
    </footer>
  </div>
</template>
