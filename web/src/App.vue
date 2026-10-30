<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Icon from './components/Icon.vue'
import { loadMeta, toasts } from './store'
import { navigationErrorHref } from './router'

const metaError = ref('')
const loading = ref(true)

function reloadNavigation(event: MouseEvent) {
  if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
  event.preventDefault()
  if (!navigationErrorHref.value) return
  const target = new URL(navigationErrorHref.value, window.location.href)
  if (target.pathname === window.location.pathname && target.search === window.location.search) {
    // An anchor to the current URL with a hash only scrolls; force a fresh load.
    window.history.replaceState(window.history.state, '', target.href)
    window.location.reload()
  } else {
    window.location.assign(target.href)
  }
}

async function init() {
  loading.value = true
  metaError.value = ''
  try {
    await loadMeta()
  } catch (e) {
    metaError.value = (e as Error).message
  } finally {
    loading.value = false
  }
}
onMounted(init)
</script>

<template>
  <div v-if="loading" class="flex min-h-dvh items-center justify-center text-slate-400">
    <div class="size-8 animate-spin rounded-full border-4 border-slate-200 dark:border-slate-700 border-t-brand-600" />
  </div>
  <div v-else-if="metaError" class="flex min-h-dvh flex-col items-center justify-center gap-4 p-6 text-center">
    <Icon name="alert" :size="40" class="text-amber-500 dark:text-amber-400" />
    <p class="text-slate-600 dark:text-slate-300">{{ metaError }}</p>
    <button class="btn-primary" @click="init"><Icon name="refresh" :size="16" /> ลองใหม่</button>
  </div>
  <RouterView v-else />

  <div v-if="navigationErrorHref" role="alert" class="fixed inset-x-4 top-4 z-[70] rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 shadow-lg dark:border-amber-700 dark:bg-slate-800 dark:text-amber-100 sm:left-auto sm:w-96 no-print">
    <p class="font-semibold">เปิดหน้าที่เลือกไม่สำเร็จ</p>
    <p class="mt-1">กรุณาตรวจสอบการเชื่อมต่อแล้วลองโหลดหน้าอีกครั้ง หากมีข้อมูลที่ยังไม่ได้บันทึก ให้บันทึกหรือคัดลอกไว้ก่อน</p>
    <div class="mt-3 flex flex-wrap gap-2">
      <a :href="navigationErrorHref" class="btn-primary" @click="reloadNavigation">โหลดหน้าที่เลือกอีกครั้ง</a>
      <button type="button" class="btn-secondary" @click="navigationErrorHref = null">ปิดข้อความ</button>
    </div>
  </div>

  <div class="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 no-print">
    <TransitionGroup enter-from-class="translate-y-4 opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
      <div
        v-for="t in toasts"
        :key="t.id"
        :class="['pointer-events-auto flex items-center gap-2 rounded-xl px-4 py-3 text-sm font-medium text-white shadow-lg', t.kind === 'ok' ? 'bg-slate-900' : 'bg-red-600']"
      >
        <Icon :name="t.kind === 'ok' ? 'check' : 'alert'" :size="16" />
        {{ t.text }}
      </div>
    </TransitionGroup>
  </div>
</template>
