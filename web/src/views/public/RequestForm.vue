<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError, type PublicAsset, type TicketType, type Approver } from '@/api'
import Icon from '@/components/Icon.vue'
import PublicShell from '@/components/PublicShell.vue'
import SignaturePad from '@/components/SignaturePad.vue'
import { nowLocalInput, todayInput } from '@/format'
import { meta, rememberTicket, savedRequester, saveRequester } from '@/store'
import { typeInfo } from '@/ticketTypes'

const props = defineProps<{ type: string }>()
const route = useRoute()

const type = computed(() => props.type as TicketType)
const def = computed(() => meta.value!.ticket_types[type.value])
const requiresApproval = computed(() => type.value==='service'||type.value==='purchase')
const approvalLabel = computed(() => type.value==='purchase'?'ผู้มีอำนาจอนุมัติต้นสังกัด (หน./ผจก.)':'หัวหน้าฝ่าย / ผู้อนุมัติ')

const remembered = savedRequester()
const remember = ref(true)
const asset = ref<PublicAsset | null>(null)
const photo = ref<File | null>(null)
const photoPreview = ref('')
const errors = ref<Record<string, string>>({})
const formError = ref('')
const submitting = ref(false)
const result = ref<{ ticket_no: string; title: string; mail_status?: string } | null>(null)
const approvers = ref<Approver[]>([])
const approverError = ref('')
async function loadApprovers() {
  approverError.value = ''
  try { approvers.value = await api.get<Approver[]>('/approvers') }
  catch (e) { approverError.value = (e as Error).message }
}
watch(type, (t) => { if (t === 'service'||t==='purchase') loadApprovers() }, { immediate: true })
const approverCompanyLabel = (a: Approver) => (a.companies || [a.company]).join(' / ')
const approverCompanies = computed(() => [...new Set(approvers.value.map(approverCompanyLabel))])
const copied = ref(false)

const f = reactive({
  requester_name: remembered.requester_name ?? '',
  requester_dept: remembered.requester_dept ?? '',
  requester_phone: remembered.requester_phone ?? '',
  requester_email: remembered.requester_email ?? '',
  requester_signature_data: '',
  location: '',
  description: '',
  asset_code: '',
  // repair
  job_type: 'repair',
  equipment: '',
  found_at: nowLocalInput(),
  // service / purchase / email
  position: remembered.position ?? '',
  device_kind: '',
  actions: [] as string[],
  os_target: '',
  other_text: '',
  approver_name: '',
  approver_id: '',
  // email
  name_th: '',
  name_en: '',
  company: 'ats',
  desired_email: '',
  // borrow
  borrow_items: [{device_kind:'',item:'',quantity:1}],
  start_date: todayInput(),
  end_date: '',
  // purchase
  urgent: false,
  need_by: '',
  items: [] as string[],
  spec_cpu: '',
  spec_ram: '',
  spec_storage: '',
  spec_display: '',
  spec_other: '',
})
watch(type, () => { f.requester_signature_data = '' })
function addBorrowItem(){if(f.borrow_items.length<50)f.borrow_items.push({device_kind:'',item:'',quantity:1})}
function removeBorrowItem(index:number){if(f.borrow_items.length>1){f.borrow_items.splice(index,1);errors.value=Object.fromEntries(Object.entries(errors.value).filter(([key])=>!key.startsWith('borrow_items.')))}}

// ผูกข้อมูลจาก QR เฉพาะแจ้งซ่อม รวมถึงกรณีเปิด URL เก่าที่แนบ ?asset มาด้วย
watch(
  [() => route.query.asset, type],
  async ([code, requestType], _previous, onCleanup) => {
    let active = true
    onCleanup(() => { active = false })
    asset.value = null
    f.asset_code = ''
    f.location = ''
    if (requestType !== 'repair' || typeof code !== 'string' || !code) return
    f.asset_code = code.toUpperCase()
    try {
      const loaded = await api.get<PublicAsset>(`/assets/${encodeURIComponent(f.asset_code)}`)
      if (!active) return
      asset.value = loaded
      if (!f.requester_dept && asset.value.department) f.requester_dept = asset.value.department
      if (asset.value.location) f.location = asset.value.location
    } catch {
      if (active) f.asset_code = ''
    }
  },
  { immediate: true },
)

// แนะนำชื่อ E-mail ตามรูปแบบบริษัท: ชื่อจริง.อักษรแรกนามสกุล
const suggestedEmail = computed(() => {
  const [first, last] = f.name_en.trim().toLowerCase().split(/\s+/)
  return first && last ? `${first.replace(/[^a-z]/g, '')}.${last[0]}` : ''
})

const descLabel = computed(() =>
  ({
    repair: 'อาการ / รายละเอียดปัญหา',
    service: 'รายละเอียดการร้องขอ',
    email: 'วัตถุประสงค์ในการใช้งาน',
    borrow: 'วัตถุประสงค์การใช้งาน',
    purchase: 'วัตถุประสงค์ / ความต้องการในการใช้งาน',
  })[type.value],
)

const descPlaceholder = computed(() =>
  ({
    repair: 'เช่น เปิดเครื่องแล้วจอดำ มีเสียงดังติ๊ด 3 ครั้ง',
    service: 'เช่น ขอติดตั้ง AutoCAD 2024 / ขอ VPN เข้าใช้งานจากบ้าน',
    email: 'เช่น ใช้ติดต่อลูกค้าและ supplier',
    borrow: 'เช่น ใช้นำเสนองานที่ลูกค้า',
    purchase: 'เช่น เครื่องเดิมช้ามาก ใช้งาน AutoCAD ไม่ไหว',
  })[type.value],
)

async function onPhoto(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  photo.value = await shrinkImage(file)
  if (photoPreview.value) URL.revokeObjectURL(photoPreview.value)
  photoPreview.value = URL.createObjectURL(photo.value)
}

function removePhoto() {
  photo.value = null
  if (photoPreview.value) URL.revokeObjectURL(photoPreview.value)
  photoPreview.value = ''
}

/** ย่อรูปจากกล้องมือถือให้ไม่เกิน 1600px เพื่ออัปโหลดเร็ว */
async function shrinkImage(file: File): Promise<File> {
  try {
    const bitmap = await createImageBitmap(file)
    const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height))
    if (scale === 1 && file.size < 1.5 * 1024 * 1024) return file
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)
    canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    const blob = await new Promise<Blob | null>((r) => canvas.toBlob(r, 'image/jpeg', 0.85))
    return blob ? new File([blob], 'photo.jpg', { type: 'image/jpeg' }) : file
  } catch {
    return file
  }
}

function payload() {
  const common = {
    type: type.value,
    requester_name: f.requester_name,
    requester_dept: f.requester_dept,
    requester_phone: f.requester_phone,
    requester_email: f.requester_email,
    description: f.description,
  }
  switch (type.value) {
    case 'repair':
      return { ...common, asset_code: f.asset_code, location: f.location, job_type: f.job_type, equipment: f.equipment, found_at: f.found_at }
    case 'service':
      return { ...common, requester_signature_data: f.requester_signature_data, position: f.position, device_kind: f.device_kind, actions: f.actions, os_target: f.os_target, other_text: f.other_text, approver_id: f.approver_id }
    case 'email':
      return { ...common, name_th: f.name_th, name_en: f.name_en, position: f.position, company: f.company, desired_email: f.desired_email, approver_name: f.approver_name }
    case 'borrow':
      return { ...common, borrow_items: f.borrow_items, start_date: f.start_date, end_date: f.end_date }
    case 'purchase':
      return {
        ...common,
        requester_signature_data: f.requester_signature_data,
        approver_id: f.approver_id,
        position: f.position,
        urgent: f.urgent,
        need_by: f.need_by,
        items: f.items,
        spec_cpu: f.spec_cpu,
        spec_ram: f.spec_ram,
        spec_storage: f.spec_storage,
        spec_display: f.spec_display,
        spec_other: f.spec_other,
      }
  }
}

async function submit() {
  if (submitting.value) return
  errors.value = {}
  formError.value = ''
  if (requiresApproval.value && !f.requester_signature_data) {
    errors.value.requester_signature_data = 'กรุณาวาดลายเซ็นผู้ร้องขอก่อนส่งคำขอ'
    formError.value = errors.value.requester_signature_data
    document.getElementById('requester_signature_heading')?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    return
  }
  submitting.value = true
  try {
    const body = new FormData()
    body.append('payload', JSON.stringify(payload()))
    if (photo.value) body.append('photo', photo.value)
    const res = await api.post<{ ticket_no: string; title: string; mail_status?: string }>('/tickets', body)
    rememberTicket(res.ticket_no)
    if (remember.value) {
      saveRequester({ requester_name: f.requester_name, requester_dept: f.requester_dept, requester_phone: f.requester_phone, requester_email: f.requester_email, position: f.position })
    }
    result.value = res
    f.requester_signature_data = ''
    window.scrollTo({ top: 0 })
  } catch (e) {
    if (e instanceof ApiError) {
      errors.value = e.errors
      formError.value = e.message
    } else {
      formError.value = 'ส่งไม่สำเร็จ กรุณาลองใหม่'
    }
    // เลื่อนไปช่องแรกที่ผิด
    requestAnimationFrame(() => document.querySelector('.input-error, .field-error')?.scrollIntoView({ block: 'center', behavior: 'smooth' }))
  } finally {
    submitting.value = false
  }
}

async function copyNo() {
  try {
    await navigator.clipboard.writeText(result.value!.ticket_no)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    // clipboard ใช้ไม่ได้บน http — ไม่เป็นไร
  }
}

const err = (k: string) => errors.value[k]
const inputClass = (k: string) => ['input', err(k) && 'input-error']
</script>

<template>
  <PublicShell :back="asset ? { name: 'asset', params: { code: asset.asset_code } } : '/'">
    <!-- ส่งสำเร็จ -->
    <div v-if="result" class="card p-8 text-center">
      <span class="mx-auto grid size-16 place-items-center rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400"><Icon name="check" :size="32" /></span>
      <h1 class="mt-4 text-xl font-bold">ส่งคำขอเรียบร้อย</h1>
      <p class="mt-1 text-slate-500 dark:text-slate-400">{{ result.title }}</p>
      <div class="mx-auto mt-5 max-w-xs rounded-2xl bg-slate-50 dark:bg-slate-900 p-4">
        <p class="text-sm text-slate-500 dark:text-slate-400">เลขที่คำขอ</p>
        <p class="font-mono text-2xl font-bold tracking-wider">{{ result.ticket_no }}</p>
        <button class="btn-ghost mt-1 px-3 py-1 text-xs" @click="copyNo">{{ copied ? 'คัดลอกแล้ว' : 'คัดลอก' }}</button>
      </div>
      <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">เก็บเลขนี้ไว้ติดตามสถานะ (ระบบจำไว้ในเครื่องนี้ให้ด้วย)</p>
      <p v-if="type==='borrow'" class="mt-3 rounded-lg bg-blue-50 dark:bg-blue-900/40 p-3 text-sm text-blue-800 dark:text-blue-300">ส่งคำขอให้ Admin IT Hub ตรวจสอบแล้ว เมื่อได้รับอนุมัติระบบจึงจะเพิ่มเข้าบันทึกเบิก–ยืม FM-IT-008</p>
      <p v-if="result.mail_status === 'sent'" class="mt-3 rounded-lg bg-blue-50 dark:bg-blue-900/40 p-3 text-sm text-blue-800 dark:text-blue-300">ส่งอีเมลขอลงนามให้ผู้อนุมัติแล้ว คำขออยู่ระหว่างรออนุมัติก่อน IT ดำเนินการ</p>
      <p v-else-if="result.mail_status === 'failed'" class="mt-3 rounded-lg bg-amber-50 dark:bg-amber-900/40 p-3 text-sm text-amber-800 dark:text-amber-300">บันทึกคำขอแล้ว แต่ส่งอีเมลขอลงนามไม่สำเร็จ กรุณาแจ้ง IT พร้อมเลขที่คำขอนี้เพื่อส่งอีเมลใหม่</p>
      <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
        <RouterLink :to="{ name: 'track', params: { no: result.ticket_no } }" class="btn-primary">ติดตามสถานะ</RouterLink>
        <RouterLink to="/" class="btn-secondary">กลับหน้าแรก</RouterLink>
      </div>
    </div>

    <form v-else-if="def" novalidate @submit.prevent="submit">
      <div class="mb-5 flex items-center gap-3">
        <span :class="['grid size-12 shrink-0 place-items-center rounded-xl', typeInfo[type].tone]"><Icon :name="typeInfo[type].icon" :size="24" /></span>
        <div>
          <h1 class="text-xl font-bold">{{ def.label }}</h1>
          <p class="text-xs text-slate-400">{{ def.form }}</p>
        </div>
      </div>

      <div v-if="asset" class="mb-4 flex items-center gap-3 rounded-2xl bg-slate-900 px-4 py-3 text-white">
        <Icon :name="asset.category_icon ?? 'device'" :size="22" />
        <div class="min-w-0">
          <p class="font-mono font-bold">{{ asset.asset_code }}</p>
          <p class="truncate text-sm text-slate-300">{{ asset.brand_model }} · {{ asset.department }}</p>
        </div>
      </div>

      <!-- ===== ข้อมูลเฉพาะประเภท ===== -->
      <section class="card space-y-4 p-5">
        <!-- แจ้งซ่อม -->
        <template v-if="type === 'repair'">
          <div>
            <span class="label">ประเภทงาน</span>
            <div class="grid grid-cols-2 gap-2">
              <label class="choice"><input v-model="f.job_type" type="radio" value="repair" /> งานซ่อม</label>
              <label class="choice"><input v-model="f.job_type" type="radio" value="general" /> งานทั่วไป</label>
            </div>
          </div>
          <template v-if="!asset">
            <div>
              <label class="label" for="equipment">อุปกรณ์ที่เสีย <span class="text-red-500 dark:text-red-400">*</span></label>
              <input id="equipment" v-model="f.equipment" :class="inputClass('equipment')" placeholder="เช่น Notebook, ปริ้นเตอร์ชั้น 2, Wi-Fi ห้องประชุม" />
              <p v-if="err('equipment')" class="field-error">{{ err('equipment') }}</p>
            </div>
            <div>
              <label class="label" for="asset_code">รหัสอุปกรณ์ (ถ้ามี)</label>
              <input id="asset_code" v-model="f.asset_code" :class="[...inputClass('asset_code'), 'uppercase']" placeholder="ดูจากสติกเกอร์บนเครื่อง เช่น MD-01-001" />
              <p v-if="err('asset_code')" class="field-error">{{ err('asset_code') }}</p>
            </div>
          </template>
          <div>
            <label class="label" for="description">{{ descLabel }} <span class="text-red-500 dark:text-red-400">*</span></label>
            <textarea id="description" v-model="f.description" rows="4" :class="inputClass('description')" :placeholder="descPlaceholder" />
            <p v-if="err('description')" class="field-error">{{ err('description') }}</p>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label" for="found_at">วันเวลาที่พบปัญหา</label>
              <input id="found_at" v-model="f.found_at" type="datetime-local" :class="inputClass('found_at')" />
            </div>
            <div>
              <label class="label" for="location">สถานที่</label>
              <input id="location" v-model="f.location" class="input" placeholder="เช่น อาคาร 1 ชั้น 2 ฝ่ายบัญชี" />
            </div>
          </div>
          <div>
            <span class="label">แนบรูป (ถ้ามี)</span>
            <div v-if="photoPreview" class="relative inline-block">
              <img :src="photoPreview" alt="รูปที่แนบ" class="max-h-48 rounded-xl border border-slate-200" />
              <button type="button" class="absolute -top-2 -right-2 grid size-7 place-items-center rounded-full bg-slate-900 text-white" aria-label="ลบรูป" @click="removePhoto">
                <Icon name="x" :size="14" />
              </button>
            </div>
            <label v-else class="btn-secondary w-full cursor-pointer border-dashed py-4">
              <Icon name="camera" /> ถ่ายรูป / เลือกรูปอาการ
              <input type="file" accept="image/*" class="sr-only" @change="onPhoto" />
            </label>
            <p v-if="err('photo')" class="field-error">{{ err('photo') }}</p>
          </div>
        </template>

        <!-- ร้องขอดำเนินการด้าน IT -->
        <template v-else-if="type === 'service'">
          <div>
            <span class="label">อุปกรณ์</span>
            <div class="grid grid-cols-3 gap-2">
              <label v-for="(label, k) in meta!.device_kinds" :key="k" class="choice"><input v-model="f.device_kind" type="radio" :value="k" /> {{ label }}</label>
            </div>
          </div>
          <div>
            <span class="label">ต้องการให้ดำเนินการ <span class="text-red-500 dark:text-red-400">*</span></span>
            <div class="grid gap-2">
              <label v-for="(label, k) in meta!.service_actions" :key="k" class="choice"><input v-model="f.actions" type="checkbox" :value="k" /> {{ label }}</label>
            </div>
            <p v-if="err('actions')" class="field-error">{{ err('actions') }}</p>
          </div>
          <div v-if="f.actions.includes('install')">
            <label class="label" for="os_target">OS / โปรแกรมที่ต้องการ</label>
            <input id="os_target" v-model="f.os_target" class="input" placeholder="เช่น Windows 11 Pro, Microsoft Office 2021" />
          </div>
          <div v-if="f.actions.includes('other')">
            <label class="label" for="other_text">อื่นๆ (ระบุ)</label>
            <input id="other_text" v-model="f.other_text" class="input" />
          </div>
          <div>
            <label class="label" for="description">{{ descLabel }} <span class="text-red-500 dark:text-red-400">*</span></label>
            <textarea id="description" v-model="f.description" rows="4" :class="inputClass('description')" :placeholder="descPlaceholder" />
            <p v-if="err('description')" class="field-error">{{ err('description') }}</p>
          </div>
        </template>

        <!-- ขอใช้ E-mail -->
        <template v-else-if="type === 'email'">
          <p class="rounded-xl bg-violet-50 dark:bg-violet-900/40 p-3 text-sm text-violet-900 dark:text-violet-300">กรอกข้อมูลของพนักงานที่จะใช้ E-mail (ผู้แจ้งด้านล่างอาจเป็น HR หรือหัวหน้าก็ได้)</p>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label" for="name_th">ชื่อ-นามสกุล (ไทย) <span class="text-red-500 dark:text-red-400">*</span></label>
              <input id="name_th" v-model="f.name_th" :class="inputClass('name_th')" />
              <p v-if="err('name_th')" class="field-error">{{ err('name_th') }}</p>
            </div>
            <div>
              <label class="label" for="name_en">ชื่อ-นามสกุล (อังกฤษ) <span class="text-red-500 dark:text-red-400">*</span></label>
              <input id="name_en" v-model="f.name_en" :class="inputClass('name_en')" placeholder="Somchai Thongdee" />
              <p v-if="err('name_en')" class="field-error">{{ err('name_en') }}</p>
            </div>
          </div>
          <div>
            <label class="label" for="position">ตำแหน่ง <span class="text-red-500 dark:text-red-400">*</span></label>
            <input id="position" v-model="f.position" :class="inputClass('position')" />
            <p v-if="err('position')" class="field-error">{{ err('position') }}</p>
          </div>
          <div>
            <span class="label">บริษัท <span class="text-red-500 dark:text-red-400">*</span></span>
            <div class="grid gap-2">
              <label v-for="(label, k) in meta!.email_companies" :key="k" class="choice"><input v-model="f.company" type="radio" :value="k" /> {{ label }}</label>
            </div>
          </div>
          <div>
            <label class="label" for="desired_email">ชื่อ E-mail ที่ต้องการ</label>
            <div class="flex items-center gap-2">
              <input id="desired_email" v-model="f.desired_email" class="input" :placeholder="suggestedEmail || 'somchai.t'" />
              <span class="shrink-0 text-sm text-slate-500 dark:text-slate-400">@atthasan.com</span>
            </div>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
              รูปแบบมาตรฐาน: ชื่อจริงภาษาอังกฤษ.อักษรแรกของนามสกุล
              <button v-if="suggestedEmail && !f.desired_email" type="button" class="ml-1 font-semibold text-brand-700 dark:text-brand-300" @click="f.desired_email = suggestedEmail">
                ใช้ {{ suggestedEmail }}
              </button>
            </p>
          </div>
          <div>
            <label class="label" for="description">{{ descLabel }} <span class="text-red-500 dark:text-red-400">*</span></label>
            <textarea id="description" v-model="f.description" rows="3" :class="inputClass('description')" :placeholder="descPlaceholder" />
            <p v-if="err('description')" class="field-error">{{ err('description') }}</p>
          </div>
        </template>

        <!-- เบิก-ยืมอุปกรณ์ -->
        <template v-else-if="type === 'borrow'">
          <p class="mb-4 rounded-lg bg-blue-50 dark:bg-blue-900/40 p-3 text-sm text-blue-800 dark:text-blue-300">คำขอเบิก–ยืมต้องได้รับอนุมัติจาก Admin IT Hub ก่อนเพิ่มเข้าบันทึก FM-IT-008</p>
          <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-3"><h2 class="flex-1 font-semibold">รายการอุปกรณ์ ({{f.borrow_items.length}})</h2><button type="button" class="btn-secondary" :disabled="f.borrow_items.length>=50" @click="addBorrowItem">+ เพิ่มรายการอุปกรณ์</button></div>
            <p v-if="err('borrow_items')" class="field-error">{{err('borrow_items')}}</p>
            <article v-for="(entry,i) in f.borrow_items" :key="i" class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
              <div class="mb-3 flex items-center justify-between gap-3"><h3 class="text-sm font-semibold">รายการที่ {{i+1}}</h3><button v-if="f.borrow_items.length>1" type="button" class="text-sm text-red-600 dark:text-red-400" :aria-label="`ลบอุปกรณ์รายการที่ ${i+1}`" @click="removeBorrowItem(i)">ลบรายการ</button></div>
              <p v-if="err(`borrow_items.${i}`)" class="field-error">{{err(`borrow_items.${i}`)}}</p>
              <div class="grid gap-3 sm:grid-cols-[8rem_1fr_5rem]">
                <div><label class="label" :for="`borrow-kind-${i}`">ประเภท <span class="text-red-500 dark:text-red-400">*</span></label><select :id="`borrow-kind-${i}`" v-model="entry.device_kind" :class="inputClass(`borrow_items.${i}.device_kind`)"><option value="">เลือกประเภท</option><option value="notebook">Notebook</option><option value="other">อื่นๆ</option></select><p v-if="err(`borrow_items.${i}.device_kind`)" class="field-error">{{err(`borrow_items.${i}.device_kind`)}}</p></div>
                <div class="min-w-0"><label class="label" :for="`borrow-item-${i}`">อุปกรณ์ที่ต้องการ <span class="text-red-500 dark:text-red-400">*</span></label><input :id="`borrow-item-${i}`" v-model="entry.item" maxlength="150" :class="inputClass(`borrow_items.${i}.item`)" placeholder="เช่น Notebook, Projector, เมาส์" /><p v-if="err(`borrow_items.${i}.item`)" class="field-error">{{err(`borrow_items.${i}.item`)}}</p></div>
                <div><label class="label" :for="`borrow-quantity-${i}`">จำนวน <span class="text-red-500 dark:text-red-400">*</span></label><input :id="`borrow-quantity-${i}`" v-model.number="entry.quantity" type="number" min="1" max="100" :class="inputClass(`borrow_items.${i}.quantity`)" /><p v-if="err(`borrow_items.${i}.quantity`)" class="field-error">{{err(`borrow_items.${i}.quantity`)}}</p></div>
              </div>
            </article>
            <p class="text-xs text-slate-500 dark:text-slate-400">เพิ่มได้สูงสุด 50 รายการ ใช้ช่วงเวลาและวัตถุประสงค์เดียวกันในคำขอนี้</p>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label" for="start_date">วันที่เริ่มใช้ <span class="text-red-500 dark:text-red-400">*</span></label>
              <input id="start_date" v-model="f.start_date" type="date" :class="inputClass('start_date')" />
              <p v-if="err('start_date')" class="field-error">{{ err('start_date') }}</p>
            </div>
            <div>
              <label class="label" for="end_date">วันที่คืน</label>
              <input id="end_date" v-model="f.end_date" type="date" :min="f.start_date" :class="inputClass('end_date')" />
              <p v-if="err('end_date')" class="field-error">{{ err('end_date') }}</p>
            </div>
          </div>
          <div>
            <label class="label" for="description">{{ descLabel }} <span class="text-red-500 dark:text-red-400">*</span></label>
            <textarea id="description" v-model="f.description" rows="3" :class="inputClass('description')" :placeholder="descPlaceholder" />
            <p v-if="err('description')" class="field-error">{{ err('description') }}</p>
          </div>
        </template>

        <!-- ขอซื้อครุภัณฑ์ -->
        <template v-else-if="type === 'purchase'">
          <div>
            <span class="label">ความต้องการ</span>
            <div class="grid grid-cols-2 gap-2">
              <label class="choice"><input v-model="f.urgent" type="radio" :value="false" /> ไม่ด่วน</label>
              <label class="choice"><input v-model="f.urgent" type="radio" :value="true" /> ด่วน</label>
            </div>
          </div>
          <div>
            <label class="label" for="need_by">ต้องการภายในวันที่</label>
            <input id="need_by" v-model="f.need_by" type="date" :min="todayInput()" :class="inputClass('need_by')" />
          </div>
          <div>
            <span class="label">ประเภทอุปกรณ์ที่ต้องการ <span class="text-red-500 dark:text-red-400">*</span></span>
            <div class="grid gap-2 sm:grid-cols-2">
              <label v-for="(label, k) in meta!.purchase_items" :key="k" class="choice"><input v-model="f.items" type="checkbox" :value="k" /> {{ label }}</label>
            </div>
            <p v-if="err('items')" class="field-error">{{ err('items') }}</p>
          </div>
          <div>
            <label class="label" for="description">{{ descLabel }} <span class="text-red-500 dark:text-red-400">*</span></label>
            <textarea id="description" v-model="f.description" rows="3" :class="inputClass('description')" :placeholder="descPlaceholder" />
            <p v-if="err('description')" class="field-error">{{ err('description') }}</p>
          </div>
          <details class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
            <summary class="cursor-pointer text-sm font-medium text-slate-700 dark:text-slate-200">สเปกที่ต้องการ (ถ้าทราบ)</summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
              <div><label class="label" for="spec_cpu">CPU</label><input id="spec_cpu" v-model="f.spec_cpu" class="input" placeholder="Core i5 / Ryzen 5" /></div>
              <div><label class="label" for="spec_ram">RAM</label><input id="spec_ram" v-model="f.spec_ram" class="input" placeholder="16 GB" /></div>
              <div><label class="label" for="spec_storage">SSD/HDD</label><input id="spec_storage" v-model="f.spec_storage" class="input" placeholder="512 GB" /></div>
              <div><label class="label" for="spec_display">ขนาดจอ</label><input id="spec_display" v-model="f.spec_display" class="input" placeholder="15.6 นิ้ว" /></div>
              <div class="sm:col-span-2"><label class="label" for="spec_other">อื่นๆ</label><input id="spec_other" v-model="f.spec_other" class="input" /></div>
            </div>
          </details>
        </template>
      </section>

      <!-- ===== ผู้แจ้ง ===== -->
      <section class="card mt-4 space-y-4 p-5">
        <h2 class="font-semibold">ข้อมูลผู้แจ้ง</h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="label" for="requester_name">ชื่อ-นามสกุล <span class="text-red-500 dark:text-red-400">*</span></label>
            <input id="requester_name" v-model="f.requester_name" :class="inputClass('requester_name')" autocomplete="name" />
            <p v-if="err('requester_name')" class="field-error">{{ err('requester_name') }}</p>
          </div>
          <div>
            <label class="label" for="requester_dept">ฝ่าย / แผนก <span class="text-red-500 dark:text-red-400">*</span></label>
            <input id="requester_dept" v-model="f.requester_dept" :class="inputClass('requester_dept')" />
            <p v-if="err('requester_dept')" class="field-error">{{ err('requester_dept') }}</p>
          </div>
          <div v-if="type === 'service' || type === 'purchase'">
            <label class="label" for="position">ตำแหน่ง</label>
            <input id="position" v-model="f.position" class="input" />
          </div>
          <div>
            <label class="label" for="requester_phone">เบอร์โทร / เบอร์ภายใน</label>
            <input id="requester_phone" v-model="f.requester_phone" type="tel" class="input" autocomplete="tel" />
          </div>
          <div>
            <label class="label" for="requester_email">E-mail</label>
            <input id="requester_email" v-model="f.requester_email" type="email" :class="inputClass('requester_email')" autocomplete="email" />
            <p v-if="err('requester_email')" class="field-error">{{ err('requester_email') }}</p>
          </div>
          <div v-if="requiresApproval" class="sm:col-span-2">
            <label class="label" for="approver_id">{{approvalLabel}} <span class="text-red-500 dark:text-red-400">*</span></label>
            <select id="approver_id" v-model="f.approver_id" :class="inputClass('approver_id')" required>
              <option value="" disabled>เลือก{{approvalLabel}}</option>
              <optgroup v-for="company in approverCompanies" :key="company" :label="company">
                <option v-for="a in approvers.filter(a => approverCompanyLabel(a) === company)" :key="a.id" :value="a.id">{{ a.full_name }} — {{ a.department || a.position || company }}</option>
              </optgroup>
            </select>
            <p v-if="err('approver_id')" class="field-error">{{ err('approver_id') }}</p>
            <p v-if="approverError" class="field-error">{{ approverError }} <button type="button" class="underline" @click="loadApprovers">โหลดรายชื่อใหม่</button></p>
            <p v-else-if="!approvers.length" class="mt-1 text-sm text-amber-700 dark:text-amber-300">ยังไม่มีผู้อนุมัติ กรุณาติดต่อ IT เพื่อจัดการข้อมูลพนักงาน</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">เมื่อส่งคำขอ ระบบจะส่งอีเมลให้ผู้ที่เลือกตรวจสอบและลงลายเซ็นก่อน IT ดำเนินการ</p>
          </div>
          <div v-if="type === 'email'">
            <label class="label" for="approver_name">หัวหน้าฝ่าย / ผู้อนุมัติ</label>
            <input id="approver_name" v-model="f.approver_name" class="input" />
          </div>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
          <input v-model="remember" type="checkbox" class="size-4 accent-brand-700" /> จำข้อมูลผู้แจ้งไว้ในเครื่องนี้
        </label>
      </section>

      <section v-if="requiresApproval" class="card mt-4 space-y-3 p-5" aria-labelledby="requester_signature_heading">
        <h2 id="requester_signature_heading" class="font-semibold">ลงนามผู้ร้องขอ</h2>
        <div><label class="label" for="requester_signer_name">ชื่อผู้ร้องขอ</label><input id="requester_signer_name" :value="f.requester_name" class="input bg-slate-50 dark:bg-slate-900" readonly placeholder="กรอกชื่อ-นามสกุลในข้อมูลผู้แจ้งด้านบน" /></div>
        <p class="label">ลายเซ็นผู้ร้องขอ <span class="text-red-500 dark:text-red-400">*</span></p>
        <SignaturePad :key="type" v-model="f.requester_signature_data" />
        <p v-if="err('requester_signature_data')" class="field-error">{{ err('requester_signature_data') }}</p>
        <p class="text-xs text-slate-500 dark:text-slate-400">เมื่อส่งคำขอ ระบบจะบันทึกชื่อ ลายเซ็น และวันเวลาลงนามไว้ในคำขอนี้และฉบับพิมพ์</p>
      </section>

      <p v-if="formError" class="mt-4 flex items-center gap-2 rounded-xl bg-red-50 dark:bg-red-900/40 p-3 text-sm text-red-700 dark:text-red-300"><Icon name="alert" :size="16" /> {{ formError }}</p>

      <button class="btn-primary mt-5 w-full py-3.5 text-base" :disabled="submitting">
        <span v-if="submitting" class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
        {{ submitting ? 'กำลังส่ง...' : 'ส่งคำขอ' }}
      </button>
    </form>

    <div v-else class="card p-8 text-center text-slate-500 dark:text-slate-400">ไม่พบประเภทคำขอนี้ <RouterLink to="/" class="text-brand-700 dark:text-brand-300">กลับหน้าแรก</RouterLink></div>
  </PublicShell>
</template>
