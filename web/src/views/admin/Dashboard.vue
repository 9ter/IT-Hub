<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api, type Dashboard, type TicketType } from '@/api'
import Icon from '@/components/Icon.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { fmtMinutes, priorityTone, timeAgo } from '@/format'
import { meta } from '@/store'
import { typeInfo } from '@/ticketTypes'

const data = ref<Dashboard | null>(null)
const error = ref('')

async function load() {
  try {
    data.value = await api.get<Dashboard>('/admin/dashboard')
    error.value = ''
  } catch (e) {
    error.value = (e as Error).message
  }
}

// รีเฟรชทุก 30 วินาที ให้เห็นงานใหม่
let timer: ReturnType<typeof setInterval>
onMounted(() => {
  load()
  timer = setInterval(load, 30000)
})
onBeforeUnmount(() => clearInterval(timer))

const types = computed(() => Object.keys(meta.value!.ticket_types) as TicketType[])
const assetTotal = computed(() => Object.values(data.value?.assets_by_category ?? {}).reduce((a, b) => a + b, 0))
</script>

<template>
  <div class="flex items-center justify-between gap-4">
    <h1 class="text-2xl font-bold">ภาพรวม</h1>
    <button class="btn-ghost" @click="load"><Icon name="refresh" :size="16" /> รีเฟรช</button>
  </div>
  <p v-if="error" class="mt-4 rounded-xl bg-red-50 dark:bg-red-900/40 p-3 text-sm text-red-700 dark:text-red-300">{{ error }}</p>

  <template v-if="data">
    <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <RouterLink :to="{ name: 'tickets', query: { status: 'new' } }" class="card p-4 hover:border-amber-400">
        <p class="text-sm text-slate-500 dark:text-slate-400">รอรับเรื่อง</p>
        <p class="mt-1 text-3xl font-bold text-amber-600 dark:text-amber-400">{{ data.by_status.new ?? 0 }}</p>
      </RouterLink>
      <RouterLink :to="{ name: 'tickets', query: { status: 'open' } }" class="card p-4 hover:border-brand-500">
        <p class="text-sm text-slate-500 dark:text-slate-400">งานค้างทั้งหมด</p>
        <p class="mt-1 text-3xl font-bold">{{ data.open_total }}</p>
      </RouterLink>
      <div class="card p-4">
        <p class="text-sm text-slate-500 dark:text-slate-400">เสร็จเดือนนี้</p>
        <p class="mt-1 text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ data.done_month }}</p>
        <p class="text-xs text-slate-400">จากที่รับ {{ data.created_month }} เรื่อง</p>
      </div>
      <div class="card p-4">
        <p class="text-sm text-slate-500 dark:text-slate-400">เวลาปิดงานเฉลี่ย</p>
        <p class="mt-1 text-3xl font-bold">{{ fmtMinutes(data.avg_minutes_30d) }}</p>
        <p class="text-xs text-slate-400">30 วันล่าสุด</p>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <section class="card lg:col-span-2">
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 px-5 py-4">
          <h2 class="font-semibold">งานที่ต้องทำ</h2>
          <RouterLink :to="{ name: 'tickets', query: { status: 'open' } }" class="text-sm font-medium text-brand-700 dark:text-brand-300">ดูทั้งหมด →</RouterLink>
        </div>
        <p v-if="!data.queue.length" class="p-10 text-center text-slate-400">ไม่มีงานค้าง 🎉</p>
        <RouterLink
          v-for="t in data.queue"
          :key="t.id"
          :to="{ name: 'ticket', params: { id: t.id } }"
          class="flex items-start gap-3 border-b border-slate-100 dark:border-slate-700 px-5 py-3 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-900"
        >
          <span :class="['mt-0.5 grid size-9 shrink-0 place-items-center rounded-lg', typeInfo[t.type].tone]"><Icon :name="typeInfo[t.type].icon" :size="18" /></span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium">{{ t.title }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">
              <span class="font-mono">{{ t.ticket_no }}</span> · {{ t.requester_name }} · {{ t.requester_dept }} · {{ timeAgo(t.created_at) }}
            </p>
          </div>
          <div class="flex shrink-0 flex-col items-end gap-1">
            <StatusBadge :status="t.status" />
            <span v-if="t.priority !== 'normal'" :class="['text-xs', priorityTone[t.priority]]">{{ meta?.ticket_priorities[t.priority] }}</span>
          </div>
        </RouterLink>
      </section>

      <div class="space-y-6">
        <section class="card p-5">
          <h2 class="font-semibold">งานค้างตามประเภท</h2>
          <ul class="mt-3 space-y-2">
            <li v-for="type in types" :key="type">
              <RouterLink :to="{ name: 'tickets', query: { status: 'open', type } }" class="flex items-center gap-3 rounded-lg p-1.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-900">
                <span :class="['grid size-8 place-items-center rounded-lg', typeInfo[type].tone]"><Icon :name="typeInfo[type].icon" :size="16" /></span>
                <span class="flex-1">{{ meta?.ticket_types[type].label }}</span>
                <span class="font-semibold">{{ data.open_by_type[type] ?? 0 }}</span>
              </RouterLink>
            </li>
          </ul>
        </section>
        <section class="card p-5">
          <div class="flex items-baseline justify-between">
            <h2 class="font-semibold">อุปกรณ์ที่ใช้งาน</h2>
            <span class="text-sm text-slate-500 dark:text-slate-400">{{ assetTotal }} เครื่อง</span>
          </div>
          <ul class="mt-3 space-y-2.5 text-sm">
            <li v-for="(n, name) in data.assets_by_category" :key="name">
              <div class="flex justify-between"><span>{{ name }}</span><span class="font-semibold">{{ n }}</span></div>
              <div class="mt-1 h-1.5 rounded-full bg-slate-100 dark:bg-slate-900"><div class="h-full rounded-full bg-brand-600" :style="{ width: (n / assetTotal) * 100 + '%' }" /></div>
            </li>
          </ul>
        </section>
      </div>
    </div>
  </template>
  <div v-else-if="!error" class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div v-for="i in 4" :key="i" class="card h-24 animate-pulse" />
  </div>
</template>
