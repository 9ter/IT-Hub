<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError, type PublicTicket, type TicketStatus } from '@/api'
import Icon from '@/components/Icon.vue'
import PublicShell from '@/components/PublicShell.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { fmtDateTime } from '@/format'
import { meta, myTickets } from '@/store'
import { typeInfo } from '@/ticketTypes'

const props = defineProps<{ no?: string }>()
const router = useRouter()

const input = ref(props.no ?? '')
const ticket = ref<PublicTicket | null>(null)
const error = ref('')
const loading = ref(false)
const mine = myTickets()

const steps: TicketStatus[] = ['new', 'accepted', 'in_progress', 'done']

watch(
  () => props.no,
  async (no) => {
    ticket.value = null
    error.value = ''
    if (!no) return
    input.value = no
    loading.value = true
    try {
      ticket.value = await api.get<PublicTicket>(`/track/${encodeURIComponent(no.toUpperCase())}`)
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

function search() {
  const no = input.value.trim().toUpperCase()
  if (no) router.push({ name: 'track', params: { no } })
}

function stepIndex(status: TicketStatus) {
  if (status === 'waiting') return 2
  return steps.indexOf(status)
}
</script>

<template>
  <PublicShell back="/">
    <h1 class="text-xl font-bold">ติดตามสถานะคำขอ</h1>
    <form class="mt-4 flex gap-2" @submit.prevent="search">
      <label for="no" class="sr-only">เลขที่คำขอ</label>
      <input id="no" v-model="input" class="input font-mono uppercase" placeholder="เช่น RP-6910-0001" autocomplete="off" autocapitalize="characters" />
      <button class="btn-primary shrink-0">ค้นหา</button>
    </form>
    <div v-if="!props.no && mine.length" class="mt-3 flex flex-wrap gap-2">
      <RouterLink v-for="n in mine" :key="n" :to="{ name: 'track', params: { no: n } }" class="rounded-lg bg-white px-2.5 py-1 font-mono text-xs ring-1 ring-slate-200 hover:ring-brand-500">
        {{ n }}
      </RouterLink>
    </div>

    <div v-if="loading" class="card mt-6 h-56 animate-pulse" />
    <p v-else-if="error" class="mt-6 rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ error }}</p>

    <template v-else-if="ticket">
      <section class="card mt-6 p-5">
        <div class="flex items-start gap-3">
          <span :class="['grid size-11 shrink-0 place-items-center rounded-xl', typeInfo[ticket.type].tone]"><Icon :name="typeInfo[ticket.type].icon" :size="22" /></span>
          <div class="min-w-0 flex-1">
            <p class="font-mono text-sm text-slate-500">{{ ticket.ticket_no }}</p>
            <p class="font-semibold">{{ ticket.title }}</p>
            <p class="text-sm text-slate-500">{{ meta?.ticket_types[ticket.type].label }} · ยื่นเมื่อ {{ fmtDateTime(ticket.created_at) }}</p>
          </div>
          <StatusBadge :status="ticket.status" />
        </div>

        <!-- แถบขั้นตอน -->
        <ol v-if="!['rejected', 'cancelled'].includes(ticket.status)" class="mt-6 grid grid-cols-4 gap-1 text-center text-xs">
          <li v-for="(s, i) in steps" :key="s">
            <div :class="['h-1.5 rounded-full', i <= stepIndex(ticket.status) ? 'bg-brand-600' : 'bg-slate-200']" />
            <p :class="['mt-2', i <= stepIndex(ticket.status) ? 'font-semibold text-slate-800' : 'text-slate-400']">
              {{ ticket.status === 'waiting' && s === 'in_progress' ? meta?.ticket_statuses.waiting : meta?.ticket_statuses[s] }}
            </p>
          </li>
        </ol>

        <div v-if="ticket.resolution" class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm">
          <p class="font-semibold text-emerald-900">ผลการดำเนินการ</p>
          <p class="mt-1 whitespace-pre-line text-emerald-900">{{ ticket.resolution }}</p>
        </div>
      </section>

      <section class="mt-4">
        <h2 class="mb-3 text-sm font-semibold text-slate-500">ความคืบหน้า</h2>
        <ol class="relative ml-3 border-l-2 border-slate-200">
          <li v-for="(log, i) in [...ticket.logs].reverse()" :key="i" class="relative pb-5 pl-6">
            <span :class="['absolute top-1 -left-[7px] size-3 rounded-full ring-4 ring-slate-50', i === 0 ? 'bg-brand-600' : 'bg-slate-300']" />
            <p class="text-xs text-slate-500">{{ fmtDateTime(log.created_at) }}<span v-if="log.admin_name"> · {{ log.admin_name }}</span></p>
            <p v-if="log.to_status" class="mt-0.5 text-sm font-semibold">{{ meta?.ticket_statuses[log.to_status] }}</p>
            <p v-if="log.note" class="mt-0.5 text-sm whitespace-pre-line text-slate-700">{{ log.note }}</p>
          </li>
        </ol>
      </section>
    </template>
  </PublicShell>
</template>
