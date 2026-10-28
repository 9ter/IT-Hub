<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { api, ApiError, photoUrl, type AdminRow, type Ticket, type TicketStatus } from '@/api'
import Icon from '@/components/Icon.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { fmtDate, fmtDateTime } from '@/format'
import { auth, meta, toast } from '@/store'
import { typeInfo } from '@/ticketTypes'

const props = defineProps<{ id: number }>()

const ticket = ref<Ticket | null>(null)
const admins = ref<AdminRow[]>([])
const error = ref('')
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const photoOpen = ref(false)

const f = reactive({
  status: 'new' as TicketStatus,
  priority: 'normal',
  assigned_to: '' as string | number,
  resolution: '',
  fix_method: '',
  outsource_vendor: '',
  note: '',
  note_public: true,
})

function fill(t: Ticket) {
  ticket.value = t
  Object.assign(f, {
    status: t.status,
    priority: t.priority,
    assigned_to: t.assigned_to ?? '',
    resolution: t.resolution ?? '',
    fix_method: t.fix_method ?? '',
    outsource_vendor: t.outsource_vendor ?? '',
    note: '',
    note_public: true,
  })
}

watch(
  () => props.id,
  async (id) => {
    error.value = ''
    try {
      fill(await api.get<Ticket>(`/admin/tickets/${id}`))
    } catch (e) {
      error.value = (e as Error).message
    }
  },
  { immediate: true },
)
api.get<AdminRow[]>('/admin/admins').then((a) => (admins.value = a))

async function save(overrides: Partial<typeof f> = {}) {
  Object.assign(f, overrides)
  saving.value = true
  errors.value = {}
  try {
    const body = {
      status: f.status,
      priority: f.priority,
      assigned_to: f.assigned_to === '' ? null : Number(f.assigned_to),
      resolution: f.resolution,
      fix_method: f.fix_method || null,
      outsource_vendor: f.outsource_vendor,
      note: f.note,
      note_public: f.note_public,
    }
    fill(await api.patch<Ticket>(`/admin/tickets/${props.id}`, body))
    toast('บันทึกแล้ว')
  } catch (e) {
    if (e instanceof ApiError) errors.value = e.errors
    toast((e as Error).message, 'error')
    if (ticket.value) f.status = overrides.status ? ticket.value.status : f.status
  } finally {
    saving.value = false
  }
}

// ปุ่มลัดตามสถานะปัจจุบัน
const quick = computed(() => {
  const s = ticket.value?.status
  const list: { label: string; status: TicketStatus; cls: string }[] = []
  if (s === 'new') list.push({ label: 'รับเรื่อง', status: 'accepted', cls: 'btn-primary' })
  if (s === 'new' || s === 'accepted' || s === 'waiting') list.push({ label: 'เริ่มดำเนินการ', status: 'in_progress', cls: s === 'new' ? 'btn-secondary' : 'btn-primary' })
  return list
})

const isClosed = computed(() => ['done', 'rejected', 'cancelled'].includes(ticket.value?.status ?? ''))

/** แปลง details (JSON) เป็นรายการ ป้ายกำกับ → ค่า สำหรับแสดงผล */
const detailRows = computed(() => {
  const t = ticket.value
  if (!t || !meta.value) return []
  const d = t.details as Record<string, string | string[] | number | boolean | null>
  const m = meta.value
  const rows: [string, string | null | undefined][] = []
  const list = (keys: unknown, map: Record<string, string>) => (Array.isArray(keys) ? keys.map((k) => map[k] ?? k).join(', ') : null)
  switch (t.type) {
    case 'repair':
      rows.push(['ประเภทงาน', d.job_type === 'general' ? 'งานทั่วไป' : 'งานซ่อม'], ['อุปกรณ์ที่เสีย', d.equipment as string], ['วันเวลาที่พบปัญหา', fmtDateTime(d.found_at as string)])
      break
    case 'service':
      rows.push(
        ['ตำแหน่ง', d.position as string],
        ['อุปกรณ์', m.device_kinds[d.device_kind as string]],
        ['ต้องการให้ดำเนินการ', list(d.actions, m.service_actions)],
        ['OS / โปรแกรม', d.os_target as string],
        ['อื่นๆ', d.other_text as string],
        ['ผู้อนุมัติ', d.approver_name as string],
      )
      break
    case 'email':
      rows.push(
        ['ชื่อ-นามสกุล (ไทย)', d.name_th as string],
        ['ชื่อ-นามสกุล (อังกฤษ)', d.name_en as string],
        ['ตำแหน่ง', d.position as string],
        ['บริษัท', m.email_companies[d.company as string]],
        ['E-mail ที่ต้องการ', d.desired_email ? `${d.desired_email}@atthasan.com` : null],
        ['ผู้อนุมัติ', d.approver_name as string],
      )
      break
    case 'borrow':
      rows.push(
        ['ประเภท', d.device_kind === 'notebook' ? 'Notebook' : 'อื่นๆ'],
        ['อุปกรณ์', d.item as string],
        ['จำนวน', String(d.quantity ?? '')],
        ['วันที่เริ่มใช้', fmtDate(d.start_date as string)],
        ['วันที่คืน', d.end_date ? fmtDate(d.end_date as string) : null],
      )
      break
    case 'purchase':
      rows.push(
        ['ตำแหน่ง', d.position as string],
        ['ความต้องการ', d.urgent ? 'ด่วน' : 'ไม่ด่วน'],
        ['ต้องการภายใน', d.need_by ? fmtDate(d.need_by as string) : null],
        ['ประเภทอุปกรณ์', list(d.items, m.purchase_items)],
        ['CPU', d.spec_cpu as string],
        ['RAM', d.spec_ram as string],
        ['SSD/HDD', d.spec_storage as string],
        ['ขนาดจอ', d.spec_display as string],
        ['สเปกอื่นๆ', d.spec_other as string],
      )
      break
  }
  return rows.filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== '-')
})

const printForm = () => window.print()

const signatures = computed(() =>
  ticket.value?.type === 'repair'
    ? ['ผู้แจ้งซ่อม / ผู้ตรวจรับงาน', 'เจ้าหน้าที่ฝ่ายเทคโนโลยีสารสนเทศ', 'ผอ./ผจก. ฝ่ายสนับสนุนและติดตั้ง']
    : ['ผู้ร้องขอ', 'หัวหน้าฝ่าย / ผู้อนุมัติ', 'เจ้าหน้าที่ฝ่ายเทคโนโลยีสารสนเทศ'],
)
</script>

<template>
  <p v-if="error" class="rounded-xl bg-red-50 p-4 text-red-700">{{ error }}</p>
  <div v-else-if="!ticket" class="card h-64 animate-pulse" />

  <template v-else>
    <div class="flex flex-wrap items-center gap-3 no-print">
      <RouterLink :to="{ name: 'tickets' }" class="btn-ghost -ml-2 p-2" aria-label="กลับ"><Icon name="back" /></RouterLink>
      <span :class="['grid size-10 place-items-center rounded-xl', typeInfo[ticket.type].tone]"><Icon :name="typeInfo[ticket.type].icon" /></span>
      <div class="min-w-0 flex-1">
        <p class="font-mono text-sm text-slate-500">{{ ticket.ticket_no }} · {{ meta?.ticket_types[ticket.type].label }}</p>
        <h1 class="text-xl font-bold">{{ ticket.title }}</h1>
      </div>
      <StatusBadge :status="ticket.status" />
      <button class="btn-secondary" @click="printForm"><Icon name="printer" :size="16" /> พิมพ์ฟอร์ม</button>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3 no-print">
      <div class="space-y-6 lg:col-span-2">
        <section class="card p-5">
          <h2 class="font-semibold">รายละเอียด</h2>
          <p class="mt-3 whitespace-pre-line text-slate-800">{{ ticket.description }}</p>
          <dl v-if="detailRows.length" class="mt-4 grid gap-x-6 gap-y-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-2">
            <div v-for="[label, value] in detailRows" :key="label">
              <dt class="text-slate-500">{{ label }}</dt>
              <dd class="font-medium">{{ value }}</dd>
            </div>
          </dl>
          <button v-if="ticket.has_photo" class="mt-4 block" @click="photoOpen = true">
            <img :src="photoUrl(ticket.id)" alt="รูปที่แนบ" class="max-h-56 rounded-xl border border-slate-200" />
          </button>
        </section>

        <section class="card p-5">
          <h2 class="font-semibold">ไทม์ไลน์</h2>
          <ol class="mt-4 space-y-4">
            <li v-for="log in [...ticket.logs].reverse()" :key="log.id" class="flex gap-3">
              <span :class="['mt-1.5 size-2.5 shrink-0 rounded-full', log.to_status ? 'bg-brand-600' : 'bg-slate-300']" />
              <div class="min-w-0 flex-1 text-sm">
                <p class="text-xs text-slate-500">
                  {{ fmtDateTime(log.created_at) }} · {{ log.admin_name ?? 'ผู้แจ้ง' }}
                  <span v-if="!log.is_public" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600">ภายใน</span>
                </p>
                <p v-if="log.to_status" class="font-medium">
                  <template v-if="log.from_status">{{ meta?.ticket_statuses[log.from_status] }} → </template>{{ meta?.ticket_statuses[log.to_status] }}
                </p>
                <p v-if="log.note" class="whitespace-pre-line text-slate-700">{{ log.note }}</p>
              </div>
            </li>
          </ol>
        </section>
      </div>

      <div class="space-y-6">
        <section class="card p-5 text-sm">
          <h2 class="font-semibold">ผู้แจ้ง</h2>
          <dl class="mt-3 space-y-2">
            <div class="flex gap-2"><Icon name="user" :size="16" class="mt-0.5 text-slate-400" /><dd>{{ ticket.requester_name }}</dd></div>
            <div class="flex gap-2"><Icon name="building" :size="16" class="mt-0.5 text-slate-400" /><dd>{{ ticket.requester_dept ?? '-' }}</dd></div>
            <div v-if="ticket.requester_phone" class="flex gap-2">
              <Icon name="device" :size="16" class="mt-0.5 text-slate-400" /><a :href="`tel:${ticket.requester_phone}`" class="text-brand-700">{{ ticket.requester_phone }}</a>
            </div>
            <div v-if="ticket.requester_email" class="flex gap-2">
              <Icon name="mail" :size="16" class="mt-0.5 text-slate-400" /><a :href="`mailto:${ticket.requester_email}`" class="break-all text-brand-700">{{ ticket.requester_email }}</a>
            </div>
            <div v-if="ticket.location" class="flex gap-2"><Icon name="pin" :size="16" class="mt-0.5 text-slate-400" /><dd>{{ ticket.location }}</dd></div>
          </dl>
          <RouterLink v-if="ticket.asset_id" :to="{ name: 'asset-detail', params: { id: ticket.asset_id } }" class="mt-4 flex items-center gap-3 rounded-xl bg-slate-50 p-3 hover:bg-slate-100">
            <Icon name="desktop" :size="18" class="text-slate-500" />
            <div class="min-w-0">
              <p class="font-mono font-semibold">{{ ticket.asset_code }}</p>
              <p class="truncate text-xs text-slate-500">{{ ticket.asset_model }}</p>
            </div>
          </RouterLink>
        </section>

        <section class="card p-5">
          <h2 class="font-semibold">จัดการ</h2>
          <div v-if="quick.length" class="mt-3 flex gap-2">
            <button v-for="q in quick" :key="q.status" :class="[q.cls, 'flex-1']" :disabled="saving" @click="save({ status: q.status })">{{ q.label }}</button>
          </div>
          <div class="mt-4 space-y-3">
            <div>
              <label class="label" for="status">สถานะ</label>
              <select id="status" v-model="f.status" class="input">
                <option v-for="(label, k) in meta!.ticket_statuses" :key="k" :value="k">{{ label }}</option>
              </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="label" for="priority">ความสำคัญ</label>
                <select id="priority" v-model="f.priority" class="input">
                  <option v-for="(label, k) in meta!.ticket_priorities" :key="k" :value="k">{{ label }}</option>
                </select>
              </div>
              <div>
                <label class="label" for="assigned">ผู้รับผิดชอบ</label>
                <select id="assigned" v-model="f.assigned_to" class="input">
                  <option value="">-</option>
                  <option v-for="a in admins.filter((x) => x.is_active || x.id === ticket!.assigned_to)" :key="a.id" :value="a.id">{{ a.display_name }}</option>
                </select>
              </div>
            </div>
            <button v-if="f.assigned_to !== auth.admin?.id" type="button" class="text-xs font-medium text-brand-700" @click="f.assigned_to = auth.admin!.id">มอบหมายให้ฉัน</button>
            <div v-if="ticket.type === 'repair'" class="grid grid-cols-2 gap-2">
              <label class="choice py-2 text-sm"><input v-model="f.fix_method" type="radio" value="internal" /> แก้ไขภายใน</label>
              <label class="choice py-2 text-sm"><input v-model="f.fix_method" type="radio" value="outsource" /> ส่งซ่อมภายนอก</label>
            </div>
            <div v-if="f.fix_method === 'outsource'">
              <label class="label" for="vendor">ร้าน / บริษัทที่ส่งซ่อม</label>
              <input id="vendor" v-model="f.outsource_vendor" class="input" />
            </div>
            <div>
              <label class="label" for="resolution">{{ ticket.type === 'repair' ? 'รายละเอียดการแก้ปัญหา' : 'ผลการดำเนินการ' }}</label>
              <textarea id="resolution" v-model="f.resolution" rows="3" :class="['input', errors.resolution && 'input-error']" placeholder="ผู้แจ้งจะเห็นข้อความนี้เมื่อปิดงาน" />
              <p v-if="errors.resolution" class="field-error">{{ errors.resolution }}</p>
            </div>
            <div>
              <label class="label" for="note">เพิ่มบันทึก</label>
              <textarea id="note" v-model="f.note" rows="2" class="input" placeholder="เช่น รออะไหล่ 3 วัน" />
              <label class="mt-1.5 flex items-center gap-2 text-xs text-slate-600">
                <input v-model="f.note_public" type="checkbox" class="accent-brand-700" /> ให้ผู้แจ้งเห็นบันทึกนี้
              </label>
            </div>
            <button class="btn-primary w-full" :disabled="saving" @click="save()">{{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}</button>
            <p v-if="ticket.type === 'repair' && ticket.asset_id && !isClosed" class="text-xs text-slate-500">
              เมื่อปิดงานเป็น "เสร็จสิ้น" ระบบจะบันทึกประวัติซ่อม (BM) ให้อุปกรณ์อัตโนมัติ
            </p>
          </div>
          <dl class="mt-4 space-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500">
            <div class="flex justify-between"><dt>แจ้งเมื่อ</dt><dd>{{ fmtDateTime(ticket.created_at) }}</dd></div>
            <div class="flex justify-between"><dt>เริ่มดำเนินการ</dt><dd>{{ fmtDateTime(ticket.started_at) }}</dd></div>
            <div class="flex justify-between"><dt>แล้วเสร็จ</dt><dd>{{ fmtDateTime(ticket.finished_at) }}</dd></div>
          </dl>
        </section>
      </div>
    </div>

    <div v-if="photoOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 no-print" @click="photoOpen = false">
      <img :src="photoUrl(ticket.id)" alt="รูปที่แนบ" class="max-h-full max-w-full rounded-lg" />
    </div>

    <!-- ===== ฉบับพิมพ์ (ใช้ลงนามตามแบบฟอร์ม FM-IT) ===== -->
    <article class="hidden text-[13px] leading-relaxed text-black print:block">
      <header class="flex items-start justify-between border-b-2 border-black pb-2">
        <div>
          <p class="text-lg font-bold">{{ meta?.ticket_types[ticket.type].label }}</p>
          <p>บริษัท อัตถสาร จำกัด · ฝ่ายเทคโนโลยีสารสนเทศ</p>
        </div>
        <div class="text-right">
          <p>เลขที่ <b class="font-mono">{{ ticket.ticket_no }}</b></p>
          <p>วันที่ {{ fmtDateTime(ticket.created_at) }}</p>
        </div>
      </header>
      <table class="mt-3 w-full border-collapse [&_td]:border [&_td]:border-black [&_td]:px-2 [&_td]:py-1">
        <tbody>
          <tr><td class="w-40 font-semibold">ผู้แจ้ง / ผู้ร้องขอ</td><td>{{ ticket.requester_name }} ({{ ticket.requester_dept }}) {{ ticket.requester_phone }}</td></tr>
          <tr v-if="ticket.asset_code"><td class="font-semibold">รหัสอุปกรณ์</td><td>{{ ticket.asset_code }} {{ ticket.asset_model }}</td></tr>
          <tr v-if="ticket.location"><td class="font-semibold">สถานที่</td><td>{{ ticket.location }}</td></tr>
          <tr v-for="[label, value] in detailRows" :key="label"><td class="font-semibold">{{ label }}</td><td>{{ value }}</td></tr>
          <tr><td class="font-semibold">รายละเอียด</td><td class="whitespace-pre-line">{{ ticket.description }}</td></tr>
          <tr>
            <td class="font-semibold">ผลการดำเนินการ</td>
            <td class="h-20 whitespace-pre-line">
              <template v-if="ticket.fix_method">{{ ticket.fix_method === 'outsource' ? `ส่งซ่อมภายนอก: ${ticket.outsource_vendor ?? ''}` : 'ดำเนินการแก้ไขภายใน (ฝ่าย IT)' }}<br /></template>
              {{ ticket.resolution }}
            </td>
          </tr>
          <tr><td class="font-semibold">ระยะเวลา</td><td>เริ่ม {{ fmtDateTime(ticket.started_at) }} — แล้วเสร็จ {{ fmtDateTime(ticket.finished_at) }}</td></tr>
        </tbody>
      </table>
      <div class="mt-14 grid grid-cols-3 gap-6 text-center">
        <div v-for="s in signatures" :key="s">
          <p>.......................................................</p>
          <p class="mt-1">( ....................................... )</p>
          <p class="mt-1 font-semibold">{{ s }}</p>
          <p class="mt-1">วันที่ ......../......../........</p>
        </div>
      </div>
      <p class="mt-10 text-right text-xs">{{ meta?.ticket_types[ticket.type].form }} · พิมพ์จากระบบ IT Hub</p>
    </article>
  </template>
</template>
