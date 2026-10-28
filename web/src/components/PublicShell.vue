<script setup lang="ts">
import Icon from './Icon.vue'

defineProps<{ back?: string | object }>()
</script>

<template>
  <div class="flex min-h-dvh flex-col">
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur">
      <div class="mx-auto flex h-14 max-w-3xl items-center gap-3 px-4">
        <RouterLink v-if="back" :to="back" class="btn-ghost -ml-2 p-2" aria-label="ย้อนกลับ"><Icon name="back" /></RouterLink>
        <RouterLink to="/" class="flex items-center gap-2 font-bold text-slate-900">
          <span class="grid size-8 place-items-center rounded-lg bg-brand-700 text-white"><Icon name="desktop" :size="18" /></span>
          IT Hub
        </RouterLink>
        <nav class="ml-auto flex items-center gap-1 text-sm">
          <RouterLink to="/track" class="btn-ghost px-3 py-2"><Icon name="search" :size="16" /> ติดตามสถานะ</RouterLink>
        </nav>
      </div>
    </header>
    <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-6">
      <slot />
    </main>
    <footer class="border-t border-slate-200 py-6 text-center text-xs text-slate-400">
      ฝ่ายเทคโนโลยีสารสนเทศ · บริษัท อัตถสาร จำกัด
      <RouterLink to="/admin" class="ml-2 underline-offset-2 hover:text-slate-600 hover:underline">สำหรับเจ้าหน้าที่ IT</RouterLink>
    </footer>
  </div>
</template>
