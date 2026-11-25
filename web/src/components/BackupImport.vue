<script setup lang="ts">
import { ref } from 'vue'
import { api } from '@/api'
import { fmtFormDate } from '@/format'
import Modal from './Modal.vue'

interface PlanRow { action: 'add' | 'differs'; line: string; fields: string[]; backup_date: string; server: string; dataset: string; data_size: string | null; started_at: string | null; finished_at: string | null; notes: string | null }
interface SourcePlan { label: string; year: number; file_rows: number; add: number; same: number; differs: number; rows: PlanRow[]; rows_total: number; errors: string[]; warnings: string[] }
interface Preview { summary: { add: number; same: number; differs: number; errors: number; warnings: number }; sources: SourcePlan[]; preview_hash: string; can_apply: boolean; applied?: boolean }
const emit = defineEmits<{ close: []; imported: [year: number] }>()
const file = ref<File | null>(null)
const preview = ref<Preview | null>(null), done = ref<Preview | null>(null)
const busy = ref(false), error = ref('')
const fieldLabel: Record<string, string> = { data_size: 'ขนาดไฟล์จริง', source: 'แหล่งจัดเก็บ', device: 'อุปกรณ์สำรอง', folder: 'Folder', backup_size: 'ขนาด BACK UP', started_at: 'เวลาเริ่ม', finished_at: 'เวลาเสร็จ', maker: 'จัดทำ', acknowledger: 'รับทราบ', notes: 'หมายเหตุ' }

function selectFile(event: Event) {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
  preview.value = null; done.value = null; error.value = ''
  if (file.value && (!/\.xlsx$/i.test(file.value.name) || file.value.size > 5 * 1024 * 1024)) { error.value = 'กรุณาเลือกไฟล์ .xlsx ขนาดไม่เกิน 5 MB'; file.value = null }
}
async function check() {
  if (!file.value || busy.value) return
  busy.value = true; error.value = ''; preview.value = null; done.value = null
  try { const form = new FormData(); form.append('file', file.value); preview.value = await api.post<Preview>('/admin/backups/import/preview', form) }
  catch (e) { error.value = (e as Error).message }
  finally { busy.value = false }
}
async function apply() {
  if (!file.value || !preview.value?.can_apply || busy.value) return
  busy.value = true; error.value = ''
  try {
    const form = new FormData(); form.append('file', file.value); form.append('preview_hash', preview.value.preview_hash)
    done.value = await api.post<Preview>('/admin/backups/import/apply', form)
    preview.value = null
    const withAdds = done.value.sources.filter(s => s.add > 0)
    emit('imported', withAdds.length ? Math.max(...withAdds.map(s => s.year)) : 0)
  } catch (e) {
    error.value = (e as Error).message
    preview.value = null // anything unclear (409 / network) must be checked again
  } finally { busy.value = false }
}
const close = () => { if (!busy.value) emit('close') }
</script>

<template>
  <Modal title="Import Excel รายงาน Backup FM-IT-005" wide @close="close">
    <p class="text-sm text-slate-600 dark:text-slate-300">ใช้ไฟล์รูปแบบเดียวกับต้นฉบับ FM-IT-005 (หนึ่ง Sheet ต่อปี ชื่อ Sheet มีปี พ.ศ. เช่น <span class="font-mono">2569</span> ข้อมูลเริ่มแถว 6) ระบบจะ<strong>เพิ่มเฉพาะครั้งที่ยังไม่มี</strong> (เทียบวันที่ + Server + ข้อมูลที่ BACK UP) บันทึกที่มีอยู่แล้วไม่ถูกเขียนทับ ถ้าค่าต่างจากไฟล์จะแสดงให้ตรวจเท่านั้น</p>
    <div class="mt-4 flex flex-wrap items-center gap-3">
      <input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" aria-label="เลือกไฟล์ Excel" class="input max-w-full flex-1" :disabled="busy" @change="selectFile" />
      <button class="btn-primary" :disabled="!file || busy" @click="check">{{ busy && !preview ? 'กำลังตรวจสอบ...' : 'ตรวจสอบไฟล์' }}</button>
    </div>
    <p v-if="error" role="alert" class="mt-3 rounded-xl bg-red-50 dark:bg-red-900/40 p-3 text-sm text-red-700 dark:text-red-300">{{ error }}</p>

    <div v-if="done" class="mt-4 rounded-xl bg-green-50 dark:bg-green-900/40 p-4 text-sm text-green-800 dark:text-green-300" role="status">
      นำเข้าแล้ว: เพิ่ม {{ done.summary.add }} ครั้ง
      <div class="mt-3"><button class="btn-secondary" @click="emit('close')">ปิด</button></div>
    </div>

    <template v-if="preview">
      <p class="mt-4 text-sm">เพิ่มใหม่ <strong>{{ preview.summary.add }}</strong> · ตรงกับระบบ {{ preview.summary.same }} · <span :class="preview.summary.differs ? 'text-amber-700 dark:text-amber-300' : ''">ต่างจากระบบ (ไม่เปลี่ยน) {{ preview.summary.differs }}</span></p>
      <section v-for="s in preview.sources" :key="s.label" class="mt-3 rounded-xl border border-slate-200 dark:border-slate-700 p-3 text-sm">
        <h3 class="font-semibold">{{ s.label }} <span class="font-normal text-slate-500 dark:text-slate-400">· ปี {{ s.year }} · {{ s.file_rows }} แถว</span></h3>
        <p class="mt-1 text-slate-600 dark:text-slate-300">เพิ่มใหม่ {{ s.add }} · ตรงกับระบบ {{ s.same }} · ต่างจากระบบ {{ s.differs }}</p>
        <details v-if="s.rows.length" class="mt-2"><summary class="cursor-pointer text-brand-700 dark:text-brand-300">ดูรายละเอียด ({{ s.rows_total }})</summary>
          <div class="table-wrap mt-2 max-h-64 overflow-y-auto"><table class="table"><thead><tr><th>ผล</th><th>แถว</th><th>ว/ด/ป</th><th>ข้อมูล</th><th>ขนาด / เวลา</th></tr></thead>
            <tbody><tr v-for="(r, i) in s.rows" :key="i"><td class="whitespace-nowrap">{{ r.action === 'add' ? 'เพิ่มใหม่' : 'ต่าง: ' + r.fields.map(f => fieldLabel[f] || f).join(', ') }}</td><td class="whitespace-nowrap">{{ r.line }}</td><td class="font-mono">{{ fmtFormDate(r.backup_date) }}</td><td>{{ r.dataset }}<p v-if="r.notes" class="text-xs text-amber-700 dark:text-amber-300">{{ r.notes }}</p></td><td class="whitespace-nowrap">{{ r.data_size || '—' }} · {{ r.started_at || '—' }}–{{ r.finished_at || '—' }}</td></tr></tbody></table></div>
        </details>
        <ul v-if="s.errors.length" class="mt-2 list-disc pl-5 text-red-700 dark:text-red-300"><li v-for="(e, i) in s.errors" :key="i">{{ e }}</li></ul>
        <ul v-if="s.warnings.length" class="mt-2 list-disc pl-5 text-amber-700 dark:text-amber-300"><li v-for="(w, i) in s.warnings" :key="i">{{ w }}</li></ul>
      </section>
      <p v-if="!preview.can_apply" class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ preview.summary.errors ? 'แก้ข้อผิดพลาดในไฟล์ก่อนนำเข้า' : 'ทุกครั้งในไฟล์มีอยู่ในระบบแล้ว ไม่มีอะไรต้องนำเข้า' }}</p>
      <div class="mt-4 flex justify-end gap-2">
        <button class="btn-secondary" :disabled="busy" @click="close">ยกเลิก</button>
        <button class="btn-primary" :disabled="!preview.can_apply || busy" @click="apply">{{ busy ? 'กำลังนำเข้า...' : 'ยืนยันนำเข้า' }}</button>
      </div>
    </template>
  </Modal>
</template>
