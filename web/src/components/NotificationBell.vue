<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Icon from './Icon.vue'
import { fmtDateTime, priorityTone, timeAgo } from '@/format'
import { hasFreshTicket, notifyTotal, pendingFailed, pendingLoaded, pendingTickets, pendingTotal, refreshPending, setSoundEnabled, signedNotices, signedTotal, soundBlocked, soundEnabled } from '@/notifications'
import { meta } from '@/store'
import { typeInfo } from '@/ticketTypes'

const props = defineProps<{ placement: 'sidebar' | 'header' }>()

const route = useRoute()
const open = ref(false)
const root = ref<HTMLElement | null>(null)
const button = ref<HTMLButtonElement | null>(null)

const badge = computed(() => (notifyTotal.value > 99 ? '99+' : String(notifyTotal.value)))
const label = computed(() => {
  const parts = []
  if (pendingTotal.value > 0) parts.push(`คำขอที่ยังไม่อ่าน ${pendingTotal.value} รายการ`)
  if (signedTotal.value > 0) parts.push(`ลงนามแล้ว ${signedTotal.value} รายการ`)
  return parts.length ? `แจ้งเตือน: ${parts.join(', ')}` : 'แจ้งเตือน: ไม่มีรายการใหม่'
})
const signedMore = computed(() => signedTotal.value - signedNotices.value.length)
const moreCount = computed(() => pendingTotal.value - pendingTickets.value.length)

function toggle() {
  open.value = !open.value
  if (open.value) {
    hasFreshTicket.value = false
    refreshPending()
  }
}

function onPointerDown(e: PointerEvent) {
  if (!root.value?.contains(e.target as Node)) open.value = false
}

function onKeydown(e: KeyboardEvent) {
  if (e.key !== 'Escape') return
  open.value = false
  button.value?.focus()
}

function removeListeners() {
  document.removeEventListener('pointerdown', onPointerDown)
  document.removeEventListener('keydown', onKeydown)
}

watch(open, (isOpen) => {
  if (!isOpen) return removeListeners()
  document.addEventListener('pointerdown', onPointerDown)
  document.addEventListener('keydown', onKeydown)
})
watch(() => route.fullPath, () => (open.value = false))
onBeforeUnmount(removeListeners)
</script>

<template>
  <div ref="root" :class="props.placement === 'sidebar' ? 'relative hidden lg:block' : 'lg:hidden'">
    <button
      ref="button"
      type="button"
      :class="['btn-ghost relative shrink-0 p-2 no-print', props.placement === 'sidebar' && 'text-slate-300 hover:bg-white/10 hover:text-white dark:text-slate-300 dark:hover:bg-white/10']"
      :aria-label="label"
      :title="label"
      :aria-expanded="open"
      aria-haspopup="dialog"
      @click="toggle"
    >
      <Icon name="bell" />
      <span v-if="notifyTotal > 0" class="absolute -top-0.5 -right-0.5 flex">
        <span v-if="hasFreshTicket" class="absolute inline-flex size-full animate-ping rounded-full bg-red-500 opacity-75 motion-reduce:animate-none" />
        <span class="relative min-w-4.5 rounded-full bg-red-600 px-1 text-center text-[11px] leading-4.5 font-bold text-white">{{ badge }}</span>
      </span>
    </button>

    <section
      v-if="open"
      role="dialog"
      aria-label="การแจ้งเตือน"
      :class="[
        'card z-50 flex flex-col overflow-hidden text-left text-slate-900 shadow-xl dark:text-slate-100',
        props.placement === 'sidebar' ? 'absolute top-0 left-full ml-3 max-h-[calc(100dvh-2rem)] w-96' : 'fixed inset-x-2 top-16 max-h-[calc(100dvh-5rem)] sm:left-auto sm:w-96',
      ]"
    >
      <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-700">
        <h2 class="font-semibold">การแจ้งเตือน</h2>
        <span class="flex items-center gap-2">
          <span class="text-sm text-slate-500 dark:text-slate-400">{{ notifyTotal }} รายการ</span>
          <button
            type="button"
            class="btn-ghost p-1.5"
            :aria-pressed="soundEnabled"
            :aria-label="soundEnabled ? 'ปิดเสียงแจ้งเตือน' : 'เปิดเสียงแจ้งเตือน'"
            :title="soundEnabled ? 'เสียงแจ้งเตือน: เปิด (กดเพื่อปิด)' : 'เสียงแจ้งเตือน: ปิด (กดเพื่อเปิด)'"
            @click="setSoundEnabled(!soundEnabled)"
          >
            <Icon :name="soundEnabled ? 'volume' : 'volume-off'" :size="18" />
          </button>
        </span>
      </header>

      <p v-if="soundEnabled && soundBlocked" class="bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
        เบราว์เซอร์ยังไม่อนุญาตให้เล่นเสียง คลิกที่หน้าเว็บหนึ่งครั้งเพื่อเปิดเสียง
      </p>

      <p v-if="pendingFailed" class="flex items-center justify-between gap-3 bg-red-50 px-4 py-2 text-sm text-red-700 dark:bg-red-900/40 dark:text-red-300">
        ตรวจรายการล่าสุดไม่สำเร็จ
        <button type="button" class="font-semibold underline" @click="refreshPending">ลองใหม่</button>
      </p>

      <div class="min-h-0 flex-1 overflow-y-auto">
      <template v-if="signedNotices.length">
        <h3 class="bg-slate-50 px-4 py-1.5 text-xs font-semibold text-slate-600 dark:bg-slate-900/40 dark:text-slate-300">ลงนามแล้ว ({{ signedTotal }})</h3>
        <ul class="divide-y divide-slate-100 dark:divide-slate-700">
          <li v-for="n in signedNotices" :key="'s' + n.id">
            <RouterLink :to="{ name: 'ticket', params: { id: n.ticket_id } }" class="flex gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-700/50">
              <span :class="['grid size-8 shrink-0 place-items-center rounded-lg', n.status === 'approved' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300']">
                <Icon :name="n.status === 'approved' ? 'check' : 'x'" :size="16" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium break-words">
                  {{ n.approver_name }}
                  <span :class="n.status === 'approved' ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'">{{ n.status === 'approved' ? 'ลงนามแล้ว' : 'ไม่อนุมัติ / ไม่รับรอง' }}</span>
                </span>
                <span class="mt-0.5 block text-xs break-words text-slate-500 dark:text-slate-400">{{ n.role }}</span>
                <span class="mt-0.5 block truncate text-xs text-slate-500 dark:text-slate-400">{{ n.title }}</span>
                <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                  <span class="font-mono">{{ n.ticket_no }}</span>
                  <span :title="fmtDateTime(n.signed_at)">{{ timeAgo(n.signed_at) }}</span>
                </span>
              </span>
            </RouterLink>
          </li>
        </ul>
        <p v-if="signedMore > 0" class="px-4 py-2 text-xs text-slate-500 dark:text-slate-400">และอีก {{ signedMore }} รายการ — เปิดใบงานเพื่อดู</p>
        <h3 class="bg-slate-50 px-4 py-1.5 text-xs font-semibold text-slate-600 dark:bg-slate-900/40 dark:text-slate-300">คำขอที่ยังไม่อ่าน ({{ pendingTotal }})</h3>
      </template>
      <ul v-if="pendingTickets.length" class="divide-y divide-slate-100 dark:divide-slate-700">
        <li v-for="t in pendingTickets" :key="t.id">
          <RouterLink :to="{ name: 'ticket', params: { id: t.id } }" class="flex gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-700/50">
            <span :class="['grid size-8 shrink-0 place-items-center rounded-lg', typeInfo[t.type].tone]"><Icon :name="typeInfo[t.type].icon" :size="16" /></span>
            <span class="min-w-0 flex-1">
              <span class="line-clamp-2 text-sm font-medium break-words">{{ t.title }}</span>
              <span class="mt-0.5 block truncate text-xs text-slate-500 dark:text-slate-400">{{ t.requester_name }}<template v-if="t.requester_dept"> · {{ t.requester_dept }}</template></span>
              <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                <span class="font-mono">{{ t.ticket_no }}</span>
                <span>{{ timeAgo(t.created_at) }}</span>
                <span v-if="t.priority === 'high' || t.priority === 'urgent'" :class="priorityTone[t.priority]">{{ meta?.ticket_priorities[t.priority] }}</span>
              </span>
            </span>
          </RouterLink>
        </li>
      </ul>
      <p v-else-if="pendingLoaded" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">ไม่มีคำขอที่ยังไม่อ่าน</p>
      <p v-else-if="!pendingFailed" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">กำลังโหลด…</p>
      </div>

      <RouterLink :to="{ name: 'tickets', query: { status: 'new' } }" class="border-t border-slate-200 px-4 py-2.5 text-center text-sm font-semibold text-brand-700 hover:bg-slate-50 dark:border-slate-700 dark:text-brand-300 dark:hover:bg-slate-700/50">
        ดูคำขอรอรับเรื่องทั้งหมด<template v-if="moreCount > 0"> (ยังไม่อ่านอีก {{ moreCount }} รายการ)</template>
      </RouterLink>
    </section>
  </div>
</template>
