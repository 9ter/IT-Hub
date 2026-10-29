<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { api, ApiError, photoUrl, type AdminRow, type Ticket, type TicketStatus, type Approver, type PurchaseFields, type BorrowItem, type ArchiveStatus } from '@/api'
import Icon from '@/components/Icon.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import ServiceRequestPrint from '@/components/ServiceRequestPrint.vue'
import DocumentPrint from '@/components/DocumentPrint.vue'
import PurchaseSignatures from '@/components/PurchaseSignatures.vue'
import { purchasePrintDocument } from '@/purchasePrint'
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
const sendingApproval = ref(false)
const approvers = ref<Approver[]>([])
const selectedApprover = ref<number | ''>('')
const selectedCompletionApprover = ref<number | ''>('')
const sendingCompletion = ref(false)
const refreshing = ref(false)
const signingIt = ref(false)
const purchaseBusy = ref(false)
const archiveBusy=ref(false)
const archiveLabels:Record<string,string>={pending:'รอส่งเอกสารไป NAS',processing:'กำลังสร้าง PDF / ส่งไป NAS',sent:'ส่งเอกสารไป NAS สำเร็จ',failed:'ส่งเอกสารไป NAS ไม่สำเร็จ',obsolete:'มีเอกสารฉบับใหม่กว่าหรือเปิดงานใหม่แล้ว'}
async function refreshArchive(retry=false){
  if(archiveBusy.value||!ticket.value)return
  archiveBusy.value=true
  const id=props.id
  try{const result=retry?await api.post<ArchiveStatus>(`/admin/tickets/${id}/archive/retry`):((await api.get<Ticket>(`/admin/tickets/${id}`)).nas_archive||null);if(ticket.value?.id===id){ticket.value.nas_archive=result;if(retry)toast('เข้าคิวส่งเอกสารไป NAS ที่ตั้งค่าปัจจุบันแล้ว')}}
  catch(e){toast((e as Error).message,'error')}finally{archiveBusy.value=false}
}
const borrowDeciding = ref(false),borrowNote = ref('')
async function decideBorrow(decision:'approved'|'rejected') {
  if(borrowDeciding.value||saving.value)return
  borrowDeciding.value=true
  const decidingId=props.id
  try {
    const updated=await api.post<Ticket>(`/admin/tickets/${decidingId}/borrow-approval`,{decision,decision_note:borrowNote.value})
    if(props.id===decidingId){fill(updated);borrowNote.value='';toast(decision==='approved'?'อนุมัติและเพิ่มเข้าบันทึก FM-IT-008 แล้ว':'บันทึกผลไม่อนุมัติแล้ว')}
  }catch(e){toast((e as Error).message,'error')}
  finally{borrowDeciding.value=false}
}
const ownSignatureAvailable = computed(() => !!admins.value.find(a => a.id === auth.admin?.id)?.signature_data)
async function signIt() {
  if (signingIt.value) return
  signingIt.value = true
  try {
    ticket.value = await api.post<Ticket>(`/admin/tickets/${props.id}/it-signature`)
    toast('บันทึกลายเซ็นเจ้าหน้าที่ IT สำหรับเอกสารแล้ว')
  } catch(e) { toast((e as Error).message,'error') }
  finally { signingIt.value = false }
}
const completionApproved = computed(() => !!ticket.value?.completion_approval_current && ticket.value.completion_approval?.status === 'approved'
  && (ticket.value.resolution || '') === f.resolution.trim())
async function requestCompletion() {
  if (sendingCompletion.value || saving.value) return
  sendingCompletion.value = true
  errors.value = {}
  try {
    const t = await api.post<Ticket>(`/admin/tickets/${props.id}/completion-approval`, { approver_id: selectedCompletionApprover.value, resolution: f.resolution })
    ticket.value = t
    selectedCompletionApprover.value = t.completion_approval?.employee_id ?? t.default_completion_approver_id ?? ''
    f.resolution = t.resolution || ''
    if (t.completion_approval?.mail_status === 'sent') toast('ส่งอีเมลขอลายเซ็น ผอ./ผจก. ก่อนปิดงานแล้ว')
    else toast(t.completion_approval?.mail_error || 'บันทึกผลแล้ว แต่ส่งอีเมลไม่สำเร็จ', 'error')
  } catch(e) { if (e instanceof ApiError) errors.value = e.errors; toast((e as Error).message,'error') }
  finally { sendingCompletion.value = false }
}
async function refreshApproval() {
  refreshing.value = true
  try {
    const previous = ticket.value
    const updated = await api.get<Ticket>(`/admin/tickets/${props.id}`)
    if (f.status === previous?.status) f.status = updated.status
    if (f.resolution === (previous?.resolution || '')) f.resolution = updated.resolution || ''
    ticket.value = updated
  }
  catch(e) { toast((e as Error).message,'error') }
  finally { refreshing.value = false }
}
async function resendApproval() {
  sendingApproval.value = true
  try {
    fill(await api.post<Ticket>(`/admin/tickets/${props.id}/approval/resend`, { approver_id: selectedApprover.value }))
    if (ticket.value?.approval?.mail_status === 'sent') toast('ส่งอีเมลขอลงนามใหม่แล้ว ลิงก์เดิมถูกยกเลิก')
    else toast(ticket.value?.approval?.mail_error || 'ส่งอีเมลไม่สำเร็จ', 'error')
  } catch(e) { toast((e as Error).message, 'error') }
  finally { sendingApproval.value = false }
}

const f = reactive({
  status: 'new' as TicketStatus,
  priority: 'normal',
  assigned_to: '' as string | number,
  resolution: '',
  fix_method: '',
  outsource_vendor: '',
  note: '',
  note_public: true,
  purchase_fields: {vendor:'',vendor_phone:'',quotation:'',quotation_date:'',amount:'',vat:''} as PurchaseFields,
})

function fill(t: Ticket) {
  ticket.value = t
  selectedApprover.value = t.approval?.employee_id ?? ''
  selectedCompletionApprover.value = t.completion_approval?.employee_id ?? t.default_completion_approver_id ?? ''
  Object.assign(f, {
    status: t.status,
    priority: t.priority,
    assigned_to: t.assigned_to ?? '',
    resolution: t.resolution ?? '',
    fix_method: t.fix_method ?? '',
    outsource_vendor: t.outsource_vendor ?? '',
    note: '',
    note_public: true,
    purchase_fields: {...(t.purchase_fields||{vendor:'',vendor_phone:'',quotation:'',quotation_date:'',amount:'',vat:''})},
  })
}

watch(
  () => props.id,
  async (id) => {
    error.value = ''
    borrowNote.value = ''
    try {
      fill(await api.get<Ticket>(`/admin/tickets/${id}`))
    } catch (e) {
      error.value = (e as Error).message
    }
  },
  { immediate: true },
)
api.get<AdminRow[]>('/admin/admins').then((a) => (admins.value = a))
api.get<Approver[]>('/approvers').then((a) => (approvers.value = a)).catch(() => {})

async function save(overrides: Partial<typeof f> = {}) {
  if(purchaseBusy.value||borrowDeciding.value)return
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
      ...(ticket.value?.type==='purchase'?{purchase_fields:f.purchase_fields,work_revision:ticket.value.completion_revision}:{}),
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

function applyPurchaseUpdate(t:Ticket,preserveDraft=false){
  if(f.status===ticket.value?.status)f.status=t.status
  ticket.value=t
  if(!preserveDraft){f.resolution=t.resolution||'';f.purchase_fields={...(t.purchase_fields||{vendor:'',vendor_phone:'',quotation:'',quotation_date:'',amount:'',vat:''})}}
}

// ปุ่มลัดตามสถานะปัจจุบัน
const approvalBlocked = computed(() => (!!ticket.value?.approval && ticket.value.approval.status !== 'approved') || (ticket.value?.type==='borrow'&&ticket.value.borrow_approval?.status!=='approved'))
const quick = computed(() => {
  if (approvalBlocked.value) return []
  const s = ticket.value?.status
  const list: { label: string; status: TicketStatus; cls: string }[] = []
  if (s === 'new') list.push({ label: 'รับเรื่อง', status: 'accepted', cls: 'btn-primary' })
  if (s === 'new' || s === 'accepted' || s === 'waiting') list.push({ label: 'เริ่มดำเนินการ', status: 'in_progress', cls: s === 'new' ? 'btn-secondary' : 'btn-primary' })
  if (ticket.value?.completion_required && completionApproved.value && !['done','rejected','cancelled'].includes(s || '')) list.push({ label: 'ปิดงานเป็นเสร็จสิ้น', status: 'done', cls: 'btn-primary' })
  return list
})

const isClosed = computed(() => ['done', 'rejected', 'cancelled'].includes(ticket.value?.status ?? ''))
const borrowItems=computed<BorrowItem[]>(()=>{
  if(ticket.value?.type!=='borrow')return []
  const d=ticket.value.details
  return Array.isArray(d.borrow_items)?d.borrow_items as BorrowItem[]:[{device_kind:d.device_kind as BorrowItem['device_kind'],item:String(d.item||''),quantity:Number(d.quantity||1)}]
})

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
        ['รายการอุปกรณ์', `${borrowItems.value.length} รายการ`],
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

const servicePrint = ref<InstanceType<typeof ServiceRequestPrint> | null>(null)
const purchasePrint = ref<InstanceType<typeof DocumentPrint> | null>(null)
const purchaseDocument = computed(()=>ticket.value?.type==='purchase'?purchasePrintDocument(ticket.value):null)
const approvalLabel = computed(()=>ticket.value?.type==='purchase'?'ผู้มีอำนาจอนุมัติต้นสังกัด (หน./ผจก.)':'หัวหน้าฝ่าย / ผู้อนุมัติ')
async function printForm() {
  await servicePrint.value?.prepare()
  await purchasePrint.value?.prepare()
  window.print()
}

const signatures = computed(() =>
  ticket.value?.type === 'repair'
    ? ['ผู้แจ้งซ่อม / ผู้ตรวจรับงาน', 'เจ้าหน้าที่ฝ่ายเทคโนโลยีสารสนเทศ', 'ผอ./ผจก. ฝ่ายสนับสนุนและติดตั้ง']
    : ticket.value?.type === 'service'
    ? ['ผู้ร้องขอ', 'หัวหน้าฝ่าย / ผู้อนุมัติ', 'เจ้าหน้าที่เทคโนโลยีสารสนเทศ', 'ผอ./ผจก. ฝ่ายสนับสนุนและติดตั้ง', 'ผู้ช่วยกรรมการผู้จัดการ']
    : ['ผู้ร้องขอ', 'หัวหน้าฝ่าย / ผู้อนุมัติ', 'เจ้าหน้าที่ฝ่ายเทคโนโลยีสารสนเทศ'],
)
</script>

<template>
  <p v-if="error" class="rounded-xl bg-red-50 dark:bg-red-900/40 p-4 text-red-700 dark:text-red-300">{{ error }}</p>
  <div v-else-if="!ticket" class="card h-64 animate-pulse" />

  <template v-else>
    <div class="flex flex-wrap items-center gap-3 no-print">
      <RouterLink :to="{ name: 'tickets' }" class="btn-ghost -ml-2 p-2" aria-label="กลับ"><Icon name="back" /></RouterLink>
      <span :class="['grid size-10 place-items-center rounded-xl', typeInfo[ticket.type].tone]"><Icon :name="typeInfo[ticket.type].icon" /></span>
      <div class="min-w-0 flex-1">
        <p class="font-mono text-sm text-slate-500 dark:text-slate-400">{{ ticket.ticket_no }} · {{ meta?.ticket_types[ticket.type].label }}</p>
        <h1 class="text-xl font-bold">{{ ticket.title }}</h1>
      </div>
      <div class="flex w-full items-center justify-end gap-3 sm:w-auto">
        <StatusBadge :status="ticket.status" />
        <button v-if="ticket.type!=='borrow'" class="btn-secondary" @click="printForm"><Icon name="printer" :size="16" /> พิมพ์ฟอร์ม</button>
        <RouterLink v-if="ticket.type!=='borrow'" :to="{name:'document-new',query:{form:meta?.ticket_types[ticket.type].form,ticket:String(ticket.id)}}" class="btn-secondary">จัดทำเอกสาร FM-IT</RouterLink>
        <RouterLink v-else-if="ticket.borrow_approval?.document_id" :to="{name:'document-detail',params:{id:ticket.borrow_approval.document_id}}" class="btn-secondary">เปิดบันทึก / พิมพ์ FM-IT-008</RouterLink>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3 no-print">
      <div class="min-w-0 space-y-6 lg:col-span-2">
        <section v-if="ticket.status==='done'||ticket.nas_archive" class="card space-y-3 p-5">
          <h2 class="font-semibold">ไฟล์เอกสารบน NAS</h2><p class="font-medium">{{archiveLabels[ticket.nas_archive?.status||'']||'ยังไม่ได้เข้าคิวส่งเอกสาร'}}</p>
          <p v-if="ticket.nas_archive?.filename" class="break-all font-mono text-sm">{{ticket.nas_archive.relative_path||ticket.nas_archive.filename}}</p><p v-if="ticket.nas_archive?.sent_at" class="text-sm text-slate-500 dark:text-slate-400">ส่งเมื่อ {{fmtDateTime(ticket.nas_archive.sent_at)}}</p>
          <p v-if="ticket.nas_archive?.last_error" class="text-sm text-red-700 dark:text-red-300">{{ticket.nas_archive.last_error}}</p>
          <p v-if="ticket.nas_archive&&['pending','failed'].includes(ticket.nas_archive.status)" class="text-sm text-slate-500 dark:text-slate-400">ระบบส่งเอกสารอัตโนมัติและลองใหม่เมื่อเชื่อมต่อไม่ได้ การปิดงานถูกบันทึกแล้ว</p>
          <div class="flex flex-wrap gap-3"><button class="btn-secondary" :disabled="archiveBusy" @click="refreshArchive()">ตรวจสถานะการส่ง</button><button v-if="ticket.status==='done'&&(!ticket.nas_archive||['failed','obsolete'].includes(ticket.nas_archive.status))" class="btn-secondary" :disabled="archiveBusy" @click="refreshArchive(true)">ส่งเอกสารไป NAS ปัจจุบันอีกครั้ง</button><RouterLink to="/admin/nas" class="btn-ghost">ตั้งค่า Map NAS</RouterLink></div>
        </section>
        <section v-if="ticket.type==='borrow'&&ticket.borrow_approval" class="card space-y-3 p-5">
          <h2 class="font-semibold">Admin IT Hub อนุมัติการเบิก–ยืม</h2>
          <p class="font-medium text-brand-700 dark:text-brand-300">{{ticket.status==='cancelled'?'ยกเลิกคำขอแล้ว':{pending:'รอ Admin IT Hub อนุมัติ',approved:'อนุมัติและเพิ่มเข้าบันทึกแล้ว',rejected:'ไม่อนุมัติ'}[ticket.borrow_approval.status]}}</p>
          <template v-if="ticket.borrow_approval.status==='pending'&&!isClosed">
            <p class="text-sm text-slate-600 dark:text-slate-300">ตรวจสอบผู้ยืม อุปกรณ์ จำนวน และช่วงเวลา เมื่ออนุมัติระบบจะเพิ่มเข้าบันทึก FM-IT-008 โดยอัตโนมัติ</p>
            <label for="borrow-decision-note" class="label">หมายเหตุ / เหตุผลที่ไม่อนุมัติ (ผู้ร้องขอเห็น)</label>
            <textarea id="borrow-decision-note" v-model="borrowNote" class="input" rows="3" maxlength="2000" />
            <div class="flex flex-wrap gap-3"><button class="btn-primary" :disabled="borrowDeciding||saving" @click="decideBorrow('approved')">{{borrowDeciding?'กำลังบันทึก...':'อนุมัติและเพิ่มเข้าบันทึก FM-IT-008'}}</button><button class="btn-secondary text-red-700 dark:text-red-300" :disabled="borrowDeciding||saving||!borrowNote.trim()" @click="decideBorrow('rejected')">ไม่อนุมัติการเบิก–ยืม</button></div>
            <p class="text-xs text-slate-500 dark:text-slate-400">หากไม่อนุมัติ กรุณาระบุเหตุผล คำขอนี้จะไม่เข้าบันทึก</p>
          </template>
          <template v-else>
            <p v-if="ticket.borrow_approval.admin_name" class="text-sm">ผู้พิจารณา {{ticket.borrow_approval.admin_name}} · {{fmtDateTime(ticket.borrow_approval.decided_at)}}</p>
            <p v-if="ticket.borrow_approval.decision_note" class="whitespace-pre-wrap text-sm">{{ticket.borrow_approval.decision_note}}</p>
            <RouterLink v-if="ticket.borrow_approval.document_id" :to="{name:'document-detail',params:{id:ticket.borrow_approval.document_id}}" class="inline-block text-sm font-medium text-brand-700 dark:text-brand-300">{{ticket.borrow_approval.document_no}} · เปิดบันทึกเพื่อจัดการคืนอุปกรณ์</RouterLink>
          </template>
        </section>
        <section v-if="ticket.approval" class="card space-y-3 p-5">
          <h2 class="font-semibold">{{approvalLabel}}</h2>
          <p>{{ ticket.approval.approver_name }} · {{ ticket.approval.approver_email }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400">{{ ticket.approval.approver_company }} · {{ ticket.approval.approver_department }}</p>
          <p class="font-medium text-brand-700 dark:text-brand-300">{{ { pending: 'รอการลงนามอนุมัติ', approved: 'ลงนามอนุมัติแล้ว', rejected: 'ไม่อนุมัติ' }[ticket.approval.status] }}</p>
          <template v-if="ticket.approval.status === 'pending'">
            <p class="text-sm">อีเมล: {{ { pending: 'รอส่ง', sent: 'ส่งแล้ว', failed: 'ส่งไม่สำเร็จ' }[ticket.approval.mail_status] }}<span v-if="ticket.approval.mail_sent_at"> · {{ fmtDateTime(ticket.approval.mail_sent_at) }}</span></p>
            <p v-if="ticket.approval.mail_error" class="text-sm text-red-700 dark:text-red-300">{{ ticket.approval.mail_error }}</p>
            <p v-if="ticket.approval.expires_at" class="text-xs text-slate-500 dark:text-slate-400">ลิงก์หมดอายุ {{ fmtDateTime(ticket.approval.expires_at) }}</p>
            <label for="resend_approver" class="label">ผู้รับอีเมลขอลงนามใหม่</label>
            <select id="resend_approver" v-model="selectedApprover" class="input">
              <option value="">ใช้ผู้อนุมัติเดิม</option>
              <option v-for="a in approvers" :key="a.id" :value="a.id">{{ a.full_name }} · {{ a.department || '—' }} · {{ (a.companies || [a.company]).join(' / ') }}</option>
            </select>
            <button v-if="!['done','rejected','cancelled'].includes(ticket.status)" class="btn-secondary" :disabled="sendingApproval" @click="resendApproval">{{ sendingApproval ? 'กำลังส่ง...' : 'ส่งอีเมลขอลงนามใหม่' }}</button>
          </template>
          <template v-else>
            <img v-if="ticket.approval.signature_data" :src="ticket.approval.signature_data" alt="ลายเซ็นผู้อนุมัติ" class="bg-white rounded h-24 max-w-full object-contain" />
            <p class="text-sm">ลงนามเมื่อ {{ fmtDateTime(ticket.approval.signed_at) }}</p>
            <p v-if="ticket.approval.decision_note" class="whitespace-pre-wrap text-sm">หมายเหตุ: {{ ticket.approval.decision_note }}</p>
          </template>
        </section>
        <section v-if="ticket.completion_required && (!isClosed || ticket.completion_approval)" class="card space-y-3 p-5">
          <h2 class="font-semibold">ผอ./ผจก. รับรองผลก่อนปิดงาน</h2>
          <p v-if="approvalBlocked" class="text-sm text-amber-800 dark:text-amber-300">รอหัวหน้าฝ่ายลงนามอนุมัติก่อนขอรับรองผลการดำเนินการ</p>
          <p v-else class="text-sm text-slate-600 dark:text-slate-300">กรอกผลการดำเนินการ เลือกผู้รับรอง แล้วส่งอีเมลขอลายเซ็น เมื่อรับรองผลแล้ว IT จึงปิดงานเป็นเสร็จสิ้นได้</p>
          <template v-if="ticket.completion_approval">
            <p class="font-medium">{{ ticket.completion_approval.approver_name }} · {{ ticket.completion_approval.approver_email }}</p>
            <p v-if="!ticket.completion_approval_current || (ticket.resolution || '') !== f.resolution.trim()" class="rounded-lg bg-amber-50 dark:bg-amber-900/40 p-3 text-sm text-amber-800 dark:text-amber-300">ผลการดำเนินการมีการแก้ไข ต้องส่งขอลายเซ็นรับรองใหม่ก่อนปิดงาน</p>
            <p v-else class="font-medium text-brand-700 dark:text-brand-300">{{ {pending:'รอ ผอ./ผจก. ลงนามรับรองผล',approved:'ลงนามรับรองผลแล้ว พร้อมปิดงาน',rejected:'ไม่รับรองผล กรุณาตรวจสอบและส่งใหม่'}[ticket.completion_approval.status] }}</p>
            <p v-if="ticket.completion_approval.mail_status === 'failed'" class="text-sm text-red-700 dark:text-red-300">{{ ticket.completion_approval.mail_error }}</p>
            <p v-else-if="ticket.completion_approval.mail_sent_at" class="text-xs text-slate-500 dark:text-slate-400">ส่งอีเมลเมื่อ {{ fmtDateTime(ticket.completion_approval.mail_sent_at) }} · ลิงก์หมดอายุ {{ fmtDateTime(ticket.completion_approval.expires_at) }}</p>
            <img v-if="ticket.completion_approval.signature_data" :src="ticket.completion_approval.signature_data" alt="ลายเซ็น ผอ./ผจก." class="bg-white rounded h-24 max-w-full object-contain" />
            <p v-if="ticket.completion_approval.signed_at" class="text-sm">ลงนามเมื่อ {{ fmtDateTime(ticket.completion_approval.signed_at) }}</p>
            <p v-if="ticket.completion_approval.decision_note" class="whitespace-pre-wrap text-sm">หมายเหตุ: {{ ticket.completion_approval.decision_note }}</p>
            <details class="text-sm"><summary class="cursor-pointer text-slate-500 dark:text-slate-400">ผลที่ส่งขอรับรอง</summary><p class="mt-2 whitespace-pre-wrap">{{ ticket.completion_approval.resolution_snapshot }}</p></details>
          </template>
          <template v-if="!isClosed && !completionApproved">
            <label for="completion_approver" class="label">ผอ./ผจก. ผู้รับรองผล</label>
            <select id="completion_approver" v-model="selectedCompletionApprover" class="input" :disabled="approvalBlocked || sendingCompletion">
              <option value="" disabled>เลือกผู้รับรองผล</option>
              <option v-for="a in approvers" :key="a.id" :value="a.id">{{ a.full_name }} · {{ a.department || '—' }} · {{ (a.companies || [a.company]).join(' / ') }}</option>
            </select>
            <p v-if="errors.approver_id" class="field-error">{{ errors.approver_id }}</p>
            <p v-if="!f.resolution.trim()" class="text-xs text-slate-500 dark:text-slate-400">กรุณากรอกช่อง “ผลการดำเนินการ” ก่อนส่งขอลายเซ็น</p>
            <button class="btn-primary" :disabled="approvalBlocked || sendingCompletion || saving || !selectedCompletionApprover || !f.resolution.trim()" @click="requestCompletion">{{ sendingCompletion ? 'กำลังส่ง...' : ticket.completion_approval?.status === 'pending' && ticket.completion_approval_current && (ticket.resolution || '') === f.resolution.trim() ? 'ส่งอีเมลขอลายเซ็นใหม่' : 'บันทึกผลและขอลายเซ็น ผอ./ผจก.' }}</button>
          </template>
          <button class="btn-secondary" :disabled="refreshing || sendingCompletion || saving" @click="refreshApproval">{{ refreshing ? 'กำลังตรวจสอบ...' : 'ตรวจสถานะลายเซ็น' }}</button>
        </section>
        <PurchaseSignatures v-if="ticket.type==='purchase'" v-model="f.purchase_fields" :ticket="ticket" :resolution="f.resolution" :approvers="approvers" :own-signature-available="ownSignatureAvailable" :busy="saving||sendingCompletion" @updated="applyPurchaseUpdate" @busy="purchaseBusy=$event" />
        <section v-else-if="ticket.type!=='borrow'" class="card space-y-3 p-5">
          <h2 class="font-semibold">เจ้าหน้าที่เทคโนโลยีสารสนเทศ</h2>
          <template v-if="ticket.it_signature">
            <img :src="ticket.it_signature.signature_data" alt="ลายเซ็นเจ้าหน้าที่ IT" class="bg-white rounded h-24 max-w-full object-contain" />
            <p class="font-medium">{{ ticket.it_signature.signer_name }}</p>
            <p class="text-sm text-slate-500 dark:text-slate-400">ลงนามเมื่อ {{ fmtDateTime(ticket.it_signature.signed_at) }}</p>
          </template>
          <template v-else>
            <p class="text-sm text-slate-600 dark:text-slate-300">ระบบใช้ลายเซ็นประจำบัญชีของเจ้าหน้าที่ที่ส่งผลดำเนินการหรือปิดงาน และบันทึกไว้ในเอกสารของใบงานนี้</p>
            <RouterLink to="/admin/admins" class="inline-block text-sm font-medium text-brand-700 dark:text-brand-300">{{ ownSignatureAvailable ? 'จัดการลายเซ็นในหน้าผู้ดูแลระบบ' : 'เพิ่มลายเซ็นของฉันในหน้าผู้ดูแลระบบ' }}</RouterLink>
            <p v-if="(ticket.resolution || '') !== f.resolution.trim() || !ticket.resolution" class="text-xs text-slate-500 dark:text-slate-400">กรุณาบันทึกผลการดำเนินการก่อนลงนามในใบงานนี้</p>
            <button v-if="ownSignatureAvailable && !['rejected','cancelled'].includes(ticket.status)" class="btn-secondary" :disabled="signingIt || saving || sendingCompletion || approvalBlocked || !ticket.resolution || (ticket.resolution || '') !== f.resolution.trim()" @click="signIt">{{ signingIt ? 'กำลังลงนาม...' : 'ลงนามเจ้าหน้าที่ IT ด้วยลายเซ็นของฉัน' }}</button>
          </template>
        </section>
        <section class="card p-5">
          <h2 class="font-semibold">รายละเอียด</h2>
          <p class="mt-3 whitespace-pre-line text-slate-800 dark:text-slate-200">{{ ticket.description }}</p>
          <div v-if="ticket.type==='borrow'" class="table-wrap mt-4"><table class="table"><thead><tr><th>#</th><th>ประเภท</th><th>อุปกรณ์ที่ขอเบิก–ยืม</th><th>จำนวน</th></tr></thead><tbody><tr v-for="(item,i) in borrowItems" :key="i"><td>{{i+1}}</td><td>{{item.device_kind==='notebook'?'Notebook':'อื่นๆ'}}</td><td class="whitespace-pre-wrap break-words">{{item.item}}</td><td>{{item.quantity}}</td></tr></tbody></table></div>
          <dl v-if="detailRows.length" class="mt-4 grid gap-x-6 gap-y-3 border-t border-slate-100 dark:border-slate-700 pt-4 text-sm sm:grid-cols-2">
            <div v-for="[label, value] in detailRows" :key="label">
              <dt class="text-slate-500 dark:text-slate-400">{{ label }}</dt>
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
              <span :class="['mt-1.5 size-2.5 shrink-0 rounded-full', log.to_status ? 'bg-brand-600' : 'bg-slate-300 dark:bg-slate-600']" />
              <div class="min-w-0 flex-1 text-sm">
                <p class="text-xs text-slate-500 dark:text-slate-400">
                  {{ fmtDateTime(log.created_at) }} · {{ log.admin_name ?? 'ผู้แจ้ง' }}
                  <span v-if="!log.is_public" class="ml-1 rounded bg-slate-100 dark:bg-slate-900 px-1.5 py-0.5 text-[11px] font-medium text-slate-600 dark:text-slate-300">ภายใน</span>
                </p>
                <p v-if="log.to_status" class="font-medium">
                  <template v-if="log.from_status">{{ meta?.ticket_statuses[log.from_status] }} → </template>{{ meta?.ticket_statuses[log.to_status] }}
                </p>
                <p v-if="log.note" class="whitespace-pre-line text-slate-700 dark:text-slate-200">{{ log.note }}</p>
              </div>
            </li>
          </ol>
        </section>
      </div>

      <div class="min-w-0 space-y-6">
        <section class="card p-5 text-sm">
          <h2 class="font-semibold">ผู้แจ้ง</h2>
          <dl class="mt-3 space-y-2">
            <div class="flex gap-2"><Icon name="user" :size="16" class="mt-0.5 text-slate-400" /><dd>{{ ticket.requester_name }}</dd></div>
            <div class="flex gap-2"><Icon name="building" :size="16" class="mt-0.5 text-slate-400" /><dd>{{ ticket.requester_dept ?? '-' }}</dd></div>
            <div v-if="ticket.requester_phone" class="flex gap-2">
              <Icon name="device" :size="16" class="mt-0.5 text-slate-400" /><a :href="`tel:${ticket.requester_phone}`" class="text-brand-700 dark:text-brand-300">{{ ticket.requester_phone }}</a>
            </div>
            <div v-if="ticket.requester_email" class="flex gap-2">
              <Icon name="mail" :size="16" class="mt-0.5 text-slate-400" /><a :href="`mailto:${ticket.requester_email}`" class="break-all text-brand-700 dark:text-brand-300">{{ ticket.requester_email }}</a>
            </div>
            <div v-if="ticket.location" class="flex gap-2"><Icon name="pin" :size="16" class="mt-0.5 text-slate-400" /><dd>{{ ticket.location }}</dd></div>
          </dl>
          <div v-if="ticket.requester_signature" class="mt-4 border-t border-slate-100 dark:border-slate-700 pt-3">
            <p class="font-medium">ลายเซ็นผู้ร้องขอ</p>
            <img :src="ticket.requester_signature.signature_data" alt="ลายเซ็นผู้ร้องขอ" class="bg-white rounded h-20 max-w-full object-contain" />
            <p>{{ ticket.requester_signature.requester_name }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">ลงนามเมื่อ {{ fmtDateTime(ticket.requester_signature.signed_at) }}</p>
          </div>
          <RouterLink v-if="ticket.asset_id" :to="{ name: 'asset-detail', params: { id: ticket.asset_id } }" class="mt-4 flex items-center gap-3 rounded-xl bg-slate-50 dark:bg-slate-900 p-3 hover:bg-slate-100 dark:hover:bg-slate-900">
            <Icon name="desktop" :size="18" class="text-slate-500 dark:text-slate-400" />
            <div class="min-w-0">
              <p class="font-mono font-semibold">{{ ticket.asset_code }}</p>
              <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ ticket.asset_model }}</p>
            </div>
          </RouterLink>
        </section>

        <section class="card p-5">
          <h2 class="font-semibold">จัดการ</h2>
          <p v-if="approvalBlocked" class="mt-3 rounded-lg bg-amber-50 dark:bg-amber-900/40 p-3 text-sm text-amber-800 dark:text-amber-300">{{ticket.type==='borrow'?'ต้องให้ Admin IT Hub อนุมัติการเบิก–ยืมก่อน ระบบจึงจะเพิ่มเข้าบันทึกและเริ่มดำเนินการได้':'ต้องได้รับการลงนามอนุมัติก่อนรับเรื่อง เริ่มดำเนินการ หรือปิดงาน'}}</p>
          <div v-if="quick.length" class="mt-3 flex gap-2">
            <button v-for="q in quick" :key="q.status" :class="[q.cls, 'flex-1']" :disabled="saving||purchaseBusy" @click="save({ status: q.status })">{{ q.label }}</button>
          </div>
          <div class="mt-4 space-y-3">
            <div>
              <label class="label" for="status">สถานะ</label>
              <select id="status" v-model="f.status" class="input">
                <option v-for="(label, k) in meta!.ticket_statuses" :key="k" :value="k" :disabled="(approvalBlocked && ['accepted','in_progress','waiting','done'].includes(k)) || (ticket.type==='borrow'&&approvalBlocked&&k==='rejected') || (k === 'done' && ticket.completion_required && !completionApproved && ticket.status !== 'done')">{{ label }}</option>
              </select>
              <p v-if="errors.status" class="field-error">{{ errors.status }}</p>
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
            <button v-if="f.assigned_to !== auth.admin?.id" type="button" class="text-xs font-medium text-brand-700 dark:text-brand-300" @click="f.assigned_to = auth.admin!.id">มอบหมายให้ฉัน</button>
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
              <label class="mt-1.5 flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                <input v-model="f.note_public" type="checkbox" class="accent-brand-700" /> ให้ผู้แจ้งเห็นบันทึกนี้
              </label>
            </div>
            <p v-if="ticket.completion_required && !completionApproved && !isClosed" class="text-xs text-amber-800 dark:text-amber-300">ต้องมีลายเซ็น ผอ./ผจก. รับรองผลล่าสุดก่อนปิดงาน</p>
            <button class="btn-primary w-full" :disabled="saving || sendingCompletion || purchaseBusy || borrowDeciding" @click="save()">{{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}</button>
            <p v-if="ticket.type === 'repair' && ticket.asset_id && !isClosed" class="text-xs text-slate-500 dark:text-slate-400">
              เมื่อปิดงานเป็น "เสร็จสิ้น" ระบบจะบันทึกประวัติซ่อม (BM) ให้อุปกรณ์อัตโนมัติ
            </p>
          </div>
          <dl class="mt-4 space-y-1 border-t border-slate-100 dark:border-slate-700 pt-3 text-xs text-slate-500 dark:text-slate-400">
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
    <ServiceRequestPrint v-if="ticket.type === 'service'" ref="servicePrint" :ticket="ticket" />
    <DocumentPrint v-else-if="ticket.type==='purchase'&&ticket.print_form&&purchaseDocument" ref="purchasePrint" :document="purchaseDocument" :definition="ticket.print_form" :status-label="approvalBlocked?'รออนุมัติต้นสังกัด':meta?.ticket_statuses[ticket.status]" />
    <article v-else class="hidden text-[13px] leading-relaxed text-black print:block">
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
          <template v-if="ticket.type==='borrow'"><tr v-for="(item,i) in borrowItems" :key="`borrow-${i}`"><td class="font-semibold">อุปกรณ์รายการที่ {{i+1}}</td><td>{{item.device_kind==='notebook'?'Notebook':'อื่นๆ'}} · {{item.item}} · จำนวน {{item.quantity}}</td></tr></template>
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
      <div class="mt-8 grid grid-cols-3 gap-x-6 gap-y-4 text-center">
        <div v-for="s in signatures" :key="s" class="break-inside-avoid">
          <template v-if="s === 'ผู้ร้องขอ'">
            <img v-if="ticket.requester_signature" :src="ticket.requester_signature.signature_data" alt="ลายเซ็นผู้ร้องขอ" class="bg-white rounded mx-auto h-16 max-w-full object-contain" />
            <p v-else>.......................................................</p>
            <p class="mt-1">({{ ticket.requester_signature?.requester_name || ticket.requester_name }})</p>
            <p class="mt-1 font-semibold">{{ s }}</p>
            <p v-if="ticket.requester_signature">ลงนาม · {{ fmtDateTime(ticket.requester_signature.signed_at) }}</p>
            <p v-else class="mt-1">วันที่ ......../......../........</p>
          </template>
          <template v-else-if="s === 'หัวหน้าฝ่าย / ผู้อนุมัติ' && ticket.approval?.signature_data">
            <img :src="ticket.approval.signature_data" alt="ลายเซ็นผู้อนุมัติ" class="bg-white rounded mx-auto h-16 max-w-full object-contain" />
            <p>({{ ticket.approval.approver_name }})</p>
            <p class="mt-1 font-semibold">{{ s }}</p>
            <p>{{ ticket.approval.status === 'approved' ? 'อนุมัติ' : 'ไม่อนุมัติ' }} · {{ fmtDateTime(ticket.approval.signed_at) }}</p>
          </template>
          <template v-else-if="s === 'ผอ./ผจก. ฝ่ายสนับสนุนและติดตั้ง' && ticket.completion_approval_current && ticket.completion_approval?.signature_data">
            <img :src="ticket.completion_approval.signature_data" alt="ลายเซ็น ผอ./ผจก." class="bg-white rounded mx-auto h-16 max-w-full object-contain" />
            <p>({{ ticket.completion_approval.approver_name }})</p>
            <p class="mt-1 font-semibold">{{ s }}</p>
            <p>{{ ticket.completion_approval.status === 'approved' ? 'รับรองผล' : 'ไม่รับรองผล' }} · {{ fmtDateTime(ticket.completion_approval.signed_at) }}</p>
          </template>
          <template v-else-if="(s === 'เจ้าหน้าที่เทคโนโลยีสารสนเทศ' || s === 'เจ้าหน้าที่ฝ่ายเทคโนโลยีสารสนเทศ') && ticket.it_signature">
            <img :src="ticket.it_signature.signature_data" alt="ลายเซ็นเจ้าหน้าที่ IT" class="bg-white rounded mx-auto h-16 max-w-full object-contain" />
            <p>({{ ticket.it_signature.signer_name }})</p>
            <p class="mt-1 font-semibold">{{ s }}</p>
            <p>ลงนาม · {{ fmtDateTime(ticket.it_signature.signed_at) }}</p>
          </template>
          <template v-else>
          <p>.......................................................</p>
          <p v-if="s === 'ผอ./ผจก. ฝ่ายสนับสนุนและติดตั้ง' && ticket.completion_approval" class="mt-1">({{ ticket.completion_approval.approver_name }})</p>
          <p v-else-if="s === 'หัวหน้าฝ่าย / ผู้อนุมัติ' && (ticket.approval?.approver_name || ticket.details.approver_name)" class="mt-1">({{ ticket.approval?.approver_name || ticket.details.approver_name }})</p>
          <p v-else-if="(s === 'เจ้าหน้าที่เทคโนโลยีสารสนเทศ' || s === 'เจ้าหน้าที่ฝ่ายเทคโนโลยีสารสนเทศ') && ticket.assigned_name" class="mt-1">({{ ticket.assigned_name }})</p>
          <p v-else class="mt-1">( ....................................... )</p>
          <p class="mt-1 font-semibold">{{ s }}</p>
          <p class="mt-1">วันที่ ......../......../........</p>
          </template>
        </div>
      </div>
      <p v-if="ticket.approval?.decision_note" class="mt-5 whitespace-pre-wrap text-xs">หมายเหตุผู้อนุมัติ: {{ ticket.approval.decision_note }}</p>
      <p v-if="ticket.completion_approval_current && ticket.completion_approval?.decision_note" class="mt-3 whitespace-pre-wrap text-xs">หมายเหตุ ผอ./ผจก.: {{ ticket.completion_approval.decision_note }}</p>
      <p class="mt-10 text-right text-xs">{{ meta?.ticket_types[ticket.type].form }} · พิมพ์จากระบบ IT Hub</p>
    </article>
  </template>
</template>
