<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, type PublicTicket, type TicketType } from '@/api'
import Icon from '@/components/Icon.vue'
import PublicShell from '@/components/PublicShell.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { timeAgo } from '@/format'
import { forgetTicket, meta, myTickets } from '@/store'
import { TICKET_NO_PATTERN, typeInfo } from '@/ticketTypes'

const router = useRouter()
const query = ref('')
const recent = ref<PublicTicket[]>([])

const types = computed(() => Object.entries(meta.value!.ticket_types) as [TicketType, { label: string; form: string }][])

function go() {
  const q = query.value.trim().toUpperCase()
  if (!q) return
  router.push(TICKET_NO_PATTERN.test(q) ? { name: 'track', params: { no: q } } : { name: 'asset', params: { code: q } })
}

onMounted(async () => {
  const nos = myTickets().slice(0, 5)
  const results = await Promise.allSettled(nos.map((no) => api.get<PublicTicket>(`/track/${encodeURIComponent(no)}`)))
  recent.value = results.flatMap((r, i) => {
    if (r.status === 'fulfilled') return [r.value]
    if ((r.reason as { status?: number }).status === 404) forgetTicket(nos[i])
    return []
  })
})
</script>

<template>
  <PublicShell>
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 to-brand-900 px-6 py-8 text-white sm:px-8 sm:py-10">
      <div class="absolute -top-10 -right-10 size-48 rounded-full bg-white/5" />
      <div class="absolute -right-4 -bottom-16 size-40 rounded-full bg-white/5" />
      <p class="text-sm font-medium text-brand-100">ฝ่ายเทคโนโลยีสารสนเทศ</p>
      <h1 class="mt-1 text-3xl font-bold sm:text-4xl">IT Hub</h1>
      <p class="mt-2 max-w-md text-brand-100">แจ้งซ่อมและขอใช้บริการ IT ได้ที่นี่ ไม่ต้องเข้าสู่ระบบ</p>

      <form class="relative mt-6 flex gap-2" @submit.prevent="go">
        <label for="q" class="sr-only">รหัสอุปกรณ์หรือเลขที่คำขอ</label>
        <input
          id="q"
          v-model="query"
          class="input border-0 py-3 uppercase placeholder:normal-case"
          placeholder="รหัสอุปกรณ์ (MD-01-001) หรือเลขที่คำขอ"
          autocomplete="off"
          autocapitalize="characters"
        />
        <button class="btn shrink-0 bg-white dark:bg-slate-800 px-4 text-brand-800 dark:text-brand-300 hover:bg-brand-50 dark:hover:bg-brand-900/40" aria-label="ค้นหา"><Icon name="search" /></button>
      </form>
      <p class="relative mt-3 flex items-center gap-2 text-sm text-brand-100">
        <Icon name="qr" :size="16" /> หรือสแกน QR Code ที่ติดบนเครื่อง เพื่อดูข้อมูลและแจ้งซ่อมทันที
      </p>
    </section>

    <section v-if="recent.length" class="mt-8">
      <h2 class="mb-3 text-sm font-semibold text-slate-500 dark:text-slate-400">คำขอล่าสุดจากเครื่องนี้</h2>
      <div class="card divide-y divide-slate-100 dark:divide-slate-700">
        <RouterLink
          v-for="t in recent"
          :key="t.ticket_no"
          :to="{ name: 'track', params: { no: t.ticket_no } }"
          class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-900"
        >
          <span :class="['grid size-9 shrink-0 place-items-center rounded-lg', typeInfo[t.type].tone]"><Icon :name="typeInfo[t.type].icon" :size="18" /></span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium">{{ t.title }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t.ticket_no }} · {{ timeAgo(t.created_at) }}</p>
          </div>
          <StatusBadge :status="t.status" />
        </RouterLink>
      </div>
    </section>

    <section class="mt-8">
      <h2 class="mb-3 text-sm font-semibold text-slate-500 dark:text-slate-400">บริการ IT</h2>
      <div class="grid gap-3 sm:grid-cols-2">
        <RouterLink
          v-for="[key, t] in types"
          :key="key"
          :to="{ name: 'request', params: { type: key } }"
          :class="['card group flex items-start gap-4 p-4 transition hover:border-brand-500 hover:shadow-md', key === 'repair' && 'sm:col-span-2']"
        >
          <span :class="['grid size-12 shrink-0 place-items-center rounded-xl', typeInfo[key].tone]"><Icon :name="typeInfo[key].icon" :size="24" /></span>
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-slate-900 dark:text-slate-100">{{ t.label }}</p>
            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ typeInfo[key].desc }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ t.form }}</p>
          </div>
          <Icon name="chevron" class="mt-3 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600 dark:group-hover:text-brand-400" />
        </RouterLink>
      </div>
    </section>
  </PublicShell>
</template>
