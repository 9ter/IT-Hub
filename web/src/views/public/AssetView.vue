<script setup lang="ts">
import { ref, watch } from 'vue'
import { api, ApiError, type PublicAsset } from '@/api'
import Icon from '@/components/Icon.vue'
import PublicShell from '@/components/PublicShell.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { fmtDate, timeAgo } from '@/format'
import { meta } from '@/store'

const props = defineProps<{ code: string }>()

const asset = ref<PublicAsset | null>(null)
const error = ref('')
const loading = ref(true)

watch(
  () => props.code,
  async (code) => {
    loading.value = true
    error.value = ''
    try {
      asset.value = await api.get<PublicAsset>(`/assets/${encodeURIComponent(code.toUpperCase())}`)
    } catch (e) {
      asset.value = null
      error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

const assetStatusTone: Record<string, string> = {
  active: 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300',
  repair: 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300',
  spare: 'bg-slate-100 dark:bg-slate-900 text-slate-700 dark:text-slate-200',
  retired: 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
}
</script>

<template>
  <PublicShell back="/">
    <div v-if="loading" class="card h-64 animate-pulse" />

    <div v-else-if="error" class="card p-8 text-center">
      <Icon name="search" :size="36" class="mx-auto text-slate-300" />
      <p class="mt-3 font-semibold">{{ error }}</p>
      <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">ตรวจสอบรหัสบนสติกเกอร์อีกครั้ง หรือแจ้งซ่อมโดยระบุอุปกรณ์เอง</p>
      <RouterLink :to="{ name: 'request', params: { type: 'repair' } }" class="btn-primary mt-5"><Icon name="wrench" :size="18" /> แจ้งซ่อม</RouterLink>
    </div>

    <template v-else-if="asset">
      <section class="card overflow-hidden">
        <div class="flex items-start gap-4 bg-slate-900 px-5 py-5 text-white">
          <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-white/10"><Icon :name="asset.category_icon ?? 'device'" :size="28" /></span>
          <div class="min-w-0">
            <p class="font-mono text-2xl font-bold tracking-wide">{{ asset.asset_code }}</p>
            <p class="truncate text-slate-300">{{ asset.brand_model ?? '-' }}</p>
          </div>
          <span :class="['ml-auto shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold', assetStatusTone[asset.status]]">
            {{ meta?.asset_statuses[asset.status] }}
          </span>
        </div>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-4 p-5 text-sm">
          <div>
            <dt class="text-slate-500 dark:text-slate-400">ประเภท</dt>
            <dd class="mt-0.5 font-medium">{{ asset.category ?? '-' }}</dd>
          </div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">บริษัท</dt>
            <dd class="mt-0.5 font-medium">{{ asset.company }}</dd>
          </div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">ผู้ใช้งาน</dt>
            <dd class="mt-0.5 font-medium">{{ asset.holder_nickname ?? '-' }}</dd>
          </div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">หน่วยงาน</dt>
            <dd class="mt-0.5 font-medium">{{ asset.department ?? '-' }}</dd>
          </div>
          <div v-if="asset.location" class="col-span-2">
            <dt class="text-slate-500 dark:text-slate-400">สถานที่</dt>
            <dd class="mt-0.5 font-medium">{{ asset.location }}</dd>
          </div>
        </dl>
      </section>

      <section v-if="asset.open_tickets.length" class="mt-4 rounded-2xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/40 p-4">
        <p class="flex items-center gap-2 text-sm font-semibold text-amber-900 dark:text-amber-300"><Icon name="clock" :size="16" /> เครื่องนี้มีงานที่กำลังดำเนินการ</p>
        <RouterLink
          v-for="t in asset.open_tickets"
          :key="t.ticket_no"
          :to="{ name: 'track', params: { no: t.ticket_no } }"
          class="mt-2 flex items-center justify-between gap-2 rounded-xl bg-white dark:bg-slate-800 px-3 py-2.5 text-sm hover:ring-1 hover:ring-amber-300 dark:hover:ring-amber-800"
        >
          <span>
            <span class="font-mono font-semibold">{{ t.ticket_no }}</span>
            <span class="ml-2 text-slate-500 dark:text-slate-400">{{ timeAgo(t.created_at) }}</span>
          </span>
          <StatusBadge :status="t.status" />
        </RouterLink>
        <p class="mt-2 text-xs text-amber-800 dark:text-amber-300">หากเป็นปัญหาเดิม ไม่ต้องแจ้งซ้ำ — กดดูสถานะได้เลย</p>
      </section>

      <div class="mt-5 grid gap-3">
        <RouterLink :to="{ name: 'request', params: { type: 'repair' }, query: { asset: asset.asset_code } }" class="btn-primary py-3.5 text-base">
          <Icon name="wrench" /> แจ้งซ่อมเครื่องนี้
        </RouterLink>
        <RouterLink :to="{ name: 'home' }" class="btn-secondary py-3">
          <Icon name="service" :size="18" /> ขอติดตั้งโปรแกรม / บริการอื่น
        </RouterLink>
      </div>

      <section v-if="asset.recent_maintenance.length" class="mt-6">
        <h2 class="mb-2 text-sm font-semibold text-slate-500 dark:text-slate-400">ประวัติการบำรุงรักษาล่าสุด</h2>
        <ul class="card divide-y divide-slate-100 dark:divide-slate-700 text-sm">
          <li v-for="(m, i) in asset.recent_maintenance" :key="i" class="flex items-center justify-between px-4 py-2.5">
            <span>{{ fmtDate(m.performed_on) }}</span>
            <span :class="['rounded-md px-2 py-0.5 text-xs font-semibold', m.kind === 'BM' ? 'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-300' : 'bg-emerald-50 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300']">
              {{ m.kind === 'BM' ? 'ซ่อมเมื่อเสีย (BM)' : 'บำรุงรักษา (PM)' }}
            </span>
          </li>
        </ul>
      </section>
    </template>
  </PublicShell>
</template>
