<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import Icon from './Icon.vue'

defineProps<{ title: string; wide?: boolean }>()
const emit = defineEmits<{ close: [] }>()

const onKey = (e: KeyboardEvent) => e.key === 'Escape' && emit('close')
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-0 sm:items-center sm:p-4" @mousedown.self="emit('close')">
      <div :class="['max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-white dark:bg-slate-800 shadow-xl sm:rounded-2xl', wide ? 'sm:max-w-2xl' : 'sm:max-w-md']" role="dialog" aria-modal="true">
        <div class="sticky top-0 flex items-center justify-between border-b border-slate-100 dark:border-slate-700 bg-white dark:bg-slate-800 px-5 py-4">
          <h2 class="text-lg font-semibold">{{ title }}</h2>
          <button class="btn-ghost -mr-2 p-2" aria-label="ปิด" @click="emit('close')"><Icon name="x" /></button>
        </div>
        <div class="p-5">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>
