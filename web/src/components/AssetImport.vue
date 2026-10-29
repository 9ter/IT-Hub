<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/api'
import { meta } from '@/store'
import Modal from './Modal.vue'
import Icon from './Icon.vue'

interface Summary { total: number; created: number; updated: number; unchanged: number; renamed: number }
interface ImportRow {
  sheet: string; row: number; asset_code: string; company: string; brand_model: string
  previous_code: string | null; reason: string; action: 'created' | 'updated' | 'unchanged'
  changes: Record<string, { before: string | number | null; after: string | number | null }>
}
interface Preview {
  summary: Summary; rows: ImportRow[]; errors: { sheet: string; row: number; asset_code: string; message: string }[]
  preview_hash: string; can_apply: boolean
}
interface ImportResult { summary: Summary; renamed_ids: number[] }
const emit = defineEmits<{ close: []; imported: [] }>()
const router = useRouter()
const apiBase = `${import.meta.env.BASE_URL}api`
const file = ref<File | null>(null)
const preview = ref<Preview | null>(null)
const result = ref<ImportResult | null>(null)
const busy = ref(false)
const error = ref('')
const showUnchanged = ref(false)
const visibleRows = computed(() => preview.value?.rows.filter((row) => showUnchanged.value || row.action !== 'unchanged') ?? [])
const actionLabels = { created: 'เพิ่มใหม่', updated: 'แก้ไข', unchanged: 'ตรงเดิม' }
const fieldLabels: Record<string, string> = {
  asset_code: 'รหัส', category_id: 'หมวด', company: 'บริษัท', brand_model: 'ยี่ห้อ / รุ่น', serial_no: 'Serial', hostname: 'ชื่อเครื่อง',
  holder_name: 'ผู้ครอบครอง', holder_nickname: 'ชื่อเล่น', department: 'หน่วยงาน', location: 'สถานที่', status: 'สถานะ',
  registered_at: 'วันที่ขึ้นทะเบียน', warranty_until: 'วันหมดประกัน', specs: 'สเปก', notes: 'หมายเหตุ',
}

function displayValue(field: string, value: string | number | null) {
  if (value === null) return '—'
  if (field === 'category_id') return meta.value?.categories.find((category) => category.id === Number(value))?.name ?? String(value)
  if (field === 'status') return meta.value?.asset_statuses[value as keyof typeof meta.value.asset_statuses] ?? String(value)
  return String(value)
}

function selectFile(event: Event) {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
  preview.value = null; result.value = null; error.value = ''
  if (file.value && (!/\.xlsx$/i.test(file.value.name) || file.value.size > 5 * 1024 * 1024)) {
    error.value = 'กรุณาเลือกไฟล์ .xlsx ขนาดไม่เกิน 5 MB'
    file.value = null
  }
}

async function inspectFile() {
  if (!file.value || busy.value) return
  busy.value = true; error.value = ''; preview.value = null; result.value = null
  try {
    const form = new FormData(); form.append('file', file.value)
    preview.value = await api.post<Preview>('/admin/assets/import/preview', form)
  } catch (e) { error.value = (e as Error).message }
  finally { busy.value = false }
}

async function apply() {
  if (!file.value || !preview.value?.can_apply || busy.value) return
  busy.value = true; error.value = ''
  try {
    const form = new FormData(); form.append('file', file.value); form.append('preview_hash', preview.value.preview_hash)
    result.value = await api.post<ImportResult>('/admin/assets/import/apply', form)
    preview.value = null
    emit('imported')
  } catch (e) {
    error.value = (e as Error).message
    // A stale preview or an uncertain network response must be inspected again before retrying.
    if (!(e instanceof ApiError) || e.status === 409 || e.status === 0 || e.status >= 500) preview.value = null
  } finally { busy.value = false }
}

function close() { if (!busy.value) emit('close') }
function printRenamed() {
  if (!result.value?.renamed_ids.length) return
  window.open(router.resolve({ name: 'qr-print', query: { ids: result.value.renamed_ids.join(',') } }).href, '_blank', 'noopener')
}
</script>

<template>
  <Modal title="Import ทะเบียนอุปกรณ์" wide @close="close">
    <div class="space-y-4">
      <p class="text-sm text-slate-600 dark:text-slate-300">นำเข้าไฟล์ Excel รูปแบบ FM-IT-001 จาก Sheet ATS / INNO ระบบจะแสดงรายการให้ตรวจสอบก่อนบันทึก</p>
      <div class="rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-900/60">
        <a :href="`${apiBase}/admin/assets/import/template`" class="font-medium text-brand-700 underline dark:text-brand-300">ดาวน์โหลดแบบฟอร์มเปล่า .xlsx</a>
        <p class="mt-2">กรอกตั้งแต่แถว 5: ลำดับ, หมายเลข, ผู้ครอบครอง, ยี่ห้อ/รุ่น, Serial No, หน่วยงาน, วันที่ขึ้นทะเบียน, ชื่อเล่น, Type และหมายเหตุ</p>
        <p class="mt-1 text-slate-500 dark:text-slate-400">Type: Notebook / Computer / AIO · วันที่ใช้ ค.ศ. หรือ พ.ศ. ได้ · ไม่เกิน 1,000 รายการ / 5 MB</p>
      </div>
      <label class="block text-sm font-medium">
        เลือกไฟล์ Excel
        <input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" :disabled="busy" class="input mt-2 min-w-0 max-w-full text-sm" @change="selectFile" />
      </label>
      <p class="text-xs text-slate-500 dark:text-slate-400">จับคู่ด้วย Serial เพื่อรักษาประวัติเครื่องเดิม ช่องว่างคงข้อมูลเดิมไว้ และอุปกรณ์ที่ไม่มีในไฟล์ยังอยู่ในระบบ</p>
      <p v-if="error" role="alert" class="break-words rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">{{ error }}</p>
      <p v-if="busy" role="status" class="text-sm text-brand-700 dark:text-brand-300">กำลังดำเนินการ กรุณารอสักครู่…</p>
      <div v-if="preview" class="space-y-3" aria-live="polite">
        <div class="grid grid-cols-2 gap-2 text-center text-sm sm:grid-cols-4">
          <div class="rounded-lg bg-emerald-50 p-3 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">เพิ่มใหม่ <strong>{{ preview.summary.created }}</strong></div>
          <div class="rounded-lg bg-blue-50 p-3 text-blue-800 dark:bg-blue-950/40 dark:text-blue-300">แก้ไข <strong>{{ preview.summary.updated }}</strong></div>
          <div class="rounded-lg bg-slate-100 p-3 dark:bg-slate-900">ตรงเดิม <strong>{{ preview.summary.unchanged }}</strong></div>
          <div class="rounded-lg bg-red-50 p-3 text-red-800 dark:bg-red-950/40 dark:text-red-300">ต้องแก้ไข <strong>{{ preview.errors.length }}</strong></div>
        </div>
        <p v-if="preview.summary.renamed" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">เปลี่ยนรหัสทะเบียน {{ preview.summary.renamed }} เครื่อง หลังบันทึกต้องพิมพ์ QR ใหม่ให้ตรงกับรหัสล่าสุด</p>
        <div v-if="preview.errors.length" role="alert" class="space-y-2 rounded-lg border border-red-200 p-3 text-sm dark:border-red-900">
          <p class="font-semibold text-red-700 dark:text-red-300">แก้ไขรายการเหล่านี้ในไฟล์ แล้วตรวจสอบอีกครั้ง ยังไม่มีการบันทึกข้อมูล</p>
          <p v-for="(issue, i) in preview.errors" :key="i" class="break-words">{{ issue.sheet }} แถว {{ issue.row }} · {{ issue.asset_code }}: {{ issue.message }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="showUnchanged" type="checkbox" /> แสดงรายการที่ตรงเดิมด้วย</label>
        <div class="max-h-80 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-2 dark:border-slate-700">
          <details v-for="row in visibleRows" :key="`${row.sheet}-${row.row}`" class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-900/60">
            <summary class="cursor-pointer break-words">
              <strong>{{ row.asset_code }}</strong> · {{ row.company }} · {{ actionLabels[row.action] }}
              <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ row.brand_model }} · {{ row.sheet }} แถว {{ row.row }}</span>
            </summary>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ row.reason }}</p>
            <dl class="mt-2 space-y-2">
              <div v-for="(change, field) in row.changes" :key="field" class="break-words">
                <dt class="font-medium">{{ fieldLabels[field] ?? field }}</dt>
                <dd class="whitespace-pre-wrap text-slate-500 dark:text-slate-400">{{ displayValue(String(field), change.before) }} → {{ displayValue(String(field), change.after) }}</dd>
              </div>
            </dl>
          </details>
          <p v-if="!visibleRows.length" class="p-3 text-center text-sm text-slate-500 dark:text-slate-400">ไม่มีรายการที่เปลี่ยนแปลง</p>
        </div>
      </div>
      <div v-if="result" role="status" class="space-y-2 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
        <p class="font-semibold">Import สำเร็จ</p>
        <p>เพิ่มใหม่ {{ result.summary.created }} · แก้ไข {{ result.summary.updated }} · ตรงเดิม {{ result.summary.unchanged }} รายการ</p>
        <p v-if="result.summary.renamed">เปลี่ยนรหัส {{ result.summary.renamed }} เครื่อง กรุณาพิมพ์ QR ใหม่</p>
        <button v-if="result.renamed_ids.length" class="btn-secondary" @click="printRenamed"><Icon name="qr" :size="16" /> พิมพ์ QR รายการเปลี่ยนรหัส</button>
      </div>
      <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-700">
        <button class="btn-secondary" :disabled="busy" @click="close">{{ result ? 'ปิด' : 'ยกเลิก' }}</button>
        <button v-if="!result" class="btn-secondary" :disabled="!file || busy" @click="inspectFile">ตรวจสอบไฟล์</button>
        <button v-if="preview?.can_apply && (preview.summary.created || preview.summary.updated)" class="btn-primary" :disabled="busy" @click="apply">ยืนยัน Import {{ preview.summary.created + preview.summary.updated }} รายการ</button>
      </div>
    </div>
  </Modal>
</template>

