<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Icon from './components/Icon.vue'
import { loadMeta, toasts } from './store'

const metaError = ref('')
const loading = ref(true)

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
    <div class="size-8 animate-spin rounded-full border-4 border-slate-200 border-t-brand-600" />
  </div>
  <div v-else-if="metaError" class="flex min-h-dvh flex-col items-center justify-center gap-4 p-6 text-center">
    <Icon name="alert" :size="40" class="text-amber-500" />
    <p class="text-slate-600">{{ metaError }}</p>
    <button class="btn-primary" @click="init"><Icon name="refresh" :size="16" /> ลองใหม่</button>
  </div>
  <RouterView v-else />

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
