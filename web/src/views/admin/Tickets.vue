<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, type AdminRow, type Paged, type TicketRow } from '@/api'
import Icon from '@/components/Icon.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { fmtDateTime, priorityTone } from '@/format'
import { meta } from '@/store'
import { typeInfo } from '@/ticketTypes'

const route = useRoute()
const router = useRouter()

const q = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '')
const filters = reactive({ status: q('status') || 'open', type: q('type'), assigned_to: q('assigned_to'), q: q('q'), from: q('from'), to: q('to') })
const page = ref(Number(q('page')) || 1)
const result = ref<Paged<TicketRow> | null>(null)
const admins = ref<AdminRow[]>([])
const loading = ref(false)

api.get<AdminRow[]>('/admin/admins').then((a) => (admins.value = a.filter((x) => x.is_active)))

async function load() {
  loading.value = true
  try {
    result.value = await api.get<Paged<TicketRow>>('/admin/tickets', { ...filters, page: page.value })
  } finally {
    loading.value = false
  }
}

// เก็บตัวกรองใน URL — กดย้อนกลับจากหน้ารายละเอียดแล้วยังอยู่ที่เดิม
watch(
  [filters, page],
  () => {
    router.replace({ query: Object.fromEntries(Object.entries({ ...filters, page: page.value > 1 ? page.value : '' }).filter(([, v]) => v !== '')) })
    load()
  },
  { deep: true, immediate: true },
)

let searchTimer: ReturnType<typeof setTimeout>
function onSearch(e: Event) {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    filters.q = (e.target as HTMLInputElement).value
    page.value = 1
  }, 300)
}

function setFilter<K extends keyof typeof filters>(k: K, v: string) {
  filters[k] = v
  page.value = 1
}

const totalPages = () => (result.value ? Math.max(1, Math.ceil(result.value.total / result.value.per_page)) : 1)
</script>

<template>
  <h1 class="text-2xl font-bold">คำขอ / แจ้งซ่อม</h1>

  <div class="mt-5 flex flex-wrap gap-2">
    <button
      v-for="[k, label] in [['open', 'งานค้าง'], ['all', 'ทั้งหมด'], ...Object.entries(meta!.ticket_statuses)]"
      :key="k"
      :class="['rounded-full px-3.5 py-1.5 text-sm font-medium transition', filters.status === k ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:ring-slate-400']"
      @click="setFilter('status', k)"
    >
      {{ label }}
    </button>
  </div>

  <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
    <div class="relative lg:col-span-2">
      <Icon name="search" :size="16" class="absolute top-1/2 left-3 -translate-y-1/2 text-slate-400" />
      <input :value="filters.q" class="input pl-9" placeholder="ค้นหาเลขที่ / เรื่อง / ผู้แจ้ง / รหัสอุปกรณ์" @input="onSearch" />
    </div>
    <select :value="filters.type" class="input" @change="setFilter('type', ($event.target as HTMLSelectElement).value)">
      <option value="">ทุกประเภท</option>
      <option v-for="(t, k) in meta!.ticket_types" :key="k" :value="k">{{ t.label }}</option>
    </select>
    <select :value="filters.assigned_to" class="input" @change="setFilter('assigned_to', ($event.target as HTMLSelectElement).value)">
      <option value="">ผู้รับผิดชอบทั้งหมด</option>
      <option value="none">ยังไม่มีผู้รับผิดชอบ</option>
      <option v-for="a in admins" :key="a.id" :value="String(a.id)">{{ a.display_name }}</option>
    </select>
    <div class="flex items-center gap-1">
      <input :value="filters.from" type="date" class="input px-2 text-sm" aria-label="ตั้งแต่วันที่" @change="setFilter('from', ($event.target as HTMLInputElement).value)" />
      <span class="text-slate-400">–</span>
      <input :value="filters.to" type="date" class="input px-2 text-sm" aria-label="ถึงวันที่" @change="setFilter('to', ($event.target as HTMLInputElement).value)" />
    </div>
  </div>

  <section class="card mt-4 overflow-hidden">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>เลขที่</th>
            <th>เรื่อง</th>
            <th>ผู้แจ้ง</th>
            <th>สถานะ</th>
            <th>ผู้รับผิดชอบ</th>
            <th>วันที่แจ้ง</th>
          </tr>
        </thead>
        <tbody :class="loading && 'opacity-50'">
          <tr v-for="t in result?.data" :key="t.id" class="cursor-pointer" @click="router.push({ name: 'ticket', params: { id: t.id } })">
            <td class="font-mono text-xs whitespace-nowrap">
              <RouterLink :to="{ name: 'ticket', params: { id: t.id } }" class="font-semibold text-brand-700" @click.stop>{{ t.ticket_no }}</RouterLink>
            </td>
            <td class="min-w-64">
              <div class="flex items-center gap-2">
                <span :class="['grid size-6 shrink-0 place-items-center rounded-md', typeInfo[t.type].tone]"><Icon :name="typeInfo[t.type].icon" :size="13" /></span>
                <span class="line-clamp-1">{{ t.title }}</span>
              </div>
              <span v-if="t.priority !== 'normal'" :class="['ml-8 text-xs', priorityTone[t.priority]]">{{ meta?.ticket_priorities[t.priority] }}</span>
            </td>
            <td class="whitespace-nowrap">
              {{ t.requester_name }}
              <p class="text-xs text-slate-500">{{ t.requester_dept }}</p>
            </td>
            <td><StatusBadge :status="t.status" /></td>
            <td class="whitespace-nowrap text-slate-600">{{ t.assigned_name ?? '-' }}</td>
            <td class="whitespace-nowrap text-slate-600">{{ fmtDateTime(t.created_at) }}</td>
          </tr>
          <tr v-if="result && !result.data.length">
            <td colspan="6" class="py-12 text-center text-slate-400">ไม่พบคำขอ</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="result && result.total > result.per_page" class="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm">
      <span class="text-slate-500">ทั้งหมด {{ result.total }} รายการ</span>
      <div class="flex items-center gap-2">
        <button class="btn-secondary px-3 py-1.5" :disabled="page <= 1" @click="page--">ก่อนหน้า</button>
        <span>{{ page }} / {{ totalPages() }}</span>
        <button class="btn-secondary px-3 py-1.5" :disabled="page >= totalPages()" @click="page++">ถัดไป</button>
      </div>
    </div>
  </section>
</template>
