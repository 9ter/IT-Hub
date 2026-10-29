<script setup lang="ts">
import Icon from './Icon.vue'
import itHubLogo from '@/assets/ithub-logo.png'
import ThemeToggle from './ThemeToggle.vue'

defineProps<{ back?: string | object }>()
</script>

<template>
  <div class="flex min-h-dvh flex-col print:block print:min-h-0">
    <header class="sticky top-0 z-30 border-b border-slate-200 dark:border-slate-700 bg-white/90 dark:bg-slate-900/90 backdrop-blur no-print">
      <div class="mx-auto flex h-14 max-w-3xl items-center gap-3 px-4">
        <RouterLink v-if="back" :to="back" class="btn-ghost -ml-2 p-2" aria-label="ย้อนกลับ"><Icon name="back" /></RouterLink>
        <RouterLink to="/" class="shrink-0" aria-label="IT Hub หน้าหลัก">
          <img :src="itHubLogo" alt="IT Hub" width="1721" height="914" class="h-11 w-auto rounded bg-white object-contain" />
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
      ฝ่ายเทคโนโลยีสารสนเทศ · บริษัท อัตถสาร จำกัด
      <RouterLink to="/admin" class="ml-2 underline-offset-2 hover:text-slate-600 dark:hover:text-slate-300 hover:underline">สำหรับเจ้าหน้าที่ IT</RouterLink>
    </footer>
  </div>
</template>
