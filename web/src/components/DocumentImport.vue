<script setup lang="ts">
import { computed, ref } from 'vue'
import { api, ApiError } from '@/api'
import type { DocumentDefinition } from '@/documents'
import Modal from './Modal.vue'

interface PreviewDocument {
  sheet: string; title: string; company: string; document_date: string
  fields: { label: string; value: string }[]; columns: string[]; rows_preview: string[][]; row_count: number
  warnings: string[]; errors: string[]; action: 'create' | 'duplicate' | 'error'
  duplicate: { id: number; document_no: string } | null
}
interface Preview {
  form_code: string; source: 'template' | 'original'
  summary: { total: number; create: number; duplicate: number; errors: number }
  documents: PreviewDocument[]; preview_hash: string; can_apply: boolean
}
interface ImportResult { created: { id: number; document_no: string; title: string }[] }

const props = defineProps<{ form: DocumentDefinition }>()
const emit = defineEmits<{ close: []; imported: [] }>()
const apiBase = `${import.meta.env.BASE_URL}api`
// Original FM-IT report workbooks (one sheet = one document) are also accepted for these forms.
const originalForms = ['FM-IT-002', 'FM-IT-005', 'FM-IT-006']
const acceptsOriginal = computed(() => originalForms.includes(props.form.code))
const file = ref<File | null>(null)
const preview = ref<Preview | null>(null)
const result = ref<ImportResult | null>(null)
const busy = ref(false)
const error = ref('')
const actionLabels = { create: 'สร้างฉบับร่าง', duplicate: 'นำเข้าแล้ว (ข้าม)', error: 'ต้องแก้ไข' }
const actionTone = {
  create: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
  duplicate: 'bg-slate-100 text-slate-700 dark:bg-slate-900 dark:text-slate-300',
  error: 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-300',
}

function selectFile(event: Event) {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
  preview.value = null; result.value = null; error.value = ''
  if (file.value && (!/\.xlsx$/i.test(file.value.name) || file.value.size > 5 * 1024 * 1024)) {
    error.value = 'กรุณาเลือกไฟล์ .xlsx ขนาดไม่เกิน 5 MB'
    file.value = null
  }
}

function payload(withHash: boolean) {
  const form = new FormData()
  form.append('file', file.value as File)
  form.append('form_code', props.form.code)
  if (withHash && preview.value) form.append('preview_hash', preview.value.preview_hash)
  return form
}

async function inspectFile() {
  if (!file.value || busy.value) return
  busy.value = true; error.value = ''; preview.value = null; result.value = null
  try { preview.value = await api.post<Preview>('/admin/document-import/preview', payload(false)) }
  catch (e) { error.value = (e as Error).message }
  finally { busy.value = false }
}

async function apply() {
  if (!file.value || !preview.value?.can_apply || busy.value) return
  busy.value = true; error.value = ''
  try {
    result.value = await api.post<ImportResult>('/admin/document-import/apply', payload(true))
    preview.value = null
    emit('imported')
  } catch (e) {
    error.value = (e as Error).message
    // A stale preview or an uncertain network response must be inspected again before retrying.
    if (!(e instanceof ApiError) || e.status === 409 || e.status === 0 || e.status >= 500) preview.value = null
  } finally { busy.value = false }
}

function close() { if (!busy.value) emit('close') }
</script>

<template>
  <Modal :title="`Import Excel ${form.code}`" wide @close="close">
    <div class="space-y-4">
      <p class="text-sm text-slate-600 dark:text-slate-300">{{ form.title }} · นำเข้าเป็น<strong>ฉบับร่าง</strong> ตรวจทานและเลือกผู้ลงนามในระบบก่อนส่ง ไม่มีการลงนามหรืออนุมัติจาก Excel</p>
      <div class="space-y-2 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-900/60">
        <a :href="`${apiBase}/admin/document-import/template/${encodeURIComponent(form.code)}`" class="font-medium text-brand-700 underline dark:text-brand-300">ดาวน์โหลดแบบฟอร์ม Excel สำหรับ Import ({{ form.code }})</a>
        <p>Sheet “ข้อมูลเอกสาร” กรอกคอลัมน์ C<span v-if="form.columns.length"> · Sheet “รายการ” กรอกตั้งแต่แถว 3 (ไม่เกิน 1,000 แถว)</span> · ช่องตัวเลือกมีรายการให้เลือก</p>
        <p class="text-slate-500 dark:text-slate-400">วันที่ใช้ วว/ดด/ปปปป ได้ทั้ง พ.ศ. และ ค.ศ. · ไฟล์ .xlsx ไม่เกิน 5 MB</p>
        <p v-if="acceptsOriginal" class="text-slate-500 dark:text-slate-400">หรือใช้ไฟล์ต้นฉบับ {{ form.code }} เดิมได้เลย ระบบสร้าง 1 เอกสารต่อ 1 Sheet</p>
      </div>
      <label class="block text-sm font-medium">
        เลือกไฟล์ Excel
        <input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" :disabled="busy" class="input mt-2 min-w-0 max-w-full text-sm" @change="selectFile" />
      </label>
      <p v-if="error" role="alert" class="break-words rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">{{ error }}</p>
      <p v-if="busy" role="status" class="text-sm text-brand-700 dark:text-brand-300">กำลังดำเนินการ กรุณารอสักครู่…</p>

      <div v-if="preview" class="space-y-3" aria-live="polite">
        <div class="grid grid-cols-3 gap-2 text-center text-sm">
          <div class="rounded-lg bg-emerald-50 p-3 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">สร้างใหม่ <strong>{{ preview.summary.create }}</strong></div>
          <div class="rounded-lg bg-slate-100 p-3 dark:bg-slate-900">นำเข้าแล้ว <strong>{{ preview.summary.duplicate }}</strong></div>
          <div class="rounded-lg bg-red-50 p-3 text-red-800 dark:bg-red-950/40 dark:text-red-300">ต้องแก้ไข <strong>{{ preview.summary.errors }}</strong></div>
        </div>
        <p v-if="preview.summary.errors" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">แก้ไขรายการที่แจ้งในไฟล์ แล้วตรวจสอบอีกครั้ง ยังไม่มีการบันทึกข้อมูล</p>
        <details v-for="(doc, i) in preview.documents" :key="i" class="rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700" :open="preview.documents.length === 1 || doc.action === 'error'">
          <summary class="cursor-pointer break-words">
            <span :class="['mr-2 inline-block rounded-full px-2 py-0.5 text-xs font-medium', actionTone[doc.action]]">{{ actionLabels[doc.action] }}</span>
            <strong>{{ doc.title }}</strong>
            <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ doc.company }}<template v-if="preview.source === 'original'"> · Sheet {{ doc.sheet }}</template><template v-if="form.columns.length"> · {{ doc.row_count }} รายการ</template></span>
          </summary>
          <div class="mt-3 space-y-3">
            <p v-if="doc.duplicate" class="text-slate-600 dark:text-slate-300">ไฟล์เดียวกันนี้นำเข้าแล้วเป็น <RouterLink :to="{ name: 'document-detail', params: { id: doc.duplicate.id } }" class="font-mono text-brand-700 underline dark:text-brand-300">{{ doc.duplicate.document_no }}</RouterLink></p>
            <ul v-if="doc.errors.length" class="list-disc space-y-1 pl-5 text-red-700 dark:text-red-300"><li v-for="(e, j) in doc.errors" :key="j" class="break-words">{{ e }}</li></ul>
            <ul v-if="doc.warnings.length" class="list-disc space-y-1 pl-5 text-amber-800 dark:text-amber-300"><li v-for="(w, j) in doc.warnings" :key="j" class="break-words">{{ w }}</li></ul>
            <dl v-if="doc.fields.length" class="grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
              <template v-for="(f, j) in doc.fields" :key="j"><dt class="font-medium text-slate-500 dark:text-slate-400">{{ f.label }}</dt><dd class="whitespace-pre-wrap break-words">{{ f.value }}</dd></template>
            </dl>
            <div v-if="doc.rows_preview.length" class="table-wrap">
              <table class="table text-xs"><thead><tr><th v-for="c in doc.columns" :key="c" class="whitespace-nowrap">{{ c }}</th></tr></thead>
                <tbody><tr v-for="(row, j) in doc.rows_preview" :key="j"><td v-for="(v, k) in row" :key="k" class="max-w-48 truncate" :title="v">{{ v }}</td></tr></tbody></table>
              <p v-if="doc.row_count > doc.rows_preview.length" class="mt-1 text-xs text-slate-500 dark:text-slate-400">แสดง {{ doc.rows_preview.length }} จาก {{ doc.row_count }} รายการ</p>
            </div>
          </div>
        </details>
      </div>

      <div v-if="result" role="status" class="space-y-2 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
        <p class="font-semibold">Import สำเร็จ สร้างฉบับร่าง {{ result.created.length }} เอกสาร</p>
        <ul class="space-y-1"><li v-for="d in result.created" :key="d.id"><RouterLink :to="{ name: 'document-detail', params: { id: d.id } }" class="font-mono underline">{{ d.document_no }}</RouterLink> · {{ d.title }}</li></ul>
        <p>เปิดเอกสารเพื่อตรวจทาน เลือกผู้ลงนาม แล้วจึงส่งลงนาม</p>
      </div>
      <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-700">
        <button class="btn-secondary" :disabled="busy" @click="close">{{ result ? 'ปิด' : 'ยกเลิก' }}</button>
        <button v-if="!result" class="btn-secondary" :disabled="!file || busy" @click="inspectFile">ตรวจสอบไฟล์</button>
        <button v-if="preview?.can_apply" class="btn-primary" :disabled="busy" @click="apply">ยืนยัน Import {{ preview.summary.create }} เอกสาร</button>
      </div>
    </div>
  </Modal>
</template>
