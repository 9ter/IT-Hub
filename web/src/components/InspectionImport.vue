<script setup lang="ts">
import { ref } from 'vue'
import { api } from '@/api'
import { fmtFormDate } from '@/format'
import { inspectionMonths } from '@/inspections'
import Modal from './Modal.vue'

interface YearPlan {
  year: number; sheet: string; existing_items: number; file_items: number; add_items: number; set_results: number; overwrite_results: number
  set_months: number; months: { month: number; inspected_on: string | null; inspector: string | null; reviewer: string | null }[]
  rows: { action: 'add' | 'update'; asset_code: string | null; item: string | null; results: number }[]; rows_total: number
  warnings: string[]; errors: string[]
}
interface Preview {
  summary: { years: number; add_items: number; set_results: number; set_months: number; errors: number; warnings: number }
  years: YearPlan[]; preview_hash: string; can_apply: boolean; applied?: boolean
}
const emit = defineEmits<{ close: []; imported: [year: number] }>()
const file = ref<File | null>(null)
const preview = ref<Preview | null>(null)
const done = ref<Preview | null>(null)
const busy = ref(false), error = ref('')

function selectFile(event: Event) {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
  preview.value = null; done.value = null; error.value = ''
  if (file.value && (!/\.xlsx$/i.test(file.value.name) || file.value.size > 5 * 1024 * 1024)) { error.value = 'กรุณาเลือกไฟล์ .xlsx ขนาดไม่เกิน 5 MB'; file.value = null }
}
async function check() {
  if (!file.value || busy.value) return
  busy.value = true; error.value = ''; preview.value = null; done.value = null
  try { const form = new FormData(); form.append('file', file.value); preview.value = await api.post<Preview>('/admin/inspections/import/preview', form) }
  catch (e) { error.value = (e as Error).message }
  finally { busy.value = false }
}
async function apply() {
  if (!file.value || !preview.value?.can_apply || busy.value) return
  busy.value = true; error.value = ''
  try {
    const form = new FormData(); form.append('file', file.value); form.append('preview_hash', preview.value.preview_hash)
    done.value = await api.post<Preview>('/admin/inspections/import/apply', form)
    preview.value = null
    emit('imported', done.value.years[0]?.year ?? 0)
  } catch (e) {
    error.value = (e as Error).message
    preview.value = null // anything unclear (409 / network) must be checked again
  } finally { busy.value = false }
}
const close = () => { if (!busy.value) emit('close') }
</script>

<template>
  <Modal title="Import Excel ใบตรวจสอบ FM-IT-002" wide @close="close">
    <p class="text-sm text-slate-600 dark:text-slate-300">ใช้ไฟล์รูปแบบเดียวกับต้นฉบับ FM-IT-002 (หนึ่ง Sheet ต่อปี ชื่อ Sheet มีปี พ.ศ. เช่น <span class="font-mono">FM-IT-002(2569)</span>) ระบบจะ<strong>เพิ่มรายการที่ยังไม่มี</strong> และ<strong>ใส่ผลตรวจ / วันที่ตรวจ / ผู้ตรวจ / ผู้ทบทวน</strong>ตามที่ไฟล์มี โดยไม่ลบรายการหรือผลที่บันทึกไว้แล้ว</p>
    <div class="mt-4 flex flex-wrap items-center gap-3">
      <input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" aria-label="เลือกไฟล์ Excel" class="input max-w-full flex-1" :disabled="busy" @change="selectFile" />
      <button class="btn-primary" :disabled="!file || busy" @click="check">{{ busy && !preview ? 'กำลังตรวจสอบ...' : 'ตรวจสอบไฟล์' }}</button>
    </div>
    <p v-if="error" role="alert" class="mt-3 rounded-xl bg-red-50 dark:bg-red-900/40 p-3 text-sm text-red-700 dark:text-red-300">{{ error }}</p>

    <div v-if="done" class="mt-4 rounded-xl bg-green-50 dark:bg-green-900/40 p-4 text-sm text-green-800 dark:text-green-300" role="status">
      นำเข้าแล้ว: เพิ่มรายการ {{ done.summary.add_items }} · ผลตรวจ {{ done.summary.set_results }} ช่อง · ข้อมูลประจำเดือน {{ done.summary.set_months }} เดือน
      <div class="mt-3"><button class="btn-secondary" @click="emit('close')">ปิด</button></div>
    </div>

    <template v-if="preview">
      <p class="mt-4 text-sm">พบ {{ preview.summary.years }} ปี · เพิ่มรายการ <strong>{{ preview.summary.add_items }}</strong> · ใส่ผลตรวจ <strong>{{ preview.summary.set_results }}</strong> ช่อง · ข้อมูลประจำเดือน <strong>{{ preview.summary.set_months }}</strong> เดือน</p>
      <section v-for="y in preview.years" :key="y.year" class="mt-3 rounded-xl border border-slate-200 dark:border-slate-700 p-3 text-sm">
        <h3 class="font-semibold">ปี {{ y.year }} <span class="font-normal text-slate-500 dark:text-slate-400">· Sheet {{ y.sheet }}</span></h3>
        <p class="mt-1 text-slate-600 dark:text-slate-300">ในไฟล์ {{ y.file_items }} รายการ · ในระบบ {{ y.existing_items }} · เพิ่มใหม่ {{ y.add_items }} · ผลตรวจ {{ y.set_results }} ช่อง<span v-if="y.overwrite_results" class="text-amber-700 dark:text-amber-300"> (เปลี่ยนผลเดิม {{ y.overwrite_results }} ช่อง)</span></p>
        <ul v-if="y.months.length" class="mt-1 text-slate-600 dark:text-slate-300">
          <li v-for="m in y.months" :key="m.month">{{ inspectionMonths[m.month - 1] }}: วันที่ตรวจ {{ fmtFormDate(m.inspected_on) || '—' }} · ผู้ตรวจสอบ {{ m.inspector || '—' }} · ผู้ทบทวน {{ m.reviewer || '—' }}</li>
        </ul>
        <details v-if="y.rows.length" class="mt-2"><summary class="cursor-pointer text-brand-700 dark:text-brand-300">ดูรายการที่เปลี่ยน ({{ y.rows_total }})</summary>
          <div class="table-wrap mt-2 max-h-64 overflow-y-auto"><table class="table"><thead><tr><th>การทำงาน</th><th>หมายเลขเครื่อง</th><th>รายการ</th><th>ผลตรวจ</th></tr></thead>
            <tbody><tr v-for="(r, i) in y.rows" :key="i"><td>{{ r.action === 'add' ? 'เพิ่มใหม่' : 'ใส่ผลตรวจ' }}</td><td class="font-mono">{{ r.asset_code || '—' }}</td><td>{{ r.item || '—' }}</td><td>{{ r.results }} ช่อง</td></tr></tbody></table></div>
        </details>
        <ul v-if="y.errors.length" class="mt-2 list-disc pl-5 text-red-700 dark:text-red-300"><li v-for="(e, i) in y.errors" :key="i">{{ e }}</li></ul>
        <ul v-if="y.warnings.length" class="mt-2 list-disc pl-5 text-amber-700 dark:text-amber-300"><li v-for="(w, i) in y.warnings" :key="i">{{ w }}</li></ul>
      </section>
      <p v-if="!preview.can_apply" class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ preview.summary.errors ? 'แก้ข้อผิดพลาดในไฟล์ก่อนนำเข้า' : 'ข้อมูลในไฟล์ตรงกับระบบแล้ว ไม่มีอะไรต้องนำเข้า' }}</p>
      <div class="mt-4 flex justify-end gap-2">
        <button class="btn-secondary" :disabled="busy" @click="close">ยกเลิก</button>
        <button class="btn-primary" :disabled="!preview.can_apply || busy" @click="apply">{{ busy ? 'กำลังนำเข้า...' : 'ยืนยันนำเข้า' }}</button>
      </div>
    </template>
  </Modal>
</template>
