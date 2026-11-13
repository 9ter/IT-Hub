<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError, type AdminRow, type Paged, type TicketRow } from '@/api'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { fmtDateTime, priorityTone } from '@/format'
import { refreshPending } from '@/notifications'
import { meta, toast } from '@/store'
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

// ลิงก์ "ดูทั้งหมด" จากปุ่มแจ้งเตือนเปลี่ยน query ขณะอยู่หน้านี้
watch(() => route.query.status, (status) => {
  if (typeof status === 'string' && status !== filters.status) setFilter('status', status)
})

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

const deleting = ref<TicketRow | null>(null)
const deleteReason = ref('')
const deleteError = ref('')
const blockingDocumentId = ref('')
const deleteBusy = ref(false)
const restoringId = ref<number | null>(null)

function openDelete(t: TicketRow) {
  deleting.value = t
  deleteReason.value = ''
  deleteError.value = ''
  blockingDocumentId.value = ''
}

function closeDelete() {
  if (!deleteBusy.value) deleting.value = null
}

// ลบรายการสุดท้ายของหน้าแล้วถอยกลับหน้าก่อน ไม่ให้เหลือหน้าว่าง
function reloadAfterRemoval() {
  refreshPending()
  if (result.value?.data.length === 1 && page.value > 1) page.value--
  else load()
}

async function confirmDelete() {
  const t = deleting.value
  const reason = deleteReason.value.trim()
  if (!t || deleteBusy.value) return
  if (!reason) {
    deleteError.value = 'กรุณาระบุเหตุผลที่ลบ'
    return
  }
  deleteBusy.value = true
  deleteError.value = ''
  blockingDocumentId.value = ''
  try {
    await api.del(`/admin/tickets/${t.id}`, { reason })
    deleting.value = null
    toast(`ลบ ${t.ticket_no} แล้ว`)
    reloadAfterRemoval()
  } catch (e) {
    const err = e as ApiError
    deleteError.value = err.errors?.reason ?? err.message
    blockingDocumentId.value = err.errors?.document_id ?? ''
  } finally {
    deleteBusy.value = false
  }
}

async function restore(t: TicketRow) {
  if (restoringId.value !== null) return
  restoringId.value = t.id
  try {
    await api.post(`/admin/tickets/${t.id}/restore`)
    toast(`กู้คืน ${t.ticket_no} แล้ว`)
    reloadAfterRemoval()
  } catch (e) {
    toast((e as ApiError).message, 'error')
  } finally {
    restoringId.value = null
  }
}
</script>

<template>
  <h1 class="text-2xl font-bold">คำขอ / แจ้งซ่อม</h1>

  <div class="mt-5 flex flex-wrap gap-2">
    <button
      v-for="[k, label] in [['open', 'งานค้าง'], ['all', 'ทั้งหมด'], ...Object.entries(meta!.ticket_statuses), ['deleted', 'ลบแล้ว']]"
      :key="k"
      :class="['rounded-full px-3.5 py-1.5 text-sm font-medium transition', filters.status === k ? 'bg-slate-900 text-white' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 ring-1 ring-slate-200 dark:ring-slate-700 hover:ring-slate-400']"
      @click="setFilter('status', k)"
    >
      {{ label }}
    </button>
  </div>

  <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
    <div class="relative lg:col-span-2">
      <Icon name="search" :size="16" class="absolute top-1/2 left-3 -translate-y-1/2 text-slate-400" />
      <input :value="filters.q" class="input pl-9" placeholder="ค้นหาเลขที่ / เรื่อง / ผู้แจ้ง / บริษัท / รหัสอุปกรณ์" @input="onSearch" />
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
            <th aria-label="จัดการ"></th>
          </tr>
        </thead>
        <tbody :class="loading && 'opacity-50'">
          <tr v-for="t in result?.data" :key="t.id" :class="!t.deleted_at && 'cursor-pointer'" @click="!t.deleted_at && router.push({ name: 'ticket', params: { id: t.id } })">
            <td class="font-mono text-xs whitespace-nowrap">
              <span v-if="t.deleted_at" class="font-semibold text-slate-500 dark:text-slate-400">{{ t.ticket_no }}</span>
              <RouterLink v-else :to="{ name: 'ticket', params: { id: t.id } }" class="font-semibold text-brand-700 dark:text-brand-300" @click.stop>{{ t.ticket_no }}</RouterLink>
            </td>
            <td class="min-w-64">
              <div class="flex items-center gap-2">
                <span :class="['grid size-6 shrink-0 place-items-center rounded-md', typeInfo[t.type].tone]"><Icon :name="typeInfo[t.type].icon" :size="13" /></span>
                <span class="line-clamp-1">{{ t.title }}</span>
              </div>
              <span v-if="t.priority !== 'normal'" :class="['ml-8 text-xs', priorityTone[t.priority]]">{{ meta?.ticket_priorities[t.priority] }}</span>
              <p v-if="t.deleted_at" class="mt-1 ml-8 text-xs text-red-600 dark:text-red-400">ลบโดย {{ t.deleted_by_name ?? '-' }} เมื่อ {{ fmtDateTime(t.deleted_at) }}: {{ t.delete_reason }}</p>
            </td>
            <td class="whitespace-nowrap">
              {{ t.requester_name }}
              <p class="text-xs text-slate-500 dark:text-slate-400">{{ t.requester_dept }}</p>
              <p v-if="t.requester_company" class="text-xs text-brand-700 dark:text-brand-300">{{ t.requester_company }}</p>
            </td>
            <td><StatusBadge :status="t.status" /><p v-if="t.nas_archive_status" :class="['mt-1 whitespace-nowrap text-xs',t.nas_archive_status==='failed'?'text-red-600 dark:text-red-400':'text-slate-500 dark:text-slate-400']">NAS: {{ {sent:'ส่งแล้ว',failed:'ส่งไม่สำเร็จ',pending:'รอส่ง',processing:'กำลังส่ง',obsolete:'ฉบับเก่า'}[t.nas_archive_status]||t.nas_archive_status }}</p></td>
            <td class="whitespace-nowrap text-slate-600 dark:text-slate-300">{{ t.assigned_name ?? '-' }}</td>
            <td class="whitespace-nowrap text-slate-600 dark:text-slate-300">{{ fmtDateTime(t.created_at) }}</td>
            <td class="text-right" @click.stop>
              <button v-if="t.deleted_at" class="btn-secondary px-3 py-1.5 whitespace-nowrap" :disabled="restoringId !== null" :aria-label="`กู้คืนรายการ ${t.ticket_no}`" @click="restore(t)">
                <Icon name="refresh" :size="14" /> กู้คืน
              </button>
              <button v-else class="btn-ghost p-2 text-red-600 dark:text-red-400" title="ลบรายการ" :aria-label="`ลบรายการ ${t.ticket_no}`" @click="openDelete(t)">
                <Icon name="trash" :size="16" />
              </button>
            </td>
          </tr>
          <tr v-if="result && !result.data.length">
            <td colspan="7" class="py-12 text-center text-slate-400">ไม่พบคำขอ</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="result && result.total > result.per_page" class="flex items-center justify-between border-t border-slate-100 dark:border-slate-700 px-4 py-3 text-sm">
      <span class="text-slate-500 dark:text-slate-400">ทั้งหมด {{ result.total }} รายการ</span>
      <div class="flex items-center gap-2">
        <button class="btn-secondary px-3 py-1.5" :disabled="page <= 1" @click="page--">ก่อนหน้า</button>
        <span>{{ page }} / {{ totalPages() }}</span>
        <button class="btn-secondary px-3 py-1.5" :disabled="page >= totalPages()" @click="page++">ถัดไป</button>
      </div>
    </div>
  </section>

  <Modal v-if="deleting" :title="`ลบรายการ ${deleting.ticket_no}`" @close="closeDelete">
    <form class="space-y-4" @submit.prevent="confirmDelete">
      <p class="font-medium break-words">{{ deleting.title }}</p>
      <p class="text-sm text-slate-600 dark:text-slate-300">รายการจะหายจากทุกหน้าและหน้าติดตามสถานะ ลิงก์ลงนามที่ค้างอยู่จะใช้ไม่ได้ ส่วนลายเซ็น ประวัติ และไฟล์บน NAS ยังเก็บไว้ กู้คืนได้จากแท็บ “ลบแล้ว”</p>
      <div>
        <label class="label" for="delete-reason">เหตุผลที่ลบ <span class="text-red-600">*</span></label>
        <textarea id="delete-reason" v-model="deleteReason" class="input" rows="3" maxlength="255" autofocus></textarea>
        <p v-if="deleteError" class="field-error" role="alert">{{ deleteError }}</p>
        <RouterLink v-if="blockingDocumentId" :to="{ name: 'document-detail', params: { id: blockingDocumentId } }" class="mt-1 inline-block text-sm font-semibold text-brand-700 dark:text-brand-300">เปิดบันทึกเบิก–ยืม</RouterLink>
      </div>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" :disabled="deleteBusy" @click="closeDelete">ยกเลิก</button>
        <button type="submit" class="btn-danger" :disabled="deleteBusy">{{ deleteBusy ? 'กำลังลบ...' : 'ลบรายการ' }}</button>
      </div>
    </form>
  </Modal>
</template>
